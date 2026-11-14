<?php

namespace Tests;

use App\Cron\ScheduleRunner;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

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

    private function runAt(string $time): ?array
    {
        Carbon::setTestNow(Carbon::parse($time, config('app.timezone')));

        return (new ScheduleRunner($this->app, $this->app->make(Kernel::class), $this->cache))->run($this->schedule);
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

        $this->runAt('2026-11-14 10:05');

        $this->assertArrayNotHasKey('never', $this->runs);
    }

    public function testArtisanCommandsRunInThisProcess()
    {
        $this->schedule->command('about')->everyMinute();

        $ran = $this->runAt('2026-11-14 10:05');

        $about = array_values(array_filter($ran, fn ($task) => str_contains($task['task'], 'about')));
        $this->assertCount(1, $about);
        $this->assertSame(0, $about[0]['status']);
    }

    public function testScheduleOfAgorakitIsReadable()
    {
        $commands = array_map(fn ($event) => $event->command, $this->app->make(Schedule::class)->events());

        $this->assertNotEmpty(array_filter($commands, fn ($command) => str_contains((string) $command, 'agorakit:sendreminders')));
    }
}
