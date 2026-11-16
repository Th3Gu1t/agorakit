<?php

namespace Tests;

use App\Cron\ScheduleRunner;
use App\Cron\TaskResult;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

/**
 * Scheduled tasks run by cron.php, called rarely or irregularly.
 */
class ScheduleRunnerTest extends BrowserKitTestCase
{
    private Repository $cache;
    private Schedule $schedule;

    /**
     * Times each task ran at, by name.
     *
     * @var array<string, list<string>>
     */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore());
        $this->schedule = new Schedule(config('app.timezone'));
        foreach (['everyFiveMinutes', 'hourly', 'daily'] as $frequency) {
            $this->schedule->call(function () use ($frequency) {
                $this->runs[$frequency][] = Carbon::now()->format('Y-m-d H:i');
            })->name($frequency)->{$frequency}();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<TaskResult>|null
     */
    private function runAt(string $time): ?array
    {
        Carbon::setTestNow(Carbon::parse($time, config('app.timezone')));

        return (new ScheduleRunner($this->app, $this->app->make(Kernel::class), $this->cache))->run($this->schedule);
    }

    /**
     * The result of one task in what runAt() returned.
     *
     * @param list<TaskResult> $results
     */
    private function resultOf(array $results, string $task): TaskResult
    {
        $matching = array_values(array_filter($results, fn (TaskResult $result) => str_contains($result->task, $task)));
        $this->assertCount(1, $matching, "one result for $task");

        return $matching[0];
    }

    public function testHourlyCallsAtAnOddMinuteRunEveryTask()
    {
        // the scheduled task of a shared host: once an hour, at a minute it chooses
        foreach (['2026-11-14 22:17', '2026-11-14 23:17', '2026-11-15 00:17', '2026-11-15 01:17'] as $time) {
            $this->runAt($time);
        }

        $this->assertCount(4, $this->runs['everyFiveMinutes']);
        // hourly: the first call catches up on 22:00, then each call runs it
        $this->assertSame(['2026-11-14 22:17', '2026-11-14 23:17', '2026-11-15 00:17', '2026-11-15 01:17'], $this->runs['hourly']);
        // daily: due at midnight, run once, at the first call after it
        $this->assertSame(['2026-11-15 00:17'], $this->runs['daily']);
    }

    public function testCallsEveryFiveMinutesRunTasksOnTime()
    {
        foreach (['2026-11-14 23:50', '2026-11-14 23:55', '2026-11-15 00:00', '2026-11-15 00:05'] as $time) {
            $this->runAt($time);
        }

        $this->assertSame(
            ['2026-11-14 23:50', '2026-11-14 23:55', '2026-11-15 00:00', '2026-11-15 00:05'],
            $this->runs['everyFiveMinutes']
        );
        // hourly: the first call catches up on 23:00, then it runs on time
        $this->assertSame(['2026-11-14 23:50', '2026-11-15 00:00'], $this->runs['hourly']);
        $this->assertSame(['2026-11-15 00:00'], $this->runs['daily']);
    }

    public function testTwoCallsInTheSameMinuteRunTasksOnce()
    {
        $this->runAt('2026-11-14 10:05:10');
        $this->runAt('2026-11-14 10:05:40');

        $this->assertCount(1, $this->runs['everyFiveMinutes']);
    }

    public function testFirstCallOnlyLooksAnHourBack()
    {
        $this->runAt('2026-11-14 15:30');

        // the daily task was due at midnight, long before the first call
        $this->assertArrayNotHasKey('daily', $this->runs);
        $this->assertSame(['2026-11-14 15:30'], $this->runs['hourly']);
    }

    public function testCallWhileAnotherRunsDoesNothing()
    {
        $lock = $this->cache->lock('agorakit:schedule-runner', 60);
        $lock->get();

        $this->assertNull($this->runAt('2026-11-14 10:05'));
        $this->assertSame([], $this->runs);

        $lock->release();
        $this->assertNotNull($this->runAt('2026-11-14 10:10'));
        $this->assertSame(['2026-11-14 10:10'], $this->runs['everyFiveMinutes']);
    }

    public function testFiltersAreHonoured()
    {
        $this->schedule->call(function () {
            $this->runs['never'][] = 'ran';
        })->name('never')->everyMinute()->when(fn () => false);

        $results = $this->runAt('2026-11-14 10:05');

        $this->assertArrayNotHasKey('never', $this->runs);
        $never = $this->resultOf($results, 'never');
        $this->assertSame(TaskResult::SKIPPED, $never->result);
        $this->assertSame('its conditions are not met', $never->detail);
    }

    public function testArtisanCommandsRunInThisProcess()
    {
        $this->schedule->command('about')->everyMinute();

        $about = $this->resultOf($this->runAt('2026-11-14 10:05'), 'about');

        $this->assertSame(TaskResult::OK, $about->result);
        $this->assertNotNull($about->seconds);
    }

    public function testEveryTaskGetsAResult()
    {
        $this->runAt('2026-11-14 10:00');

        $results = $this->runAt('2026-11-14 10:05');

        $this->assertCount(3, $results);
        $this->assertSame(TaskResult::OK, $this->resultOf($results, 'everyFiveMinutes')->result);
        $hourly = $this->resultOf($results, 'hourly');
        $this->assertSame(TaskResult::NOT_DUE, $hourly->result);
        $this->assertSame('next due at 2026-11-14 11:00', $hourly->detail);
        $this->assertSame('next due at 2026-11-15 00:00', $this->resultOf($results, 'daily')->detail);
    }

    public function testFailedCommandGivesItsLastMessage()
    {
        $this->app->make(Kernel::class)->registerCommand(new class extends Command {
            protected $signature = 'test:fails';

            public function handle(): int
            {
                $this->info('Checking the mailbox');
                $this->error('The mailbox cannot be reached');

                return 2;
            }
        });
        $this->schedule->command('test:fails')->everyMinute();

        $failed = $this->resultOf($this->runAt('2026-11-14 10:05'), 'test:fails');

        $this->assertSame(TaskResult::FAILED, $failed->result);
        $this->assertSame('exit code 2: The mailbox cannot be reached', $failed->detail);
    }

    public function testExceptionIsReportedAndOtherTasksStillRun()
    {
        $this->schedule->call(function () {
            throw new RuntimeException("Class \"Ddeboer\\Imap\\Server\" not found\nwith a second line");
        })->name('crashes')->everyMinute();

        $results = $this->runAt('2026-11-14 10:05');

        $crashes = $this->resultOf($results, 'crashes');
        $this->assertSame(TaskResult::FAILED, $crashes->result);
        $this->assertSame('RuntimeException: Class "Ddeboer\\Imap\\Server" not found with a second line', $crashes->detail);
        $this->assertSame(TaskResult::OK, $this->resultOf($results, 'everyFiveMinutes')->result);
    }

    public function testMaintenanceModeDelaysDueTasks()
    {
        $down = storage_path('framework/down');
        file_put_contents($down, json_encode(['status' => 503]));
        try {
            $results = $this->runAt('2026-11-14 10:05');
        } finally {
            unlink($down);
        }

        $this->assertSame([], $this->runs);
        $skipped = $this->resultOf($results, 'everyFiveMinutes');
        $this->assertSame(TaskResult::SKIPPED, $skipped->result);
        $this->assertSame('the site is in maintenance mode', $skipped->detail);

        // the tasks it held back run at the first call after it
        $this->runAt('2026-11-14 10:07');
        $this->assertSame(['2026-11-14 10:07'], $this->runs['everyFiveMinutes']);
    }

    public function testTaskOfAnotherEnvironmentIsSkipped()
    {
        $this->schedule->call(fn () => null)->name('staging only')->everyMinute()->environments(['staging']);

        $result = $this->resultOf($this->runAt('2026-11-14 10:05'), 'staging only');

        $this->assertSame(TaskResult::SKIPPED, $result->result);
        $this->assertStringStartsWith('not run in the ', $result->detail);
    }

    public function testScheduleOfAgorakitIsReadable()
    {
        $commands = array_map(fn ($event) => $event->command, $this->app->make(Schedule::class)->events());

        $this->assertNotEmpty(array_filter($commands, fn ($command) => str_contains((string) $command, 'agorakit:sendreminders')));
    }
}
