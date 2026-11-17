<?php

namespace App\Services\Ai;

use App\Models\User;
use InvalidArgumentException;

class AiProviderRegistry
{
    /**
     * @var array<string, AiProvider>
     */
    private array $providers = [];

    /**
     * @param  iterable<AiProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            if (isset($this->providers[$provider->name()])) {
                throw new InvalidArgumentException("Duplicate AI provider [{$provider->name()}].");
            }

            $this->providers[$provider->name()] = $provider;
        }
    }

    public function forProvider(string $name): AiProvider
    {
        return $this->providers[$name]
            ?? throw new InvalidArgumentException("AI provider [{$name}] is not registered.");
    }

    public function forUser(User $user, ?string $name = null, ?string $model = null): AiProvider
    {
        $provider = $this->forProvider($name ?? $user->ai_provider ?? (string) config('services.ai.provider', 'openai'));

        if ($provider instanceof OpenAiProvider) {
            return $provider->forCredentials(
                $user->ai_api_key ?: (string) config('services.ai.api_key', ''),
                $model ?? $user->ai_model ?? (string) config('services.ai.model', ''),
            );
        }

        return $provider;
    }

    /**
     * @return array<string, string>
     */
    public function availableFor(User $user): array
    {
        $provider = $this->forUser($user);

        return $provider->isConfigured() ? [$provider->name() => ucfirst($provider->name())] : [];
    }

    /**
     * @return array<string, string>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                $available[$provider->name()] = ucfirst($provider->name());
            }
        }

        return $available;
    }
}
