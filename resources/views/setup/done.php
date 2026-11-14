<div class="card">
    <h2><?= $e($t('The setup page is locked')) ?></h2>
    <p><?= $e($t('The file setup.lock now closes this page. To open it again, for an upgrade for instance, delete that file at the root of Agorakit.')) ?></p>
    <a class="button" href="<?= $e(rtrim(dirname($self), '/') . '/') ?>"><?= $e($t('Go to the site')) ?></a>
</div>
