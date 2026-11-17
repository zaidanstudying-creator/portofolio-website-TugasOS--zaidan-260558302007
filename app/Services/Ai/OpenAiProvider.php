<?php

namespace App\Services\Ai;

use App\Exceptions\Services\Ai\AiProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProvider
{
    public function __construct(private ?string $apiKey = null, private ?string $configuredModel = null) {}

    public function forCredentials(?string $apiKey, ?string $model): self
    {
        return new self($apiKey, $model);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) ($this->configuredModel ?: config('services.ai.model', ''));
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey ?: config('services.ai.api_key')) && filled($this->model());
    }

    /**
     * @param  string|array<int, array<string, mixed>>  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function structured(string $schemaName, string $instructions, string|array $input, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw AiProviderUnavailableException::notConfigured($this->name());
        }

        $response = Http::acceptJson()
            ->withToken((string) ($this->apiKey ?: config('services.ai.api_key')))
            ->connectTimeout(5)
            ->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $this->model(),
                'store' => false,
                'instructions' => $instructions,
                'input' => $input,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ])->throw()->json();

        if (($response['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The AI provider did not complete the response.');
        }

        foreach ($response['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('The AI provider refused the request.');
                }

                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $decoded = json_decode($content['text'], true, flags: JSON_THROW_ON_ERROR);

                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        throw new RuntimeException('The AI provider returned no structured result.');
    }
}
