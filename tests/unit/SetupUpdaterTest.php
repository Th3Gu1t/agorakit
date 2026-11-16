<?php

namespace Tests;

use App\Setup\EnvironmentFile;
use App\Setup\HttpClient;
use App\Setup\SetupGuard;
use App\Setup\SetupPage;
use App\Setup\Updater;
use App\Setup\UpdateException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * The update of the code from the setup page, on a fake installation and fake
 * GitHub releases.
 */
class SetupUpdaterTest extends TestCase
{
    private const REPOSITORY = 'https://github.com/example/agorakit';

    private string $dir;
    private string $install;
    private string $releases;

    /**
     * Answers like GitHub, from the files of $this->releases.
     */
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agorakit-update-' . bin2hex(random_bytes(4));
        $this->install = $this->dir . '/install';
        $this->releases = $this->dir . '/releases';
        mkdir($this->releases, 0755, true);

        $this->http = new class ($this->releases) extends HttpClient {
            public ?string $latest = null;
            public array $downloads = [];

            public function __construct(private string $releases)
            {
                parent::__construct();
            }

            public function location(string $url): ?string
            {
                return $this->latest;
            }

            public function download(string $url, string $file): void
            {
                $this->downloads[] = $url;
                $source = $this->releases . '/' . basename((string) parse_url($url, PHP_URL_PATH));
                if (!is_file($source)) {
                    throw new UpdateException('GitHub answered :status for :url.', ['status' => 404, 'url' => $url]);
                }
                copy($source, $file);
            }
        };

        $this->installation('v1.0-fork.1');
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function updater(): Updater
    {
        return new Updater($this->install, $this->http);
    }

    public function testFindsTheLatestReleaseAndComparesVersions()
    {
        $this->http->latest = self::REPOSITORY . '/releases/tag/v1.0-fork.2';
        $updater = $this->updater();

        $this->assertNull($updater->unavailable());
        $this->assertSame('v1.0-fork.2', $updater->latest());
        $this->assertTrue($updater->isNewer('v1.0-fork.2'));
        $this->assertTrue($updater->isNewer('v1.1-fork.1'));
        $this->assertFalse($updater->isNewer('v1.0-fork.1'));
        $this->assertSame(self::REPOSITORY . '/releases/tag/v1.0-fork.2', $updater->notesUrl('v1.0-fork.2'));
    }

    public function testRepositoryWithoutReleaseIsReported()
    {
        // GitHub then redirects to the list of releases
        $this->http->latest = self::REPOSITORY . '/releases';

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('The repository :repository has no release.');
        $this->updater()->latest();
    }

    public function testUnavailableOutsideAReleaseArchiveOrGitHub()
    {
        unlink($this->install . '/VERSION');
        $this->assertSame(
            'This installation does not come from a release archive: it has no VERSION file.',
            $this->updater()->unavailable()?->getMessage()
        );

        file_put_contents($this->install . '/VERSION', "v1.0-fork.1\nabc123\nhttps://gitlab.example.org/a/b\n");
        $this->assertStringStartsWith('Updates come from the releases of a GitHub repository', $this->updater()->unavailable()?->getMessage());
    }

    public function testUpdateReplacesTheCodeAndKeepsTheData()
    {
        $this->release('v1.0-fork.2');
        $updater = $this->updater();

        $state = $updater->prepare('v1.0-fork.2');

        $this->assertSame(['tag' => 'v1.0-fork.2', 'from' => 'v1.0-fork.1', 'php' => '8.2.0', 'applied' => false], $state);
        $this->assertSame([
            self::REPOSITORY . '/releases/download/v1.0-fork.2/agorakit-v1.0-fork.2.zip',
            self::REPOSITORY . '/releases/download/v1.0-fork.2/agorakit-v1.0-fork.2.zip.sha256',
        ], $this->http->downloads);
        // nothing changed yet
        $this->assertFileExists($this->install . '/app/Old.php');
        $this->assertFalse($updater->inMaintenance());

        $updater->apply();

        // the code of the release, and nothing of the previous one
        $this->assertStringStartsWith("v1.0-fork.2\n", file_get_contents($this->install . '/VERSION'));
        $this->assertFileExists($this->install . '/app/New.php');
        $this->assertFileDoesNotExist($this->install . '/app/Old.php');
        $this->assertSame('new autoload', file_get_contents($this->install . '/vendor/autoload.php'));
        $this->assertDirectoryDoesNotExist($this->install . '/vendor/removed-package');
        $this->assertFileDoesNotExist($this->install . '/bootstrap/cache/config.php');
        $this->assertSame('new index', file_get_contents($this->install . '/public/index.php'));
        $this->assertFileExists($this->install . '/public/js/app.js');

        // what the release does not ship stays
        $this->assertSame('APP_KEY=secret', file_get_contents($this->install . '/.env'));
        $this->assertSame('app.engine.version=8.2', file_get_contents($this->install . '/.ovhconfig'));
        $this->assertSame('photo', file_get_contents($this->install . '/public/upload/photo.jpg'));
        $this->assertTrue(is_link($this->install . '/public/storage'));
        $this->assertSame('report', file_get_contents($this->install . '/storage/app/groups/1/report.pdf'));
        $this->assertSame('session', file_get_contents($this->install . '/storage/framework/sessions/abc'));
        $this->assertSame('custom', file_get_contents($this->install . '/storage/app/.gitignore'));
        // storage only gets what it lacks
        $this->assertFileExists($this->install . '/storage/import/.gitignore');

        $this->assertTrue($updater->inMaintenance());
        $this->assertTrue($updater->state()['applied']);

        $updater->finish();

        $this->assertFalse($updater->inMaintenance());
        $this->assertNull($updater->state());
        $this->assertFileExists($this->install . '/storage/framework/update/previous/app/Old.php');
        $this->assertSame('old index', file_get_contents($this->install . '/storage/framework/update/previous/public/index.php'));
        $this->assertDirectoryDoesNotExist($this->install . '/storage/framework/update/staging');
        $this->assertSame([], glob($this->install . '/storage/framework/update/agorakit-*'));
    }

    public function testSiteAlreadyInMaintenanceStaysInMaintenance()
    {
        $this->release('v1.0-fork.2');
        file_put_contents($this->install . '/storage/framework/down', '{}');
        $updater = $this->updater();

        $updater->prepare('v1.0-fork.2');
        $updater->apply();
        $updater->finish();

        $this->assertTrue($updater->inMaintenance());
    }

    public function testDamagedDownloadChangesNothing()
    {
        $this->release('v1.0-fork.2');
        file_put_contents($this->releases . '/agorakit-v1.0-fork.2.zip.sha256', str_repeat('0', 64) . "  agorakit-v1.0-fork.2.zip\n");

        $this->assertRefused('v1.0-fork.2', 'The archive does not match its checksum: the download is damaged, try again.');
    }

    public function testPathOutsideTheReleaseFolderIsRefused()
    {
        $this->release('v1.0-fork.2', ['agorakit-v1.0-fork.2/../evil.php' => '<?php']);

        $this->assertRefused('v1.0-fork.2', 'The archive holds an unexpected path: :path');
        $this->assertFileDoesNotExist($this->install . '/storage/framework/update/evil.php');
    }

    public function testArchiveOfAnotherRepositoryIsRefused()
    {
        $this->release('v1.0-fork.2', [], 'https://github.com/someone/else');

        $this->assertRefused('v1.0-fork.2', 'The archive is not the release :tag of :repository.');
    }

    public function testReleaseNeedingANewerPhpIsRefused()
    {
        $this->release('v1.0-fork.2', [
            'agorakit-v1.0-fork.2/vendor/composer/platform_check.php' => '<?php if (!(PHP_VERSION_ID >= 990000)) {}',
        ]);

        $this->assertRefused(
            'v1.0-fork.2',
            'Version :tag needs PHP :required or later; this server runs PHP :current. '
                . 'Choose a newer PHP version in the control panel of your host first.'
        );
    }

    public function testFailedMovePutsThePreviousVersionBack()
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root ignores the permissions this test relies on.');
        }
        $this->release('v1.0-fork.2');
        $updater = $this->updater();
        $updater->prepare('v1.0-fork.2');

        // vendor comes last: everything before it has moved when it fails
        chmod($this->install . '/vendor', 0555);
        try {
            $updater->apply();
            $this->fail('The update should have failed.');
        } catch (UpdateException $e) {
            $this->assertSame('The update failed, and the previous version is back in place: :error', $e->getMessage());
        } finally {
            chmod($this->install . '/vendor', 0755);
        }

        $this->assertStringStartsWith("v1.0-fork.1\n", file_get_contents($this->install . '/VERSION'));
        $this->assertFileExists($this->install . '/app/Old.php');
        $this->assertFileDoesNotExist($this->install . '/app/New.php');
        $this->assertSame('old index', file_get_contents($this->install . '/public/index.php'));
        $this->assertFileDoesNotExist($this->install . '/public/js/app.js');
        $this->assertFileDoesNotExist($this->install . '/storage/import/.gitignore');
        $this->assertFalse($updater->inMaintenance());
        // still ready: the update can be tried again
        $this->assertFalse($updater->state()['applied']);
        $updater->apply();
        $this->assertFileExists($this->install . '/app/New.php');
    }

    public function testSetupPageDrivesTheUpdate()
    {
        $this->release('v1.0-fork.2');
        $session = [];
        $page = $this->page($session);
        $get = fn () => $page()->handle('GET', [], [], ['SCRIPT_NAME' => '/setup.php', 'HTTP_HOST' => 'agora.test']);
        $post = function (array $form) use ($page, &$session) {
            return $page()->handle('POST', [], $form + ['_token' => $session['csrf']], ['SCRIPT_NAME' => '/setup.php']);
        };

        $get();
        $post(['action' => 'key', 'key' => trim(file_get_contents($this->dir . '/setup.key'))]);
        $this->assertStringContainsString('Installed version: v1.0-fork.1', $get()->body);

        $this->assertSame(303, $post(['action' => 'update-check'])->status);
        $body = $get()->body;
        $this->assertStringContainsString('Version v1.0-fork.2 is available.', $body);
        $this->assertStringContainsString(self::REPOSITORY . '/releases/tag/v1.0-fork.2', $body);

        // only the release that was found can be prepared
        $post(['action' => 'update-prepare', 'tag' => 'v9.9-fork.1']);
        $this->assertNull($this->updater()->state());

        $post(['action' => 'update-prepare', 'tag' => 'v1.0-fork.2']);
        $body = $get()->body;
        $this->assertStringContainsString('Version v1.0-fork.2 is downloaded and checked.', $body);
        $this->assertStringContainsString('Install version v1.0-fork.2', $body);

        $post(['action' => 'update-install']);
        $body = $get()->body;
        $this->assertStringContainsString('Version v1.0-fork.2 is installed.', $body);
        $this->assertStringContainsString('The site is in maintenance', $body);
        $this->assertFileExists($this->install . '/app/New.php');

        $this->assertStringContainsString('setup.lock', $post(['action' => 'lock'])->body);
        $this->assertFalse($this->updater()->inMaintenance());
        $this->assertFileExists($this->install . '/storage/framework/update/previous/app/Old.php');
    }

    public function testSetupPageShowsWhyAReleaseIsRefused()
    {
        $this->release('v1.0-fork.2');
        file_put_contents($this->releases . '/agorakit-v1.0-fork.2.zip.sha256', str_repeat('0', 64) . "  agorakit-v1.0-fork.2.zip\n");
        $session = [];
        $page = $this->page($session);
        $post = function (array $form) use ($page, &$session) {
            return $page()->handle('POST', [], $form + ['_token' => $session['csrf']], ['SCRIPT_NAME' => '/setup.php']);
        };
        // the page in French, from the first request on
        $page()->handle('GET', ['lang' => 'fr'], [], ['SCRIPT_NAME' => '/setup.php']);
        $post(['action' => 'key', 'key' => trim(file_get_contents($this->dir . '/setup.key'))]);
        $post(['action' => 'update-check']);
        $post(['action' => 'update-prepare', 'tag' => 'v1.0-fork.2']);

        $body = $page()->handle('GET', [], [], ['SCRIPT_NAME' => '/setup.php'])->body;

        $this->assertStringContainsString("L&#039;archive ne correspond pas à sa somme de contrôle", $body);
        $this->assertStringContainsString('Télécharger et vérifier', $body);
    }

    public function testPreparedUpdateCanBeCancelled()
    {
        $this->release('v1.0-fork.2');
        $updater = $this->updater();
        $updater->prepare('v1.0-fork.2');

        $updater->discard();

        $this->assertNull($updater->state());
        $this->assertDirectoryDoesNotExist($this->install . '/storage/framework/update/staging');
        $this->assertFileExists($this->install . '/app/Old.php');
    }

    /**
     * A setup page on the fake installation; each call answers one request, with the same session.
     */
    private function page(array &$session): \Closure
    {
        return function () use (&$session): SetupPage {
            return new SetupPage(
                dirname(__DIR__, 2),
                new SetupGuard($this->dir . '/setup.lock', $this->dir . '/setup.key'),
                new EnvironmentFile($this->dir . '/.env'),
                $session,
                null,
                $this->updater()
            );
        };
    }

    /**
     * Prepares a release that must be refused, and checks the installation did not change.
     */
    private function assertRefused(string $tag, string $message): void
    {
        $updater = $this->updater();
        try {
            $updater->prepare($tag);
            $this->fail('The release should have been refused.');
        } catch (UpdateException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertNull($updater->state());
        $this->assertDirectoryDoesNotExist($this->install . '/storage/framework/update/staging');
        $this->assertFileExists($this->install . '/app/Old.php');
        $this->assertFalse($updater->inMaintenance());
    }

    /**
     * An installed release, with data of the site around it.
     */
    private function installation(string $version): void
    {
        $this->files($this->install, [
            'VERSION' => "$version\nabc123\n" . self::REPOSITORY . "\n",
            'artisan' => 'old artisan',
            'app/Old.php' => '<?php',
            'bootstrap/app.php' => '<?php',
            'bootstrap/cache/config.php' => '<?php return [];',
            'vendor/autoload.php' => 'old autoload',
            'vendor/removed-package/src/A.php' => '<?php',
            'public/index.php' => 'old index',
            'public/setup.php' => 'old setup',
            'public/upload/photo.jpg' => 'photo',
            'storage/app/.gitignore' => 'custom',
            'storage/app/groups/1/report.pdf' => 'report',
            'storage/app/public/.gitignore' => '*',
            'storage/framework/sessions/abc' => 'session',
            'storage/logs/laravel.log' => 'log',
            '.env' => 'APP_KEY=secret',
            '.ovhconfig' => 'app.engine.version=8.2',
        ]);
        symlink('../storage/app/public', $this->install . '/public/storage');
    }

    /**
     * Publishes a release archive and its checksum, as the release workflow does.
     *
     * @param array<string, string> $extra entries added to the archive, by path in it
     */
    private function release(string $tag, array $extra = [], string $repository = self::REPOSITORY): void
    {
        $name = 'agorakit-' . $tag;
        $entries = [
            "$name/VERSION" => "$tag\ndef456\n$repository\n",
            "$name/artisan" => 'new artisan',
            "$name/app/New.php" => '<?php',
            "$name/bootstrap/app.php" => '<?php',
            "$name/bootstrap/cache/.gitignore" => '*',
            "$name/vendor/autoload.php" => 'new autoload',
            "$name/vendor/composer/platform_check.php" => '<?php if (!(PHP_VERSION_ID >= 80200)) {}',
            "$name/public/index.php" => 'new index',
            "$name/public/setup.php" => 'new setup',
            "$name/public/js/app.js" => 'js',
            "$name/storage/app/.gitignore" => 'from the release',
            "$name/storage/framework/sessions/.gitignore" => '*',
            "$name/storage/import/.gitignore" => '*',
        ];
        // overrides by the test come last, as a later entry would
        $entries = array_merge($entries, $extra);

        $zip = $this->releases . "/$name.zip";
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $path => $content) {
            $archive->addFromString($path, $content);
        }
        $archive->close();
        file_put_contents("$zip.sha256", hash_file('sha256', $zip) . "  $name.zip\n");

        $this->http->latest = self::REPOSITORY . "/releases/tag/$tag";
    }

    /**
     * @param array<string, string> $files contents, by path under $root
     */
    private function files(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("$root/$path"))) {
                mkdir(dirname("$root/$path"), 0755, true);
            }
            file_put_contents("$root/$path", $content);
        }
    }

    private function remove(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                chmod($item->getPathname(), 0755);
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($folder);
    }
}
