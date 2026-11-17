<?php

namespace App\Exceptions\Services\Ai;

use Exception;

class AiRateLimitException extends Exception
{
    public function __construct(public int $retryAfterSeconds)
    {
        parent::__construct('AI request rate limit exceeded.');
    }
}
