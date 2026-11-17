<?php

namespace App\Http\Controllers;

use App\Services\ExternalOAuthRevocationService;
use App\Services\GoogleAccessTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ExternalOAuthController extends Controller
{
    private const GOOGLE_SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/drive.file',
    ];

    private const GITHUB_API_VERSION = '2026-03-10';

    public function redirectToGoogle(): RedirectResponse
    {
        $clientId = config('services.google.client_id');
        $redirectUri = config('services.google.redirect');

        if (! filled($clientId) || ! filled(config('services.google.client_secret')) || ! filled($redirectUri)) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'Isi GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, dan GOOGLE_REDIRECT_URI sebelum menghubungkan Google.',
            ]);
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        session()->put([
            'oauth.google.state' => $state,
            'oauth.google.verifier' => $verifier,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::GOOGLE_SCOPES),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent select_account',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('oauth.google.state');
        $verifier = $request->session()->pull('oauth.google.verifier');

        if (! is_string($expectedState) || ! is_string($request->query('state')) || ! hash_equals($expectedState, $request->query('state'))) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'State OAuth tidak cocok. Hubungkan kembali akun Google.',
            ]);
        }

        if ($request->filled('error') || ! $request->filled('code') || ! is_string($verifier)) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'Akses Google tidak diberikan. Tidak ada koneksi yang disimpan.',
            ]);
        }

        try {
            $tokens = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code' => $request->string('code')->toString(),
                'code_verifier' => $verifier,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.google.redirect'),
            ])->throw()->json();
        } catch (Throwable) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'Google tidak dapat menyelesaikan koneksi. Coba lagi nanti.',
            ]);
        }

        if (! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['refresh_token'] ?? null)) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'Google tidak memberikan token akses jangka panjang. Koneksi lama dipertahankan; coba hubungkan ulang dan pilih akun Google.',
            ]);
        }

        $connection = $request->user()->integrations()->firstOrNew(['provider' => 'google']);
        $connection->fill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => isset($tokens['scope']) ? explode(' ', $tokens['scope']) : self::GOOGLE_SCOPES,
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return redirect()->route('integrations.index')->with('status', 'Akun Google terhubung. Sinkronisasi belum dijalankan.');
    }

    public function disconnectGoogle(Request $request, ExternalOAuthRevocationService $revocations): RedirectResponse
    {
        $connection = $request->user()->integrations()->where('provider', 'google')->first();

        if ($connection && ! $revocations->revoke($connection)) {
            return redirect()->route('integrations.index')->withErrors([
                'google' => 'Google belum mengonfirmasi pencabutan akses. Koneksi lokal tetap tersimpan; coba lagi.',
            ]);
        }

        if ($connection) {
            $connection->delete();
        }

        return redirect()->route('integrations.index')->with('status', 'Koneksi Google dihapus dari TUGAS!OS.');
    }

    public function googlePickerToken(Request $request, GoogleAccessTokenService $tokens): JsonResponse
    {
        if (! filled(config('services.google.picker_api_key')) || ! filled(config('services.google.app_id'))) {
            return response()->json(['message' => 'Google Picker belum dikonfigurasi.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        $connection = $request->user()->integrations()->where('provider', 'google')->where('status', 'connected')->first();
        $scopes = $connection->scopes ?? [];

        if (! $connection || ! in_array('https://www.googleapis.com/auth/drive.file', $scopes, true)) {
            return response()->json(['message' => 'Hubungkan ulang Google dan izinkan akses Drive terbatas.'], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        try {
            $accessToken = $tokens->for($connection);
        } catch (Throwable) {
            return response()->json(['message' => 'Token Google tidak dapat diperbarui. Hubungkan ulang akun.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['accessToken' => $accessToken])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function redirectToGitHub(): RedirectResponse
    {
        $clientId = config('services.github.client_id');
        $redirectUri = config('services.github.redirect');

        if (! filled($clientId) || ! filled(config('services.github.client_secret')) || ! filled($redirectUri)) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'Isi GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET, dan GITHUB_REDIRECT_URI sebelum menghubungkan GitHub.',
            ]);
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        session()->put([
            'oauth.github.state' => $state,
            'oauth.github.verifier' => $verifier,
        ]);

        return redirect()->away('https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'allow_signup' => 'false',
        ]));
    }

    public function handleGitHubCallback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('oauth.github.state');
        $verifier = $request->session()->pull('oauth.github.verifier');

        if (! is_string($expectedState) || ! is_string($request->query('state')) || ! hash_equals($expectedState, $request->query('state'))) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'State OAuth GitHub tidak cocok. Hubungkan kembali akun.',
            ]);
        }

        if ($request->filled('error') || ! $request->filled('code') || ! is_string($verifier)) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'Akses GitHub tidak diberikan. Tidak ada koneksi yang disimpan.',
            ]);
        }

        try {
            $token = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)
                ->post('https://github.com/login/oauth/access_token', [
                    'client_id' => config('services.github.client_id'),
                    'client_secret' => config('services.github.client_secret'),
                    'code' => $request->string('code')->toString(),
                    'code_verifier' => $verifier,
                    'redirect_uri' => config('services.github.redirect'),
                ])->throw()->json();

            $grantedScopes = preg_split('/[\s,]+/', (string) ($token['scope'] ?? '')) ?: [];

            if (! is_string($token['access_token'] ?? null) || ! in_array('read:user', $grantedScopes, true)) {
                throw new \UnexpectedValueException('GitHub did not grant the requested identity scope.');
            }

            $profile = Http::withToken($token['access_token'])->acceptJson()->withHeaders([
                'X-GitHub-Api-Version' => self::GITHUB_API_VERSION,
                'User-Agent' => 'TUGAS-OS/1.0',
            ])->connectTimeout(5)->timeout(15)->get('https://api.github.com/user')->throw()->json();
        } catch (Throwable) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'GitHub tidak dapat menyelesaikan koneksi. Coba lagi nanti.',
            ]);
        }

        if (! is_int($profile['id'] ?? null)
            || ! is_string($profile['login'] ?? null)
            || ! preg_match('/^[A-Za-z0-9-]{1,39}$/', $profile['login'])) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'Respons identitas GitHub tidak lengkap. Coba hubungkan kembali.',
            ]);
        }

        $connection = $request->user()->integrations()->firstOrNew(['provider' => 'github']);
        $connection->fill([
            'external_user_id' => (string) $profile['id'],
            'account_label' => $profile['login'],
            'access_token' => $token['access_token'],
            'refresh_token' => null,
            'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
            'scopes' => preg_split('/[\s,]+/', (string) ($token['scope'] ?? '')),
            'status' => 'connected',
            'last_error' => null,
            'settings' => [
                'login' => $profile['login'],
                'profile_url' => 'https://github.com/'.rawurlencode($profile['login']),
            ],
        ])->save();

        return redirect()->route('integrations.index')->with('status', 'GitHub terhubung. Aplikasi hanya meminta identitas dan menampilkan repository publik.');
    }

    public function disconnectGitHub(Request $request, ExternalOAuthRevocationService $revocations): RedirectResponse
    {
        $connection = $request->user()->integrations()->where('provider', 'github')->first();

        if (! $connection) {
            return redirect()->route('integrations.index')->with('status', 'Tidak ada koneksi GitHub aktif.');
        }

        if (! $revocations->revoke($connection)) {
            return redirect()->route('integrations.index')->withErrors([
                'github' => 'GitHub belum mengonfirmasi pencabutan token. Koneksi lokal tidak dihapus; coba lagi.',
            ]);
        }

        $connection->delete();

        return redirect()->route('integrations.index')->with('status', 'Token GitHub dicabut dan koneksi lokal dihapus.');
    }
}
