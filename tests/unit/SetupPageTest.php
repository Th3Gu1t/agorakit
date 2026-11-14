<?php

namespace Tests;

use App\Setup\SetupGuard;
use App\Setup\SetupPage;
use App\Setup\SetupResponse;
use PHPUnit\Framework\TestCase;

/**
 * The setup page, without a configured application: its lock and its key.
 */
class SetupPageTest extends TestCase
{
    private string $dir;
    private array $session = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agorakit-setup-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        chmod($this->dir, 0700);
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        rmdir($this->dir);
    }

    private function page(): SetupPage
    {
        return new SetupPage(dirname(__DIR__, 2), new SetupGuard($this->dir . '/setup.lock', $this->dir . '/setup.key'), $this->session);
    }

    private function get(array $query = [], array $server = []): SetupResponse
    {
        return $this->page()->handle('GET', $query, [], $server + ['SCRIPT_NAME' => '/setup.php']);
    }

    /**
     * Posts a form, with the token of the session unless the form sets its own.
     */
    private function post(array $form): SetupResponse
    {
        return $this->page()->handle('POST', [], $form + ['_token' => $this->session['csrf'] ?? ''], ['SCRIPT_NAME' => '/setup.php']);
    }

    private function key(): string
    {
        return trim(file_get_contents($this->dir . '/setup.key'));
    }

    private function signIn(): void
    {
        $this->get();
        $this->post(['action' => 'key', 'key' => $this->key()]);
    }

    public function testFirstVisitWritesAKeyAndAsksForIt()
    {
        $response = $this->get();

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="key"', $response->body);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->key());
    }

    public function testKeyStaysTheSameUntilTheLock()
    {
        $this->get();
        $key = $this->key();
        $this->get();

        $this->assertSame($key, $this->key());
    }

    public function testWrongKeyIsRefused()
    {
        $this->get();

        $response = $this->post(['action' => 'key', 'key' => 'not-the-key']);

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('This is not the key written in setup.key.', $response->body);
        $this->assertStringNotContainsString('Lock the setup page', $this->get()->body);
    }

    public function testRightKeyOpensThePage()
    {
        $this->get();

        $response = $this->post(['action' => 'key', 'key' => ' ' . $this->key() . "\n"]);

        $this->assertSame(303, $response->status);
        $this->assertSame('/setup.php', $response->headers['Location']);
        $this->assertTrue($response->regenerateSession);
        $this->assertStringContainsString('Lock the setup page', $this->get()->body);
    }

    public function testFormWithoutTheTokenOfTheSessionIsRefused()
    {
        $this->get();

        $response = $this->post(['action' => 'key', 'key' => $this->key(), '_token' => 'forged']);

        $this->assertSame(419, $response->status);
        $this->assertArrayNotHasKey('authenticated', $this->session);
    }

    public function testLockClosesThePageAndForgetsTheKey()
    {
        $this->signIn();

        $response = $this->post(['action' => 'lock']);

        $this->assertSame(200, $response->status);
        $this->assertFileExists($this->dir . '/setup.lock');
        $this->assertFileDoesNotExist($this->dir . '/setup.key');
        $this->assertSame([], $this->session);

        $locked = $this->get();
        $this->assertSame(403, $locked->status);
        $this->assertStringContainsString('The setup page is locked', $locked->body);
        $this->assertStringNotContainsString('name="key"', $locked->body);
    }

    public function testLockedPageRefusesEvenASignedInSession()
    {
        $this->signIn();
        touch($this->dir . '/setup.lock');

        $response = $this->post(['action' => 'lock']);

        $this->assertSame(403, $response->status);
        $this->assertSame([], $this->session);
    }

    public function testDeletingTheLockOpensThePageWithANewKey()
    {
        $this->signIn();
        $oldKey = $this->key();
        $this->post(['action' => 'lock']);
        unlink($this->dir . '/setup.lock');

        $response = $this->get();

        $this->assertStringContainsString('name="key"', $response->body);
        $this->assertNotSame($oldKey, $this->key());
    }

    public function testRootThatCannotBeWrittenIsReported()
    {
        chmod($this->dir, 0500);

        $response = $this->get();

        $this->assertSame(500, $response->status);
        $this->assertStringContainsString('cannot write the file setup.key', $response->body);
    }

    public function testSignedInPageShowsTheServerAndTheScheduledTask()
    {
        $this->signIn();

        $body = $this->get()->body;

        $this->assertStringContainsString('PHP extension pdo_mysql', $body);
        $this->assertStringContainsString(dirname(__DIR__, 2) . '/artisan schedule:run', $body);
    }

    public function testPageSpeaksTheLanguageOfTheBrowser()
    {
        $french = $this->get([], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,fr;q=0.8,en;q=0.5']);
        $this->assertStringContainsString("Installation d'Agorakit", html_entity_decode($french->body, ENT_QUOTES));
        $this->assertStringContainsString('<html lang="fr">', $french->body);

        $english = $this->get(['lang' => 'en'], ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $this->assertStringContainsString('Agorakit setup', $english->body);

        // the choice is kept for the next pages
        $this->assertStringContainsString('<html lang="en">', $this->get([], ['HTTP_ACCEPT_LANGUAGE' => 'fr'])->body);
    }
}
