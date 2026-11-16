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
 *
 * Every task of the schedule gets a result, run or not, so that cron.php can
 * tell a monitoring service what happened (see RunReport).
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
     * @return list<TaskResult>|null what became of each task of the schedule, or null when another call holds the lock
     */
    public function run(Schedule $schedule): ?array
    {
        $lock = $this->cache->lock(self::LOCK, 3600);
        if (!$lock->get()) {
            return null;
        }

        try {
            $results = [];
            foreach ($schedule->events() as $event) {
                $results[] = $this->handle($event, Carbon::now());
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs a task if it is due and nothing prevents it.
     */
    private function handle(Event $event, Carbon $now): TaskResult
    {
        $name = $this->name($event);

        if (!$event->runsInEnvironment($this->app->environment())) {
            return new TaskResult($name, TaskResult::SKIPPED, 'not run in the ' . $this->app->environment() . ' environment');
        }
        if (!$this->isDue($event, $now)) {
            return new TaskResult($name, TaskResult::NOT_DUE, 'next due at ' . $this->nextDue($event, $now));
        }
        // neither of these counts as a run: the task runs at the first call where they no longer apply
        if ($this->app->isDownForMaintenance() && !$event->runsInMaintenanceMode()) {
            return new TaskResult($name, TaskResult::SKIPPED, 'the site is in maintenance mode');
        }
        if (!$event->filtersPass($this->app)) {
            return new TaskResult($name, TaskResult::SKIPPED, 'its conditions are not met');
        }

        $start = microtime(true);
        [$status, $detail] = $this->runEvent($event);
        $seconds = microtime(true) - $start;
        $this->cache->forever($this->key($event), $now->getTimestamp());

        return $status === 0
            ? new TaskResult($name, TaskResult::OK, '', $seconds)
            : new TaskResult($name, TaskResult::FAILED, $detail, $seconds);
    }

    /**
     * Whether the task was due at some moment since its previous run here.
     */
    private function isDue(Event $event, Carbon $now): bool
    {
        $lastDue = $this->expression($event)->getPreviousRunDate($this->local($event, $now), 0, true);
        $lastRun = $this->cache->get($this->key($event), $now->getTimestamp() - self::FIRST_RUN_LOOKBACK);

        return $lastDue->getTimestamp() > $lastRun;
    }

    /**
     * When the task is due next, in its own time zone.
     */
    private function nextDue(Event $event, Carbon $now): string
    {
        return $this->expression($event)->getNextRunDate($this->local($event, $now))->format('Y-m-d H:i');
    }

    private function expression(Event $event): CronExpression
    {
        return new CronExpression($event->expression);
    }

    private function local(Event $event, Carbon $now): Carbon
    {
        return $now->copy()->setTimezone($event->timezone ?: config('app.timezone'));
    }

    /**
     * Runs one task in this process.
     *
     * @return array{int, string} the exit status, 0 when the task succeeded, and why it failed
     */
    private function runEvent(Event $event): array
    {
        try {
            if ($event instanceof CallbackEvent) {
                $event->run($this->app);
                $status = (int) ($event->exitCode ?? 0);

                return [$status, $status === 0 ? '' : 'exit code ' . $status];
            }

            $command = $this->artisanCommand((string) $event->command);
            if ($command === null) {
                return [1, 'not an artisan command: only a real crontab can run it'];
            }

            $status = $this->kernel->call($command);

            return [$status, $status === 0 ? '' : $this->failure($status, $this->kernel->output())];
        } catch (Throwable $e) {
            report($e);

            return [1, self::oneLine(class_basename($e) . ': ' . $e->getMessage())];
        }
    }

    /**
     * The exit status of a failed command, with the last thing it printed, which usually says why.
     */
    private function failure(int $status, string $output): string
    {
        $lines = array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), fn ($line) => $line !== '');
        $last = (string) end($lines);

        return 'exit code ' . $status . ($last === '' ? '' : ': ' . self::oneLine($last));
    }

    private static function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
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
