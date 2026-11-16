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
    <h2><?= $e($t('Updating Agorakit')) ?></h2>
    <?php if ($update['installed'] !== null): ?>
        <p><?= $e($t('Installed version: :version', ['version' => $update['installed']])) ?></p>
    <?php endif; ?>
    <?php if ($update['maintenance']): ?>
        <div class="flash warning" role="status"><?= $e($t('The site is in maintenance: visitors get an error page until you lock this page.')) ?></div>
    <?php endif; ?>

    <?php if ($update['state'] !== null && $update['state']['applied']): ?>
        <ul class="checks">
            <li>
                <span class="status ok"><?= $e($t('OK')) ?></span>
                <?= $e($t('Version :tag is installed.', ['tag' => $update['state']['tag']])) ?>
            </li>
        </ul>
        <p><?= $e($t('Run the migrations below if there are any, then lock this page: the site goes back online.')) ?></p>
        <p class="muted"><?= $e($t('The previous version is kept in storage/framework/update/previous until the next update.')) ?></p>
    <?php elseif ($update['state'] !== null): ?>
        <p><?= $e($t('Version :tag is downloaded and checked. It needs PHP :php or later; this server runs PHP :current.', ['tag' => $update['state']['tag'], 'php' => $update['state']['php'], 'current' => PHP_VERSION])) ?></p>
        <p><?= $e($t('Installing puts the site in maintenance, then replaces the code of Agorakit in a few seconds. The .env file, the storage folder (uploaded files, sessions, logs) and every file the release does not contain stay as they are.')) ?></p>
        <p class="muted"><?= $e($t('For an upgrade, make a copy of the database first, from the control panel of your host.')) ?></p>
        <form method="post" action="<?= $e($self) ?>" style="display: inline">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="action" value="update-install">
            <button type="submit"><?= $e($t('Install version :tag', ['tag' => $update['state']['tag']])) ?></button>
        </form>
        <form method="post" action="<?= $e($self) ?>" style="display: inline">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="action" value="update-cancel">
            <button type="submit" class="secondary"><?= $e($t('Cancel')) ?></button>
        </form>
    <?php elseif ($update['unavailable'] !== null): ?>
        <p class="muted"><?= $e($t('Automatic updates are not available: :reason', ['reason' => $update['unavailable']])) ?></p>
    <?php else: ?>
        <?php if ($update['check'] !== null && $update['check']['newer']): ?>
            <p>
                <?= $e($t('Version :tag is available.', ['tag' => $update['check']['latest']])) ?>
                <a href="<?= $e($update['check']['notes']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($t('Release notes')) ?></a>
            </p>
            <form method="post" action="<?= $e($self) ?>">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="action" value="update-prepare">
                <input type="hidden" name="tag" value="<?= $e($update['check']['latest']) ?>">
                <button type="submit"><?= $e($t('Download and check')) ?></button>
            </form>
            <p class="muted"><?= $e($t('Nothing changes on the site at this step.')) ?></p>
        <?php else: ?>
            <?php if ($update['check'] !== null): ?>
                <ul class="checks">
                    <li>
                        <span class="status ok"><?= $e($t('OK')) ?></span>
                        <?= $e($t('Agorakit is up to date (latest release: :tag).', ['tag' => $update['check']['latest']])) ?>
                    </li>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?= $e($self) ?>">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="action" value="update-check">
                <button type="submit"><?= $e($t('Check for updates')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
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
    <p><?= $e($t('For instance on OVH web hosting, in "Scheduled tasks - CRON": run this file every hour, with PHP 8.3 or later, giving its path from the root of your FTP space (such as www/agorakit/public/cron.php). Summaries and reminders may then leave up to an hour late.')) ?></p>
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
