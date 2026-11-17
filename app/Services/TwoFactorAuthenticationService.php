<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class TwoFactorAuthenticationService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function hasEnabled(User $user): bool
    {
        return filled($user->two_factor_secret) && filled($user->two_factor_confirmed_at);
    }

    public function beginSetup(User $user): string
    {
        if ($this->hasEnabled($user)) {
            throw new \RuntimeException('Two-factor authentication is already enabled.');
        }

        $secret = $this->encodeBase32(random_bytes(20));
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * @return list<string>|null
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if ($this->hasEnabled($user) || ! $this->verifyTotp($user, $code)) {
            return null;
        }

        return DB::transaction(function () use ($user, $code): ?array {
            $lockedUser = User::query()->lockForUpdate()->whereKey($user->getKey())->firstOrFail();

            if ($this->hasEnabled($lockedUser) || ! $this->verifyTotp($lockedUser, $code)) {
                return null;
            }

            $recoveryCodes = $this->newRecoveryCodes();
            $lockedUser->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
                'two_factor_confirmed_at' => now(),
            ])->save();

            return $recoveryCodes;
        });
    }

    public function verifyTotp(User $user, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code) || ! filled($user->two_factor_secret)) {
            return false;
        }

        try {
            $secret = decrypt($user->two_factor_secret);
            $key = $this->decodeBase32($secret);
        } catch (Throwable) {
            return false;
        }

        if ($key === '') {
            return false;
        }

        $timeCounter = intdiv(now()->getTimestamp(), 30);

        for ($offset = -1; $offset <= 1; $offset++) {
            $counter = $timeCounter + $offset;
            $message = pack('N2', intdiv($counter, 4_294_967_296), $counter % 4_294_967_296);
            $digest = hash_hmac('sha1', $message, $key, true);
            $index = ord($digest[19]) & 0x0F;
            $unpacked = unpack('N', substr($digest, $index, 4));

            if ($unpacked === false) {
                continue;
            }

            $binary = $unpacked[1] & 0x7FFFFFFF;
            $expected = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    public function verify(User $user, string $code): bool
    {
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $lockedUser = User::query()->lockForUpdate()->whereKey($user->getKey())->first();

            if (! $lockedUser || ! $this->hasEnabled($lockedUser)) {
                return false;
            }

            $recoveryCodes = $this->decryptedRecoveryCodes($lockedUser);

            foreach ($recoveryCodes as $index => $recoveryCode) {
                if (hash_equals(mb_strtolower($recoveryCode), mb_strtolower(trim($code)))) {
                    unset($recoveryCodes[$index]);
                    $lockedUser->forceFill([
                        'two_factor_recovery_codes' => encrypt(json_encode(array_values($recoveryCodes), JSON_THROW_ON_ERROR)),
                    ])->save();

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return list<string>|null
     */
    public function regenerateRecoveryCodes(User $user, string $code): ?array
    {
        if (! $this->hasEnabled($user) || ! $this->verifyTotp($user, $code)) {
            return null;
        }

        $recoveryCodes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
        ])->save();

        return $recoveryCodes;
    }

    public function disable(User $user, string $code): bool
    {
        if (! $this->hasEnabled($user) || ! $this->verify($user, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    private function decryptedRecoveryCodes(User $user): array
    {
        if (! filled($user->two_factor_recovery_codes)) {
            return [];
        }

        try {
            $codes = json_decode(decrypt($user->two_factor_recovery_codes), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($codes)
            ? array_values(array_filter($codes, fn (mixed $code): bool => is_string($code)))
            : [];
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn (): string => strtoupper(bin2hex(random_bytes(5))),
            range(1, 10),
        );
    }

    private function encodeBase32(string $value): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (unpack('C*', $value) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }

        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function decodeBase32(string $value): string
    {
        $encoded = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        $buffer = 0;
        $bits = 0;
        $decoded = '';

        foreach (str_split($encoded) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);

            if ($index === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xFF);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
    }
}
