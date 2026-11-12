<?php

namespace Tests;

use App\Mail\LoginByEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Sign-in scenarios: with a password, and with a login link received by email.
 *
 * Runs after UserTest, which registered the "admin" account these tests use.
 */
class LoginTest extends BaseTest
{
    private const ADMIN_EMAIL = 'admin@agorakit.local';
    private const ADMIN_PASSWORD = '123456789';
    private const LINK_SENT = 'Check your mailbox, we sent you a login link. It will expires in 30 minutes';

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

    public function testLoginByEmailFormSendsLoginLink()
    {
        Mail::fake();

        $this->visit('/login/email')
            ->type(self::ADMIN_EMAIL, 'email')
            ->press(__('Send me the link'))
            ->see(self::LINK_SENT);

        $this->assertStringContainsString('/autologin?', $this->loginLinkSentTo(self::ADMIN_EMAIL));
    }
}
