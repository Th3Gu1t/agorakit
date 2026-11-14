<?php

namespace Tests;

use App\Setup\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * What the setup page checks on the server.
 */
class SetupRequirementsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agorakit-requirements-' . bin2hex(random_bytes(4));
        $folders = [
            'public', 'bootstrap/cache', 'storage/app', 'storage/framework/cache', 'storage/framework/sessions',
            'storage/framework/views', 'storage/logs',
        ];
        foreach ($folders as $folder) {
            mkdir($this->dir . '/' . $folder, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * The status of the check whose label is given.
     */
    private function statusOf(array $server, string $label, array $replace = []): ?string
    {
        foreach ((new Requirements($this->dir, $server))->check() as $check) {
            if ($check['label'] === $label && ($check['replace'] ?? []) == $replace) {
                return $check['status'];
            }
        }

        return null;
    }

    public function testThisServerHasTheRequiredExtensions()
    {
        $this->assertSame(Requirements::OK, $this->statusOf([], 'PHP extension :extension', ['extension' => 'pdo_mysql']));
        $this->assertSame(Requirements::OK, $this->statusOf([], 'PHP extension :extension', ['extension' => 'gd']));
    }

    public function testFoldersThatCannotBeWrittenAreErrors()
    {
        $requirements = new Requirements($this->dir, ['DOCUMENT_ROOT' => $this->dir . '/public', 'HTTPS' => 'on']);
        $this->assertTrue($requirements->pass());

        chmod($this->dir . '/storage/logs', 0500);

        $this->assertSame(Requirements::ERROR, $this->statusOf([], 'The folder :folder can be written to', ['folder' => 'storage/logs']));
        $this->assertFalse($requirements->pass());
    }

    public function testServingThePublicFolderIsRight()
    {
        $label = 'The web server serves the public folder only';

        $this->assertSame(Requirements::OK, $this->statusOf(['DOCUMENT_ROOT' => $this->dir . '/public/'], $label));
    }

    public function testServingTheWholeFolderIsAnError()
    {
        $label = 'The web server serves the whole Agorakit folder: anyone can download .env and setup.key. '
            . 'Make the domain point to the public folder.';

        $this->assertSame(Requirements::ERROR, $this->statusOf(['DOCUMENT_ROOT' => $this->dir], $label));
        $this->assertFalse((new Requirements($this->dir, ['DOCUMENT_ROOT' => $this->dir]))->pass());
    }

    public function testUnknownDocumentRootIsAWarning()
    {
        $label = 'Could not check which folder the web server serves: make sure the domain points to the public folder.';

        $this->assertSame(Requirements::WARNING, $this->statusOf(['DOCUMENT_ROOT' => '/srv/elsewhere'], $label));
        $this->assertSame(Requirements::WARNING, $this->statusOf([], $label));
    }

    public function testPlainHttpIsAWarning()
    {
        $this->assertSame(Requirements::OK, $this->statusOf(['HTTPS' => 'on'], 'The page is served over HTTPS'));
        $this->assertSame(Requirements::OK, $this->statusOf(['HTTP_X_FORWARDED_PROTO' => 'https'], 'The page is served over HTTPS'));
        $this->assertSame(
            Requirements::WARNING,
            $this->statusOf([], 'The page is not served over HTTPS: the key and the passwords you type travel unencrypted.')
        );
    }
}
