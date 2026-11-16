<?php

namespace App\Cron;

/**
 * What became of one scheduled task during a call to cron.php.
 */
final class TaskResult
{
    /** The task ran and succeeded. */
    public const OK = 'ok';

    /** The task ran and failed: an exit status other than 0, or an exception. */
    public const FAILED = 'failed';

    /** The task was not due since its previous run. */
    public const NOT_DUE = 'not due';

    /** The task was due but did not run: maintenance mode, environment or its own conditions. */
    public const SKIPPED = 'skipped';

    /**
     * @param string $task a short name: the description of the task or its artisan command
     * @param string $result one of the constants above
     * @param string $detail why it failed or did not run, or when it is due next
     * @param float|null $seconds how long it ran, when it ran
     */
    public function __construct(
        public readonly string $task,
        public readonly string $result,
        public readonly string $detail = '',
        public readonly ?float $seconds = null,
    ) {
    }

    public function failed(): bool
    {
        return $this->result === self::FAILED;
    }
}
