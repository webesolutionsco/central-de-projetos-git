<?php

namespace Tests\Feature;

use App\Services\CommitCheckJobs;
use App\Services\LocalProjectReader;
use App\Services\TestFailureReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class TestFailurePrivacyTest extends TestCase
{
    private string $directory;

    private const PRIVATE_VALUE = 'SENSITIVE_SENTINEL_5391';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/privacy-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->directory, 0700, true);
        config(['central.check_jobs_path' => $this->directory]);
        Process::fake(['*' => Process::result('123')]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function rawFailure(): string
    {
        return 'Failed asserting that <html><input name="_token" value="'.self::PRIVATE_VALUE.'"><p>Nome Privado '.$this::PRIVATE_VALUE.'@example.test</p></html> contains "expected".';
    }

    private function legacyJob(): array
    {
        return [
            'id' => str_repeat('a', 32), 'project' => 'fixture', 'sha' => str_repeat('b', 40),
            'created_at' => time(), 'updated_at' => time(), 'status' => 'failed', 'stage' => 'Testes Laravel',
            'completed_tests' => 2, 'total_tests' => 2, 'failed_tests' => 1, 'errored_tests' => 0,
            'output' => $this->rawFailure(), 'message' => $this->rawFailure(),
            'current_test' => 'Tests\\Feature\\ExampleTest::test_response with data set "'.self::PRIVATE_VALUE.'"',
            'result' => [
                'commit' => str_repeat('b', 40),
                'checks' => [[
                    'name' => 'Testes Laravel', 'passed' => false, 'status' => 'failed',
                    'output' => json_encode(['failures' => [[
                        'test' => 'Tests\\Feature\\ExampleTest::test_response',
                        'file' => 'C:/Users/'.self::PRIVATE_VALUE.'/tests/Feature/ExampleTest.php',
                        'line' => 42, 'message' => $this->rawFailure(),
                    ]]]),
                    'explanation' => $this->rawFailure(),
                    'test_summary' => ['tests' => 2, 'failures' => 1, 'errors' => 0, 'skipped' => 0],
                ]],
                'cleanup' => ['successful' => true, 'message' => $this->rawFailure(), 'output' => $this->rawFailure()],
                'technical_commands' => [['output' => $this->rawFailure()]],
            ],
        ];
    }

    private function assertPrivateDataAbsent(array $report): void
    {
        $serialized = json_encode($report);
        $this->assertStringNotContainsString(self::PRIVATE_VALUE, $serialized);
        $this->assertStringNotContainsString('Nome Privado', $serialized);
        $this->assertStringNotContainsString('_token', $serialized);
        $this->assertStringNotContainsString('<html>', $serialized);
        $this->assertStringNotContainsString('"output"', $serialized);
        $this->assertStringNotContainsString('technical_commands', $serialized);
    }

    public function test_raw_encoded_and_truncated_messages_never_reach_report(): void
    {
        $reports = new TestFailureReport;
        foreach ([$this->rawFailure(), htmlspecialchars($this->rawFailure()), json_encode($this->rawFailure()), substr($this->rawFailure(), 0, 100), self::PRIVATE_VALUE] as $raw) {
            $safe = $reports->result(['checks' => [['passed' => false, 'output' => $raw, 'explanation' => $raw]], 'cleanup' => ['message' => $raw]]);
            $this->assertPrivateDataAbsent($safe);
            $this->assertSame(['test', 'file', 'line', 'message', 'exception', 'application_file', 'application_line', 'category'], array_keys($safe['checks'][0]['failures'][0]));
            $this->assertSame($safe, $reports->result($safe));
        }
    }

    public function test_junit_retains_only_test_location_summary_and_counts(): void
    {
        $path = $this->directory.'/junit.xml';
        file_put_contents($path, '<testsuites><testsuite><testcase class="Tests\Feature\ExampleTest" name="test_response" file="C:/Users/'.self::PRIVATE_VALUE.'/tests/Feature/ExampleTest.php" line="42"><failure><![CDATA['.$this->rawFailure()."\nC:/response/tests/".self::PRIVATE_VALUE.".php:99\n".']]></failure></testcase><testcase class="Tests\Feature\ExampleTest" name="test_success" /></testsuite></testsuites>');
        $report = (new TestFailureReport)->junit($path);
        $this->assertPrivateDataAbsent($report);
        $this->assertSame(['test' => 'Tests\Feature\ExampleTest::test_response', 'file' => 'tests/Feature/ExampleTest.php', 'line' => 42, 'message' => 'A resposta não contém o conteúdo esperado. Conteúdo omitido.', 'exception' => null, 'application_file' => null, 'application_line' => null, 'category' => 'test_failure'], $report['failures'][0]);
        $this->assertSame(['tests' => 2, 'failures' => 1, 'errors' => 0, 'skipped' => 0], $report['summary']);
        file_put_contents($path, '<!DOCTYPE testsuites [<!ENTITY leak "'.self::PRIVATE_VALUE.'">]><testsuites/>');
        $this->assertNull((new TestFailureReport)->junit($path));
        file_put_contents($path, '<testsuites>'.self::PRIVATE_VALUE);
        $this->expectOutputString('');
        $this->assertNull((new TestFailureReport)->junit($path));
    }

    public function test_live_progress_removes_dataset_values_and_assertion_body(): void
    {
        $reports = new TestFailureReport;
        $raw = "Test Suite Loaded (2 tests)\nTest Prepared (Tests\\Feature\\ExampleTest::test_response with data set \"".self::PRIVATE_VALUE."\")\nTest Failed (Tests\\Feature\\ExampleTest::test_response)\n".$this->rawFailure()."\nTest Finished (Tests\\Feature\\ExampleTest::test_response)\n";
        $progress = $reports->progress('Testes Laravel', $raw);
        $this->assertPrivateDataAbsent($progress);
        $this->assertSame(2, $progress['total_tests']);
        $this->assertSame(1, $progress['completed_tests']);
        $this->assertSame(1, $progress['failed_tests']);
        $this->assertNull($progress['current_test']);
        $this->assertSame([], $reports->progress('Build isolado', $raw));
    }

    public function test_safe_report_classifies_exception_and_retains_only_application_location(): void
    {
        $failure = (new TestFailureReport)->failure('', '', null, 'Illuminate\\Database\\QueryException: '.self::PRIVATE_VALUE."\napp/Services/MemberReader.php:37");

        $this->assertPrivateDataAbsent(['failure' => $failure]);
        $this->assertSame('Illuminate\\Database\\QueryException', $failure['exception']);
        $this->assertSame('database', $failure['category']);
        $this->assertSame('app/Services/MemberReader.php', $failure['application_file']);
        $this->assertSame(37, $failure['application_line']);
    }

    public function test_legacy_status_latest_and_cancel_responses_are_safe_and_history_can_be_scrubbed(): void
    {
        $job = $this->legacyJob();
        $path = $this->directory.'/'.$job['id'];
        file_put_contents($path.'.json', json_encode($job));
        file_put_contents($path.'.stdout', $this->rawFailure());
        file_put_contents($path.'.stderr', $this->rawFailure());
        $jobs = app(CommitCheckJobs::class);
        $this->assertPrivateDataAbsent($jobs->read($job['id'], 'fixture'));
        $this->assertPrivateDataAbsent($jobs->latest('fixture'));
        $this->assertPrivateDataAbsent($jobs->cancel($job['id'], 'fixture'));
        foreach (['latest', $job['id']] as $endpoint) {
            $response = $this->getJson('/api/projects/fixture/commit-checks/'.$endpoint)->assertOk();
            $this->assertPrivateDataAbsent($response->json());
        }
        $this->assertSame(['sanitized' => 1, 'skipped' => 0], $jobs->sanitizeHistory());
        $saved = json_decode(file_get_contents($path.'.json'), true);
        $this->assertPrivateDataAbsent($saved);
        $this->assertSame('failed', $saved['status']);
        $this->assertTrue($saved['result']['cleanup']['successful']);
        $this->assertSame(2, $saved['total_tests']);
        $this->assertNull($saved['result']['checks'][0]['failures'][0]['file']);
        $this->assertSame('', file_get_contents($path.'.stdout'));
        $this->assertSame('', file_get_contents($path.'.stderr'));
    }

    public function test_worker_persistence_and_exception_messages_are_sanitized(): void
    {
        $reader = Mockery::mock(LocalProjectReader::class);
        $reader->shouldReceive('commitDiff')->andReturn(['commit' => ['sha' => str_repeat('b', 40)]]);
        $reader->shouldReceive('normalizeTestTarget')->andReturnNull();
        $reader->shouldReceive('runCommitChecks')->once()->andReturn($this->legacyJob()['result']);
        $jobs = new CommitCheckJobs($reader);
        $job = $jobs->start('fixture', 'bbbbbbb');
        $jobs->execute($job['id']);
        $this->assertPrivateDataAbsent(json_decode(file_get_contents($this->directory.'/'.$job['id'].'.json'), true));
        $reader->shouldReceive('runCommitChecks')->once()->andThrow(new \RuntimeException($this->rawFailure()));
        $second = $jobs->start('fixture', 'bbbbbbb');
        $jobs->execute($second['id']);
        $saved = $jobs->read($second['id']);
        $this->assertPrivateDataAbsent($saved);
        $this->assertSame('failed', $saved['status']);
    }

    public function test_legitimate_http_failure_and_successful_result_remain_useful(): void
    {
        $reports = new TestFailureReport;
        $this->assertSame('Resposta HTTP inesperada: esperado 200, recebido 500.', $reports->message('Expected response status code [200] but received 500. '.self::PRIVATE_VALUE));
        $safe = $reports->result(['checks' => [['name' => 'Testes Laravel', 'passed' => true, 'status' => 'passed', 'output' => self::PRIVATE_VALUE]], 'cleanup' => ['successful' => true]]);
        $this->assertPrivateDataAbsent($safe);
        $this->assertTrue($safe['checks'][0]['passed']);
        $this->assertSame([], $safe['checks'][0]['failures']);
        $this->assertTrue($safe['cleanup']['successful']);
    }

    public function test_timeout_retains_safe_cause_without_partial_process_output(): void
    {
        $safe = (new TestFailureReport)->result(['checks' => [['name' => 'Testes Laravel', 'passed' => false, 'status' => 'infrastructure_unavailable', 'cause' => 'test_timeout', 'output' => $this->rawFailure()]]]);
        $this->assertPrivateDataAbsent($safe);
        $this->assertSame('test_timeout', $safe['checks'][0]['cause']);
        $this->assertStringContainsString('limite de tempo', $safe['checks'][0]['explanation']);
        $this->assertFalse($safe['checks'][0]['passed']);
    }

    public function test_assertion_body_cannot_spoof_test_identifiers_in_live_or_legacy_reports(): void
    {
        $raw = "Test Failed (ExampleTest::test_response)\n<html>\nTest Failed (PrivatePerson::".self::PRIVATE_VALUE.")\nprivate content\nTest Prepared (PrivatePerson::".self::PRIVATE_VALUE.")\n</html>";
        $reports = new TestFailureReport;
        $this->assertPrivateDataAbsent($reports->progress('Testes Laravel', $raw));
        $this->assertPrivateDataAbsent($reports->result(['checks' => [['passed' => false, 'output' => $raw]]]));
        $this->assertPrivateDataAbsent($reports->job(['current_test' => 'PrivatePerson::'.self::PRIVATE_VALUE, 'live_failures' => [['test' => 'PrivatePerson::'.self::PRIVATE_VALUE, 'message' => $raw]]]));
        $fakeJson = json_encode(['failures' => [['test' => 'PrivatePerson::'.self::PRIVATE_VALUE, 'file' => 'tests/'.self::PRIVATE_VALUE.'.php', 'line' => 1, 'message' => $raw]]]);
        $this->assertPrivateDataAbsent($reports->result(['checks' => [['passed' => false, 'output' => $fakeJson]]]));
    }

    public function test_reader_progress_boundary_never_sends_raw_process_output_to_job_callback(): void
    {
        $reader = app(LocalProjectReader::class);
        $captured = null;
        (new \ReflectionProperty($reader, 'verificationProgress'))->setValue($reader, function (string $stage, array $progress) use (&$captured): void {
            $this->assertSame('Testes Laravel', $stage);
            $captured = $progress;
        });
        (new \ReflectionMethod($reader, 'reportVerification'))->invoke($reader, 'Testes Laravel', "Test Failed (ExampleTest::test_response)\n".$this->rawFailure());
        $this->assertIsArray($captured);
        $this->assertPrivateDataAbsent($captured);
        $this->assertSame(1, $captured['failed_tests']);
    }
}
