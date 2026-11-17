<?php

namespace App\Exceptions\Services\Ai;

use Exception;

class AiProviderUnavailableException extends Exception
{
    public static function notConfigured(string $provider): self
    {
        return new self("AI provider [{$provider}] is not configured.");
    }
}
