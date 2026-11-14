<?php
/**
 * Layout of the setup page. Plain PHP, since the setup page works before the
 * application is configured. Self-contained: no external style or script.
 *
 * @var callable $t translates a string
 * @var callable $e escapes a string for HTML
 */
?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($t('Agorakit setup')) ?></title>
    <style>
        :root { --ink: #1d273b; --muted: #667085; --line: #dce1e7; --primary: #206bc4; --ok: #2f9e44; --warn: #e67700; --bad: #d63939; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f6fa; color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 46rem; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: .5rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.6rem; margin: 0 0 1rem; }
        h2 { font-size: 1.2rem; margin: 0 0 .75rem; }
        h3 { font-size: 1rem; margin: 1.25rem 0 .5rem; }
        p { margin: 0 0 1rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        .lang { text-align: right; font-size: .9rem; margin-bottom: .5rem; }
        a { color: var(--primary); }
        label { display: block; font-weight: 600; margin: 1rem 0 .25rem; }
        input[type=text], input[type=password], input[type=email], input[type=url], input[type=number], select {
            width: 100%; padding: .5rem .75rem; border: 1px solid var(--line); border-radius: .375rem; font: inherit; background: #fff;
        }
        .check { display: flex; gap: .5rem; align-items: center; margin-top: 1rem; font-weight: 600; }
        .check input { margin: 0; }
        .help { color: var(--muted); font-size: .85rem; margin-top: .25rem; }
        button, .button { display: inline-block; margin-top: 1rem; padding: .55rem 1rem; border: 0; border-radius: .375rem; background: var(--primary); color: #fff; font: inherit; cursor: pointer; text-decoration: none; }
        button.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .flash { padding: .75rem 1rem; border-radius: .375rem; margin-bottom: 1rem; }
        .flash.success { background: #ebfbee; border: 1px solid #b2f2bb; }
        .flash.error { background: #fff5f5; border: 1px solid #ffc9c9; }
        .flash.warning { background: #fff9db; border: 1px solid #ffec99; }
        ul.checks { list-style: none; padding: 0; margin: 0; }
        ul.checks li { padding: .35rem 0; border-bottom: 1px solid var(--line); }
        ul.checks li:last-child { border-bottom: 0; }
        .status { display: inline-block; min-width: 4.5rem; font-weight: 700; }
        .status.ok { color: var(--ok); }
        .status.warning { color: var(--warn); }
        .status.error { color: var(--bad); }
        pre { background: #1d273b; color: #f1f3f5; padding: 1rem; border-radius: .375rem; overflow-x: auto; font-size: .85rem; white-space: pre-wrap; }
        code { background: #eef1f6; padding: .1rem .3rem; border-radius: .25rem; font-size: .9em; word-break: break-all; }
        fieldset { border: 0; padding: 0; margin: 0 0 1.5rem; }
        legend { font-size: 1.1rem; font-weight: 700; padding: 0; margin-bottom: .25rem; }
    </style>
</head>
<body>
<main>
    <div class="lang">
        <?php foreach (\App\Setup\SetupPage::LOCALES as $language): ?>
            <?php if ($language === $locale): ?>
                <strong><?= $e(strtoupper($language)) ?></strong>
            <?php else: ?>
                <a href="<?= $e($self . '?lang=' . $language) ?>"><?= $e(strtoupper($language)) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <h1><?= $e($t('Agorakit setup')) ?></h1>

    <?php foreach ($flash as $message): ?>
        <div class="flash <?= $e($message['type']) ?>" role="status"><?= $e($message['text']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
</main>
</body>
</html>
