<?php

namespace App\Setup;

use PDO;
use PDOException;

/**
 * The form of the setup page that writes the .env file: the values it shows,
 * and the checks of what is submitted. Error messages are translation keys.
 */
class EnvironmentForm
{
    /**
     * @param array<string, mixed> $server the request environment ($_SERVER)
     */
    public function __construct(private EnvironmentFile $file, private array $server, private string $locale)
    {
    }

    /**
     * Values to show: those of the current file, or defaults. Passwords are never shown.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $current = $this->file->values();
        $values = [];
        foreach (EnvironmentSchema::fields() as $key => $field) {
            $values[$key] = $field['type'] === EnvironmentSchema::PASSWORD
                ? ''
                : ($current[$key] ?? $this->defaultValue($field));
        }

        return $values;
    }

    /**
     * Whether the current file has a value for this option, for passwords left empty.
     */
    public function hasValue(string $key): bool
    {
        return ($this->file->values()[$key] ?? '') !== '';
    }

    /**
     * Checks a submitted form.
     *
     * @param array<string, mixed> $input the submitted options, by key
     *
     * @return array{0: array<string, string>, 1: array<string, string>} the values, and the errors by key
     */
    public function submit(array $input): array
    {
        $current = $this->file->values();
        $values = [];
        $errors = [];

        foreach (EnvironmentSchema::fields() as $key => $field) {
            if ($field['type'] === EnvironmentSchema::BOOLEAN) {
                $values[$key] = empty($input[$key]) ? 'false' : 'true';
                continue;
            }

            $value = trim((string) ($input[$key] ?? ''));
            // a password left empty keeps the current one
            if ($field['type'] === EnvironmentSchema::PASSWORD && $value === '') {
                $value = $current[$key] ?? '';
            }
            if ($field['type'] === EnvironmentSchema::URL) {
                $value = rtrim($value, '/');
            }

            $values[$key] = $value;
            $error = $this->error($field, $value);
            if ($error) {
                $errors[$key] = $error;
            }
        }

        // In production, Agorakit builds every link with https (AppServiceProvider).
        if (!isset($errors['APP_URL']) && $values['APP_ENV'] === 'production' && str_starts_with(strtolower($values['APP_URL']), 'http://')) {
            $errors['APP_URL'] = 'In production, Agorakit only works over HTTPS: the address must start with https://. '
                . 'To try it without HTTPS, choose the local environment.';
        }

        return [$values, $errors];
    }

    /**
     * Why the database cannot be reached with these options, or null if it can.
     *
     * @param array<string, string> $values
     */
    public static function databaseError(array $values): ?string
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $values['DB_HOST'] ?? '',
            (int) ($values['DB_PORT'] ?? 3306),
            $values['DB_DATABASE'] ?? ''
        );

        try {
            new PDO($dsn, $values['DB_USERNAME'] ?? '', $values['DB_PASSWORD'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function defaultValue(array $field): string
    {
        return match ($field['key']) {
            'APP_URL' => $this->siteUrl(),
            'APP_DEFAULT_LOCALE' => array_key_exists($this->locale, EnvironmentSchema::LOCALES) ? $this->locale : 'en',
            default => (string) ($field['default'] ?? ''),
        };
    }

    /**
     * The address of the site, guessed from the address of the setup page.
     */
    private function siteUrl(): string
    {
        $https = ($this->server['HTTPS'] ?? 'off') !== 'off' || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        $folder = rtrim(str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/setup.php'))), '/');

        return ($https ? 'https' : 'http') . '://' . $host . $folder;
    }

    /**
     * What is wrong with a value, as a translation key, or null.
     */
    private function error(array $field, string $value): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return 'Line breaks and control characters are not allowed.';
        }
        if ($value === '') {
            return empty($field['required']) ? null : 'This option is required.';
        }

        return match (true) {
            $field['key'] === 'TIME_ZONE' && !in_array($value, timezone_identifiers_list(), true)
                => 'Enter a time zone such as Europe/Paris.',
            $field['type'] === EnvironmentSchema::URL
                && (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value))
                => 'Enter a full address, starting with https://.',
            $field['type'] === EnvironmentSchema::EMAIL && !filter_var($value, FILTER_VALIDATE_EMAIL)
                => 'Enter a valid email address.',
            $field['type'] === EnvironmentSchema::NUMBER && !ctype_digit($value)
                => 'Enter a whole number.',
            $field['type'] === EnvironmentSchema::SELECT && !array_key_exists($value, $field['options'])
                => 'Choose one of the options.',
            default => null,
        };
    }
}
