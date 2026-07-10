<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;

/**
 * Programmatic access to the token lifecycle.
 */
final class SessionsResource
{
    public function __construct(private readonly TokenManager $tokenManager)
    {
    }

    /**
     * Explicitly trigger token acquisition; returns the access token.
     *
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function authenticate(): string
    {
        return $this->tokenManager->getAccessToken();
    }

    /**
     * Return the current cached token, acquiring one first if not yet authenticated.
     *
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function getAccessToken(): string
    {
        return $this->tokenManager->getAccessToken();
    }

    /**
     * Force an immediate token refresh regardless of expiry state; returns the new token.
     *
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function refreshAccessToken(): string
    {
        return $this->tokenManager->refresh();
    }

    /** Invalidate and clear the cached token; the next API call re-authenticates. */
    public function clearStoredToken(): void
    {
        $this->tokenManager->clear();
    }
}
