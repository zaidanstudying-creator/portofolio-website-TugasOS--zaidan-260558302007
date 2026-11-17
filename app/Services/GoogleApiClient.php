<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoogleApiClient
{
    public function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(
                [250, 750],
                fn (int $attempt, Throwable $exception): int => $this->retryDelay($attempt, $exception),
                fn (Throwable $exception, PendingRequest $request, ?string $method): bool => $this->shouldRetry($exception, $method),
                throw: false,
            );
    }

    private function shouldRetry(Throwable $exception, ?string $method): bool
    {
        if (! in_array($method, ['GET', 'PATCH', 'DELETE'], true)) {
            return false;
        }

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
                || in_array(data_get($response->json(), 'error.errors.0.reason'), [
                    'rateLimitExceeded',
                    'userRateLimitExceeded',
                    'quotaExceeded',
                ], true)
            ));

        if (! $isRateLimited && ! $response->serverError()) {
            return false;
        }

        $providerDelay = $this->providerRetryDelay($exception);

        return $providerDelay === null || $providerDelay <= 3000;
    }

    private function retryDelay(int $attempt, Throwable $exception): int
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

        $retryAfter = trim($exception->response->header('Retry-After'));

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

        return null;
    }
}
