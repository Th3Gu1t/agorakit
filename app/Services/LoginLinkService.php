<?php

namespace App\Services;

use App\Mail\LoginByEmail;
use App\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends login links by email, within limits.
 *
 * Anyone can ask for a login link for any account: each request sends an
 * email to a mailbox the visitor may not own. Without a limit, this can flood
 * the mailbox of a member, or make the server send mail in bulk and hurt its
 * sending reputation.
 */
class LoginLinkService
{
    /**
     * Period over which requests are counted, in seconds.
     * It matches the lifetime of a link: more links than the limit could not be of use.
     */
    public const WINDOW_SECONDS = 1800;

    /**
     * Links sent to one account per period.
     */
    public const MAX_PER_ACCOUNT = 3;

    /**
     * Links sent on behalf of one IP address per period.
     * Higher than the limit per account: several members can share an address.
     */
    public const MAX_PER_ADDRESS = 20;

    /**
     * Sends a login link to the user, unless too many were sent recently.
     *
     * @return bool false when the link was not sent because a limit is reached
     */
    public function send(User $user, ?string $address): bool
    {
        if ($this->limitReached($user, $address)) {
            return false;
        }

        $this->countRequest($user, $address);
        Mail::to($user->email)->send(new LoginByEmail($user));

        return true;
    }

    /**
     * Tells if this account, or this IP address, already got its share of links.
     */
    public function limitReached(User $user, ?string $address): bool
    {
        return RateLimiter::tooManyAttempts(self::accountKey($user), self::MAX_PER_ACCOUNT)
            || RateLimiter::tooManyAttempts(self::addressKey($address), self::MAX_PER_ADDRESS);
    }

    public static function accountKey(User $user): string
    {
        return 'login-link:account:' . $user->id;
    }

    public static function addressKey(?string $address): string
    {
        return 'login-link:address:' . $address;
    }

    private function countRequest(User $user, ?string $address): void
    {
        RateLimiter::hit(self::accountKey($user), self::WINDOW_SECONDS);
        RateLimiter::hit(self::addressKey($address), self::WINDOW_SECONDS);
    }
}
