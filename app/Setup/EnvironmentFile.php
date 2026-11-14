<?php

namespace App\Setup;

use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and writes the .env file of the installation.
 *
 * Writing keeps the options the setup page does not know, keeps APP_KEY or
 * generates it, and quotes values so that Laravel reads back exactly what was
 * written. The previous file is kept as .env.backup.
 */
class EnvironmentFile
{
    public function __construct(private string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * The options of the file, as Laravel reads them, or none if there is no file.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        if (!$this->exists()) {
            return [];
        }

        return array_map('strval', Dotenv::parse((string) file_get_contents($this->path)));
    }

    /**
     * Writes the file with these options, on top of the options already in it.
     *
     * @param array<string, string> $values
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function write(array $values): void
    {
        $values = array_merge($this->values(), $values);
        foreach (array_keys(EnvironmentSchema::fields()) as $key) {
            $values[$key] ??= '';
        }
        if (($values['APP_KEY'] ?? '') === '') {
            $values['APP_KEY'] = self::generateKey();
        }

        $content = self::render($values);
        if (Dotenv::parse($content) != $values) {
            throw new RuntimeException('The .env file would not read back as written.');
        }

        if ($this->exists() && !@copy($this->path, dirname($this->path) . '/.env.backup')) {
            throw new RuntimeException('Cannot keep a copy of the current .env file.');
        }

        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . $this->path);
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write ' . $this->path);
        }
    }

    /**
     * A key for the encryption of Laravel, as "php artisan key:generate" makes it.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * The content of a .env file: the options of the setup page in its order
     * and sections, then APP_KEY, then the options it does not know.
     *
     * @param array<string, string> $values
     */
    public static function render(array $values): string
    {
        $lines = ['# Written by the setup page of Agorakit (public/setup.php) on ' . gmdate('Y-m-d H:i') . ' UTC.'];
        $written = [];

        foreach (EnvironmentSchema::sections() as $section) {
            $lines[] = '';
            $lines[] = '# ' . $section['title'];
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                $lines[] = $key . '=' . self::quote($values[$key] ?? '');
                $written[$key] = true;
            }
        }

        $lines[] = '';
        $lines[] = '# Encryption key: changing it logs everyone out and makes encrypted data unreadable';
        $lines[] = 'APP_KEY=' . self::quote($values['APP_KEY'] ?? '');
        $written['APP_KEY'] = true;

        $others = array_diff_key($values, $written);
        if ($others) {
            $lines[] = '';
            $lines[] = '# Other options, kept as they were';
            foreach ($others as $key => $value) {
                $lines[] = $key . '=' . self::quote($value);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A value as it must be written for Laravel to read it back unchanged.
     *
     * @throws InvalidArgumentException for values that cannot be written on one line
     */
    public static function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Values cannot contain line breaks or control characters.');
        }
        if (preg_match('#^[A-Za-z0-9_./:@,+-]*$#', $value)) {
            return $value;
        }

        // In double quotes, dotenv reads \\ \" and \$ back as \ " and $, and nothing else is special.
        return '"' . addcslashes($value, '\\"$') . '"';
    }
}
