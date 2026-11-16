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

// On hosts that display errors, PHP prints notices and deprecations as they
// happen: they would go out before the status of the answer, which then could
// no longer say that a task failed. They are held back and written after the
// report, for the person who reads it.
ob_start();

require $basePath . '/vendor/autoload.php';

$app = require $basePath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$web = PHP_SAPI !== 'cli';

if ($web) {
    $token = (string) config('agorakit.cron_token');
    if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        // nothing of the server for a caller without the token
        ob_end_clean();
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

$printed = trim(strip_tags((string) ob_get_clean()));
if ($web) {
    http_response_code($report->httpStatus());
}
echo $report->text();
if ($printed !== '') {
    echo "\nPHP printed meanwhile:\n" . $printed . "\n";
}

exit($report->exitCode());
