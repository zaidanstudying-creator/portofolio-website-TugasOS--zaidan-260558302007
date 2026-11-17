<?php

namespace App\Services\Ai;

interface AiProvider
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    /**
     * @param  string|array<int, array<string, mixed>>  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function structured(string $schemaName, string $instructions, string|array $input, array $schema): array;
}
