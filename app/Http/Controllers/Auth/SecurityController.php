<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\TwoFactorAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User self-service security settings: enable/confirm/disable TOTP 2FA.
 */
class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Security', [
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            // Pending enrolment data lives in the session until confirmed.
            'enrolment' => $request->session()->get('2fa.enrolment'),
        ]);
    }

    public function enable(Request $request, TwoFactorAuth $tfa): RedirectResponse
    {
        $user = $request->user();
        $secret = $tfa->generateSecret();

        // Store on the user (encrypted) but leave unconfirmed until verified.
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        $request->session()->put('2fa.enrolment', [
            'secret' => $secret,
            'otpauth' => $tfa->otpauthUri($user, $secret),
        ]);

        return back();
    }

    public function confirm(Request $request, TwoFactorAuth $tfa): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $tfa->verify($user->two_factor_secret, (string) $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Try again.']);
        }

        $codes = $tfa->recoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode($codes),
        ])->save();

        $request->session()->forget('2fa.enrolment');
        AuditLogger::record('security.2fa_enabled', $user);

        return back()->with('success', 'Two-factor authentication enabled.')->with('recoveryCodes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::record('security.2fa_disabled', $user);

        return back()->with('success', 'Two-factor authentication disabled.');
    }
}
