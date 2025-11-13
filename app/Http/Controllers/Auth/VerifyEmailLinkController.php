<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailLinkController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $redirectUrl = rtrim(
            config('app.frontend_url', config('app.url')),
            '/'
        ) . '/welcome';

        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away($redirectUrl);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away($redirectUrl);
    }
}

