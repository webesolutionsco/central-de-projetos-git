<?php

namespace Tests\Feature;

use App\Services\LocalProjectReader;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CommitCheckTimeoutTest extends TestCase
{
    public function test_user_cancellation_stops_a_silent_process_and_preserves_output(): void
    {
        $reader = app(LocalProjectReader::class);
        $started = microtime(true);
        (new \ReflectionProperty($reader, 'verificationCancelled'))->setValue($reader, fn (): bool => microtime(true) - $started > 0.7);
        try {
            (new ReflectionMethod($reader, 'runCheck'))->invoke(
                $reader, base_path(), 'Cancelamento real',
                [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', 'echo "iniciou"; fflush(STDOUT); sleep(20);'],
                10, null,
            );
            $this->fail('O processo deveria ter sido cancelado.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(499, $exception->getCode());
            $this->assertStringContainsString('iniciou', $exception->getMessage());
            $this->assertLessThan(8, microtime(true) - $started);
        }
    }

    public function test_global_budget_caps_the_stage_and_does_not_restart(): void
    {
        $reader = app(LocalProjectReader::class);
        $deadline = new \ReflectionProperty($reader, 'verificationDeadline');
        $method = new ReflectionMethod($reader, 'verificationTimeout');
        $deadline->setValue($reader, hrtime(true) / 1e9 + 2.5);
        $this->assertSame(2, $method->invoke($reader, 60));
        $deadline->setValue($reader, hrtime(true) / 1e9 - 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prazo global');
        $method->invoke($reader, 60);
    }

    public function test_windows_timeout_ends_the_spawned_descendant(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Verificação da árvore de processos Windows.');
        }
        $script = '$p=proc_open([PHP_BINARY,"-r","sleep(30);"],[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes); echo "CHILD_PID=".proc_get_status($p)["pid"]."\\n"; fflush(STDOUT); sleep(30);';
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class), base_path(), 'Árvore descartável',
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', $script], 2, null,
        );
        $this->assertFalse($result['passed']);
        $this->assertSame(1, preg_match('/CHILD_PID=(\d+)/', $result['output'], $matches));
        $listing = new Process(['tasklist.exe', '/FI', 'PID eq '.$matches[1], '/FO', 'CSV', '/NH']);
        $listing->setTimeout(5);
        $listing->mustRun();
        $this->assertStringNotContainsString('"'.$matches[1].'"', $listing->getOutput());
    }

    public function test_timeout_preserves_partial_output_and_ends_the_child(): void
    {
        $started = microtime(true);
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class),
            base_path(),
            'Processo descartável',
            [PHP_BINARY, '-r', 'echo "saida parcial"; fflush(STDOUT); sleep(10); echo "terminou indevidamente";'],
            1,
        );

        $this->assertFalse($result['passed']);
        $this->assertSame('infrastructure_unavailable', $result['status']);
        $this->assertContains($result['cause'], ['test_timeout', 'idle_timeout']);
        $this->assertStringContainsString('saida parcial', $result['output']);
        $this->assertStringNotContainsString('terminou indevidamente', $result['output']);
        $this->assertLessThan(8, microtime(true) - $started);
    }

    public function test_a_normal_failure_is_not_reported_as_a_timeout(): void
    {
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class), base_path(), 'Falha controlada',
            [PHP_BINARY, '-r', 'fwrite(STDERR, "assertion failed"); exit(1);'],
        );

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('assertion failed', $result['output']);
    }

    public function test_long_output_preserves_the_final_failure(): void
    {
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class), base_path(), 'Saída longa',
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', 'echo str_repeat("x", 12000)."FALHA FINAL"; exit(1);'],
        );

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('FALHA FINAL', $result['output']);
    }

    public function test_silent_test_can_finish_without_an_idle_deadline(): void
    {
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class), base_path(), 'Teste silencioso',
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', 'sleep(2); echo getenv("CENTRAL_TEST_PROBE");'],
            5, null, ['CENTRAL_TEST_PROBE' => 'concluiu'],
        );

        $this->assertTrue($result['passed']);
        $this->assertSame('concluiu', $result['output']);
    }

    public function test_total_deadline_still_applies_without_an_idle_deadline(): void
    {
        $result = (new ReflectionMethod(LocalProjectReader::class, 'runCheck'))->invoke(
            app(LocalProjectReader::class), base_path(), 'Teste longo',
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', 'sleep(10);'],
            1, null,
        );

        $this->assertFalse($result['passed']);
        $this->assertSame('test_timeout', $result['cause']);
    }

    public function test_isolated_environment_uses_disposable_key_and_database(): void
    {
        $method = new ReflectionMethod(LocalProjectReader::class, 'isolatedTestEnvironment');
        $reader = app(LocalProjectReader::class);
        $first = $method->invoke($reader, 'C:/isolated');
        $second = $method->invoke($reader, 'C:/isolated');

        $this->assertSame('testing', $first['APP_ENV']);
        $this->assertSame('sqlite', $first['DB_CONNECTION']);
        $this->assertSame(':memory:', $first['DB_DATABASE']);
        $this->assertSame('', $first['DB_URL']);
        $this->assertSame('array', $first['MAIL_MAILER']);
        $this->assertNotSame($first['APP_KEY'], $second['APP_KEY']);
        $this->assertSame(32, strlen(base64_decode(substr($first['APP_KEY'], 7), true)));
        $this->assertStringStartsWith('C:/isolated/', $first['APP_CONFIG_CACHE']);
    }

    public function test_frontend_preparation_refuses_dependencies_from_another_version(): void
    {
        $directory = sys_get_temp_dir().'/central-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory.'/source');
        mkdir($directory.'/checkout');
        file_put_contents($directory.'/source/package.json', '{}');
        file_put_contents($directory.'/checkout/package.json', json_encode([
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^8.0'],
        ]));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('dependências JavaScript locais diferem');
            (new ReflectionMethod(LocalProjectReader::class, 'prepareFrontendAssets'))->invoke(
                app(LocalProjectReader::class), $directory.'/source', $directory.'/checkout',
            );
        } finally {
            unlink($directory.'/source/package.json');
            unlink($directory.'/checkout/package.json');
            rmdir($directory.'/source');
            rmdir($directory.'/checkout');
            rmdir($directory);
        }
    }
}
