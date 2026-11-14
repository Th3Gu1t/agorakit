<?php

/*
 * The setup page: installs or upgrades Agorakit from a browser, on hosts where
 * no command can be run. See App\Setup\SetupPage.
 *
 * Once the setup is finished, the file setup.lock at the root of Agorakit
 * closes this page; deleting that file opens it again.
 */

$basePath = dirname(__DIR__);

// Checked first, in plain words: the dependencies would fail with less helpful errors.
if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Agorakit needs PHP 8.2 or later; this server runs PHP ' . PHP_VERSION . '.');
}
if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('The vendor folder of Agorakit is missing: upload a release archive, which includes it.');
}

require $basePath . '/vendor/autoload.php';

$https = ($_SERVER['HTTPS'] ?? 'off') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('agorakit_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$page = new App\Setup\SetupPage(
    $basePath,
    App\Setup\SetupGuard::forInstallation($basePath),
    new App\Setup\EnvironmentFile($basePath . '/.env'),
    $_SESSION
);
$response = $page->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET, $_POST, $_SERVER);

if ($response->regenerateSession) {
    session_regenerate_id(true);
}
$response->send();
