<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class CommitCheckJobs
{
    public function __construct(private readonly LocalProjectReader $reader, private readonly TestFailureReport $reports = new TestFailureReport) {}

    private function root(): string
    {
        $root = (string) config('central.check_jobs_path', storage_path('app/private/commit-check-jobs'));
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Não foi possível criar o histórico privado de verificações.');
        }

        return $root;
    }

    private function path(string $id, string $suffix = '.json'): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('Identificador de verificação inválido.');
        }

        return $this->root().DIRECTORY_SEPARATOR.$id.$suffix;
    }

    public function read(string $id, ?string $project = null): array
    {
        $handle = @fopen($this->path($id), 'rb');
        if ($handle === false) {
            throw new RuntimeException('Verificação não encontrada.');
        }
        try {
            flock($handle, LOCK_SH);
            $job = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($project !== null && $job['project'] !== $project) {
            throw new RuntimeException('Esta verificação pertence a outro projeto.');
        }
        $job['cancel_requested'] = is_file($this->path($id, '.cancel'));
        $job['heartbeat_delayed'] = in_array($job['status'], ['queued', 'running'], true) && time() - $job['updated_at'] > 60;
        if ($job['heartbeat_delayed'] && is_file($this->path($id, '.pid'))) {
            $pid = trim(file_get_contents($this->path($id, '.pid')));
            if (ctype_digit($pid)) {
                $listing = Process::timeout(5)->run(['tasklist.exe', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
                if ($listing->successful() && ! str_contains($listing->output(), '"'.$pid.'"')) {
                    $job['status'] = 'interrupted';
                    $job['message'] = 'O processo do executor terminou sem registrar resultado. A limpeza não foi confirmada; a promoção permanece bloqueada.';
                }
            }
        }

        return $this->reports->job($job);
    }

    private function save(array $job): void
    {
        $job = $this->reports->job($job);
        $handle = fopen($this->path($job['id']), 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível salvar o andamento da verificação.');
        }
        try {
            flock($handle, LOCK_EX);
            $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('Gravação incompleta do andamento da verificação.');
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function latest(string $project): ?array
    {
        $latest = null;
        foreach (glob($this->root().'/*.json') as $file) {
            $job = $this->read(basename($file, '.json'));
            if ($job['project'] === $project && ($latest === null || $job['created_at'] >= $latest['created_at'])) {
                $latest = $job;
            }
        }

        return $latest;
    }

    /** @return array{sanitized: int, skipped: int} */
    public function sanitizeHistory(): array
    {
        $summary = ['sanitized' => 0, 'skipped' => 0];
        foreach (glob($this->root().'/*.json') as $file) {
            $id = basename($file, '.json');
            if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
                continue;
            }
            $lock = fopen($this->path($id, '.lock'), 'c+b');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $summary['skipped']++;

                continue;
            }
            try {
                $job = $this->read($id);
                if (in_array($job['status'], ['queued', 'running'], true)) {
                    $summary['skipped']++;

                    continue;
                }
                $this->save($job);
                foreach (['.stdout', '.stderr'] as $suffix) {
                    $path = $this->path($id, $suffix);
                    if (is_file($path) && file_put_contents($path, '') === false) {
                        throw new RuntimeException('Não foi possível limpar uma saída antiga do executor.');
                    }
                }
                $summary['sanitized']++;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $summary;
    }

    public function start(string $project, string $sha, ?string $testTarget = null): array
    {
        $commit = $this->reader->commitDiff($project, $sha)['commit'];
        $testTarget = $testTarget === null ? null : $this->reader->normalizeTestTarget($testTarget);
        $lock = fopen($this->root().'/launch.lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Outra verificação está sendo iniciada. Aguarde alguns segundos.');
        }
        try {
            foreach (glob($this->root().'/*.json') as $file) {
                $other = $this->read(basename($file, '.json'));
                if (in_array($other['status'], ['queued', 'running'], true)) {
                    if ($other['project'] === $project) {
                        return $other;
                    }
                    throw new RuntimeException('Já existe uma verificação em execução no projeto '.$other['project'].'. Aguarde ou cancele essa verificação.');
                }
            }
            $job = [
                'id' => bin2hex(random_bytes(16)), 'project' => $project, 'sha' => $commit['sha'],
                'status' => 'queued', 'stage' => 'Aguardando início do executor',
                'created_at' => time(), 'updated_at' => time(), 'elapsed_seconds' => 0,
                'completed_tests' => 0, 'total_tests' => null, 'current_test' => null,
                'failed_tests' => 0, 'errored_tests' => 0, 'test_target' => $testTarget,
                'output' => '', 'result' => null, 'message' => '',
            ];
            $this->save($job);
            try {
                $this->launch($job['id']);
            } catch (\Throwable $exception) {
                $job['status'] = 'failed';
                $job['message'] = 'O executor em segundo plano não iniciou: '.$exception->getMessage();
                $job['updated_at'] = time();
                $this->save($job);
            }

            return $this->read($job['id'], $project);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function launch(string $id): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            throw new RuntimeException('O executor automático está disponível neste ambiente Windows.');
        }
        $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $arguments = '-d opcache.enable_cli=0 -d display_errors=0 -d log_errors=0 "'.base_path('artisan').'" central:verify '.$id.' --no-interaction';
        $script = '$ErrorActionPreference="Stop"; Start-Process -WindowStyle Hidden -FilePath '.$quote((string) config('central.php_binary'))
            .' -ArgumentList '.$quote($arguments).' -WorkingDirectory '.$quote(base_path())
            .' -RedirectStandardOutput '.$quote($this->path($id, '.stdout'))
            .' -RedirectStandardError '.$quote($this->path($id, '.stderr')).' -PassThru | Select-Object -ExpandProperty Id';
        $result = Process::timeout(10)->run([
            'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')),
        ]);
        if (! $result->successful()) {
            throw new RuntimeException(mb_substr($result->errorOutput(), -2000));
        }
        $pid = trim($result->output());
        if (! ctype_digit($pid) || file_put_contents($this->path($id, '.pid'), $pid) === false) {
            throw new RuntimeException('Não foi possível registrar o identificador do executor.');
        }
    }

    public function cancel(string $id, string $project): array
    {
        $job = $this->read($id, $project);
        if (in_array($job['status'], ['queued', 'running'], true)) {
            if (file_put_contents($this->path($id, '.cancel'), 'cancel') === false) {
                throw new RuntimeException('Não foi possível solicitar o cancelamento.');
            }
        }

        return $this->read($id, $project);
    }

    public function execute(string $id): void
    {
        $lock = fopen($this->path($id, '.lock'), 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Esta verificação já possui um executor.');
        }
        $job = $this->read($id);
        if ($job['status'] !== 'queued') {
            fclose($lock);

            return;
        }
        $job['status'] = 'running';
        $job['updated_at'] = time();
        $this->save($job);
        register_shutdown_function(function () use (&$job): void {
            if ($job['status'] === 'running') {
                $job['status'] = 'interrupted';
                $job['message'] = 'O executor foi interrompido inesperadamente. A limpeza não foi confirmada; a promoção permanece bloqueada.';
                $job['updated_at'] = time();
                $this->save($job);
            }
        });
        try {
            $result = $this->reader->runCommitChecks($job['project'], $job['sha'], 1800,
                function (string $stage, array $progress) use (&$job): void {
                    $job['stage'] = $stage;
                    $job['updated_at'] = time();
                    $job['elapsed_seconds'] = time() - $job['created_at'];
                    if ($stage === 'Testes Laravel') {
                        foreach (['total_tests', 'completed_tests', 'failed_tests', 'errored_tests', 'current_test', 'live_failures'] as $field) {
                            $job[$field] = $progress[$field] ?? null;
                        }
                    }
                    $this->save($job);
                },
                fn (): bool => is_file($this->path($id, '.cancel')),
                $job['test_target'] ?? null,
            );
            $job['result'] = $result;
            $causes = array_column($result['checks'], 'cause');
            $passed = $result['checks'] !== [] && ! in_array(false, array_column($result['checks'], 'passed'), true);
            $job['status'] = in_array('cancelled', $causes, true) ? 'cancelled' : ($passed ? 'passed' : 'failed');
            $job['message'] = $passed ? 'Todas as verificações foram aprovadas.' : 'Verificação concluída sem aprovação. Confira os resultados abaixo.';
            if ($job['status'] === 'cancelled') {
                $job['message'] = 'Verificação cancelada. Confira abaixo a confirmação da limpeza.';
            }
        } catch (\Throwable $exception) {
            $job['status'] = 'failed';
            $job['message'] = 'Falha do executor: '.$exception->getMessage();
        } finally {
            $job['updated_at'] = time();
            $job['elapsed_seconds'] = time() - $job['created_at'];
            $this->save($job);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
