<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Stores GitHub credentials encrypted and scoped to a single HTTPS repository.
 * A previous single-token installation is detected for migration guidance, but
 * is never used for another repository.
 */
class GitHubCredentialStore
{
    /** @return array{configured: bool, scope: ?string, source: string} */
    public function statusForOrigin(?string $origin): array
    {
        $scope = $this->scopeFromOrigin($origin ?? '');
        if ($scope === null) {
            return ['configured' => false, 'scope' => null, 'source' => 'none'];
        }

        if (array_key_exists($scope, $this->tokens())) {
            return ['configured' => true, 'scope' => $scope, 'source' => 'repository'];
        }

        $legacyConfigured = $this->legacyToken() !== null;

        return ['configured' => $legacyConfigured, 'scope' => $scope, 'source' => $legacyConfigured ? 'legacy' : 'none'];
    }

    public function saveForOrigin(string $origin, string $token): void
    {
        $scope = $this->requiredScope($origin);
        $this->validateToken($token);

        $tokens = $this->tokens();
        $tokens[$scope] = trim($token);
        $this->writeTokens($tokens);
    }

    public function removeForOrigin(string $origin): void
    {
        $scope = $this->requiredScope($origin);
        $tokens = $this->tokens();
        unset($tokens[$scope]);
        $this->writeTokens($tokens);
    }

    /** @return array<string, string> */
    public function environmentForOrigin(string $origin): array
    {
        $scope = $this->scopeFromOrigin($origin);
        if ($scope === null) {
            return [];
        }

        $tokens = $this->tokens();
        $token = $tokens[$scope] ?? null;
        if ($token === null) {
            return [];
        }

        // Git receives this only through the child-process environment. It is
        // deliberately absent from commands, responses, terminal and logs.
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraheader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x-access-token:'.$token),
        ];
    }

    /** @return array<string, string> */
    private function tokens(): array
    {
        if (! is_file($this->scopedPath())) {
            return [];
        }

        try {
            $tokens = json_decode(Crypt::decryptString((string) file_get_contents($this->scopedPath())), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($tokens)) {
                throw new RuntimeException('O armazenamento de credenciais do GitHub está inválido.');
            }

            return array_filter($tokens, fn (mixed $token): bool => is_string($token) && $token !== '');
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new RuntimeException('As credenciais salvas do GitHub não puderam ser lidas. Remova-as e informe novos tokens.');
        }
    }

    /** @param array<string, string> $tokens */
    private function writeTokens(array $tokens): void
    {
        $directory = dirname($this->scopedPath());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento seguro da credencial.');
        }

        $temporary = $this->scopedPath().'.tmp';
        $payload = Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR));
        $written = file_put_contents($temporary, $payload, LOCK_EX);
        if ($written === false || ! rename($temporary, $this->scopedPath())) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível salvar a credencial do GitHub.');
        }
    }

    private function validateToken(string $token): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{20,500}$/', trim($token))) {
            throw new RuntimeException('Informe um token do GitHub válido. Ele deve ter entre 20 e 500 caracteres, sem espaços.');
        }
    }

    private function requiredScope(string $origin): string
    {
        $scope = $this->scopeFromOrigin($origin);
        if ($scope === null) {
            throw new RuntimeException('Este projeto não possui um repositório HTTPS do GitHub conectado. Conecte-o antes de guardar um token.');
        }

        return $scope;
    }

    private function scopeFromOrigin(string $origin): ?string
    {
        if (! preg_match('#^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', trim($origin), $matches)) {
            return null;
        }

        return strtolower($matches[1].'/'.$matches[2]);
    }

    private function legacyToken(): ?string
    {
        if (! is_file($this->legacyPath())) {
            return null;
        }

        try {
            return Crypt::decryptString((string) file_get_contents($this->legacyPath()));
        } catch (\Throwable) {
            throw new RuntimeException('A credencial legada do GitHub não pôde ser lida. Remova-a e informe um novo token.');
        }
    }

    private function scopedPath(): string
    {
        return storage_path('app/private/github-tokens.enc');
    }

    private function legacyPath(): string
    {
        return storage_path('app/private/github-token.enc');
    }
}
