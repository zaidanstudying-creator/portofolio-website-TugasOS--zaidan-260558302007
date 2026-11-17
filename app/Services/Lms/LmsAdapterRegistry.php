<?php

namespace App\Services\Lms;

use App\Exceptions\Services\Lms\LmsAdapterUnavailableException;
use InvalidArgumentException;
use LogicException;

class LmsAdapterRegistry
{
    /** @var array<string, LmsAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<LmsAdapter>  $adapters
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $platform = $adapter->platform();

            if (! preg_match('/^[a-z0-9_-]{2,40}$/', $platform)) {
                throw new InvalidArgumentException('LMS adapter platform keys must be lowercase identifiers.');
            }

            if (array_key_exists($platform, $this->adapters)) {
                throw new LogicException("An LMS adapter is already registered for [{$platform}].");
            }

            $this->adapters[$platform] = $adapter;
        }
    }

    public function forPlatform(string $platform): LmsAdapter
    {
        return $this->adapters[$platform]
            ?? throw new LmsAdapterUnavailableException("No verified submission connector is configured for [{$platform}].");
    }

    /**
     * @return array<string, string>
     */
    public function available(): array
    {
        $adapters = [];

        foreach ($this->adapters as $key => $adapter) {
            $adapters[$key] = $adapter->label();
        }

        return $adapters;
    }
}
