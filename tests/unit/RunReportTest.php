<?php

namespace Tests;

use App\Cron\RunReport;
use App\Cron\TaskResult;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The answer of cron.php, as cron-job.org and the scheduled tasks of a host read it.
 */
class RunReportTest extends TestCase
{
    private function report(?array $results): RunReport
    {
        return new RunReport($results, Carbon::parse('2026-11-16 12:35:02', 'Europe/Paris'));
    }

    public function testFailedTaskTurnsTheAnswerIntoAnError()
    {
        $report = $this->report([
            new TaskResult('agorakit:sendnotifications', TaskResult::OK, '', 0.421),
            new TaskResult('agorakit:checkmailbox', TaskResult::FAILED, 'exit code 1: Email checking needs the ddeboer/imap package', 0.05),
            new TaskResult('agorakit:sendreminders', TaskResult::NOT_DUE, 'next due at 2026-11-16 12:40'),
            new TaskResult('agorakit:cleanupdisk', TaskResult::SKIPPED, 'the site is in maintenance mode'),
        ]);

        $this->assertTrue($report->failed());
        $this->assertSame(500, $report->httpStatus());
        $this->assertSame(1, $report->exitCode());
        $this->assertSame(
            "Scheduled tasks of Agorakit, 2026-11-16 12:35:02 (Europe/Paris): "
            . "1 ok, 1 failed (agorakit:checkmailbox), 1 not due, 1 skipped.\n"
            . "\n"
            . "ok        agorakit:sendnotifications  0.42 s\n"
            . "failed    agorakit:checkmailbox       0.05 s  exit code 1: Email checking needs the ddeboer/imap package\n"
            . "not due   agorakit:sendreminders      next due at 2026-11-16 12:40\n"
            . "skipped   agorakit:cleanupdisk        the site is in maintenance mode\n",
            $report->text()
        );
    }

    public function testTasksThatDidNotRunAreNoError()
    {
        $report = $this->report([
            new TaskResult('agorakit:sendnotifications', TaskResult::OK, '', 0.1),
            new TaskResult('agorakit:sendreminders', TaskResult::NOT_DUE, 'next due at 2026-11-16 12:40'),
            new TaskResult('agorakit:cleanupdisk', TaskResult::SKIPPED, 'the site is in maintenance mode'),
        ]);

        $this->assertFalse($report->failed());
        $this->assertSame(200, $report->httpStatus());
        $this->assertSame(0, $report->exitCode());
        $this->assertStringContainsString(': 1 ok, 1 not due, 1 skipped.', $report->text());
    }

    public function testRunInProgressIsNoError()
    {
        $report = $this->report(null);

        $this->assertSame(200, $report->httpStatus());
        $this->assertSame(
            "Scheduled tasks of Agorakit, 2026-11-16 12:35:02 (Europe/Paris): another run is in progress, nothing was run.\n",
            $report->text()
        );
    }

    public function testEmptySchedule()
    {
        $this->assertStringEndsWith(": no task is scheduled.\n\n", $this->report([])->text());
    }
}
