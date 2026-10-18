<?php

namespace App\Services;

class TestFailureReport
{
    private const STAGES = ['Aguardando início do executor', 'Criando cópia isolada', 'Copiando dependências PHP', 'Copiando dependências JavaScript', 'Preparando autoload', 'Build isolado', 'Testes Laravel', 'Pint', 'Removendo cópia temporária'];

    public function testName(string $name): string
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*)(?:$|\s|#)/', $name, $matches)) {
            return $matches[1];
        }

        return 'Teste não identificado';
    }

    public function file(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~(?:^|/)(tests/[A-Za-z0-9_./-]+\.php)$~i', $path, $matches)
            && ! str_contains($matches[1], '..') && ! str_contains($matches[1], '//')) {
            return $matches[1];
        }

        return null;
    }

    public function message(string $raw): string
    {
        $safe = [
            'O registro esperado não foi encontrado no banco de teste. Valores omitidos.',
            'A resposta não contém o conteúdo esperado. Conteúdo omitido.',
            'A resposta contém conteúdo que deveria estar ausente. Conteúdo omitido.',
            'Os valores obtido e esperado são diferentes. Valores omitidos.',
            'O teste terminou com uma exceção. Detalhes dinâmicos omitidos.',
            'O teste falhou. Detalhes dinâmicos omitidos para proteger dados pessoais e credenciais.',
            'A verificação foi interrompida antes de produzir uma falha estruturada.',
        ];
        if (in_array($raw, $safe, true)) {
            return $raw;
        }
        if (preg_match('/^Resposta HTTP inesperada: esperado ([1-5][0-9]{2}), recebido ([1-5][0-9]{2})\.$/', $raw)) {
            return $raw;
        }
        if (preg_match('/Expected response status code \[([1-5][0-9]{2})\] but received ([1-5][0-9]{2})\./', $raw, $codes)) {
            return 'Resposta HTTP inesperada: esperado '.$codes[1].', recebido '.$codes[2].'.';
        }
        $raw = strtolower($raw);

        return match (true) {
            str_contains($raw, 'a row in the table') => $safe[0],
            str_contains($raw, 'does not contain'), str_contains($raw, 'should not contain') => $safe[2],
            str_contains($raw, 'contains'), str_contains($raw, 'contain the string') => $safe[1],
            str_contains($raw, 'identical'), str_contains($raw, 'equal') => $safe[3],
            str_contains($raw, 'exception') => $safe[4],
            default => $safe[5],
        };
    }

    /** @return array{test: string, file: ?string, line: ?int, message: string, exception: ?string, application_file: ?string, application_line: ?int, category: string} */
    public function failure(string $test, string $file, mixed $line, string $message): array
    {
        $file = $this->file($file);
        $number = filter_var($line, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
        $exception = $this->exception($message);
        [$applicationFile, $applicationLine] = $this->applicationLocation($message);

        return ['test' => $this->testName($test), 'file' => $file, 'line' => $file !== null && $number !== false ? $number : null, 'message' => $this->message($message), 'exception' => $exception, 'application_file' => $applicationFile, 'application_line' => $applicationLine, 'category' => $this->category($message, $exception)];
    }

    private function exception(string $raw): ?string
    {
        if (preg_match('/(?:^|\R)(?:PHP (?:Fatal )?error:\s*)?([A-Za-z_][A-Za-z0-9_\\\\]{0,190}(?:Exception|Error))(?::|\s)/', $raw, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** @return array{?string, ?int} */
    private function applicationLocation(string $raw): array
    {
        if (preg_match('~(?:^|[\\s(])((?:app|routes|config)/[A-Za-z0-9_./-]+\.php):([1-9][0-9]{0,7})~', str_replace('\\', '/', $raw), $matches) && ! str_contains($matches[1], '..') && ! str_contains($matches[1], '//')) {
            return [$matches[1], (int) $matches[2]];
        }

        return [null, null];
    }

    private function category(string $raw, ?string $exception): string
    {
        $value = strtolower($raw.' '.$exception);

        return match (true) {
            str_contains($value, 'validationexception'), str_contains($value, 'validation failed') => 'validation',
            str_contains($value, 'authorizationexception'), str_contains($value, 'authenticationexception'), str_contains($value, 'access denied') => 'access_control',
            str_contains($value, 'queryexception'), str_contains($value, 'pdoexception'), str_contains($value, 'sqlstate') => 'database',
            str_contains($value, 'modelnotfoundexception'), str_contains($value, 'not found') => 'not_found',
            str_contains($value, 'assertion') => 'assertion',
            $exception !== null => 'application_exception',
            default => 'test_failure',
        };
    }

    /** @return list<array{test: string, file: ?string, line: ?int, message: string}> */
    public function fromOutput(string $output): array
    {
        $failures = [];
        if (preg_match_all('/^Test (?:Failed|Errored) \(([^\r\n]+)\)\r?\n([^\r\n]*)/m', $output, $events, PREG_SET_ORDER)) {
            foreach (array_slice($events, 0, 50) as $event) {
                $failures[] = $this->failure('', '', null, $event[2]);
            }
        }

        return $failures;
    }

    public function junit(string $path): ?array
    {
        $xml = file_get_contents($path);
        if ($xml === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $report = simplexml_load_string($xml, options: LIBXML_NONET);
            if ($report === false) {
                return null;
            }
            $failures = [];
            foreach (array_slice($report->xpath('//testcase[failure or error]'), 0, 50) as $case) {
                $message = (string) ($case->failure ?? $case->error);
                $file = (string) $case['file'];
                $line = (string) $case['line'];
                $class = (string) ($case['class'] ?? $case['classname']);
                $failures[] = $this->failure($class.'::'.(string) $case['name'], $file, $line, $message);
            }

            return ['failures' => $failures, 'summary' => [
                'tests' => count($report->xpath('//testcase')), 'failures' => count($report->xpath('//testcase/failure')),
                'errors' => count($report->xpath('//testcase/error')), 'skipped' => count($report->xpath('//testcase/skipped')),
            ]];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function result(array $result): array
    {
        $checks = [];
        foreach ($result['checks'] ?? [] as $check) {
            $passed = ($check['passed'] ?? false) === true;
            $status = in_array($check['status'] ?? '', ['passed', 'failed', 'infrastructure_unavailable'], true) ? $check['status'] : ($passed ? 'passed' : 'failed');
            $failures = [];
            foreach (array_slice($check['failures'] ?? $this->fromOutput((string) ($check['output'] ?? '')), 0, 50) as $failure) {
                $failures[] = $this->failure((string) ($failure['test'] ?? ''), (string) ($failure['file'] ?? ''), $failure['line'] ?? null, (string) ($failure['message'] ?? ''));
            }
            if (! $passed && $failures === []) {
                $failures[] = $this->failure('', '', null, $status === 'infrastructure_unavailable' ? 'A verificação foi interrompida antes de produzir uma falha estruturada.' : (string) ($check['output'] ?? ''));
            }
            $safe = [
                'name' => in_array($check['name'] ?? '', ['Preparação isolada', 'Testes Laravel', 'Pint', 'Limpeza'], true) ? $check['name'] : 'Verificação',
                'status' => $status, 'passed' => $passed, 'failures' => $failures,
                'explanation' => $passed ? 'Verificação concluída com sucesso.' : ($status === 'infrastructure_unavailable' ? 'Verificação incompleta. A promoção permanece bloqueada.' : 'A verificação encontrou falhas. Confira o resumo sanitizado.'),
            ];
            if (in_array($check['cause'] ?? '', ['cancelled', 'test_timeout', 'idle_timeout', 'preparation_timeout', 'preparation_incomplete', 'php_timeout', 'infrastructure_error'], true)) {
                $safe['cause'] = $check['cause'];
                $safe['explanation'] = match ($check['cause']) {
                    'cancelled' => 'Verificação cancelada pelo usuário; testes não aprovados.',
                    'test_timeout' => 'O processo de teste atingiu o limite de tempo; testes não aprovados.',
                    'idle_timeout' => 'O processo atingiu o limite sem produzir progresso; testes não aprovados.',
                    'preparation_timeout' => 'A preparação da cópia isolada atingiu o limite de tempo; testes não aprovados.',
                    'php_timeout' => 'O PHP atingiu o limite de execução; testes não aprovados.',
                    'infrastructure_error' => 'A infraestrutura não permitiu concluir a verificação; testes não aprovados.',
                    default => 'A preparação da cópia isolada não foi concluída; testes não aprovados.',
                };
            }
            if (isset($check['test_summary'])) {
                $safe['test_summary'] = $this->counts($check['test_summary']);
            }
            $checks[] = $safe;
        }
        $clean = ($result['cleanup']['successful'] ?? false) === true;

        return [
            'commit' => preg_match('/^[a-f0-9]{7,64}$/i', $result['commit'] ?? '') ? $result['commit'] : '',
            'checks' => $checks,
            'cleanup' => ['successful' => $clean, 'message' => $clean ? 'Cópia temporária removida.' : 'Limpeza não confirmada. A promoção permanece bloqueada.'],
            'elapsed_seconds' => max(0, (float) ($result['elapsed_seconds'] ?? 0)),
        ];
    }

    private function counts(array $summary): array
    {
        $safe = [];
        foreach (['tests', 'failures', 'errors', 'skipped'] as $key) {
            $safe[$key] = max(0, (int) ($summary[$key] ?? 0));
        }

        return $safe;
    }

    public function job(array $job): array
    {
        $status = in_array($job['status'] ?? '', ['queued', 'running', 'passed', 'failed', 'cancelled', 'interrupted'], true) ? $job['status'] : 'interrupted';
        $safe = [
            'id' => preg_match('/^[a-f0-9]{32}$/', $job['id'] ?? '') ? $job['id'] : '',
            'project' => preg_match('/^[A-Za-z0-9_-]{1,100}$/', $job['project'] ?? '') ? $job['project'] : 'project',
            'sha' => preg_match('/^[a-f0-9]{7,64}$/i', $job['sha'] ?? '') ? $job['sha'] : '',
            'cancel_requested' => ($job['cancel_requested'] ?? false) === true,
            'heartbeat_delayed' => ($job['heartbeat_delayed'] ?? false) === true,
            'test_target' => $this->testTarget($job['test_target'] ?? null),
        ];
        foreach (['created_at', 'updated_at', 'elapsed_seconds', 'completed_tests', 'total_tests', 'failed_tests', 'errored_tests'] as $key) {
            $safe[$key] = $key === 'total_tests' && ! isset($job[$key]) ? null : max(0, (int) ($job[$key] ?? 0));
        }
        $safe['status'] = $status;
        $safe['stage'] = in_array($job['stage'] ?? '', self::STAGES, true) ? $job['stage'] : 'Aguardando início do executor';
        $safe['current_test'] = null;
        $safe['result'] = is_array($job['result'] ?? null) ? $this->result($job['result']) : null;
        $safe['message'] = match ($status) {
            'passed' => 'Todas as verificações foram aprovadas.',
            'failed' => $safe['result'] === null ? 'O executor não concluiu a verificação. Nenhum teste foi aprovado.' : 'Verificação concluída sem aprovação. Confira o resumo sanitizado.',
            'cancelled' => 'Verificação cancelada. Confira a confirmação da limpeza.',
            'interrupted' => 'Executor interrompido. Resultado e limpeza não confirmados.',
            default => '',
        };
        $safe['report_format'] = 'safe-test-report-v1';
        $safe['live_failures'] = [];
        foreach (array_slice($job['live_failures'] ?? [], 0, 50) as $failure) {
            $safe['live_failures'][] = $this->failure('', '', null, (string) ($failure['message'] ?? ''));
        }

        return $safe;
    }

    private function testTarget(mixed $target): ?string
    {
        $target = (string) $target;
        if (preg_match('~^tests/(?:Feature|Unit)/[A-Za-z][A-Za-z0-9_/-]*Test\.php$~', $target) || preg_match('/^Tests\\\\(?:Feature|Unit)\\\\[A-Za-z][A-Za-z0-9_]*Test(?:::[A-Za-z][A-Za-z0-9_]*)?$/', $target)) {
            return $target;
        }

        return null;
    }

    public function progress(string $stage, string $output): array
    {
        if ($stage !== 'Testes Laravel') {
            return [];
        }
        preg_match('/Test Suite Loaded \((\d+) tests?\)/', $output, $total);

        return [
            'total_tests' => isset($total[1]) ? (int) $total[1] : null,
            'completed_tests' => preg_match_all('/^Test Finished \(/m', $output),
            'failed_tests' => preg_match_all('/^Test Failed \(/m', $output),
            'errored_tests' => preg_match_all('/^Test Errored \(/m', $output),
            'current_test' => null,
            'live_failures' => $this->fromOutput($output),
        ];
    }
}
