<?php

namespace App\Cron;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Runs the scheduled tasks of Agorakit for hosts that cannot run
 * "php artisan schedule:run" every minute.
 *
 * Shared hosts offer at best a scheduled task once an hour at a minute of
 * their choice, or a web cron service calling a page every few minutes.
 * schedule:run only runs the tasks due at the current minute, so called at
 * 02:17 it would skip a daily task due at 00:00 for ever. This runner instead
 * runs each task when it was due at any moment since its previous run here.
 *
 * Commands run in this PHP process, through the console kernel: from a web
 * request, schedule:run would start new "php artisan" processes, which shared
 * hosts often do not allow. A lock keeps two calls from running together.
 */
class ScheduleRunner
{
    private const LOCK = 'agorakit:schedule-runner';

    /**
     * How far back the first run looks for due tasks.
     */
    private const FIRST_RUN_LOOKBACK = 3600;

    public function __construct(private Application $app, private Kernel $kernel, private Cache $cache)
    {
    }

    /**
     * Runs the tasks of the schedule that are due.
     *
     * @return list<array{task: string, status: int}>|null the tasks run, or null when another call holds the lock
     */
    public function run(Schedule $schedule): ?array
    {
        $lock = $this->cache->lock(self::LOCK, 3600);
        if (!$lock->get()) {
            return null;
        }

        try {
            $ran = [];
            foreach ($schedule->events() as $event) {
                $now = Carbon::now();
                if (!$this->isDue($event, $now)) {
                    continue;
                }
                $ran[] = ['task' => $this->name($event), 'status' => $this->runEvent($event)];
                $this->cache->forever($this->key($event), $now->getTimestamp());
            }

            return $ran;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the task was due at some moment since its previous run here.
     */
    private function isDue(Event $event, Carbon $now): bool
    {
        if (!$event->runsInEnvironment($this->app->environment()) || !$event->filtersPass($this->app)) {
            return false;
        }
        if ($this->app->isDownForMaintenance() && !$event->runsInMaintenanceMode()) {
            return false;
        }

        $timezone = $event->timezone ?: config('app.timezone');
        $lastDue = (new CronExpression($event->expression))
            ->getPreviousRunDate($now->copy()->setTimezone($timezone), 0, true);
        $lastRun = $this->cache->get($this->key($event), $now->getTimestamp() - self::FIRST_RUN_LOOKBACK);

        return $lastDue->getTimestamp() > $lastRun;
    }

    /**
     * Runs one task in this process.
     *
     * @return int the exit status, 0 when the task succeeded
     */
    private function runEvent(Event $event): int
    {
        try {
            if ($event instanceof CallbackEvent) {
                $event->run($this->app);

                return (int) ($event->exitCode ?? 0);
            }

            $command = $this->artisanCommand((string) $event->command);
            if ($command === null) {
                // a shell command: only a real crontab can run it
                return 1;
            }

            return $this->kernel->call($command);
        } catch (Throwable $e) {
            report($e);

            return 1;
        }
    }

    /**
     * The artisan command line of a scheduled command, without the PHP and artisan binaries.
     */
    private function artisanCommand(string $command): ?string
    {
        $prefix = Artisan::phpBinary() . ' ' . Artisan::artisanBinary() . ' ';

        return str_starts_with($command, $prefix) ? substr($command, strlen($prefix)) : null;
    }

    /**
     * A short name of the task: its description, or its artisan command.
     */
    private function name(Event $event): string
    {
        return $event->description
            ?: $this->artisanCommand((string) $event->command)
            ?? $event->getSummaryForDisplay();
    }

    private function key(Event $event): string
    {
        return 'agorakit:schedule-runner:' . $event->mutexName();
    }
}
