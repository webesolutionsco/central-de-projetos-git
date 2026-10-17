<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use InvalidArgumentException;
use RuntimeException;

class LocalProjectReader
{
    public function __construct(
        private readonly GitHubCredentialStore $credentials,
        private readonly ProjectRegistry $projects,
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
