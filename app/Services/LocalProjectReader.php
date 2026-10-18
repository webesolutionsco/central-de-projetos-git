<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use InvalidArgumentException;
use RuntimeException;

class LocalProjectReader
{
    private ?float $verificationDeadline = null;

    private ?\Closure $verificationProgress = null;

    private ?\Closure $verificationCancelled = null;

    private function verificationProcess(array $command, string $path, int $limit, string $stage, array $environment = [], ?int $idle = null): \Illuminate\Contracts\Process\ProcessResult
    {
        if ($this->verificationCancelled !== null && ($this->verificationCancelled)()) {
            throw new RuntimeException('Cancelamento solicitado. Nenhuma nova etapa foi iniciada.', 499);
        }
        $this->reportVerification($stage, '');
        $pending = Process::timeout($this->verificationTimeout($limit))->path($path)->env($environment);
        if ($idle !== null) {
            $pending->idleTimeout($idle);
        }
        $process = $pending->start($command);
        $lastReport = 0;
        try {
            while ($process->running()) {
                if ($this->verificationCancelled !== null && ($this->verificationCancelled)()) {
                    $process->stop(0);
                    throw new RuntimeException('Cancelado pelo usuário. Saída parcial: '.mb_substr($process->output()."\n".$process->errorOutput(), -10000), 499);
                }
                $process->ensureNotTimedOut();
                if (microtime(true) - $lastReport >= 1) {
                    $this->reportVerification($stage, $process->output()."\n".$process->errorOutput());
                    $lastReport = microtime(true);
                }
                usleep(100000);
            }
            $result = $process->wait();
            $this->reportVerification($stage, $result->output()."\n".$result->errorOutput());

            return $result;
        } finally {
            if ($process->running()) {
                $process->stop(0);
            }
        }
    }

    private function reportVerification(string $stage, string $output): void
    {
        if ($this->verificationProgress !== null) {
            ($this->verificationProgress)($stage, $this->testReports->progress($stage, $output));
        }
    }

    private function verificationTimeout(int $stageLimit): int
    {
        $remaining = $this->verificationDeadline === null ? $stageLimit : $this->verificationDeadline - hrtime(true) / 1e9;
        if ($remaining < 1) {
            throw new RuntimeException('O prazo global de execução terminou. Nenhuma etapa adicional foi iniciada.', 408);
        }

        return min($stageLimit, (int) floor($remaining));
    }

    public function __construct(
        private readonly GitHubCredentialStore $credentials,
        private readonly ProjectRegistry $projects,
        private readonly TestFailureReport $testReports = new TestFailureReport,
    )
    {
    }

    public function projects(): array
    {
        return collect($this->projects->all())
            ->map(fn (array $project, string $id) => $this->summary($id, $project))
            ->values()
            ->all();
    }

    public function details(string $id): array
    {
        return $this->inspect($id, $this->project($id));
    }

    public function initialize(string $id): array
    {
        $path = $this->authorizedPath($this->project($id)['directory']);
        if (! is_dir($path)) {
            throw new InvalidArgumentException('A pasta autorizada não foi encontrada.');
        }
        if (! $this->gitAvailable()) {
            throw new RuntimeException('Git não encontrado pelo servidor PHP. Verifique CENTRAL_GIT_BINARY.');
        }
        if ($this->isRepository($path)) {
            throw new InvalidArgumentException('Este projeto já é um repositório Git.');
        }

        $result = $this->git($path, ['init', '-b', 'main']);
        if (! $result->successful()) {
            throw new RuntimeException('O Git não conseguiu iniciar o repositório: '.trim($result->errorOutput()));
        }

        $details = $this->details($id);
        array_unshift($details['technical_commands'], [
            'command' => 'git init -b main',
            'purpose' => 'Inicia o histórico Git somente nesta pasta e define “main” como a linha principal.',
        ]);

        return $details;
    }

    public function configureIdentity(string $id, string $name, string $email): array
    {
        $path = $this->repositoryPath($id);
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || mb_strlen($name) > 120 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um nome de autor e um e-mail válido para os commits.');
        }

        foreach ([['user.name', $name], ['user.email', $email]] as [$key, $value]) {
            $result = $this->git($path, ['config', '--local', $key, $value]);
            if (! $result->successful()) {
                throw new RuntimeException('Não foi possível salvar a identidade local do Git.');
            }
        }

        return ['message' => 'Identidade configurada somente neste projeto.', 'technical_commands' => [
            ['command' => 'git config --local user.name "'.$name.'"', 'purpose' => 'Define quem assina os commits deste projeto, sem alterar outros projetos.'],
            ['command' => 'git config --local user.email "'.$email.'"', 'purpose' => 'Define o e-mail exibido nos commits deste projeto, sem alterar outros projetos.'],
        ]];
    }

    public function connectRemote(string $id, string $url): array
    {
        $path = $this->repositoryPath($id);
        $url = trim($url);
        if (! preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$#', $url)) {
            throw new InvalidArgumentException('Informe uma URL HTTPS do GitHub, como https://github.com/organizacao/repositorio.git. URLs com token ou senha não são aceitas.');
        }

        $existing = $this->output($path, ['remote', 'get-url', 'origin']);
        if ($existing !== null && $this->normalizeGitHubUrl($existing) !== $this->normalizeGitHubUrl($url)) {
            throw new InvalidArgumentException('Este projeto já está ligado a outro “origin”. A Central não substitui remotos existentes.');
        }
        if ($existing === null) {
            $result = $this->git($path, ['remote', 'add', 'origin', $url]);
            if (! $result->successful()) {
                throw new RuntimeException('Não foi possível conectar o repositório ao GitHub: '.trim($result->errorOutput()));
            }
        }

        return ['message' => 'Repositório GitHub conectado. Nenhum arquivo foi enviado.', 'technical_commands' => [[
            'command' => 'git remote add origin '.$url,
            'purpose' => 'Salva o endereço do repositório remoto. Ainda não envia arquivos nem commits.',
        ]]];
    }

    public function createGitHubRepository(string $id, string $owner, string $name, string $description, bool $private): array
    {
        $this->repositoryPath($id);
        $owner = trim($owner);
        $name = trim($name);
        $description = trim($description);
        if ($owner !== '' && ! preg_match('/^[A-Za-z0-9-]{1,39}$/', $owner)) {
            throw new InvalidArgumentException('O proprietário do GitHub deve conter apenas letras, números e hífen.');
        }
        if (! preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $name) || $description === '' || mb_strlen($description) > 350) {
            throw new InvalidArgumentException('Informe um nome de repositório válido e uma descrição de até 350 caracteres.');
        }
        $path = $this->repositoryPath($id);
        if ($this->output($path, ['remote', 'get-url', 'origin']) !== null) {
            throw new InvalidArgumentException('Este projeto já possui um repositório remoto conectado.');
        }
        $token = $this->credentials->token();
        if ($token === null) {
            throw new InvalidArgumentException('Salve um token do GitHub na aba “Publicações” antes de criar um repositório pela Central.');
        }

        $endpoint = $owner === '' ? 'https://api.github.com/user/repos' : 'https://api.github.com/orgs/'.$owner.'/repos';
        try {
            $response = Http::accept('application/vnd.github+json')
                ->withToken($token)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(20)
                ->post($endpoint, [
                    'name' => $name,
                    'description' => $description,
                    'private' => $private,
                    'auto_init' => false,
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('Não foi possível alcançar a API do GitHub para criar o repositório.');
        }

        if (! $response->successful() || ! is_string($response->json('clone_url'))) {
            if ($response->status() === 422) {
                throw new InvalidArgumentException('O GitHub não criou o repositório. Verifique se esse nome já existe e se sua conta tem permissão para criá-lo.');
            }
            if (in_array($response->status(), [401, 403], true)) {
                throw new InvalidArgumentException('O GitHub recusou o token. Para criar repositórios, use um token com “Administration: write” e acesso ao proprietário escolhido.');
            }
            throw new RuntimeException('O GitHub não confirmou a criação do repositório.');
        }

        $remote = $this->connectRemote($id, (string) $response->json('clone_url'));
        return [
            'message' => 'Repositório criado no GitHub e conectado ao projeto local. Nenhum arquivo foi enviado.',
            'url' => (string) $response->json('html_url'),
            'technical_commands' => $remote['technical_commands'],
        ];
    }

    /** Prepara somente arquivos que ainda aparecem no estado Git atual. */
    public function stage(string $id, array $files): array
    {
        $path = $this->authorizedPath($this->project($id)['directory']);
        $available = $this->stageableFiles($path);
        $requested = array_values(array_unique(array_filter($files, 'is_string')));
        if ($requested === []) {
            throw new InvalidArgumentException('Selecione pelo menos um arquivo para preparar.');
        }
        foreach ($requested as $file) {
            if (! isset($available[$file])) {
                throw new InvalidArgumentException('Um arquivo selecionado não pertence às alterações atuais. Atualize a leitura e tente novamente.');
            }
            if ($this->isSensitive($file)) {
                throw new InvalidArgumentException('Arquivo sensível bloqueado: '.$file.'.');
            }
        }
        $result = $this->git($path, array_merge(['add', '--'], $requested));
        if (! $result->successful()) {
            throw new RuntimeException('Não foi possível preparar os arquivos: '.trim($result->errorOutput()));
        }
        return ['staged' => $requested, 'technical_commands' => [[
            'command' => 'git add -- '.implode(' ', array_map(fn ($file) => '"'.$file.'"', $requested)),
            'purpose' => 'Prepara somente os arquivos escolhidos para o próximo commit.',
        ]]];
    }

    public function commit(string $id, string $message): array
    {
        $path = $this->authorizedPath($this->project($id)['directory']);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 500) {
            throw new InvalidArgumentException('Informe uma descrição entre 1 e 500 caracteres para o commit.');
        }
        $staged = $this->output($path, ['diff', '--cached', '--name-only']);
        if ($staged === null) {
            throw new InvalidArgumentException('Não há arquivos preparados para salvar.');
        }
        foreach (preg_split('/\R/', $staged) as $file) {
            if ($this->isSensitive($file)) {
                throw new InvalidArgumentException('O commit foi bloqueado pois contém um arquivo sensível: '.$file.'.');
            }
        }
        $result = $this->git($path, ['commit', '-m', $message]);
        if (! $result->successful()) {
            throw new RuntimeException('O Git não confirmou o commit: '.trim($result->errorOutput()));
        }
        return ['sha' => $this->output($path, ['rev-parse', '--short', 'HEAD']), 'message' => $message, 'technical_commands' => [[
            'command' => 'git commit -m "'.$message.'"',
            'purpose' => 'Cria um registro local no histórico. Não envia nada à internet.',
        ]]];
    }

    /** @return array{branches: list<array{name: string, sha: string, subject: string, date: string}>, technical_commands: list<array{command: string, purpose: string}>} */
    public function remoteBranches(string $id): array
    {
        $path = $this->repositoryPath($id);
        $this->requireOrigin($path);
        $result = $this->git($path, ['for-each-ref', "--format=%(refname:short)\t%(objectname:short)\t%(subject)\t%(committerdate:iso-strict)", 'refs/remotes/origin']);
        if (! $result->successful()) {
            throw new RuntimeException('Não foi possível ler as versões já consultadas do GitHub.');
        }

        $branches = collect(preg_split('/\R/', trim($result->output())) ?: [])
            ->map(function (string $line): ?array {
                [$name, $sha, $subject, $date] = array_pad(explode("\t", $line, 4), 4, '');
                if ($name === '' || $name === 'origin/HEAD' || ! str_starts_with($name, 'origin/')) {
                    return null;
                }

                return ['name' => substr($name, 7), 'sha' => $sha, 'subject' => $subject, 'date' => $date];
            })
            ->filter()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'branches' => $branches,
            'technical_commands' => [[
                'command' => 'git for-each-ref refs/remotes/origin',
                'purpose' => 'Lista as versões do GitHub que já foram consultadas, sem alterar arquivos.',
            ]],
        ];
    }

    /** @return array{base: array{name: string, sha: string}, source: array{name: string, sha: string}, base_only: int, source_only: int, equivalent_count: int, commits: list<array{sha: string, short_sha: string, author: string, date: string, subject: string, patch_equivalent: bool, equivalent_commit: ?array{sha: string, short_sha: string}}>, files: list<array{status: string, path: string}>, stat: string, technical_commands: list<array{command: string, purpose: string}>} */
    public function compareRemoteBranches(string $id, string $baseBranch, string $sourceBranch): array
    {
        $path = $this->repositoryPath($id);
        $base = $this->remoteBranch($path, $baseBranch);
        $source = $this->remoteBranch($path, $sourceBranch);
        if ($base['reference'] === $source['reference']) {
            throw new InvalidArgumentException('Escolha duas versões diferentes para comparar.');
        }

        $counts = preg_split('/\s+/', trim((string) $this->output($path, ['rev-list', '--left-right', '--count', $base['reference'].'...'.$source['reference']])));
        if (count($counts) !== 2) {
            throw new RuntimeException('Não foi possível comparar o histórico das versões selecionadas.');
        }

        $commitResult = $this->git($path, ['log', '-n', '50', '--format=%H%x1f%h%x1f%an%x1f%aI%x1f%s', $base['reference'].'..'.$source['reference']]);
        if (! $commitResult->successful()) {
            throw new RuntimeException('Não foi possível listar os commits exclusivos da versão de origem.');
        }
        $commits = collect(preg_split('/\R/', trim($commitResult->output())) ?: [])
            ->filter()
            ->map(function (string $line): array {
                [$sha, $shortSha, $author, $date, $subject] = array_pad(explode("\x1f", $line, 5), 5, '');
                return [...compact('sha', 'shortSha', 'author', 'date', 'subject'), 'patch_equivalent' => false, 'equivalent_commit' => null];
            })
            ->values()
            ->all();
        $commits = $this->markPatchEquivalentCommits($path, $base['reference'], $source['reference'], $commits);

        $fileOutput = $this->output($path, ['diff', '--name-status', $base['reference'].'...'.$source['reference']]);
        $files = collect(preg_split('/\R/', trim((string) $fileOutput)) ?: [])
            ->filter()
            ->map(function (string $line): array {
                [$status, $path] = array_pad(explode("\t", $line, 2), 2, '');
                return compact('status', 'path');
            })
            ->values()
            ->all();

        return [
            'base' => ['name' => $baseBranch, 'sha' => $base['sha']],
            'source' => ['name' => $sourceBranch, 'sha' => $source['sha']],
            'base_only' => (int) $counts[0],
            'source_only' => (int) $counts[1],
            'equivalent_count' => count(array_filter($commits, fn (array $commit): bool => $commit['patch_equivalent'])),
            'commits' => $commits,
            'files' => $files,
            'stat' => $this->output($path, ['diff', '--stat', $base['reference'].'...'.$source['reference']]) ?? 'Nenhum arquivo diferente.',
            'technical_commands' => [
                ['command' => 'git rev-list --left-right --count '.$base['reference'].'...'.$source['reference'], 'purpose' => 'Conta o que existe apenas em cada versão.'],
                ['command' => 'git log '.$base['reference'].'..'.$source['reference'], 'purpose' => 'Lista os commits que a versão de origem possui e a base ainda não possui.'],
                ['command' => 'git cherry -v '.$base['reference'].' '.$source['reference'], 'purpose' => 'Confere se algum commit tem a mesma alteração já aplicada por cherry-pick, mesmo com outro SHA.'],
                ['command' => 'git diff --stat '.$base['reference'].'...'.$source['reference'], 'purpose' => 'Resume os arquivos diferentes, sem modificar qualquer versão.'],
            ],
        ];
    }

    /** @return array{commit: array{sha: string, short_sha: string, subject: string}, diff: string, technical_commands: list<array{command: string, purpose: string}>} */
    public function commitDiff(string $id, string $sha): array
    {
        $path = $this->repositoryPath($id);
        $sha = trim($sha);
        if (! preg_match('/^[a-f0-9]{7,64}$/i', $sha)) {
            throw new InvalidArgumentException('O commit selecionado é inválido. Atualize a comparação e tente novamente.');
        }
        if (! $this->git($path, ['cat-file', '-e', $sha.'^{commit}'])->successful()) {
            throw new InvalidArgumentException('O commit selecionado não foi encontrado neste projeto.');
        }

        $header = $this->output($path, ['show', '-s', '--format=%H%x1f%h%x1f%s', $sha]);
        [$fullSha, $shortSha, $subject] = array_pad(explode("\x1f", (string) $header, 3), 3, '');
        $result = $this->git($path, ['show', '--format=', '--no-ext-diff', '--unified=3', $fullSha]);
        if (! $result->successful()) {
            throw new RuntimeException('Não foi possível abrir a diferença deste commit.');
        }
        $diff = $result->output();
        if (mb_strlen($diff) > 120000) {
            $diff = mb_substr($diff, 0, 120000)."\n\n… Diferença interrompida após 120 mil caracteres para manter a Central responsiva.";
        }

        return [
            'commit' => ['sha' => $fullSha, 'short_sha' => $shortSha, 'subject' => $subject],
            'diff' => $diff ?: 'Este commit não alterou arquivos.',
            'technical_commands' => [[
                'command' => 'git show --no-ext-diff '.$shortSha,
                'purpose' => 'Mostra as linhas alteradas somente para leitura; não modifica arquivos nem versões.',
            ]],
        ];
    }

    public function normalizeTestTarget(?string $testTarget): ?string
    {
        $testTarget = trim((string) $testTarget);
        if ($testTarget === '') {
            return null;
        }
        $testTarget = str_replace('\\', '/', $testTarget);
        if (preg_match('~^tests/(?:Feature|Unit)/[A-Za-z][A-Za-z0-9_/-]*Test\.php$~', $testTarget)
            && ! str_contains($testTarget, '//') && ! str_contains($testTarget, '..')) {
            return $testTarget;
        }

        $class = str_replace('/', '\\', $testTarget);
        if (preg_match('/^(?:Tests\\\\)?(?:Feature|Unit)\\\\[A-Za-z][A-Za-z0-9_]*Test(?:::[A-Za-z][A-Za-z0-9_]*)?$/', $class)) {
            return str_starts_with($class, 'Tests\\') ? $class : 'Tests\\Feature\\'.$class;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*Test(?:::[A-Za-z][A-Za-z0-9_]*)?$/', $class)) {
            return 'Tests\\Feature\\'.$class;
        }

        throw new InvalidArgumentException('Selecione um arquivo em tests/Feature ou tests/Unit, ou informe uma classe de teste válida, como WebeIgrejaMemberTest.');
    }

    /** @return array{commit: string, checks: list<array{name: string, status: 'passed'|'failed'|'infrastructure_unavailable', passed: bool, output: string, explanation: string}>, technical_commands: list<array{command: string, purpose: string}>} */
    public function runCommitChecks(string $id, string $sha, int $budget = 100, ?\Closure $progress = null, ?\Closure $cancelled = null, ?string $testTarget = null): array
    {
        $started = hrtime(true) / 1e9;
        $budget = max(10, min(1800, $budget));
        if (! set_time_limit($budget + 90)) {
            throw new RuntimeException('O PHP não permitiu reservar tempo para preparar, testar e limpar a cópia. A verificação não foi iniciada.');
        }
        $path = $this->repositoryPath($id);
        $sha = trim($sha);
        if (! preg_match('/^[a-f0-9]{7,64}$/i', $sha) || ! $this->git($path, ['cat-file', '-e', $sha.'^{commit}'])->successful()) {
            throw new InvalidArgumentException('O commit selecionado não foi encontrado neste projeto.');
        }
        $testTarget = $this->normalizeTestTarget($testTarget);

        $root = storage_path('app/private/commit-checkouts');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Não foi possível preparar a área isolada de verificações.');
        }
        $checkout = $root.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12));
        $this->verificationDeadline = $started + $budget;
        $this->verificationProgress = $progress;
        $this->verificationCancelled = $cancelled;
        $checks = [];
        $cleanup = ['successful' => false, 'message' => 'Limpeza não confirmada.'];
        try {
            $worktree = $this->verificationProcess($this->gitCommand($path, ['worktree', 'add', '--detach', $checkout, $sha]), $path, 30, 'Criando cópia isolada');
            if (! $worktree->successful()) {
                throw new RuntimeException('Não foi possível abrir uma cópia isolada deste commit para os testes. '.$worktree->errorOutput());
            }
            $this->prepareInstalledDependencies($path, $checkout);
            $this->prepareFrontendAssets($path, $checkout);
            $checks = [];
            $phpBinary = $this->phpCliBinary();
            if (is_file($checkout.DIRECTORY_SEPARATOR.'artisan')) {
                if (! is_file($checkout.'/.env.testing') && file_put_contents($checkout.'/.env.testing', "APP_ENV=testing\n") === false) {
                    throw new RuntimeException('Não foi possível preparar o ambiente descartável de testes.');
                }
                if (! is_file($checkout.DIRECTORY_SEPARATOR.'vendor/phpunit/phpunit/phpunit')) {
                    throw new RuntimeException('PHPUnit não está instalado na cópia isolada; os testes Laravel não iniciaram.');
                }
                $testCommand = [$phpBinary, '-d', 'opcache.enable_cli=0', 'vendor/phpunit/phpunit/phpunit', '--debug', '--log-junit', '.central-test-results.xml'];
                if ($testTarget !== null) {
                    if (str_starts_with($testTarget, 'tests/')) {
                        if (! is_file($checkout.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $testTarget))) {
                            throw new InvalidArgumentException('O arquivo de teste selecionado não existe no commit escolhido.');
                        }
                        $testCommand[] = $testTarget;
                    } else {
                        $testCommand[] = '--filter';
                        $testCommand[] = '^'.preg_quote($testTarget, '/').'(?:$|::)';
                    }
                }
                $checks[] = $this->runCheck($checkout, 'Testes Laravel', $testCommand, $budget, null, $this->isolatedTestEnvironment($checkout));
                $reportPath = $checkout.'/.central-test-results.xml';
                if (is_file($reportPath)) {
                    $report = $this->testReports->junit($reportPath);
                    if ($report !== null) {
                        $checks[0]['test_summary'] = $report['summary'];
                        $checks[0]['failures'] = $report['failures'];
                    }
                }
            }
            if (($checks === [] || $checks[0]['passed']) && is_file($checkout.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pint')) {
                $checks[] = $this->runCheck($checkout, 'Pint', [$phpBinary, '-d', 'opcache.enable_cli=0', 'vendor/bin/pint', '--test'], 120, null);
            }
            if ($checks === []) {
                throw new RuntimeException('Este commit não possui receitas de teste reconhecidas pela Central.');
            }

        } catch (ProcessTimedOutException $exception) {
            $checks[] = [
                'name' => 'Preparação isolada', 'status' => 'infrastructure_unavailable', 'passed' => false, 'cause' => 'preparation_timeout',
                'output' => mb_substr(trim($exception->result->output()."\n".$exception->result->errorOutput()), -10000),
                'explanation' => 'A preparação atingiu seu prazo. O processo foi interrompido; testes não aprovados.',
            ];
        } catch (RuntimeException $exception) {
            $checks[] = [
                'name' => 'Preparação isolada', 'status' => 'infrastructure_unavailable', 'passed' => false,
                'cause' => match ($exception->getCode()) { 408 => 'preparation_timeout', 499 => 'cancelled', default => 'preparation_incomplete' }, 'output' => $exception->getMessage(),
                'explanation' => 'A verificação não concluiu. Consulte a saída parcial; nenhuma promoção foi liberada.',
            ];
        } finally {
            $this->verificationDeadline = null;
            $this->verificationCancelled = null;
            $this->reportVerification('Removendo cópia temporária', '');
            try {
                $removed = Process::timeout(20)->path($path)->run($this->gitCommand($path, ['worktree', 'remove', '--force', $checkout]));
                $clean = $removed->successful() && ! is_dir($checkout);
                $cleanup = ['successful' => $clean, 'message' => $clean ? 'Cópia temporária removida.' : 'Não foi possível confirmar a remoção da cópia temporária: '.$checkout, 'output' => mb_substr($removed->errorOutput(), -2000)];
            } catch (\Throwable $exception) {
                $cleanup = ['successful' => false, 'message' => 'Limpeza interrompida; cópia temporária pendente: '.$checkout, 'output' => mb_substr($exception->getMessage(), -2000)];
            }
            $this->verificationProgress = null;
        }

        if (! $cleanup['successful']) {
            $checks[] = ['name' => 'Limpeza', 'status' => 'infrastructure_unavailable', 'passed' => false, 'output' => $cleanup['output'] ?? '', 'explanation' => $cleanup['message']];
        }

        return $this->testReports->result([
                'commit' => substr($sha, 0, 7),
                'cleanup' => $cleanup,
                'elapsed_seconds' => round(hrtime(true) / 1e9 - $started, 2),
                'checks' => $checks,
                'technical_commands' => [[
                    'command' => 'git worktree add --detach <área-isolada> '.$sha,
                    'purpose' => 'Abre uma cópia temporária do commit escolhido, sem tocar nas alterações locais do projeto.',
                ], [
                    'command' => 'PHP CLI -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --debug --log-junit .central-test-results.xml'.($testTarget === null ? '' : ' <teste-selecionado>'),
                    'purpose' => 'Executa a suíte de testes Laravel diretamente pelo PHPUnit, sem iniciar Apache nem herdar o cache CLI.',
                ], [
                    'command' => 'composer dump-autoload --no-scripts --no-plugins',
                    'purpose' => 'Prepara uma cópia independente das dependências e aponta o carregamento de classes para o commit selecionado.',
                ], [
                    'command' => 'node node_modules/vite/bin/vite.js build (quando aplicável)',
                    'purpose' => 'Compila a interface do commit na cópia isolada. Não reutiliza o build nem o .env do projeto de trabalho.',
                ]],
            ]);
    }

    public function fetch(string $id): array
    {
        $path = $this->authorizedPath($this->project($id)['directory']);
        $origin = $this->requireOrigin($path);
        try {
            $result = Process::timeout(20)
                ->env(array_merge(['GIT_TERMINAL_PROMPT' => '0'], $this->credentials->environmentForOrigin($origin)))
                ->path($path)
                ->run($this->gitCommand($path, ['fetch', 'origin']));
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('A consulta ao GitHub demorou demais. Verifique sua conexão e a autenticação do Git; nenhum arquivo local foi alterado.');
        }
        if (! $result->successful()) {
            $error = trim($result->errorOutput());
            if (str_contains(strtolower($error), 'terminal prompts disabled') || str_contains(strtolower($error), 'could not read username')) {
                throw new RuntimeException('O GitHub pediu autenticação. Em “Publicações”, salve um token do GitHub para este servidor ou configure uma credencial disponível para a conta que executa o Apache.');
            }
            throw new RuntimeException('Não foi possível consultar o GitHub. Verifique a conexão e o acesso ao repositório. Detalhe técnico: '.$error);
        }
        $details = $this->details($id);
        $commands = $details['technical_commands'];
        array_unshift($commands, [
            'command' => 'git fetch origin',
            'purpose' => 'Atualiza as referências do GitHub sem modificar arquivos locais.',
        ]);
        $details['technical_commands'] = $commands;
        return $details;
    }

    public function integrateRemoteHistory(string $id): array
    {
        $path = $this->repositoryPath($id);
        $origin = $this->requireOrigin($path);
        $details = $this->details($id);
        if ($details['has_changes']) {
            throw new InvalidArgumentException('Salve ou descarte as alterações locais antes de integrar o histórico já existente no GitHub.');
        }
        $branch = $details['branch'];
        if ($branch === null || $branch === 'HEAD destacado') {
            throw new InvalidArgumentException('Não foi possível identificar a branch local para integrar o histórico.');
        }

        $fetch = $this->remoteProcess($path, $origin, ['fetch', 'origin'], 30);
        if (! $fetch->successful()) {
            throw new RuntimeException('Não foi possível consultar o GitHub antes da integração: '.trim($fetch->errorOutput()));
        }
        $remoteReference = 'origin/'.$branch;
        if (! $this->git($path, ['rev-parse', '--verify', $remoteReference])->successful()) {
            throw new InvalidArgumentException('O GitHub ainda não possui histórico nesta branch. Use “Enviar ao GitHub” para o primeiro envio.');
        }
        if ($this->git($path, ['merge-base', '--is-ancestor', $remoteReference, 'HEAD'])->successful()) {
            return ['message' => 'O histórico do GitHub já está integrado.', 'technical_commands' => [[
                'command' => 'git merge-base --is-ancestor '.$remoteReference.' HEAD',
                'purpose' => 'Confere que o histórico remoto já faz parte do histórico local.',
            ]]];
        }

        $merge = $this->git($path, ['merge', '--no-edit', '--allow-unrelated-histories', $remoteReference]);
        if (! $merge->successful()) {
            throw new RuntimeException('A integração encontrou conflito. A Central não resolve conflitos automaticamente; revise os arquivos indicados pelo Git antes de continuar.');
        }

        return ['message' => 'Histórico inicial do GitHub integrado com segurança.', 'technical_commands' => [
            ['command' => 'git fetch origin', 'purpose' => 'Obtém o histórico remoto sem substituir arquivos locais.'],
            ['command' => 'git merge --no-edit --allow-unrelated-histories '.$remoteReference, 'purpose' => 'Une o histórico inicial do GitHub ao histórico local após sua confirmação.'],
        ]];
    }

    public function push(string $id): array
    {
        $path = $this->repositoryPath($id);
        $origin = $this->requireOrigin($path);
        $tracking = $this->git($path, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}']);
        $branch = $this->output($path, ['branch', '--show-current']);
        if (! $tracking->successful()) {
            if ($branch === null) {
                throw new InvalidArgumentException('Não foi possível identificar a branch local para o envio.');
            }
            $fetch = $this->remoteProcess($path, $origin, ['fetch', 'origin'], 30);
            if (! $fetch->successful()) {
                throw new RuntimeException('Não foi possível consultar o GitHub antes do primeiro envio: '.trim($fetch->errorOutput()));
            }
            $remoteReference = 'origin/'.$branch;
            $remoteExists = $this->git($path, ['rev-parse', '--verify', $remoteReference])->successful();
            if ($remoteExists && ! $this->git($path, ['merge-base', '--is-ancestor', $remoteReference, 'HEAD'])->successful()) {
                throw new InvalidArgumentException('O GitHub já possui um histórico diferente. Use “Integrar histórico do GitHub” antes de enviar para evitar sobrescrita.');
            }
            $result = $this->remoteProcess($path, $origin, ['push', '-u', 'origin', $branch], 45);
            if (! $result->successful()) {
                throw new RuntimeException($this->describePushFailure($result->errorOutput(), true));
            }
            return ['message' => 'Primeiro envio ao GitHub confirmado.', 'head' => $this->output($path, ['rev-parse', '--short', 'HEAD']), 'technical_commands' => [[
                'command' => 'git push -u origin '.$branch,
                'purpose' => 'Envia o primeiro histórico e define o destino remoto desta branch.',
            ]]];
        }
        $counts = preg_split('/\s+/', trim((string) $this->output($path, ['rev-list', '--left-right', '--count', '@{upstream}...HEAD'])));
        if (count($counts) !== 2 || (int) $counts[0] > 0) {
            throw new InvalidArgumentException('O GitHub possui alterações mais recentes. Atualize e resolva a divergência antes de enviar.');
        }
        if ((int) $counts[1] === 0) {
            throw new InvalidArgumentException('Não há commits locais para enviar.');
        }
        $result = $this->remoteProcess($path, $origin, ['push'], 45);
        if (! $result->successful()) {
            throw new RuntimeException($this->describePushFailure($result->errorOutput()));
        }
        return ['message' => 'Envio ao GitHub confirmado.', 'head' => $this->output($path, ['rev-parse', '--short', 'HEAD']), 'technical_commands' => [[
            'command' => 'git push',
            'purpose' => 'Envia os commits locais para a branch remota configurada.',
        ]]];
    }

    private function summary(string $id, array $project): array
    {
        $data = $this->inspect($id, $project);

        return array_intersect_key($data, array_flip([
            'id', 'label', 'source', 'path', 'exists', 'is_git', 'is_laravel', 'branch', 'head',
            'origin', 'has_changes', 'changes_count', 'ahead', 'behind', 'remote_checked_at',
            'last_commit', 'error',
            'migrations',
        ]));
    }

    private function inspect(string $id, array $project): array
    {
        $path = $this->authorizedPath($project['directory'] ?? '');
        $base = [
            'id' => $id,
            'label' => $project['label'] ?? $id,
            'source' => $project['source'] ?? 'config',
            'path' => $path,
            'exists' => is_dir($path),
            'is_git' => false,
            'can_initialize' => false,
            'is_laravel' => false,
            'branch' => null,
            'head' => null,
            'origin' => null,
            'identity_configured' => false,
            'identity' => null,
            'has_changes' => false,
            'changes_count' => 0,
            'ahead' => null,
            'behind' => null,
            'remote_checked_at' => null,
            'last_commit' => null,
            'changes' => $this->emptyChanges(),
            'migrations' => [],
            'technical_commands' => [[
                'command' => 'git rev-parse --show-toplevel',
                'purpose' => 'Confere se esta pasta é a raiz de um repositório Git.',
            ]],
            'error' => null,
        ];

        if (! $base['exists']) {
            $base['error'] = 'A pasta autorizada não foi encontrada.';
            return $base;
        }

        if (! $this->gitAvailable()) {
            $base['error'] = 'Git não encontrado pelo servidor PHP. Verifique CENTRAL_GIT_BINARY.';
            return $base;
        }

        $base['is_laravel'] = is_file($path.DIRECTORY_SEPARATOR.'artisan')
            && is_file($path.DIRECTORY_SEPARATOR.'composer.json');

        if (! $this->isRepository($path)) {
            $base['can_initialize'] = true;
            $base['error'] = 'Esta pasta ainda não é a raiz de um repositório Git.';
            return $base;
        }

        $base['is_git'] = true;
        $identityName = $this->output($path, ['config', '--get', 'user.name']);
        $identityEmail = $this->output($path, ['config', '--get', 'user.email']);
        $localIdentityName = $this->output($path, ['config', '--local', '--get', 'user.name']);
        $localIdentityEmail = $this->output($path, ['config', '--local', '--get', 'user.email']);
        $base['identity_configured'] = $identityName !== null && $identityEmail !== null;
        if ($base['identity_configured']) {
            $base['identity'] = [
                'name' => $identityName,
                'email' => $identityEmail,
                'scope' => $localIdentityName !== null && $localIdentityEmail !== null ? 'project' : 'computer',
            ];
        }
        $statusOutput = $this->output($path, ['status']) ?? '';
        $base['technical_commands'] = $this->inspectionCommands($statusOutput);
        $base['branch'] = $this->output($path, ['branch', '--show-current']) ?: 'HEAD destacado';
        $base['head'] = $this->output($path, ['rev-parse', '--short', 'HEAD']);
        $base['origin'] = $this->output($path, ['remote', 'get-url', 'origin']);
        $base['last_commit'] = $this->lastCommit($path);
        $base['changes'] = $this->changes($path);
        $base['changes_count'] = $this->uniqueChangeCount($base['changes']);
        $base['migrations'] = $this->migrationChanges($base['changes']);
        $base['has_changes'] = $base['changes_count'] > 0;

        $tracking = $this->git($path, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}']);
        if ($tracking->successful()) {
            $counts = preg_split('/\s+/', trim($this->output($path, ['rev-list', '--left-right', '--count', '@{upstream}...HEAD'])));
            if (count($counts) === 2) {
                $base['behind'] = (int) $counts[0];
                $base['ahead'] = (int) $counts[1];
            }
        }

        return $base;
    }

    private function changes(string $path): array
    {
        $changes = $this->emptyChanges();
        $result = $this->git($path, ['status', '--porcelain=v1', '-z', '--untracked-files=all']);
        if (! $result->successful()) {
            return $changes;
        }

        $records = explode("\0", $result->output());
        for ($i = 0; $i < count($records); $i++) {
            $record = $records[$i];
            if ($record === '') {
                continue;
            }
            $xy = substr($record, 0, 2);
            $file = substr($record, 3);
            if (in_array($xy[0] ?? '', ['R', 'C'], true) && isset($records[$i + 1])) {
                $file .= ' ← '. $records[++$i];
            }
            $gitPath = substr($record, 3);
            $item = [
                'path' => $file,
                'git_path' => $gitPath,
                'code' => $xy,
                'sensitive' => $this->isSensitive($gitPath),
            ];
            if ($xy === '??') {
                $changes['untracked'][] = $item;
                continue;
            }
            if (($xy[0] ?? ' ') !== ' ') {
                $changes['staged'][] = $item;
            }
            if (($xy[1] ?? ' ') !== ' ') {
                $changes['unstaged'][] = $item;
            }
            foreach (['A' => 'new', 'M' => 'modified', 'D' => 'deleted', 'R' => 'renamed'] as $code => $type) {
                if (str_contains($xy, $code)) {
                    $changes[$type][] = $item;
                    break;
                }
            }
        }

        return $changes;
    }

    private function emptyChanges(): array
    {
        return ['new' => [], 'modified' => [], 'deleted' => [], 'renamed' => [], 'staged' => [], 'unstaged' => [], 'untracked' => []];
    }

    private function uniqueChangeCount(array $changes): int
    {
        $items = [];
        foreach ($changes as $group) {
            foreach ($group as $change) {
                $items[$change['code']."\0".$change['path']] = true;
            }
        }
        return count($items);
    }

    private function project(string $id): array
    {
        return $this->projects->find($id);
    }

    private function repositoryPath(string $id): string
    {
        $path = $this->authorizedPath($this->project($id)['directory']);
        if (! $this->isRepository($path)) {
            throw new InvalidArgumentException('Inicie o Git neste projeto antes de usar esta ação.');
        }

        return $path;
    }

    private function isRepository(string $path): bool
    {
        $result = $this->git($path, ['rev-parse', '--show-toplevel']);
        if (! $result->successful()) {
            return false;
        }

        $root = realpath(trim($result->output()));
        $project = realpath($path);

        return $root !== false && $project !== false
            && strcasecmp($root, $project) === 0;
    }

    private function stageableFiles(string $path): array
    {
        $files = [];
        foreach ($this->changes($path) as $group) {
            foreach ($group as $change) {
                $files[$change['git_path']] = true;
            }
        }
        return $files;
    }

    private function migrationChanges(array $changes): array
    {
        $migrations = [];
        foreach ($changes as $group) {
            foreach ($group as $change) {
                $file = str_replace('\\', '/', $change['git_path']);
                if (str_starts_with($file, 'database/migrations/') && str_ends_with($file, '.php')) {
                    $migrations[$file] = $change;
                }
            }
        }
        return array_values($migrations);
    }

    private function isSensitive(string $file): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $file)));
        return $name === '.env' || (str_starts_with($name, '.env.') && $name !== '.env.example')
            || str_ends_with($name, '.pem') || str_ends_with($name, '.key')
            || in_array($name, ['id_rsa', 'id_ed25519', 'credentials.json'], true);
    }

    private function requireOrigin(string $path): string
    {
        $origin = $this->output($path, ['remote', 'get-url', 'origin']);
        if ($origin === null) {
            throw new InvalidArgumentException('Este projeto não possui um remoto “origin” configurado.');
        }
        return $origin;
    }

    /** @return array{reference: string, sha: string} */
    private function remoteBranch(string $path, string $branch): array
    {
        $branch = trim($branch);
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/\-]{0,199}$/', $branch)
            || str_contains($branch, '..')
            || str_ends_with($branch, '/')
            || str_starts_with($branch, '/')) {
            throw new InvalidArgumentException('O nome da versão selecionada é inválido.');
        }

        $reference = 'origin/'.$branch;
        $sha = $this->output($path, ['rev-parse', '--verify', $reference.'^{commit}']);
        if ($sha === null) {
            throw new InvalidArgumentException('A versão “'.$branch.'” ainda não foi encontrada. Use “Consultar GitHub” e tente novamente.');
        }

        return ['reference' => $reference, 'sha' => $sha];
    }

    /** @param list<array{sha: string, shortSha: string, author: string, date: string, subject: string, patch_equivalent: bool, equivalent_commit: ?array{sha: string, short_sha: string}}> $commits
     *  @return list<array{sha: string, shortSha: string, author: string, date: string, subject: string, patch_equivalent: bool, equivalent_commit: ?array{sha: string, short_sha: string}}>
     */
    private function markPatchEquivalentCommits(string $path, string $baseReference, string $sourceReference, array $commits): array
    {
        if ($commits === []) {
            return $commits;
        }

        $result = $this->git($path, ['cherry', '-v', $baseReference, $sourceReference]);
        if (! $result->successful()) {
            return $commits;
        }

        $equivalentSourceShas = [];
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^-\s+([a-f0-9]{7,64})\b/i', $line, $matches)) {
                $equivalentSourceShas[] = strtolower($matches[1]);
            }
        }
        if ($equivalentSourceShas === []) {
            return $commits;
        }

        foreach ($commits as $index => $commit) {
            if (! collect($equivalentSourceShas)->contains(fn (string $sha): bool => str_starts_with(strtolower($commit['sha']), $sha) || str_starts_with($sha, strtolower($commit['shortSha'])))) {
                continue;
            }
            $commits[$index]['patch_equivalent'] = true;
            $equivalent = $this->equivalentCommitInDestination($path, $baseReference, $commit['sha'], $commit['subject']);
            if ($equivalent !== null) {
                $commits[$index]['equivalent_commit'] = $equivalent;
            }
        }

        return $commits;
    }

    /** @return array{sha: string, short_sha: string}|null */
    private function equivalentCommitInDestination(string $path, string $baseReference, string $sourceSha, string $sourceSubject): ?array
    {
        $sourcePatchId = $this->patchId($path, $sourceSha);
        $candidates = collect(preg_split('/\R/', trim((string) $this->output($path, ['log', '-n', '300', '--format=%H%x1f%s', $baseReference])) ?: []))
            ->filter()
            ->map(function (string $line): array {
                [$sha, $subject] = array_pad(explode("\x1f", $line, 2), 2, '');

                return compact('sha', 'subject');
            });

        if ($sourcePatchId !== null) {
            foreach ($candidates as $candidate) {
                if ($this->patchId($path, $candidate['sha']) === $sourcePatchId) {
                    return ['sha' => $candidate['sha'], 'short_sha' => substr($candidate['sha'], 0, 7)];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['subject'] === $sourceSubject) {
                return ['sha' => $candidate['sha'], 'short_sha' => substr($candidate['sha'], 0, 7)];
            }
        }

        return null;
    }

    private function patchId(string $path, string $sha): ?string
    {
        $patch = $this->git($path, ['show', '--format=', '--no-ext-diff', $sha]);
        if (! $patch->successful() || trim($patch->output()) === '') {
            return null;
        }
        $result = Process::timeout(8)
            ->input($patch->output())
            ->path($path)
            ->run($this->gitCommand($path, ['patch-id', '--stable']));
        if (! $result->successful() || ! preg_match('/^([a-f0-9]{40,64})\s+/i', trim($result->output()), $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function phpCliBinary(): string
    {
        $phpBinary = trim((string) config('central.php_binary', PHP_BINARY));
        if ($phpBinary === '') {
            throw new RuntimeException('O caminho do PHP CLI não foi configurado. Informe CENTRAL_PHP_BINARY na configuração da Central.');
        }
        if ((str_contains($phpBinary, '\\') || str_contains($phpBinary, '/')) && ! is_file($phpBinary)) {
            throw new RuntimeException('O PHP CLI configurado não foi encontrado em “'.$phpBinary.'”. Ajuste CENTRAL_PHP_BINARY antes de executar testes.');
        }

        return $phpBinary;
    }

    private function prepareInstalledDependencies(string $projectPath, string $checkoutPath): void
    {
        $checkoutVendorPath = $checkoutPath.DIRECTORY_SEPARATOR.'vendor';
        if (is_file($checkoutVendorPath.DIRECTORY_SEPARATOR.'autoload.php')) {
            throw new RuntimeException('A cópia contém vendor versionado. Não é possível confirmar o isolamento das dependências.');
        }

        $projectVendorPath = $projectPath.DIRECTORY_SEPARATOR.'vendor';
        if (! is_file($projectVendorPath.DIRECTORY_SEPARATOR.'autoload.php')) {
            throw new RuntimeException('As dependências PHP não estão instaladas neste projeto. Execute a instalação de dependências antes de iniciar os testes isolados.');
        }

        foreach (['composer.json', 'composer.lock'] as $manifest) {
            if (! is_file($projectPath.DIRECTORY_SEPARATOR.$manifest)
                || ! is_file($checkoutPath.DIRECTORY_SEPARATOR.$manifest)
                || hash_file('sha256', $projectPath.DIRECTORY_SEPARATOR.$manifest) !== hash_file('sha256', $checkoutPath.DIRECTORY_SEPARATOR.$manifest)) {
                throw new RuntimeException('As dependências locais diferem das declaradas neste commit. É necessária uma instalação isolada das dependências antes de testar.');
            }
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            throw new RuntimeException('A preparação independente de dependências está disponível neste executor Windows.');
        }
        $copy = $this->verificationProcess(['robocopy.exe', $projectVendorPath, $checkoutVendorPath, '/E', '/XJ', '/MT:8', '/R:0', '/W:0', '/NFL', '/NDL', '/NJH', '/NJS', '/NP'], $checkoutPath, 120, 'Copiando dependências PHP');
        if ($copy->exitCode() === null || $copy->exitCode() >= 8) {
            throw new RuntimeException('Não foi possível copiar as dependências para a área isolada. '.$copy->errorOutput());
        }
        $composer = dirname($this->phpCliBinary()).DIRECTORY_SEPARATOR.'composer.phar';
        if (! is_file($composer)) {
            throw new RuntimeException('Composer não foi encontrado junto ao PHP CLI; a verificação isolada não iniciou.');
        }
        $manifestPath = $checkoutPath.DIRECTORY_SEPARATOR.'composer.json';
        $originalManifest = file_get_contents($manifestPath);
        $manifest = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
        $manifest['config']['optimize-autoloader'] = false;
        $manifest['config']['classmap-authoritative'] = false;
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Não foi possível preparar o Composer na cópia isolada.');
        }
        try {
            $autoload = $this->verificationProcess([$this->phpCliBinary(), '-d', 'opcache.enable_cli=0', $composer, 'dump-autoload', '--no-scripts', '--no-plugins', '--no-interaction'], $checkoutPath, 180, 'Preparando autoload');
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException('O Composer atingiu o prazo da etapa ou o orçamento global; os testes Laravel não iniciaram. Saída parcial: '.mb_substr(trim($exception->result->output()."\n".$exception->result->errorOutput()), -6000), 408, $exception);
        } finally {
            if (file_put_contents($manifestPath, $originalManifest) === false) {
                throw new RuntimeException('Não foi possível restaurar o manifesto da cópia isolada; verificação interrompida.');
            }
        }
        if (! $autoload->successful()) {
            throw new RuntimeException('Não foi possível preparar o carregamento isolado das classes. '.$autoload->errorOutput());
        }
    }

    /** @return array<string, string> */
    private function isolatedTestEnvironment(string $checkout): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_CONFIG_CACHE' => $checkout.'/bootstrap/cache/central-testing-config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SENTRY_LARAVEL_DSN' => '',
            'SENTRY_DSN' => '',
        ];
    }

    private function prepareFrontendAssets(string $projectPath, string $checkout): void
    {
        if (! is_file($checkout.'/package.json') || is_file($checkout.'/public/build/manifest.json')) {
            return;
        }
        $package = json_decode(file_get_contents($checkout.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($package['devDependencies']['vite']) && ! isset($package['dependencies']['vite'])) {
            return;
        }
        if (($package['scripts']['build'] ?? null) !== 'vite build') {
            throw new RuntimeException('Este projeto exige uma receita de build diferente de “vite build”. A preparação isolada ainda não suporta essa receita; testes não iniciados.');
        }
        foreach (['package.json', 'package-lock.json'] as $manifest) {
            if (! is_file($projectPath.'/'.$manifest) || ! is_file($checkout.'/'.$manifest)
                || hash_file('sha256', $projectPath.'/'.$manifest) !== hash_file('sha256', $checkout.'/'.$manifest)) {
                throw new RuntimeException('As dependências JavaScript locais diferem deste commit; não é seguro reutilizá-las para o build isolado.');
            }
        }
        if (! is_file($projectPath.'/node_modules/vite/bin/vite.js')) {
            throw new RuntimeException('As dependências JavaScript não estão instaladas. O build isolado não iniciou.');
        }
        $copy = $this->verificationProcess(['robocopy.exe', $projectPath.'/node_modules', $checkout.'/node_modules', '/E', '/XJ', '/MT:8', '/R:0', '/W:0', '/NFL', '/NDL', '/NJH', '/NJS', '/NP'], $checkout, 120, 'Copiando dependências JavaScript');
        if ($copy->exitCode() === null || $copy->exitCode() >= 8) {
            throw new RuntimeException('Não foi possível copiar as dependências JavaScript para a área isolada.');
        }
        $build = $this->runCheck($checkout, 'Build isolado', [(string) config('central.node_binary'), 'node_modules/vite/bin/vite.js', 'build'], 180, null);
        if (! $build['passed'] || ! is_file($checkout.'/public/build/manifest.json')) {
            throw new RuntimeException('A preparação da interface não concluiu; testes Laravel não iniciados. '.$build['explanation'].' '.$build['output'], in_array($build['cause'] ?? null, ['test_timeout', 'idle_timeout'], true) ? 408 : 0);
        }
    }

    /** @return array{name: string, status: 'passed'|'failed'|'infrastructure_unavailable', passed: bool, output: string, explanation: string} */
    private function runCheck(string $path, string $name, array $command, int $timeout = 25, ?int $idleTimeout = 15, array $environment = []): array
    {
        $timeout = $this->verificationTimeout($timeout);
        try {
            $result = $this->verificationProcess($command, $path, $timeout, $name, $environment, $idleTimeout === null ? null : min($idleTimeout, $timeout));
        } catch (ProcessTimedOutException $exception) {
            $idle = $exception instanceof \Illuminate\Process\Exceptions\ProcessIdleTimedOutException;
            $partialOutput = trim($exception->result->output()."\n".$exception->result->errorOutput());
            $progress = '';
            if (preg_match('/Test Suite Loaded \((\d+) tests?\)/', $partialOutput, $total)) {
                $completed = preg_match_all('/^Test Finished \(/m', $partialOutput);
                $progress = ' Foram concluídos '.$completed.' de '.$total[1].' testes; a suíte não foi aprovada integralmente.';
            }
            return [
                'name' => $name,
                'status' => 'infrastructure_unavailable',
                'cause' => $idle ? 'idle_timeout' : 'test_timeout',
                'passed' => false,
                'output' => mb_substr($partialOutput, -10000) ?: 'O comando não produziu saída antes da interrupção.',
                'explanation' => ($idle ? 'Comando sem saída por '.$idleTimeout.' segundos (possível travamento).' : 'Esta etapa atingiu o prazo disponível de '.$timeout.' segundos, limitado também pelo orçamento global.').$progress.' O executor solicitou o encerramento do processo e de seus descendentes. Resultado inconclusivo; confira a saída parcial. Isso não comprova defeito no XAMPP nem reprova o commit.',
            ];
        }
        $output = trim($result->output()."\n".$result->errorOutput());
        if (mb_strlen($output) > 10000) {
            $output = "… início da saída omitido para manter a Central responsiva.\n".mb_substr($output, -10000);
        }

        if ($result->successful()) {
            return ['name' => $name, 'status' => 'passed', 'passed' => true, 'output' => $output ?: 'Concluído sem mensagens.', 'explanation' => 'Verificação concluída com sucesso.'];
        }

        if ($this->isInfrastructureUnavailable($output) || str_contains($output, 'Maximum execution time')) {
            return [
                'name' => $name,
                'status' => 'infrastructure_unavailable',
                'cause' => str_contains($output, 'Maximum execution time') ? 'php_timeout' : 'infrastructure_error',
                'passed' => false,
                'output' => $output ?: 'O ambiente interrompeu a verificação sem retornar detalhes.',
                'explanation' => 'O PHP/ambiente interrompeu a verificação. Resultado inconclusivo; consulte a saída técnica. Isso não reprova o código do commit.',
            ];
        }

        return ['name' => $name, 'status' => 'failed', 'passed' => false, 'output' => $output ?: 'Falhou sem detalhes.', 'explanation' => 'A verificação foi executada e encontrou uma falha que precisa ser analisada.'];
    }

    private function isInfrastructureUnavailable(string $output): bool
    {
        $normalizedOutput = mb_strtolower($output);

        return preg_match('/\bah\d{5}:/i', $output) === 1
            || str_contains($normalizedOutput, 'unable to retrieve my generation from the parent')
            || str_contains($normalizedOutput, 'apache is shutting down')
            || str_contains($normalizedOutput, 'connection refused')
            || str_contains($normalizedOutput, 'could not connect to server')
            || str_contains($normalizedOutput, 'sqlstate[hy000] [2002]');
    }

    private function describePushFailure(string $error, bool $firstPush = false): string
    {
        $normalizedError = strtolower($error);

        if (str_contains($normalizedError, '403') || str_contains($normalizedError, 'permission to ')) {
            return 'O GitHub reconheceu o token, mas ele não tem permissão para enviar alterações neste repositório. Na aba “Configuração”, substitua-o por um token com acesso a este repositório e permissão “Contents: Read and write”; depois tente novamente.';
        }

        if (str_contains($normalizedError, 'terminal prompts have been disabled') || str_contains($normalizedError, 'could not read username')) {
            return 'O GitHub pediu autenticação. Na aba “Configuração”, salve um token do GitHub com acesso a este repositório e permissão “Contents: Read and write”, depois tente novamente.';
        }

        return 'O GitHub não confirmou '.($firstPush ? 'o primeiro envio' : 'o envio').': '.trim($error);
    }

    private function inspectionCommands(string $statusOutput): array
    {
        return [
            ['command' => 'git rev-parse --show-toplevel', 'purpose' => 'Confere se esta pasta é a raiz de um repositório Git.'],
            ['command' => 'git branch --show-current', 'purpose' => 'Mostra a linha de trabalho atual.'],
            ['command' => 'git rev-parse --short HEAD', 'purpose' => 'Identifica o último commit local.'],
            ['command' => 'git remote get-url origin', 'purpose' => 'Verifica se existe conexão remota configurada.'],
            ['command' => 'git status', 'purpose' => 'Mostra a branch e organiza as alterações em modificadas, preparadas e não rastreadas, sem modificar nenhum arquivo.', 'output' => $statusOutput ?: '(nenhuma linha retornada — árvore de trabalho limpa)'],
            ['command' => 'git rev-list --left-right --count @{upstream}...HEAD', 'purpose' => 'Compara commits locais com a referência remota já conhecida.'],
        ];
    }

    private function lastCommit(string $path): ?array
    {
        $result = $this->git($path, ['log', '-1', '--format=%h%x1f%an%x1f%ae%x1f%aI%x1f%s']);
        if (! $result->successful() || trim($result->output()) === '') {
            return null;
        }
        [$sha, $author, $email, $date, $message] = array_pad(explode("\x1f", trim($result->output()), 5), 5, null);
        return compact('sha', 'author', 'email', 'date', 'message');
    }

    private function authorizedPath(string $directory): string
    {
        if ($directory === '' || basename($directory) !== $directory || str_contains($directory, '..')) {
            throw new RuntimeException('Diretório de projeto inválido.');
        }
        $root = realpath((string) config('central.projects_root'));
        if ($root === false) {
            throw new RuntimeException('A raiz autorizada de projetos não está disponível.');
        }
        $candidate = $root.DIRECTORY_SEPARATOR.$directory;
        $resolved = realpath($candidate);
        if ($resolved !== false && ! str_starts_with(strtolower($resolved), strtolower($root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('O caminho está fora da raiz autorizada.');
        }
        return $resolved ?: $candidate;
    }

    private function git(string $path, array $arguments)
    {
        return Process::timeout(8)->path($path)->run($this->gitCommand($path, $arguments));
    }

    private function remoteProcess(string $path, string $origin, array $arguments, int $timeout)
    {
        return Process::timeout($timeout)
            ->env(array_merge(['GIT_TERMINAL_PROMPT' => '0'], $this->credentials->environmentForOrigin($origin)))
            ->path($path)
            ->run($this->gitCommand($path, $arguments));
    }

    private function normalizeGitHubUrl(string $url): string
    {
        return strtolower(rtrim(preg_replace('/\.git$/i', '', $url) ?? $url, '/'));
    }

    private function gitCommand(string $path, array $arguments): array
    {
        return array_merge([
            (string) config('central.git_binary', 'git'),
            '-c',
            'safe.directory='.str_replace('\\', '/', $path),
        ], $arguments);
    }

    private function gitAvailable(): bool
    {
        $result = Process::timeout(3)->run([(string) config('central.git_binary', 'git'), '--version']);
        return $result->successful();
    }

    private function output(string $path, array $arguments): ?string
    {
        $result = $this->git($path, $arguments);
        return $result->successful() ? trim($result->output()) ?: null : null;
    }
}
