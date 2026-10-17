<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Keeps the optional GitHub token outside the public directory. The token is
 * encrypted with Laravel's APP_KEY and is never returned by the HTTP API.
 */
class GitHubCredentialStore
{
    public function status(): array
    {
        return ['configured' => is_file($this->path())];
    }

    public function save(string $token): void
    {
        $token = trim($token);
        if (! preg_match('/^[A-Za-z0-9_-]{20,500}$/', $token)) {
            throw new RuntimeException('Informe um token do GitHub válido. Ele deve ter entre 20 e 500 caracteres, sem espaços.');
        }

        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento seguro da credencial.');
        }

        $temporary = $this->path().'.tmp';
        $written = file_put_contents($temporary, Crypt::encryptString($token), LOCK_EX);
        if ($written === false || ! rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível salvar a credencial do GitHub.');
        }
    }

    public function remove(): void
    {
        if (is_file($this->path()) && ! unlink($this->path())) {
            throw new RuntimeException('Não foi possível remover a credencial do GitHub.');
        }
    }

    /** @return array<string, string> */
    public function environmentForOrigin(string $origin): array
    {
        if (! $this->isGitHubHttpsOrigin($origin)) {
            return [];
        }
        $token = $this->token();
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

    public function token(): ?string
    {
        if (! is_file($this->path())) {
            return null;
        }

        try {
            return Crypt::decryptString((string) file_get_contents($this->path()));
        } catch (\Throwable) {
            throw new RuntimeException('A credencial salva do GitHub não pôde ser lida. Remova-a e informe um novo token.');
        }
    }

    private function isGitHubHttpsOrigin(string $origin): bool
    {
        return (bool) preg_match('#^https://github\.com/#i', $origin);
    }

    private function path(): string
    {
        return storage_path('app/private/github-token.enc');
    }
}
