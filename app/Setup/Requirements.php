<?php

namespace App\Setup;

/**
 * What the server must offer to run Agorakit, checked by the setup page.
 */
class Requirements
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * Extensions Laravel or Agorakit cannot run without (gd: images and avatars).
     */
    private const REQUIRED_EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pdo_mysql', 'session', 'tokenizer', 'xml',
    ];

    /**
     * Extensions some features need: zip for group export and import, curl for faster web requests.
     */
    private const OPTIONAL_EXTENSIONS = ['curl', 'zip'];

    /**
     * Folders the application writes to, relative to its root.
     */
    private const WRITABLE = [
        '.',
        'bootstrap/cache',
        'storage',
        'storage/app',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ];

    /**
     * @param array<string, mixed> $server the request environment ($_SERVER)
     */
    public function __construct(private string $basePath, private array $server)
    {
    }

    /**
     * @return list<array{status: string, label: string, replace?: array<string, string>}>
     *         labels are translation keys, with their replacements
     */
    public function check(): array
    {
        return array_merge(
            [$this->php()],
            $this->extensions(),
            $this->postByEmail(),
            $this->writableFolders(),
            [$this->documentRoot(), $this->https()],
        );
    }

    /**
     * Whether nothing stops the installation.
     */
    public function pass(): bool
    {
        foreach ($this->check() as $check) {
            if ($check['status'] === self::ERROR) {
                return false;
            }
        }

        return true;
    }

    private function php(): array
    {
        return [
            'status' => PHP_VERSION_ID >= 80200 ? self::OK : self::ERROR,
            'label' => 'PHP :version (8.2 or later is needed)',
            'replace' => ['version' => PHP_VERSION],
        ];
    }

    private function extensions(): array
    {
        $checks = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'status' => extension_loaded($extension) ? self::OK : self::ERROR,
                'label' => 'PHP extension :extension',
                'replace' => ['extension' => $extension],
            ];
        }
        foreach (self::OPTIONAL_EXTENSIONS as $extension) {
            $checks[] = [
                'status' => extension_loaded($extension) ? self::OK : self::WARNING,
                'label' => 'PHP extension :extension (optional)',
                'replace' => ['extension' => $extension],
            ];
        }

        return $checks;
    }

    /**
     * The IMAP extension, once posting by email is set up. PHP no longer ships it
     * since 8.4, so a host may offer it with some PHP versions and not others.
     *
     * @return list<array{status: string, label: string}>
     */
    private function postByEmail(): array
    {
        $driver = strtolower((new EnvironmentFile($this->basePath . '/.env'))->values()['INBOX_DRIVER'] ?? '');
        if (in_array($driver, ['', 'null', '(null)'], true)) {
            return [];
        }

        return [extension_loaded('imap')
            ? ['status' => self::OK, 'label' => 'PHP extension imap, which posting by email needs']
            : [
                'status' => self::WARNING,
                'label' => 'PHP extension imap is missing: posting by email is set up but cannot work. '
                    . 'Choose a PHP version that has it in the control panel of your host.',
            ]];
    }

    private function writableFolders(): array
    {
        $checks = [];
        foreach (self::WRITABLE as $folder) {
            $path = $folder === '.' ? $this->basePath : $this->basePath . '/' . $folder;
            $checks[] = [
                'status' => is_dir($path) && is_writable($path) ? self::OK : self::ERROR,
                'label' => $folder === '.' ? 'The root folder of Agorakit can be written to' : 'The folder :folder can be written to',
                'replace' => ['folder' => $folder],
            ];
        }

        return $checks;
    }

    /**
     * The web server must serve the public folder only: served from the root,
     * .env and setup.key could be downloaded by anyone.
     */
    private function documentRoot(): array
    {
        $documentRoot = realpath((string) ($this->server['DOCUMENT_ROOT'] ?? '')) ?: null;

        if ($documentRoot === realpath($this->basePath . '/public')) {
            return ['status' => self::OK, 'label' => 'The web server serves the public folder only'];
        }
        if ($documentRoot === realpath($this->basePath)) {
            return [
                'status' => self::ERROR,
                'label' => 'The web server serves the whole Agorakit folder: anyone can download .env and setup.key. '
                    . 'Make the domain point to the public folder.',
            ];
        }

        return [
            'status' => self::WARNING,
            'label' => 'Could not check which folder the web server serves: make sure the domain points to the public folder.',
        ];
    }

    private function https(): array
    {
        $https = ($this->server['HTTPS'] ?? 'off') !== 'off' || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return $https
            ? ['status' => self::OK, 'label' => 'The page is served over HTTPS']
            : [
                'status' => self::WARNING,
                'label' => 'The page is not served over HTTPS: the key and the passwords you type travel unencrypted.',
            ];
    }
}
