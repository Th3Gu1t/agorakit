<?php
$statusNames = ['ok' => $t('OK'), 'warning' => $t('Warning'), 'error' => $t('Error')];
?>
<?php
$attention = array_filter($checks, fn ($check) => $check['status'] !== 'ok');
$checkItem = function (array $check) use ($e, $t, $statusNames): string {
    return '<li><span class="status ' . $e($check['status']) . '">' . $e($statusNames[$check['status']]) . '</span> '
        . $e($t($check['label'], $check['replace'] ?? [])) . '</li>';
};
?>
<div class="card">
    <h2><?= $e($t('Server')) ?></h2>
    <?php if ($attention): ?>
        <ul class="checks">
            <?php foreach ($attention as $check): ?>
                <?= $checkItem($check) ?>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p><?= $e($t('The server offers everything Agorakit needs.')) ?></p>
    <?php endif; ?>
    <details>
        <summary><?= $e($t('All the checks (:count)', ['count' => count($checks)])) ?></summary>
        <ul class="checks">
            <?php foreach ($checks as $check): ?>
                <?= $checkItem($check) ?>
            <?php endforeach; ?>
        </ul>
    </details>
</div>

<div class="card">
    <h2><?= $e($t('Configuration')) ?></h2>
    <?php if (!$configured): ?>
        <p><?= $e($t('There is no .env file yet: set the options of the site, of the database and of emails.')) ?></p>
        <a class="button" href="<?= $e($self . '?step=environment') ?>"><?= $e($t('Configure')) ?></a>
    <?php else: ?>
        <ul class="checks">
            <li>
                <span class="status ok"><?= $e($t('OK')) ?></span>
                <?= $e($t('The configuration is in the .env file')) ?>
            </li>
            <li>
                <?php if ($databaseError === null): ?>
                    <span class="status ok"><?= $e($t('OK')) ?></span>
                    <?= $e($t('The database can be reached')) ?>
                <?php else: ?>
                    <span class="status error"><?= $e($t('Error')) ?></span>
                    <?= $e($t('The database cannot be reached: :error', ['error' => $databaseError])) ?>
                <?php endif; ?>
            </li>
        </ul>
        <a href="<?= $e($self . '?step=environment') ?>"><?= $e($t('Change the configuration')) ?></a>
    <?php endif; ?>
</div>

<?php if ($ready): ?>
    <div class="card">
        <h2><?= $e($t('Database')) ?></h2>
        <?php if ($applicationError !== null): ?>
            <div class="flash error" role="alert"><?= $e($t('Agorakit cannot start: :error', ['error' => $applicationError])) ?></div>
        <?php elseif ($pending === []): ?>
            <ul class="checks">
                <li>
                    <span class="status ok"><?= $e($t('OK')) ?></span>
                    <?= $e($t('The database is up to date')) ?>
                </li>
            </ul>
        <?php else: ?>
            <p><?= $e($t('Migrations to run, which create or change the tables of the database: :count.', ['count' => count($pending)])) ?></p>
            <details>
                <summary><?= $e($t('Show them')) ?></summary>
                <ul>
                    <?php foreach ($pending as $migration): ?>
                        <li><code><?= $e($migration) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <p class="muted"><?= $e($t('For an upgrade, make a copy of the database first, from the control panel of your host.')) ?></p>
            <form method="post" action="<?= $e($self) ?>">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="action" value="migrate">
                <button type="submit"><?= $e($t('Run the migrations')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($output): ?>
            <h3><?= $e($t('Last output')) ?></h3>
            <pre><?= $e($output) ?></pre>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($admins !== null): ?>
    <div class="card">
        <h2><?= $e($t('Administrator')) ?></h2>
        <?php if ($admins > 0): ?>
            <ul class="checks">
                <li>
                    <span class="status ok"><?= $e($t('OK')) ?></span>
                    <?= $e($t('An administrator account exists')) ?>
                </li>
            </ul>
        <?php else: ?>
            <p><?= $e($t('Create the administrator account of the site here. Otherwise the first person to register on the site would become its administrator.')) ?></p>
            <form method="post" action="<?= $e($self) ?>">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="action" value="admin">
                <?php
                $adminFields = [
                    'name' => [$t('Name'), 'text', 'name'],
                    'email' => [$t('Email'), 'email', 'email'],
                    'password' => [$t('Password'), 'password', 'new-password'],
                    'password_confirmation' => [$t('Password, again'), 'password', 'new-password'],
                ];
                ?>
                <?php foreach ($adminFields as $name => [$label, $type, $autocomplete]): ?>
                    <label for="admin-<?= $e($name) ?>"><?= $e($label) ?></label>
                    <input type="<?= $e($type) ?>" id="admin-<?= $e($name) ?>" name="<?= $e($name) ?>" required
                        autocomplete="<?= $e($autocomplete) ?>"
                        value="<?= $type === 'password' ? '' : $e($adminForm['values'][$name] ?? '') ?>">
                    <?php if (!empty($adminForm['errors'][$name])): ?>
                        <div class="help" style="color: var(--bad)" role="alert"><?= $e($t($adminForm['errors'][$name])) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button type="submit"><?= $e($t('Create the administrator account')) ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $e($t('Scheduled tasks')) ?></h2>
    <p><?= $e($t('Agorakit sends its email summaries and event reminders, and cleans up, through tasks that must run regularly. Set up one of these ways; using two of them does no harm, a task never runs twice.')) ?></p>

    <h3><?= $e($t('A web cron service (recommended on shared hosting)')) ?></h3>
    <p><?= $e($t('A free service such as cron-job.org calls this address every 5 minutes; summaries and reminders then leave on time.')) ?></p>
    <?php if ($cronUrl): ?>
        <p><code><?= $e($cronUrl) ?></code></p>
        <p class="muted"><?= $e($t('Keep this address to yourself: it contains the CRON_TOKEN of the .env file. Without it, the page answers 404 and runs nothing.')) ?></p>
    <?php else: ?>
        <p class="muted"><?= $e($t('Save the configuration to create this address.')) ?></p>
    <?php endif; ?>

    <h3><?= $e($t('The scheduled tasks of your host')) ?></h3>
    <p><?= $e($t('For instance on OVH web hosting, in "Scheduled tasks - CRON": run this file every hour, with PHP 8.2 or later, giving its path from the root of your FTP space (such as www/agorakit/public/cron.php). Summaries and reminders may then leave up to an hour late.')) ?></p>
    <p><code><?= $e($cronFile) ?></code></p>

    <h3><?= $e($t('A crontab')) ?></h3>
    <p><?= $e($t('If your host offers a real crontab, run this command every minute from the folder of Agorakit:')) ?></p>
    <p><code>php artisan schedule:run</code></p>
</div>

<?php
$unfinished = [];
if (!$configured) {
    $unfinished[] = $t('There is no configuration yet.');
} elseif (!$ready) {
    $unfinished[] = $t('The database cannot be reached.');
} elseif ($pending) {
    $unfinished[] = $t('Migrations are still to run.');
} elseif ($admins === 0) {
    $unfinished[] = $t('There is no administrator account yet.');
}
?>
<div class="card">
    <h2><?= $e($t('Finish and lock')) ?></h2>
    <p><?= $e($t('When everything above is done, lock this page. It writes the file setup.lock at the root of Agorakit; delete that file to open the page again.')) ?></p>
    <p class="muted"><?= $e($t('Locking also clears the caches of Agorakit, which an upgrade needs.')) ?></p>
    <?php foreach ($unfinished as $warning): ?>
        <div class="flash warning" role="status"><?= $e($warning) ?></div>
    <?php endforeach; ?>
    <form method="post" action="<?= $e($self) ?>">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="action" value="lock">
        <button type="submit"><?= $e($t('Lock the setup page')) ?></button>
    </form>
</div>
