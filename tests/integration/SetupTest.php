<?php

namespace Tests;

use App\Setup\EnvironmentFile;
use App\Setup\SetupGuard;
use App\Setup\SetupPage;
use App\Setup\SetupResponse;
use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * The steps of the setup page that need Laravel and the test database.
 *
 * The first test empties the database, so this file does not depend on what
 * other tests left in it.
 */
class SetupTest extends BrowserKitTestCase
{
    private string $dir;
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agorakit-setup-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        // a configuration that reaches the test database
        $database = config('database.connections.mysql_testing');
        (new EnvironmentFile($this->dir . '/.env'))->write([
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));

        parent::tearDown();
    }

    private function page(): SetupPage
    {
        return new SetupPage(
            base_path(),
            new SetupGuard($this->dir . '/setup.lock', $this->dir . '/setup.key'),
            new EnvironmentFile($this->dir . '/.env'),
            $this->session,
            fn () => $this->app->make(Kernel::class)
        );
    }

    private function request(string $method, array $post = []): SetupResponse
    {
        if ($method === 'POST') {
            $post += ['_token' => $this->session['csrf'] ?? ''];
        }

        return $this->page()->handle($method, [], $post, ['SCRIPT_NAME' => '/setup.php']);
    }

    private function signIn(): void
    {
        $this->request('GET');
        $this->request('POST', ['action' => 'key', 'key' => trim(file_get_contents($this->dir . '/setup.key'))]);
    }

    /**
     * Name of the latest migration of the project.
     */
    private function latestMigration(): string
    {
        $files = glob(base_path('database/migrations/*.php'));
        sort($files);

        return basename(end($files), '.php');
    }

    public function testSetUpAnEmptyDatabase()
    {
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public function testUpToDateDatabaseHasNothingToRun()
    {
        $this->signIn();

        $body = $this->request('GET')->body;

        $this->assertStringContainsString('The database can be reached', $body);
        $this->assertStringContainsString('The database is up to date', $body);
        $this->assertStringNotContainsString('Run the migrations', $body);
    }

    public function testPendingMigrationRunsFromThePage()
    {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->signIn();

        $before = $this->request('GET')->body;
        $this->assertStringContainsString('Migrations to run, which create or change the tables of the database: 1.', $before);
        $this->assertStringContainsString($this->latestMigration(), $before);

        $response = $this->request('POST', ['action' => 'migrate']);
        $this->assertSame(303, $response->status);

        $after = $this->request('GET')->body;
        $this->assertStringContainsString('The database is up to date.', $after);
        $this->assertStringContainsString($this->latestMigration(), $after);
        $this->assertStringContainsString('DONE', $after);
        $this->assertStringNotContainsString('Run the migrations', $after);
    }

    public function testAdminFormIsShownWhileNoAdminExists()
    {
        $this->assertSame(0, User::count());
        $this->signIn();

        $this->assertStringContainsString('Create the administrator account', $this->request('GET')->body);
    }

    public function testWrongAdminDetailsAreReported()
    {
        $this->signIn();

        $this->request('POST', [
            'action' => 'admin',
            'name' => 'Camille',
            'email' => 'camille@example.org',
            'password' => 'long enough',
            'password_confirmation' => 'something else',
        ]);

        $body = $this->request('GET')->body;
        $this->assertStringContainsString('The two passwords differ.', $body);
        $this->assertStringContainsString('value="camille@example.org"', $body);
        $this->assertStringNotContainsString('long enough', $body);
        $this->assertSame(0, User::count());
    }

    public function testAdminIsCreatedVerified()
    {
        $this->signIn();

        $this->request('POST', [
            'action' => 'admin',
            'name' => 'Camille',
            'email' => 'camille@example.org',
            'password' => 'long enough',
            'password_confirmation' => 'long enough',
        ]);

        $user = User::where('email', 'camille@example.org')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isVerified());
        $this->assertTrue(Hash::check('long enough', $user->password));

        $body = $this->request('GET')->body;
        $this->assertStringContainsString('The administrator account is created', $body);
        $this->assertStringContainsString('An administrator account exists', $body);
        $this->assertStringNotContainsString('Create the administrator account', $body);
    }

    public function testNoSecondAdminFromThePage()
    {
        $this->signIn();

        $this->request('POST', [
            'action' => 'admin',
            'name' => 'Intruder',
            'email' => 'intruder@example.org',
            'password' => 'long enough',
            'password_confirmation' => 'long enough',
        ]);

        $this->assertFalse(User::where('email', 'intruder@example.org')->exists());
        $this->assertStringContainsString('An administrator account exists already.', $this->request('GET')->body);
    }
}
