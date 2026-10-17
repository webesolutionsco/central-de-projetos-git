<?php

namespace App\Services;

use DirectoryIterator;
use RuntimeException;

class ProjectRegistry
{
    /** @return array<string, array{directory: string, label: string, source: string}> */
    public function all(): array
    {
        $projects = [];

        foreach (config('central.projects', []) as $id => $project) {
            if (! is_array($project) || ! isset($project['directory'], $project['label'])) {
                continue;
            }

            $projects[$id] = [
                'directory' => (string) $project['directory'],
                'label' => (string) $project['label'],
                'source' => 'config',
            ];
        }

        foreach ($this->storedProjects() as $id => $project) {
            if (! isset($projects[$id])) {
                $projects[$id] = $project;
            }
        }

        return $projects;
    }

    /** @return array{directory: string, label: string, source: string} */
    public function find(string $id): array
    {
        $project = $this->all()[$id] ?? null;
        if (! is_array($project)) {
            throw new RuntimeException('Projeto não autorizado.');
        }

        return $project;
    }

    /** @return list<string> */
    public function availableDirectories(): array
    {
        $root = $this->root();
        $directories = [];

        foreach (new DirectoryIterator($root) as $item) {
            if ($item->isDot() || ! $item->isDir()) {
                continue;
            }

            $directory = $item->getFilename();
            $path = $item->getRealPath();
            if ($path !== false && ($this->isGitRepository($path) || $this->isCentralDirectory($path))) {
                $directories[] = $directory;
            }
        }

        sort($directories, SORT_NATURAL | SORT_FLAG_CASE);

        return $directories;
    }

    /** @return array{id: string, directory: string, label: string, source: string} */
    public function add(string $directory, string $label): array
    {
        $directory = trim($directory);
        $label = trim($label);
        $this->projectPath($directory);

        if (! in_array($directory, $this->availableDirectories(), true)) {
            throw new RuntimeException('Escolha uma pasta Git encontrada dentro da pasta-base configurada.');
        }
        if ($label === '' || mb_strlen($label) > 80) {
            throw new RuntimeException('Informe um nome entre 1 e 80 caracteres para identificar o projeto.');
        }
        foreach ($this->all() as $id => $project) {
            if (strcasecmp($project['directory'], $directory) === 0) {
                return ['id' => $id, ...$project];
            }
        }

        $id = 'local-'.substr(hash('sha256', strtolower($directory)), 0, 12);
        $stored = $this->storedProjects();
        $stored[$id] = ['directory' => $directory, 'label' => $label, 'source' => 'local'];
        $this->writeStoredProjects($stored);

        return ['id' => $id, ...$stored[$id]];
    }

    private function root(): string
    {
        $root = realpath((string) config('central.projects_root'));
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('A pasta-base configurada não está disponível. Ajuste CENTRAL_PROJECTS_ROOT no arquivo .env.');
        }

        return rtrim($root, '\\/');
    }

    private function projectPath(string $directory): string
    {
        if ($directory === '' || $directory === '.' || $directory === '..' || str_contains($directory, '..') || str_contains($directory, '\\') || str_contains($directory, '/')) {
            throw new RuntimeException('Nome de pasta inválido.');
        }

        $root = $this->root();
        $path = realpath($root.DIRECTORY_SEPARATOR.$directory);
        if ($path === false || strcasecmp(dirname($path), $root) !== 0) {
            throw new RuntimeException('A pasta escolhida não existe dentro da pasta-base configurada.');
        }

        return $path;
    }

    private function isGitRepository(string $path): bool
    {
        return is_dir($path.DIRECTORY_SEPARATOR.'.git') || is_file($path.DIRECTORY_SEPARATOR.'.git');
    }

    private function isCentralDirectory(string $path): bool
    {
        return strcasecmp($path, base_path()) === 0;
    }

    /** @return array<string, array{directory: string, label: string, source: string}> */
    private function storedProjects(): array
    {
        if (! is_file($this->storagePath())) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->storagePath()), true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn (mixed $project): bool => is_array($project)
                && isset($project['directory'], $project['label'])
                && is_string($project['directory'])
                && is_string($project['label']))
            ->map(fn (array $project): array => [
                'directory' => $project['directory'],
                'label' => $project['label'],
                'source' => 'local',
            ])
            ->all();
    }

    /** @param array<string, array{directory: string, label: string, source: string}> $projects */
    private function writeStoredProjects(array $projects): void
    {
        $directory = dirname($this->storagePath());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento local de projetos.');
        }

        $temporary = $this->storagePath().'.tmp';
        $json = json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $this->storagePath())) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível salvar o projeto autorizado.');
        }
    }

    private function storagePath(): string
    {
        return (string) config('central.projects_registry_path', storage_path('app/private/authorized-projects.json'));
    }
}
