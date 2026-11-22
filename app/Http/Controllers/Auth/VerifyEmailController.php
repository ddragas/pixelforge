<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            // The starter kit leaves e-mail verification switched off on the User model, hence the mismatch.
            event(new Verified($user)); // @phpstan-ignore argument.type
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
