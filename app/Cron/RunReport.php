<?php

namespace App\Cron;

use Carbon\CarbonInterface;

/**
 * The answer of cron.php: a summary line, then one line per task of the
 * schedule, and a status that says whether a task failed.
 *
 * Monitoring services such as cron-job.org alert on an HTTP status other than
 * 2xx and show the start of the answer, hence the summary first, naming the
 * tasks that failed. The scheduled tasks of a host look at the exit code.
 */
final class RunReport
{
    /**
     * @param list<TaskResult>|null $results what ScheduleRunner::run() returned: null when another call was running
     */
    public function __construct(private ?array $results, private CarbonInterface $time)
    {
    }

    public function failed(): bool
    {
        foreach ($this->results ?? [] as $result) {
            if ($result->failed()) {
                return true;
            }
        }

        return false;
    }

    public function httpStatus(): int
    {
        return $this->failed() ? 500 : 200;
    }

    public function exitCode(): int
    {
        return $this->failed() ? 1 : 0;
    }

    public function text(): string
    {
        $when = $this->time->format('Y-m-d H:i:s') . ' (' . $this->time->getTimezone()->getName() . ')';
        if ($this->results === null) {
            return "Scheduled tasks of Agorakit, $when: another run is in progress, nothing was run.\n";
        }

        return "Scheduled tasks of Agorakit, $when: " . $this->summary() . "\n\n" . $this->lines();
    }

    /**
     * How many tasks got each result, with the names of those that failed.
     */
    private function summary(): string
    {
        if ($this->results === []) {
            return 'no task is scheduled.';
        }

        $parts = [];
        foreach ([TaskResult::OK, TaskResult::FAILED, TaskResult::NOT_DUE, TaskResult::SKIPPED] as $kind) {
            $tasks = array_filter($this->results, fn (TaskResult $result) => $result->result === $kind);
            if (!$tasks) {
                continue;
            }
            $part = count($tasks) . ' ' . $kind;
            if ($kind === TaskResult::FAILED) {
                $part .= ' (' . implode(', ', array_map(fn (TaskResult $result) => $result->task, $tasks)) . ')';
            }
            $parts[] = $part;
        }

        return implode(', ', $parts) . '.';
    }

    private function lines(): string
    {
        $width = 0;
        foreach ($this->results as $result) {
            $width = max($width, mb_strlen($result->task));
        }

        $lines = '';
        foreach ($this->results as $result) {
            $details = array_filter([
                $result->seconds === null ? '' : sprintf('%.2f s', $result->seconds),
                $result->detail,
            ]);
            $line = str_pad($result->result, 8) . '  ' . $result->task
                . ($details ? str_repeat(' ', $width - mb_strlen($result->task)) . '  ' . implode('  ', $details) : '');
            $lines .= rtrim($line) . "\n";
        }

        return $lines;
    }
}
