<?php
$statusNames = ['ok' => $t('OK'), 'warning' => $t('Warning'), 'error' => $t('Error')];
?>
<div class="card">
    <h2><?= $e($t('Server')) ?></h2>
    <ul class="checks">
        <?php foreach ($checks as $check): ?>
            <li>
                <span class="status <?= $e($check['status']) ?>"><?= $e($statusNames[$check['status']]) ?></span>
                <?= $e($t($check['label'], $check['replace'] ?? [])) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <h2><?= $e($t('Scheduled tasks')) ?></h2>
    <p><?= $e($t('Agorakit sends its email summaries and reminders, and cleans up, through a task the server must run every few minutes. In the control panel of your host, create a scheduled task (cron) that runs this command every 5 minutes:')) ?></p>
    <p><code>php <?= $e($artisan) ?> schedule:run</code></p>
</div>

<div class="card">
    <h2><?= $e($t('Finish and lock')) ?></h2>
    <p><?= $e($t('When everything above is done, lock this page. It writes the file setup.lock at the root of Agorakit; delete that file to open the page again.')) ?></p>
    <form method="post" action="<?= $e($self) ?>">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="action" value="lock">
        <button type="submit"><?= $e($t('Lock the setup page')) ?></button>
    </form>
</div>
