<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalOAuthRevocationService
{
    private const GITHUB_API_VERSION = '2026-03-10';

    public function revoke(IntegrationConnection $connection): bool
    {
        return match ($connection->provider) {
            'google' => $this->revokeGoogle($connection),
            'github' => $this->revokeGitHub($connection),
            default => false,
        };
    }

    private function revokeGoogle(IntegrationConnection $connection): bool
    {
        $tokens = array_values(array_unique(array_filter([
            $connection->refresh_token,
            $connection->access_token,
        ], 'is_string')));

        if ($tokens === []) {
            return true;
        }

        foreach ($tokens as $token) {
            try {
                $response = Http::asForm()->connectTimeout(3)->timeout(5)->post('https://oauth2.googleapis.com/revoke', [
                    'token' => $token,
                ]);

                if ($response->successful()) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    private function revokeGitHub(IntegrationConnection $connection): bool
    {
        $accessToken = $connection->access_token;

        if (! filled($accessToken)) {
            return true;
        }

        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');

        if (! filled($clientId) || ! filled($clientSecret)) {
            return false;
        }

        try {
            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->withHeaders([
                    'X-GitHub-Api-Version' => self::GITHUB_API_VERSION,
                    'User-Agent' => 'TUGAS-OS/1.0',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->delete('https://api.github.com/applications/'.rawurlencode((string) $clientId).'/grant', [
                    'access_token' => $accessToken,
                ]);

            return in_array($response->status(), [204, 404], true);
        } catch (Throwable) {
            return false;
        }
    }
}
