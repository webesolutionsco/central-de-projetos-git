<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class LaravelCloudReader
{
    public function __construct(
        private readonly LaravelCloudCredentialStore $credentials,
        private readonly GitHubCredentialStore $githubCredentials,
    )
    {
    }

    /** @return array<string, mixed> */
    public function verify(string $application, string $environment, string $expectedBranch): array
    {
        $token = $this->credentials->token();
        if ($token === null) {
            throw new RuntimeException('Salve um token do Laravel Cloud antes de consultar o ambiente.');
        }

        $result = Process::timeout(25)->env(['LARAVEL_CLOUD_TOKEN' => $token])->run([
            (string) config('central.cloud_cli'), 'environment:get', $environment, '--json', '-n',
        ]);
        if (! $result->successful()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException('Não foi possível consultar o Laravel Cloud. '.$detail);
        }

        $data = json_decode($result->output(), true);
        if (! is_array($data)) {
            throw new RuntimeException('O Laravel Cloud não retornou dados válidos do ambiente.');
        }
        if (($data['application']['name'] ?? null) !== $application) {
            throw new RuntimeException('O ambiente retornado não pertence à aplicação configurada.');
        }

        return [
            'application' => $application,
            'environment' => $data['name'] ?? $environment,
            'branch' => $data['branch'] ?? null,
            'expected_branch' => $expectedBranch,
            'branch_matches' => ($data['branch'] ?? null) === $expectedBranch,
            'status' => $data['status'] ?? null,
            'url' => $data['url'] ?? null,
            'technical_commands' => [[
                'command' => 'cloud environment:get '.$environment.' --json -n',
                'purpose' => 'Consulta o ambiente Laravel Cloud sem iniciar deploy ou migration.',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function deployToHomologation(string $application, string $environment, string $expectedBranch, string $workingDirectory): array
    {
        $environmentData = $this->verify($application, $environment, $expectedBranch);

        if (! $environmentData['branch_matches']) {
            throw new RuntimeException('A branch da homologação não confere com a configuração da Central. A publicação foi bloqueada.');
        }

        if (($environmentData['status'] ?? null) !== 'running') {
            throw new RuntimeException('A homologação não está em execução. A publicação foi bloqueada até o ambiente voltar a ficar disponível.');
        }

        $deploy = $this->command([
            (string) config('central.cloud_cli'), 'deploy', $application, $environment, '-n', '--no-wait',
        ], 30, $workingDirectory);
        if (! $deploy->successful()) {
            throw new RuntimeException('Não foi possível iniciar a publicação na homologação. '.$this->detail($deploy->errorOutput(), $deploy->output()));
        }

        $monitor = $this->command([
            (string) config('central.cloud_cli'), 'deploy:monitor', $application, $environment, '-n',
        ], 45, $workingDirectory);
        $monitorOutput = $this->detail($monitor->errorOutput(), $monitor->output());
        $monitoring = $monitor->successful() ? 'concluído' : 'em andamento';

        if (! $monitor->successful() && ! str_contains(mb_strtolower($monitorOutput), 'timeout')) {
            throw new RuntimeException('A publicação foi iniciada, mas o acompanhamento retornou um erro. '.$monitorOutput);
        }

        $deployment = $this->latestDeployment($environment);
        $expectedCommit = $this->remoteBranchCommit($workingDirectory, $expectedBranch);

        return [
            'environment' => $environmentData,
            'deployment' => $deployment,
            'expected_commit' => $expectedCommit,
            'commit_matches' => isset($deployment['commitHash']) && $deployment['commitHash'] === $expectedCommit,
            'monitoring' => $monitoring,
            'technical_commands' => [
                [
                    'command' => 'cloud deploy '.$application.' '.$environment.' -n --no-wait',
                    'purpose' => 'Inicia uma publicação somente na homologação já configurada.',
                ],
                [
                    'command' => 'cloud deploy:monitor '.$application.' '.$environment.' -n',
                    'purpose' => 'Acompanha a publicação até a conclusão ou até a Central retornar para não travar a tela.',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function latestDeployment(string $environment): array
    {
        $token = $this->credentials->token();
        if ($token === null) {
            throw new RuntimeException('Salve um token do Laravel Cloud antes de consultar a publicação.');
        }

        $result = Process::timeout(25)->env(['LARAVEL_CLOUD_TOKEN' => $token])->run([
            (string) config('central.cloud_cli'), 'deployment:list', $environment, '--json', '--fields=id,status,commitHash,commitMessage,branchName,startedAt,finishedAt,failureReason', '-n',
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('Não foi possível consultar a publicação no Laravel Cloud. '.$this->detail($result->errorOutput(), $result->output()));
        }

        $data = json_decode($result->output(), true);
        $deployments = is_array($data['data'] ?? null) ? $data['data'] : $data;
        if (! is_array($deployments) || ! isset($deployments[0]) || ! is_array($deployments[0])) {
            throw new RuntimeException('O Laravel Cloud não retornou uma publicação identificável.');
        }

        return $deployments[0];
    }

    /** @return array<string, mixed> */
    public function migrationStatus(string $application, string $environment, string $expectedBranch, string $workingDirectory, ?string $commandId = null): array
    {
        $environmentData = $this->verify($application, $environment, $expectedBranch);
        if (! $environmentData['branch_matches']) {
            throw new RuntimeException('A branch da homologação não confere com a configuração da Central. A consulta de migrations foi bloqueada.');
        }

        if ($commandId === null) {
            $started = $this->command([
                (string) config('central.cloud_cli'), 'command:run', $environment,
                '--cmd=php artisan migrate:status --no-ansi --no-interaction', '--no-monitor', '--json', '-n',
            ], 20, $workingDirectory);
            if (! $started->successful()) {
                throw new RuntimeException('Não foi possível iniciar a consulta de migrations. '.$this->detail($started->errorOutput(), $started->output()));
            }

            $startedData = $this->commandData($started->output());
            $commandId = is_string($startedData['id'] ?? null) ? $startedData['id'] : null;
            if ($commandId === null) {
                throw new RuntimeException('O Laravel Cloud iniciou a consulta, mas não retornou um identificador para acompanhar o resultado.');
            }
        }

        $result = $this->command([
            (string) config('central.cloud_cli'), 'command:get', $commandId,
            '--json', '--fields=id,status,output,exitCode,failureReason', '-n',
        ], 20, $workingDirectory);
        if (! $result->successful()) {
            throw new RuntimeException('Não foi possível obter o resultado da consulta de migrations. '.$this->detail($result->errorOutput(), $result->output()));
        }

        $data = $this->commandData($result->output());
        $status = mb_strtolower((string) ($data['status'] ?? 'unknown'));
        $finished = in_array($status, ['succeeded', 'success', 'command.success', 'failed', 'command.failed', 'cancelled', 'canceled'], true);
        $output = (string) ($data['output'] ?? '');
        if ($finished && ! in_array($status, ['succeeded', 'success', 'command.success'], true)) {
            throw new RuntimeException('A consulta de migrations terminou sem sucesso. '.((string) ($data['failureReason'] ?? $output ?: 'O Laravel Cloud não informou detalhes.')));
        }

        return [
            'output' => mb_substr($output, 0, 20000),
            'pending_count' => $finished ? preg_match_all('/\bpending\b/i', $output) : null,
            'command_id' => $commandId,
            'command_status' => $data['status'] ?? null,
            'finished' => $finished,
            'technical_commands' => [[
                'command' => 'cloud command:run '.$environment.' --cmd="php artisan migrate:status --no-ansi --no-interaction" --no-monitor --json -n',
                'purpose' => 'Inicia uma consulta remota sem prender a tela. Não executa migrations.',
            ], [
                'command' => 'cloud command:get '.$commandId.' --json -n',
                'purpose' => 'Lê o resultado disponível da consulta remota, sem iniciar outra migration.',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function deploymentStatus(string $application, string $environment, string $expectedBranch, string $workingDirectory): array
    {
        $environmentData = $this->verify($application, $environment, $expectedBranch);
        $deployment = $this->latestDeployment($environment);
        $expectedCommit = $this->remoteBranchCommit($workingDirectory, $expectedBranch);
        $deployedCommit = $deployment['commitHash'] ?? null;

        return [
            'expected_commit' => $expectedCommit,
            'deployed_commit' => $deployedCommit,
            'matches' => is_string($deployedCommit) && $deployedCommit === $expectedCommit,
            'deployment_status' => $deployment['status'] ?? null,
            'environment' => $environmentData,
            'technical_commands' => [
                [
                    'command' => 'git ls-remote origin refs/heads/'.$expectedBranch,
                    'purpose' => 'Lê o SHA atual da branch no GitHub sem alterar arquivos locais.',
                ],
                [
                    'command' => 'cloud deployment:list '.$environment.' --json -n',
                    'purpose' => 'Lê o SHA registrado pelo último deploy no Laravel Cloud.',
                ],
            ],
        ];
    }

    private function command(array $command, int $timeout, string $workingDirectory): \Illuminate\Process\ProcessResult
    {
        $token = $this->credentials->token();
        if ($token === null) {
            throw new RuntimeException('Salve um token do Laravel Cloud antes de publicar.');
        }

        $gitDirectory = str_replace('\\', '/', rtrim($workingDirectory, "\\/").DIRECTORY_SEPARATOR.'.git');
        $gitWorkingTree = str_replace('\\', '/', $workingDirectory);

        return Process::path($workingDirectory)->timeout($timeout)->env([
            'LARAVEL_CLOUD_TOKEN' => $token,
            'GIT_DIR' => $gitDirectory,
            'GIT_WORK_TREE' => $gitWorkingTree,
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => $gitWorkingTree,
        ])->run($command);
    }

    private function remoteBranchCommit(string $workingDirectory, string $branch): string
    {
        $origin = Process::timeout(10)->run([
            (string) config('central.git_binary', 'git'), '-c', 'safe.directory='.str_replace('\\', '/', $workingDirectory), '-C', $workingDirectory, 'remote', 'get-url', 'origin',
        ]);
        if (! $origin->successful()) {
            throw new RuntimeException('Não foi possível identificar o repositório GitHub deste projeto.');
        }

        $result = Process::timeout(30)->env(array_merge(['GIT_TERMINAL_PROMPT' => '0'], $this->githubCredentials->environmentForOrigin(trim($origin->output()))))->run([
            (string) config('central.git_binary', 'git'), '-c', 'safe.directory='.str_replace('\\', '/', $workingDirectory), '-C', $workingDirectory, 'ls-remote', 'origin', 'refs/heads/'.$branch,
        ]);
        if (! $result->successful() || ! preg_match('/^([a-f0-9]{40})\s/m', $result->output(), $matches)) {
            throw new RuntimeException('Não foi possível consultar o SHA atual da branch '.$branch.' no GitHub. '.$this->detail($result->errorOutput(), $result->output()));
        }

        return $matches[1];
    }

    private function detail(string $errorOutput, string $output): string
    {
        return trim($errorOutput) ?: trim($output) ?: 'O Laravel Cloud não informou detalhes.';
    }

    /** @return array<string, mixed> */
    private function commandData(string $output): array
    {
        $data = json_decode($output, true);
        if (! is_array($data)) {
            throw new RuntimeException('O Laravel Cloud não retornou dados válidos para a consulta remota.');
        }

        return is_array($data['data'] ?? null) ? $data['data'] : $data;
    }
}
