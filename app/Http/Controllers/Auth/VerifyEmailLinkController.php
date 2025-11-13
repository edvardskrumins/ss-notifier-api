<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailLinkController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $redirectUrl = rtrim(
            config('app.frontend_url', config('app.url')),
            '/'
        ) . '/welcome';

        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->away($redirectUrl);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->away($redirectUrl);
    }
}

