<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GitHubRepositoryService
{
    private const API_VERSION = '2026-03-10';

    /**
     * @return list<array{id: int, full_name: string, html_url: string, description: ?string, language: ?string, stargazers_count: int, updated_at: ?string}>
     */
    public function publicRepositories(IntegrationConnection $connection): array
    {
        if ($connection->provider !== 'github' || $connection->status !== 'connected') {
            throw new RuntimeException('Hubungkan akun GitHub terlebih dahulu.');
        }

        $login = $connection->settings['login'] ?? null;

        if (! is_string($login) || ! preg_match('/^[A-Za-z0-9-]{1,39}$/', $login)) {
            throw new RuntimeException('Identitas akun GitHub tidak valid. Hubungkan ulang akun.');
        }

        $cacheKey = 'github-public-repositories-v3-'.$connection->id.'-'.($connection->external_user_id ?? 'unknown').'-'.hash('sha256', strtolower($login));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $login): array {
            $repositories = [];
            $page = 1;

            do {
                try {
                    $response = Http::withToken($connection->access_token)
                        ->acceptJson()
                        ->withHeaders([
                            'X-GitHub-Api-Version' => self::API_VERSION,
                            'User-Agent' => 'TUGAS-OS/1.0',
                        ])
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->retry(
                            [250, 750],
                            fn (int $attempt, Throwable $exception): int => $this->githubRetryDelay($attempt, $exception),
                            fn (Throwable $exception): bool => $this->shouldRetryGitHubRequest($exception),
                        )
                        ->get('https://api.github.com/users/'.rawurlencode($login).'/repos', [
                            'type' => 'owner',
                            'sort' => 'pushed',
                            'direction' => 'desc',
                            'per_page' => 100,
                            'page' => $page,
                        ]);
                    $pageRepositories = $response->throw()->json();
                } catch (RequestException $exception) {
                    if ($exception->response->status() === 401) {
                        $connection->forceFill([
                            'status' => 'reauth_required',
                            'last_error' => 'Akses GitHub tidak berlaku. Hubungkan ulang akun GitHub untuk melanjutkan.',
                        ])->save();
                    }

                    throw $exception;
                }

                if (! is_array($pageRepositories)) {
                    throw new RuntimeException('GitHub mengembalikan daftar repositori yang tidak valid.');
                }

                $repositories = array_merge($repositories, $pageRepositories);
                $nextPage = $this->nextPage($response->header('Link'));

                if ($nextPage === null || $nextPage <= $page) {
                    break;
                }

                $page = $nextPage;
            } while (true);

            return array_values(collect($repositories)
                ->filter(fn (mixed $repository): bool => is_array($repository)
                    && ($repository['private'] ?? true) === false
                    && is_int($repository['id'] ?? null)
                    && is_string($repository['full_name'] ?? null)
                    && is_string($repository['html_url'] ?? null)
                    && parse_url($repository['html_url'], PHP_URL_HOST) === 'github.com')
                ->map(fn (array $repository): array => [
                    'id' => $repository['id'],
                    'full_name' => $repository['full_name'],
                    'html_url' => $repository['html_url'],
                    'description' => is_string($repository['description'] ?? null) ? $repository['description'] : null,
                    'language' => is_string($repository['language'] ?? null) ? $repository['language'] : null,
                    'stargazers_count' => (int) ($repository['stargazers_count'] ?? 0),
                    'updated_at' => is_string($repository['updated_at'] ?? null) ? $repository['updated_at'] : null,
                ])
                ->values()
                ->all());
        });
    }

    private function nextPage(?string $linkHeader): ?int
    {
        foreach (explode(',', $linkHeader ?? '') as $link) {
            if (preg_match('/<([^>]+)>;\\s*rel="next"/', trim($link), $matches) !== 1) {
                continue;
            }

            $queryString = parse_url($matches[1], PHP_URL_QUERY);

            if (! is_string($queryString)) {
                return null;
            }

            parse_str($queryString, $query);
            $page = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT);

            return is_int($page) && $page > 0 ? $page : null;
        }

        return null;
    }

    private function shouldRetryGitHubRequest(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;
        $isRateLimited = $response->status() === 429
            || ($response->status() === 403 && (
                $response->header('Retry-After') !== ''
                || $response->header('X-RateLimit-Remaining') === '0'
            ));

        if (! $isRateLimited && ! $response->serverError()) {
            return false;
        }

        $retryAfter = $this->providerRetryDelay($exception);

        return $retryAfter === null || $retryAfter <= 3000;
    }

    private function githubRetryDelay(int $attempt, Throwable $exception): int
    {
        $providerDelay = $this->providerRetryDelay($exception);

        if ($providerDelay !== null) {
            return $providerDelay;
        }

        return [250, 750][$attempt - 1] ?? 750;
    }

    private function providerRetryDelay(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $retryAfter = trim((string) $exception->response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                return (int) $retryAfter * 1000;
            }

            $retryAt = strtotime($retryAfter);

            if (is_int($retryAt)) {
                $secondsUntilRetry = $retryAt - time();

                return $secondsUntilRetry > 0 ? $secondsUntilRetry * 1000 : 0;
            }
        }

        $rateLimitReset = $exception->response->header('X-RateLimit-Reset');

        if ($exception->response->header('X-RateLimit-Remaining') === '0'
            && ctype_digit($rateLimitReset)) {
            $secondsUntilRetry = (int) $rateLimitReset - time();

            return $secondsUntilRetry > 0 ? $secondsUntilRetry * 1000 : 0;
        }

        return null;
    }

    /**
     * @return array{id: int, full_name: string, html_url: string}
     */
    public function linkPublicRepository(User $user, Task $project, int $repositoryId): array
    {
        if ($project->user_id !== $user->id || $project->parent_task_id !== null) {
            throw new RuntimeException('Pilih proyek induk milik akun ini.');
        }

        $connection = $user->integrations()->where('provider', 'github')->where('status', 'connected')->firstOrFail();
        $repository = collect($this->publicRepositories($connection))->firstWhere('id', $repositoryId);

        if (! is_array($repository)) {
            throw new RuntimeException('Repositori publik tidak ditemukan pada akun GitHub yang terhubung. Muat ulang daftar repositori.');
        }

        $project->links()->firstOrCreate(
            ['url' => $repository['html_url']],
            [
                'user_id' => $user->id,
                'label' => 'GitHub · '.$repository['full_name'],
                'kind' => 'repository',
            ],
        );

        $connection->mappings()->updateOrCreate(
            ['resource_type' => 'github_repository', 'internal_id' => (string) $project->id],
            [
                'external_id' => (string) $repository['id'],
                'external_container_id' => $repository['full_name'],
                'sync_hash' => hash('sha256', json_encode($repository, JSON_THROW_ON_ERROR)),
                'last_synced_at' => now(),
                'status' => 'linked',
            ],
        );

        $connection->syncEvents()->create([
            'provider' => 'github',
            'direction' => 'inbound',
            'action' => 'link_repository',
            'status' => 'succeeded',
            'context' => ['task_id' => $project->id, 'repository_id' => $repository['id'], 'full_name' => $repository['full_name']],
        ]);
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);

        return $repository;
    }
}
