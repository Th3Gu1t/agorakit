<?php

namespace App\Setup;

use RuntimeException;

/**
 * Who may use the setup page, and when.
 *
 * The page can write the configuration and change the database, from the web.
 * Two files, at the root of the installation, out of reach of the web server
 * when it serves the public folder as it must, guard it:
 *
 * - setup.lock: while it exists, the page refuses everything. It is written
 *   when the setup is finished; deleting it, through FTP or the file manager
 *   of the host, opens the page again, for an upgrade for instance.
 * - setup.key: a random key the page writes when it opens, and asks for.
 *   Only someone who can read the files of the installation knows it, so a
 *   visitor who finds the page open before its owner cannot use it. The key
 *   is deleted when the page locks, so each opening has a new one.
 */
class SetupGuard
{
    public function __construct(private string $lockFile, private string $keyFile)
    {
    }

    public static function forInstallation(string $basePath): self
    {
        return new self($basePath . '/setup.lock', $basePath . '/setup.key');
    }

    public function isLocked(): bool
    {
        return file_exists($this->lockFile);
    }

    /**
     * Writes the key file, unless it exists already.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function ensureKey(): void
    {
        if (file_exists($this->keyFile)) {
            return;
        }

        $key = bin2hex(random_bytes(16));
        if (@file_put_contents($this->keyFile, $key . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . $this->keyFile);
        }
        @chmod($this->keyFile, 0640);
    }

    public function checkKey(string $candidate): bool
    {
        if (!is_readable($this->keyFile)) {
            return false;
        }

        $key = trim((string) file_get_contents($this->keyFile));

        return $key !== '' && hash_equals($key, trim($candidate));
    }

    /**
     * Closes the page: writes the lock file and forgets the key.
     *
     * @throws RuntimeException when the lock file cannot be written
     */
    public function lock(): void
    {
        $content = 'Setup locked on ' . gmdate('Y-m-d H:i:s') . " UTC.\n"
            . "Delete this file to open the setup page again, for an upgrade.\n";

        if (@file_put_contents($this->lockFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . $this->lockFile);
        }
        @unlink($this->keyFile);
    }
}
