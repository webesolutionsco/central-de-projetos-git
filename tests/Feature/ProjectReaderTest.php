<?php

namespace Tests\Feature;

use App\Services\LocalProjectReader;
use App\Services\GitHubCredentialStore;
use App\Services\ProjectRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class ProjectReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing-projects');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/project-a');
        $path = $this->root.'/project-a';
        $this->git($path, ['init']);
        $this->git($path, ['config', 'user.email', 'central@example.test']);
        $this->git($path, ['config', 'user.name', 'Central Test']);
        File::put($path.'/tracked.txt', 'original');
        $this->git($path, ['add', 'tracked.txt']);
        $this->git($path, ['commit', '-m', 'Commit inicial']);
        config()->set('central.projects_root', $this->root);
        config()->set('central.projects', ['projecta' => ['directory' => 'project-a', 'label' => 'Projeto A']]);
        config()->set('central.projects_registry_path', $this->root.'/authorized-projects.json');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_reads_real_git_changes_without_mutating_the_repository(): void
    {
        $path = $this->root.'/project-a';
        File::put($path.'/tracked.txt', 'alterado');
        File::put($path.'/staged.txt', 'preparado');
        File::put($path.'/new.txt', 'novo');
        $this->git($path, ['add', 'staged.txt']);

        $project = app(LocalProjectReader::class)->details('projecta');

        $this->assertTrue($project['is_git']);
        $this->assertSame('master', $project['branch']);
        $this->assertSame(3, $project['changes_count']);
        $this->assertCount(1, $project['changes']['staged']);
        $this->assertCount(1, $project['changes']['unstaged']);
        $this->assertCount(1, $project['changes']['untracked']);
        $this->assertSame(' M', $project['changes']['unstaged'][0]['code']);
        $this->assertSame('??', $project['changes']['untracked'][0]['code']);
        $this->assertStringContainsString(' M tracked.txt', $this->git($path, ['status', '--porcelain'])->output());
    }

    public function test_it_rejects_a_project_outside_the_authorized_list(): void
    {
        $this->expectException(RuntimeException::class);
        app(LocalProjectReader::class)->details('..');
    }

    public function test_it_stages_only_selected_files_and_creates_a_local_commit(): void
    {
        $path = $this->root.'/project-a';
        File::put($path.'/feature.txt', 'conteúdo');

        $reader = app(LocalProjectReader::class);
        $reader->stage('projecta', ['feature.txt']);
        $commit = $reader->commit('projecta', 'Adiciona recurso de teste');

        $this->assertNotEmpty($commit['sha']);
        $this->assertSame('', trim($this->git($path, ['status', '--porcelain'])->output()));
        $this->assertSame('Adiciona recurso de teste', trim($this->git($path, ['log', '-1', '--format=%s'])->output()));
    }

    public function test_it_blocks_sensitive_files_from_being_staged(): void
    {
        File::put($this->root.'/project-a/.env', 'APP_KEY=segredo');
        $this->expectException(\InvalidArgumentException::class);
        app(LocalProjectReader::class)->stage('projecta', ['.env']);
    }

    public function test_it_blocks_remote_operations_without_an_origin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LocalProjectReader::class)->fetch('projecta');
    }

    public function test_it_can_safely_initialize_git_and_connect_an_https_github_remote(): void
    {
        $path = $this->root.'/project-new';
        File::ensureDirectoryExists($path);
        File::put($path.'/welcome.txt', 'olá');
        config()->set('central.projects.projectnew', ['directory' => 'project-new', 'label' => 'Projeto Novo']);

        $reader = app(LocalProjectReader::class);
        $initialized = $reader->initialize('projectnew');
        $this->assertTrue($initialized['is_git']);
        $this->assertSame('main', $initialized['branch']);
        $this->assertTrue($initialized['can_initialize'] === false);

        $identity = $reader->configureIdentity('projectnew', 'Pessoa de Teste', 'pessoa@example.test');
        $this->assertStringContainsString('Identidade configurada', $identity['message']);
        $this->assertSame('Pessoa de Teste', trim($this->git($path, ['config', '--get', 'user.name'])->output()));

        $remote = $reader->connectRemote('projectnew', 'https://github.com/example/central-teste.git');
        $this->assertStringContainsString('conectado', $remote['message']);
        $this->assertSame('https://github.com/example/central-teste.git', trim($this->git($path, ['remote', 'get-url', 'origin'])->output()));
    }

    public function test_it_rejects_remote_urls_with_embedded_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LocalProjectReader::class)->connectRemote('projecta', 'https://token@github.com/example/project.git');
    }

    public function test_it_creates_an_empty_github_repository_and_connects_it_without_exposing_the_token(): void
    {
        $directory = 'project-created-'.bin2hex(random_bytes(4));
        $path = $this->root.'/'.$directory;
        File::ensureDirectoryExists($path);
        config()->set('central.projects.projectcreated', ['directory' => $directory, 'label' => 'Projeto Criado']);
        $reader = app(LocalProjectReader::class);
        $reader->initialize('projectcreated');
        app(GitHubCredentialStore::class)->save('github_pat_123456789012345678901234567890');

        Http::fake([
            'https://api.github.com/orgs/example-org/repos' => Http::response([
                'clone_url' => 'https://github.com/example-org/project-created.git',
                'html_url' => 'https://github.com/example-org/project-created',
            ], 201),
        ]);

        $created = $reader->createGitHubRepository('projectcreated', 'example-org', 'project-created', 'Projeto de teste', false);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/orgs/example-org/repos'
            && $request['name'] === 'project-created'
            && $request['auto_init'] === false);
        $this->assertSame('https://github.com/example-org/project-created', $created['url']);
        $this->assertSame('https://github.com/example-org/project-created.git', trim($this->git($path, ['remote', 'get-url', 'origin'])->output()));
        app(GitHubCredentialStore::class)->remove();
    }

    public function test_it_reports_changed_migration_files_without_claiming_execution(): void
    {
        $migration = $this->root.'/project-a/database/migrations/2026_10_17_000000_add_flag.php';
        File::ensureDirectoryExists(dirname($migration));
        File::put($migration, '<?php');

        $project = app(LocalProjectReader::class)->details('projecta');

        $this->assertCount(1, $project['migrations']);
        $this->assertSame('database/migrations/2026_10_17_000000_add_flag.php', $project['migrations'][0]['git_path']);
    }

    public function test_project_endpoints_return_real_reader_data(): void
    {
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('projects.0.id', 'projecta');
        $response = $this->getJson('/api/projects/projecta');
        $response->assertOk()->assertJsonPath('project.branch', 'master');
        $this->getJson('/api/projects/not-authorized')->assertNotFound();
    }

    public function test_it_lists_and_persists_a_project_authorized_by_the_local_user(): void
    {
        File::ensureDirectoryExists($this->root.'/project-b/.git');

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('available_directories.0', 'project-a')
            ->assertJsonPath('available_directories.1', 'project-b');

        $response = $this->postJson('/api/projects', ['directory' => 'project-b', 'label' => 'Projeto B'])
            ->assertCreated()
            ->assertJsonPath('project.directory', 'project-b');

        $id = $response->json('project.id');
        $this->assertSame('project-b', app(ProjectRegistry::class)->find($id)['directory']);
        $this->getJson('/api/projects/'.$id)->assertOk()->assertJsonPath('project.label', 'Projeto B');
    }

    public function test_the_main_screen_is_available(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Central de Projetos Git')
            ->assertSee('Terminal didático')
            ->assertSee('id="csrf-token"', false)
            ->assertSee('Consulta o GitHub para atualizar a informação sobre commits novos no remoto.')
            ->assertSee('Marca somente os arquivos escolhidos para entrar no próximo commit.');
    }

    public function test_the_central_rejects_non_local_requests_by_default(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.10.20'])
            ->get('/')
            ->assertForbidden();
    }

    public function test_github_token_is_encrypted_and_its_value_is_never_returned(): void
    {
        $token = 'github_pat_123456789012345678901234567890';
        $this->putJson('/api/github-credential', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonMissing(['token' => $token]);

        $this->getJson('/api/github-credential')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonMissing(['token' => $token]);

        $content = file_get_contents(storage_path('app/private/github-token.enc'));
        $this->assertNotFalse($content);
        $this->assertStringNotContainsString($token, $content);

        $this->deleteJson('/api/github-credential')->assertOk()->assertJsonPath('configured', false);
    }

    private function git(string $path, array $arguments)
    {
        $result = Process::path($path)->run(array_merge(['git'], $arguments));
        $this->assertTrue($result->successful(), $result->errorOutput());
        return $result;
    }
}
