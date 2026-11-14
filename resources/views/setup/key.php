<div class="card">
    <h2><?= $e($t('Prove that you manage this server')) ?></h2>
    <p><?= $e($t('This page can change the configuration and the database of Agorakit, so it asks for a key that only someone with access to the files of the installation can read.')) ?></p>
    <p><?= $e($t('Open the file setup.key at the root of Agorakit, next to the public folder, through FTP or the file manager of your host, and copy its content here.')) ?></p>

    <?php if (!empty($error)): ?>
        <div class="flash error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($self) ?>">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="action" value="key">
        <label for="key"><?= $e($t('Key')) ?></label>
        <input type="password" id="key" name="key" autocomplete="off" required autofocus>
        <button type="submit"><?= $e($t('Continue')) ?></button>
    </form>
</div>
