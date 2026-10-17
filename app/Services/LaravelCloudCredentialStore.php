<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class LaravelCloudCredentialStore
{
    public function status(): array
    {
        return ['configured' => is_file($this->path())];
    }

    public function save(string $token): void
    {
        $token = trim($token);
        if (! preg_match('/^[A-Za-z0-9_-]{20,500}$/', $token)) {
            throw new RuntimeException('Informe um token do Laravel Cloud válido, sem espaços.');
        }

        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento seguro da credencial Laravel Cloud.');
        }

        $temporary = $this->path().'.tmp';
        if (file_put_contents($temporary, Crypt::encryptString($token), LOCK_EX) === false || ! rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível salvar a credencial Laravel Cloud.');
        }
    }

    public function token(): ?string
    {
        if (! is_file($this->path())) {
            return null;
        }

        try {
            return Crypt::decryptString((string) file_get_contents($this->path()));
        } catch (\Throwable) {
            throw new RuntimeException('A credencial Laravel Cloud não pôde ser lida. Remova-a e informe outra.');
        }
    }

    private function path(): string
    {
        return storage_path('app/private/laravel-cloud-token.enc');
    }
}
