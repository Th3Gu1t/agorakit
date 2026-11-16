<?php

/*
 * Runs the scheduled tasks of Agorakit (email summaries, event reminders,
 * cleanups) on hosts without a crontab for "php artisan schedule:run".
 * See App\Cron\ScheduleRunner.
 *
 * Two ways to use it:
 * - a scheduled task of the host (OVH: "Tâches planifiées - CRON") runs this
 *   file with PHP: give its path, for instance www/agorakit/public/cron.php;
 * - a web cron service calls https://<site>/cron.php?token=<CRON_TOKEN>, with
 *   the CRON_TOKEN of the .env file. Without the right token, the page answers
 *   404 and runs nothing.
 *
 * Either way, it lists every scheduled task with what became of it (see
 * App\Cron\RunReport). When a task fails, the web answer has the status 500,
 * so that the cron service can alert, and the command exits with 1.
 */

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

$app = require $basePath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$web = PHP_SAPI !== 'cli';

if ($web) {
    $token = (string) config('agorakit.cron_token');
    if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit("Not found\n");
    }

    // the tasks go on even if the caller stops waiting
    ignore_user_abort(true);
    @set_time_limit(0);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

$runner = new App\Cron\ScheduleRunner($app, $kernel, $app->make('cache.store'));
$report = new App\Cron\RunReport(
    $runner->run($app->make(Illuminate\Console\Scheduling\Schedule::class)),
    Carbon\Carbon::now()
);

if ($web) {
    http_response_code($report->httpStatus());
}
echo $report->text();

exit($report->exitCode());
