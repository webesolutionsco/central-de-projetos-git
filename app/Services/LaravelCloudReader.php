<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class LaravelCloudReader
{
    public function __construct(private readonly LaravelCloudCredentialStore $credentials)
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
            throw new RuntimeException('Não foi possível consultar o Laravel Cloud. Verifique token, aplicação e ambiente.');
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
}
