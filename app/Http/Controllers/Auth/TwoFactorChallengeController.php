<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Users\TwoFactorAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second step of login for 2FA-enabled users. The pending user id is held in
 * the session by AuthenticatedSessionController until a valid TOTP or recovery
 * code completes the login.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('auth.2fa.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactorAuth $tfa): RedirectResponse
    {
        $userId = $request->session()->get('auth.2fa.id');
        abort_if($userId === null, 419);

        $user = User::findOrFail($userId);
        $request->validate(['code' => ['required', 'string']]);
        $code = (string) $request->string('code');

        $ok = ($user->two_factor_secret && $tfa->verify($user->two_factor_secret, $code))
            || $tfa->useRecoveryCode($user, $code);

        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        $remember = (bool) $request->session()->pull('auth.2fa.remember', false);
        $request->session()->forget('auth.2fa.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return redirect()->intended(route('dashboard'));
    }
}
