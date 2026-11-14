<div class="card">
    <h2><?= $e($t('Finish and lock')) ?></h2>
    <p><?= $e($t('When everything above is done, lock this page. It writes the file setup.lock at the root of Agorakit; delete that file to open the page again.')) ?></p>
    <form method="post" action="<?= $e($self) ?>">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="action" value="lock">
        <button type="submit"><?= $e($t('Lock the setup page')) ?></button>
    </form>
</div>
