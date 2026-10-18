<?php

namespace Tests\Feature;

use App\Services\CommitCheckJobs;
use App\Services\LocalProjectReader;
use App\Services\TestFailureReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class CommitCheckJobsTest extends TestCase
{
    public function test_test_target_accepts_only_a_test_file_or_class(): void
    {
        $reader = app(LocalProjectReader::class);

        $this->assertSame('tests/Feature/WebeIgrejaMemberTest.php', $reader->normalizeTestTarget('tests/Feature/WebeIgrejaMemberTest.php'));
        $this->assertSame('Tests\\Feature\\WebeIgrejaMemberTest', $reader->normalizeTestTarget('WebeIgrejaMemberTest'));
        $this->assertSame('Tests\\Feature\\WebeIgrejaMemberTest::test_member_can_be_updated', $reader->normalizeTestTarget('Tests\\Feature\\WebeIgrejaMemberTest::test_member_can_be_updated'));
        $this->expectException(\InvalidArgumentException::class);
        $reader->normalizeTestTarget('../../artisan');
    }

    private string $jobDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobDirectory = storage_path('framework/testing/check-jobs-'.bin2hex(random_bytes(8)));
        config(['central.check_jobs_path' => $this->jobDirectory]);
        Process::fake(['*' => Process::result('123')]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->jobDirectory);
        parent::tearDown();
    }

    private function reader(): LocalProjectReader
    {
        $reader = Mockery::mock(LocalProjectReader::class);
        $reader->shouldReceive('commitDiff')->andReturn(['commit' => ['sha' => str_repeat('a', 40)]]);

        return $reader;
    }

    public function test_repeated_start_reuses_active_job_and_persists_it(): void
    {
        $reader = $this->reader();
        $jobs = new CommitCheckJobs($reader);
        $first = $jobs->start('fixture', 'aaaaaaa');
        $second = $jobs->start('fixture', 'aaaaaaa');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('queued', (new CommitCheckJobs($reader))->latest('fixture')['status']);
        Process::assertRanTimes(fn (): bool => true, 1);
    }

    public function test_worker_saves_progress_result_and_cleanup(): void
    {
        $reader = $this->reader();
        $reader->shouldReceive('runCommitChecks')->once()->andReturnUsing(function ($project, $sha, $budget, $progress, $cancelled) {
            $this->assertSame(1800, $budget);
            $this->assertFalse($cancelled());
            $progress('Testes Laravel', (new TestFailureReport)->progress('Testes Laravel', "Test Suite Loaded (2 tests)\nTest Prepared (Example::test_one)\nTest Finished (Example::test_one)\n"));

            return ['checks' => [['passed' => true]], 'cleanup' => ['successful' => true]];
        });
        $jobs = new CommitCheckJobs($reader);
        $job = $jobs->start('fixture', 'aaaaaaa');
        $jobs->execute($job['id']);
        $saved = $jobs->read($job['id'], 'fixture');
        $this->assertSame('passed', $saved['status']);
        $this->assertSame(1, $saved['completed_tests']);
        $this->assertSame(2, $saved['total_tests']);
        $this->assertTrue($saved['result']['cleanup']['successful']);
    }

    public function test_cancellation_is_scoped_and_cooperative(): void
    {
        $reader = $this->reader();
        $reader->shouldReceive('runCommitChecks')->once()->andReturnUsing(function ($project, $sha, $budget, $progress, $cancelled) {
            $this->assertTrue($cancelled());

            return ['checks' => [['passed' => false, 'cause' => 'cancelled']], 'cleanup' => ['successful' => true]];
        });
        $jobs = new CommitCheckJobs($reader);
        $job = $jobs->start('fixture', 'aaaaaaa');
        $jobs->cancel($job['id'], 'fixture');
        $jobs->execute($job['id']);
        $this->assertSame('cancelled', $jobs->read($job['id'], 'fixture')['status']);
        $this->expectException(\RuntimeException::class);
        $jobs->cancel($job['id'], 'another-project');
    }

    public function test_job_id_cannot_escape_private_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CommitCheckJobs($this->reader()))->read('../secrets');
    }

    public function test_failed_checks_are_persisted_without_approval(): void
    {
        $reader = $this->reader();
        $reader->shouldReceive('runCommitChecks')->once()->andReturn([
            'checks' => [['passed' => false, 'output' => 'Falha real de teste']],
            'cleanup' => ['successful' => false, 'message' => 'Limpeza pendente'],
        ]);
        $jobs = new CommitCheckJobs($reader);
        $job = $jobs->start('fixture', 'aaaaaaa');
        $jobs->execute($job['id']);
        $saved = (new CommitCheckJobs($reader))->latest('fixture');
        $this->assertSame('failed', $saved['status']);
        $this->assertFalse($saved['result']['cleanup']['successful']);
        $this->assertArrayNotHasKey('output', $saved['result']['checks'][0]);
        $this->assertSame(['test', 'file', 'line', 'message', 'exception', 'application_file', 'application_line', 'category'], array_keys($saved['result']['checks'][0]['failures'][0]));
    }
}
