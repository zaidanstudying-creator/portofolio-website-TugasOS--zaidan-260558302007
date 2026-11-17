<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAccessTokenService
{
    public function for(IntegrationConnection $connection): string
    {
        if ($connection->expires_at?->isAfter(now()->addMinute()) && filled($connection->access_token)) {
            return $connection->access_token;
        }

        if (! filled($connection->refresh_token)) {
            throw new RuntimeException('Google connection has no refresh token. Reconnect the account.');
        }

        try {
            $tokens = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ])->throw()->json();
        } catch (RequestException $exception) {
            if ($exception->response->json('error') === 'invalid_grant') {
                $connection->forceFill([
                    'status' => 'reauth_required',
                    'last_error' => 'Akses Google perlu diperbarui. Hubungkan ulang akun Google untuk melanjutkan sinkronisasi.',
                ])->save();

                throw new RuntimeException('Akses Google perlu diperbarui. Hubungkan ulang akun Google.', previous: $exception);
            }

            throw $exception;
        }

        if (! is_string($tokens['access_token'] ?? null)) {
            throw new \UnexpectedValueException('Google did not return a refreshed access token.');
        }

        $connection->update([
            'access_token' => $tokens['access_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ]);

        return $tokens['access_token'];
    }
}
