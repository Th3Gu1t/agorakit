<?php

namespace Tests;

use App\Setup\EnvironmentFile;
use App\Setup\SetupGuard;
use App\Setup\SetupPage;
use App\Setup\SetupResponse;
use Dotenv\Dotenv;
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
        return new SetupPage(
            dirname(__DIR__, 2),
            new SetupGuard($this->dir . '/setup.lock', $this->dir . '/setup.key'),
            new EnvironmentFile($this->dir . '/.env'),
            $this->session
        );
    }

    private function get(array $query = [], array $server = []): SetupResponse
    {
        return $this->page()->handle('GET', $query, [], $server + ['SCRIPT_NAME' => '/setup.php', 'HTTP_HOST' => 'agora.test']);
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

    /**
     * Options of a working configuration, with the test database of the project.
     */
    private function validOptions(array $changes = []): array
    {
        $project = Dotenv::parse(file_get_contents(dirname(__DIR__, 2) . '/.env'));

        return $changes + [
            'APP_NAME' => 'Les Amis du Quartier',
            'APP_URL' => 'https://agora.example.org/',
            'APP_DEFAULT_LOCALE' => 'fr',
            'TIME_ZONE' => 'Europe/Paris',
            'APP_ENV' => 'production',
            'APP_LOG' => 'daily',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $project['DB_HOST'],
            'DB_PORT' => $project['DB_PORT'],
            'DB_DATABASE' => $project['DB_DATABASE'],
            'DB_USERNAME' => $project['DB_USERNAME'],
            'DB_PASSWORD' => $project['DB_PASSWORD'],
            'MAIL_DRIVER' => 'smtp',
            'MAIL_HOST' => 'smtp.example.org',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => 'contact@example.org',
            'MAIL_PASSWORD' => 'mail secret',
            'MAIL_FROM' => 'contact@example.org',
            'MAIL_FROM_NAME' => 'Les Amis',
            'MAIL_NOREPLY' => 'noreply@example.org',
            'INBOX_DRIVER' => 'null',
            'INBOX_PORT' => '993',
            'MAX_FILE_SIZE' => '10000',
            'DATA_RETENTION' => '30',
            'CACHE_DRIVER' => 'file',
            'SESSION_DRIVER' => 'file',
            'QUEUE_DRIVER' => 'sync',
        ];
    }

    private function written(): array
    {
        return Dotenv::parse(file_get_contents($this->dir . '/.env'));
    }

    public function testFormProposesSensibleDefaults()
    {
        $this->signIn();

        $body = $this->get(['step' => 'environment'])->body;

        $this->assertStringContainsString('name="env[DB_HOST]"', $body);
        $this->assertStringContainsString('value="http://agora.test"', $body);
        $this->assertStringContainsString('value="localhost"', $body);
    }

    public function testSourceAddressComesFromTheRelease()
    {
        file_put_contents($this->dir . '/VERSION', "v1.11.4-fork.1\n0123abcd\nhttps://github.com/example/agorakit\n");
        $this->signIn();

        $body = $this->get(['step' => 'environment'])->body;

        $this->assertStringContainsString('name="env[SOURCE_URL]"', $body);
        $this->assertStringContainsString('value="https://github.com/example/agorakit"', $body);
    }

    public function testConfigurationIsWrittenOnceTheDatabaseAnswers()
    {
        $this->signIn();

        $response = $this->post(['action' => 'environment', 'env' => $this->validOptions()]);

        $this->assertSame(303, $response->status);
        $written = $this->written();
        $this->assertSame('Les Amis du Quartier', $written['APP_NAME']);
        $this->assertSame('https://agora.example.org', $written['APP_URL']);
        $this->assertSame('false', $written['APP_DEBUG']);
        $this->assertStringStartsWith('base64:', $written['APP_KEY']);

        $overview = $this->get()->body;
        $this->assertStringContainsString('The configuration is saved in the .env file.', $overview);
        $this->assertStringContainsString('The database can be reached', $overview);
    }

    public function testUnreachableDatabaseWritesNothing()
    {
        $this->signIn();

        $response = $this->post(['action' => 'environment', 'env' => $this->validOptions(['DB_PASSWORD' => 'not-the-password'])]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('The database cannot be reached with these options', $response->body);
        $this->assertStringNotContainsString('not-the-password', $response->body);
        $this->assertFileDoesNotExist($this->dir . '/.env');
    }

    public function testWrongOptionsAreReportedOneByOne()
    {
        $this->signIn();

        $response = $this->post(['action' => 'environment', 'env' => $this->validOptions([
            'APP_NAME' => '',
            'APP_URL' => 'agora.example.org',
            'MAIL_FROM' => 'not an address',
            'TIME_ZONE' => 'Moon/Base',
            'DB_PORT' => '33a',
            'MAIL_DRIVER' => 'pigeon',
        ])]);

        $this->assertSame(422, $response->status);
        foreach (
            [
                'This option is required.',
                'Enter a full address, starting with https://.',
                'Enter a valid email address.',
                'Enter a time zone such as Europe/Paris.',
                'Enter a whole number.',
                'Choose one of the options.',
            ] as $message
        ) {
            $this->assertStringContainsString($message, $response->body);
        }
        $this->assertStringNotContainsString('mail secret', $response->body);
        $this->assertFileDoesNotExist($this->dir . '/.env');
    }

    public function testProductionNeedsHttps()
    {
        $this->signIn();

        $refused = $this->post(['action' => 'environment', 'env' => $this->validOptions(['APP_URL' => 'http://agora.example.org'])]);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('In production, Agorakit only works over HTTPS', $refused->body);

        $local = $this->post(['action' => 'environment', 'env' => $this->validOptions([
            'APP_URL' => 'http://localhost:8000',
            'APP_ENV' => 'local',
        ])]);
        $this->assertSame(303, $local->status);
    }

    public function testEmptyPasswordsKeepTheCurrentOnes()
    {
        $this->signIn();
        $this->post(['action' => 'environment', 'env' => $this->validOptions()]);
        file_put_contents($this->dir . '/.env', "CUSTOM_OPTION=kept\n", FILE_APPEND);

        $form = $this->get(['step' => 'environment'])->body;
        $this->assertStringContainsString('Leave empty to keep the current password.', $form);
        $this->assertStringNotContainsString('mail secret', $form);

        $this->post(['action' => 'environment', 'env' => $this->validOptions(['DB_PASSWORD' => '', 'MAIL_PASSWORD' => ''])]);

        $written = $this->written();
        $this->assertSame('mail secret', $written['MAIL_PASSWORD']);
        $this->assertSame('kept', $written['CUSTOM_OPTION']);
    }

    public function testLockWarnsAboutWhatIsNotFinished()
    {
        $this->signIn();

        $this->assertStringContainsString('There is no configuration yet.', $this->get()->body);

        file_put_contents($this->dir . '/.env', "DB_HOST=127.0.0.1\nDB_PASSWORD=wrong\n");
        $this->assertStringContainsString('The database cannot be reached.', $this->get()->body);
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
