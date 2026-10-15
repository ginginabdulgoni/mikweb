<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor helper. Secrets + recovery codes are stored encrypted on the
 * user (see the User model casts) and never leave the server in plaintext
 * beyond the one-time enrolment display.
 */
class TwoFactorAuth
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name', 'MikWeb'),
            $user->email,
            $secret,
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    /** @return array<int, string> */
    public function recoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    /** Consume a recovery code if it matches; returns the remaining codes or null. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ? json_decode($user->two_factor_recovery_codes, true) : [];
        $code = trim($code);

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(array_values(array_diff($codes, [$code]))),
        ])->save();

        return true;
    }
}
