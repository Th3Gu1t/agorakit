<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\User;
use Auth;
use URL;
use App\Services\LoginLinkService;

class LoginByEmailController extends Controller
{
    /**
    * Show a login by mail form, might be unnecessary now
    */
    public function showLoginByEmailForm(Request $request)
    {
        $email = $request->session()->get('email');
        return view('auth.loginbyemail')
        ->with('email', $email);
    }

    public function sendLoginByEmail(Request $request, LoginLinkService $loginLinks)
    {
        // find user, even if it was deleted, like the sign-in form does
        $user = User::where('email', trim($request->get('email')))->withTrashed()->first();

        if ($user) {
            // send login link by email, unless too many were sent recently
            if (!$loginLinks->send($user, $request->ip())) {
                warning(__(
                    'Too many login links were requested. Use a link you already received, or try again later.'
                ));
                return redirect()->back();
            }
            flash(__('Check your mailbox, we sent you a login link. It will expires in 30 minutes'));
            return redirect('/');
        } else {
            flash(__('No user found, please create an account instead'));
            return redirect()->back();
        }
    }
}
