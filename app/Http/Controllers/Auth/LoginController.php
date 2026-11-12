<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use App\User;
use Auth;
use App\Services\LoginLinkService;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function login(Request $request, LoginLinkService $loginLinks)
    {
        // decide if attempt is made with an email or a username
        $login = request()->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // find user, even if it was deleted
        $user = User::where($field, trim($login))->withTrashed()->first();

        // check if password is not filled
        if (empty($request->input('password'))) {
            if ($user) {
                // send login link by email, unless too many were sent recently
                if (!$loginLinks->send($user, $request->ip())) {
                    warning(__(
                        'Too many login links were requested. Use a link you already received, or try again later.'
                    ));
                    return $this->backToLoginForm($request);
                }
                flash(__('Check your mailbox, we sent you a login link. It will expires in 30 minutes'));
                return redirect('/');
            } else {
                warning(__('No user found, please create an account instead'));
                return $this->backToLoginForm($request);
            }
        } else {
            // refuse to try more passwords after too many wrong ones
            if ($this->hasTooManyLoginAttempts($request)) {
                $this->fireLockoutEvent($request);
                return $this->sendLockoutResponse($request);
            }

            if ($user) {
                // restore user if it was deleted, even if password is not correct
                if ($user->trashed()) {
                    $user->restore();
                }

                // attempt to login
                if (Auth::attempt(['email' => $user->email, 'password' => $request->input('password')], request()->input('remember'))) {
                    $this->clearLoginAttempts($request);
                    return redirect()->intended('/');
                } else {
                    $this->incrementLoginAttempts($request);
                    warning(__('Incorrect password and/or username'));
                    return $this->backToLoginForm($request);
                }
            } else {
                warning(__('No user found, please create an account instead'));
                return $this->backToLoginForm($request);
            }
        }
    }

    /**
     * Sends the visitor back to the sign-in form, keeping the identifier they typed.
     */
    private function backToLoginForm(Request $request)
    {
        return redirect()->back()->withInput($request->only('login'));
    }

    /**
     * Allows to log with either username or email
     */
    public function username()
    {
        $login = request()->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        request()->merge([$field => $login]);
        return $field;
    }
}
