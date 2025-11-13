<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResetPasswordLinkController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $redirectUrl = $frontendUrl . '/reset-password?token=' . urlencode($token);

        if ($request->filled('email')) {
            $redirectUrl .= '&email=' . urlencode($request->query('email'));
        }

        return redirect()->away($redirectUrl);
    }
}

