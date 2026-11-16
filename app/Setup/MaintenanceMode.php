<?php

namespace App\Setup;

/**
 * Laravel's maintenance mode, without Laravel: while the update replaces the
 * code, the application cannot be started to run "php artisan down".
 *
 * Writes the same file as that command, storage/framework/down: the site then
 * answers 503 to every request. The setup page and cron.php keep working: the
 * first does not go through the HTTP kernel, the second skips its tasks.
 */
class MaintenanceMode
{
    public function __construct(private string $basePath)
    {
    }

    public function isOn(): bool
    {
        return file_exists($this->downFile());
    }

    public function on(): void
    {
        $payload = [
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => 60,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ];
        if (@file_put_contents($this->downFile(), json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new UpdateException('Cannot write :file.', ['file' => $this->downFile()]);
        }
    }

    public function off(): void
    {
        @unlink($this->downFile());
        // the pre-rendered page "php artisan down --render" may have written
        @unlink($this->basePath . '/storage/framework/maintenance.php');
    }

    private function downFile(): string
    {
        return $this->basePath . '/storage/framework/down';
    }
}
