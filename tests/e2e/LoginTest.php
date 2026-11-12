<?php

namespace Tests;

use App\Mail\LoginByEmail;
use App\Services\LoginLinkService;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sign-in scenarios: with a password, and with a login link received by email.
 *
 * Runs after UserTest, which registered the "admin" account these tests use.
 */
class LoginTest extends BaseTest
{
    private const ADMIN_EMAIL = 'admin@agorakit.local';
    private const ADMIN_PASSWORD = '123456789';
    // A translation key: its misspelling is kept so that existing translations still match.
    private const LINK_SENT = 'Check your mailbox, we sent you a login link. It will expires in 30 minutes';
    private const DELETED_EMAIL = 'deleted@agorakit.local';
    private const TOO_MANY_LINKS = 'Too many login links were requested. '
        . 'Use a link you already received, or try again later.';

    protected function tearDown(): void
    {
        User::withTrashed()->where('email', self::DELETED_EMAIL)->forceDelete();

        parent::tearDown();
    }

    /**
     * Creates an account marked for deletion.
     */
    private function deletedUser(): User
    {
        $user = User::create([
            'name' => 'Deleted',
            'email' => self::DELETED_EMAIL,
            'password' => bcrypt('irrelevant'),
        ]);
        $user->delete();

        return $user;
    }

    /**
     * Returns the login link found in the email sent to this address.
     */
    private function loginLinkSentTo(string $email): string
    {
        $link = '';

        Mail::assertSent(LoginByEmail::class, function (LoginByEmail $mail) use ($email, &$link) {
            if (!$mail->hasTo($email)) {
                return false;
            }
            $mail->build();
            $link = $mail->viewData['login_url'];

            return true;
        });

        return $link;
    }

    /**
     * Asks for a login link by leaving the password empty, and returns the link.
     */
    private function requestLoginLink(string $login, string $email): string
    {
        $this->post('/login', ['login' => $login, 'password' => '']);

        return $this->loginLinkSentTo($email);
    }

    public function testSignInPageLeadsToLoginLinkMode()
    {
        $this->visit('/login')
            ->seeElement('input[name="password"]')
            ->click(__('Link by email'))
            ->seePageIs('/login/email')
            ->seeElement('input[name="email"]')
            ->dontSeeElement('input[name="password"]');
    }

    public function testLoginLinkModeLeadsBackToPasswordMode()
    {
        $this->visit('/login/email')
            ->click(trans('messages.password'))
            ->seePageIs('/login')
            ->seeElement('input[name="password"]');
    }

    public function testBothSignInModesOfferToRegister()
    {
        $this->visit('/login')
            ->seeLink(__('Click here to register a new account'), route('register'));

        $this->visit('/login/email')
            ->seeLink(__('Click here to register a new account'), route('register'));
    }

    public function testSignInModesAreTranslated()
    {
        $this->visit('/login?force_locale=fr')
            ->seeLink('Lien par email', route('loginbyemail'));
    }

    public function testPasswordSignIn()
    {
        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->type(self::ADMIN_PASSWORD, 'password')
            ->press(trans('messages.login'));

        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testWrongPasswordIsRefused()
    {
        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->type('not-the-password', 'password')
            ->press(trans('messages.login'))
            ->see(__('Incorrect password and/or username'));

        $this->assertTrue(Auth::guest());
    }

    public function testRepeatedWrongPasswordsLockSignInForAWhile()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => 'wrong-' . $i]);
        }

        // Even the right password is now refused, with an explanation.
        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->type(self::ADMIN_PASSWORD, 'password')
            ->press(trans('messages.login'))
            ->see('Too many login attempts')
            ->seeInField('login', self::ADMIN_EMAIL);
        $this->assertTrue(Auth::guest());

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        try {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testLoginLinkStaysAvailableWhilePasswordsAreLocked()
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => 'wrong-' . $i]);
        }

        $link = $this->requestLoginLink(self::ADMIN_EMAIL, self::ADMIN_EMAIL);
        $this->get($link)->assertRedirectedTo('/');

        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testSuccessfulSignInResetsTheCountOfWrongPasswords()
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => 'wrong-' . $i]);
        }
        $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);
        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
        Auth::logout();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => 'wrong-' . $i]);
        }
        $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);

        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testIdentifierIsKeptAfterWrongPassword()
    {
        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->type('not-the-password', 'password')
            ->press(trans('messages.login'))
            ->seeInField('login', self::ADMIN_EMAIL)
            ->dontSee('not-the-password');
    }

    public function testIdentifierIsKeptWhenNoAccountMatches()
    {
        $this->visit('/login')
            ->type('nobody@agorakit.local', 'login')
            ->type('some-password', 'password')
            ->press(trans('messages.login'))
            ->seeInField('login', 'nobody@agorakit.local');

        $this->visit('/login')
            ->type('nobody@agorakit.local', 'login')
            ->press(trans('messages.login'))
            ->seeInField('login', 'nobody@agorakit.local');
    }

    public function testEmptyPasswordSendsLoginLink()
    {
        Mail::fake();

        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->press(trans('messages.login'))
            ->see(__(self::LINK_SENT));

        Mail::assertSent(LoginByEmail::class, function (LoginByEmail $mail) {
            return $mail->hasTo(self::ADMIN_EMAIL);
        });
        $this->assertTrue(Auth::guest());
    }

    public function testUsernameAlsoGetsLoginLink()
    {
        Mail::fake();

        $link = $this->requestLoginLink('admin', self::ADMIN_EMAIL);

        $this->assertStringContainsString('/autologin?', $link);
    }

    public function testUnknownIdentifierGetsNoLoginLink()
    {
        Mail::fake();

        $this->visit('/login')
            ->type('nobody@agorakit.local', 'login')
            ->press(trans('messages.login'))
            ->see(__('No user found, please create an account instead'));

        Mail::assertNothingSent();
    }

    public function testLoginLinkSignsIn()
    {
        Mail::fake();
        $link = $this->requestLoginLink(self::ADMIN_EMAIL, self::ADMIN_EMAIL);

        $this->get($link)->assertRedirectedTo('/');

        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testTamperedLoginLinkIsRefused()
    {
        Mail::fake();
        $link = $this->requestLoginLink(self::ADMIN_EMAIL, self::ADMIN_EMAIL);

        $this->get(str_replace('username=admin', 'username=newbie', $link))
            ->assertResponseStatus(401);

        $this->assertTrue(Auth::guest());
    }

    public function testExpiredLoginLinkIsRefused()
    {
        Mail::fake();
        $link = $this->requestLoginLink(self::ADMIN_EMAIL, self::ADMIN_EMAIL);

        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        try {
            $this->get($link)->assertResponseStatus(401);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue(Auth::guest());
    }

    public function testLoginLinkReactivatesDeletedAccount()
    {
        $this->deletedUser();
        Mail::fake();
        $link = $this->requestLoginLink(self::DELETED_EMAIL, self::DELETED_EMAIL);

        $this->get($link)->assertRedirectedTo('/');

        $this->assertSame(self::DELETED_EMAIL, Auth::user()->email);
        $this->assertFalse(User::withTrashed()->where('email', self::DELETED_EMAIL)->first()->trashed());
    }

    public function testLoginByEmailFormAlsoServesDeletedAccount()
    {
        $this->deletedUser();
        Mail::fake();

        $this->post('/login/email', ['email' => self::DELETED_EMAIL]);

        $this->assertStringContainsString('/autologin?', $this->loginLinkSentTo(self::DELETED_EMAIL));
    }

    public function testLoginLinksAreLimitedPerAccount()
    {
        Mail::fake();

        for ($i = 0; $i < LoginLinkService::MAX_PER_ACCOUNT; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => '']);
        }
        Mail::assertSent(LoginByEmail::class, LoginLinkService::MAX_PER_ACCOUNT);

        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->press(trans('messages.login'))
            ->see(__(self::TOO_MANY_LINKS))
            ->seeInField('login', self::ADMIN_EMAIL);

        // The dedicated form shares the same limit.
        $this->visit('/login/email')
            ->type(self::ADMIN_EMAIL, 'email')
            ->press(__('Send me the link'))
            ->see(__(self::TOO_MANY_LINKS));

        Mail::assertSent(LoginByEmail::class, LoginLinkService::MAX_PER_ACCOUNT);
    }

    public function testLoginLinksAreLimitedPerAddress()
    {
        Mail::fake();
        for ($i = 0; $i < LoginLinkService::MAX_PER_ADDRESS; $i++) {
            RateLimiter::hit(LoginLinkService::addressKey('127.0.0.1'), LoginLinkService::WINDOW_SECONDS);
        }

        $this->visit('/login')
            ->type(self::ADMIN_EMAIL, 'login')
            ->press(trans('messages.login'))
            ->see(__(self::TOO_MANY_LINKS));

        Mail::assertNothingSent();
    }

    public function testLoginLinkLimitExpires()
    {
        Mail::fake();
        for ($i = 0; $i <= LoginLinkService::MAX_PER_ACCOUNT; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => '']);
        }
        Mail::assertSent(LoginByEmail::class, LoginLinkService::MAX_PER_ACCOUNT);

        Carbon::setTestNow(Carbon::now()->addSeconds(LoginLinkService::WINDOW_SECONDS + 1));
        try {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => '']);
        } finally {
            Carbon::setTestNow();
        }

        Mail::assertSent(LoginByEmail::class, LoginLinkService::MAX_PER_ACCOUNT + 1);
    }

    public function testPasswordSignInIgnoresLoginLinkLimit()
    {
        Mail::fake();
        for ($i = 0; $i <= LoginLinkService::MAX_PER_ACCOUNT; $i++) {
            $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => '']);
        }

        $this->post('/login', ['login' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);

        $this->assertSame(self::ADMIN_EMAIL, Auth::user()->email);
    }

    public function testLoginByEmailFormSendsLoginLink()
    {
        Mail::fake();

        $this->visit('/login/email')
            ->type(self::ADMIN_EMAIL, 'email')
            ->press(__('Send me the link'))
            ->see('we sent you a login link. It will expire in 30 minutes');

        $this->assertStringContainsString('/autologin?', $this->loginLinkSentTo(self::ADMIN_EMAIL));
    }

    public function testLoginByEmailFormIsTranslated()
    {
        Mail::fake();

        $this->visit('/login/email?force_locale=fr')
            ->see('Lien par email')
            ->see('Pas besoin de mot de passe')
            ->type(self::ADMIN_EMAIL, 'email')
            ->press('Envoie-moi le lien')
            ->see('nous vous avons envoyé un lien de connexion');
    }

    public function testLoginByEmailFormTranslatesUnknownAccountMessage()
    {
        Mail::fake();

        $this->visit('/login/email?force_locale=fr')
            ->type('nobody@agorakit.local', 'email')
            ->press('Envoie-moi le lien')
            ->see('Aucun utilisateur trouvé');

        Mail::assertNothingSent();
    }
}
