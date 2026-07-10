<?php

declare(strict_types=1);

namespace Flute\Sdk\Enums;

/**
 * The Flute deployment an SDK instance targets.
 *
 * A backed string enum: the case values ('sandbox', 'production') are exactly
 * what callers may pass to the Flute constructor's "environment" config key, and
 * this enum is the single source of truth for the corresponding API and OAuth
 * hostnames (the OAuth host values are the ones Flute specifies). Override those
 * hosts via the "baseUrl"/"oauthBaseUrl" config keys.
 */
enum Environment: string
{
    case SANDBOX = 'sandbox';
    case PRODUCTION = 'production';

    /** Base URL of the REST API for this environment (no trailing slash). */
    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://sandbox.api.flute.com',
            self::PRODUCTION => 'https://api.flute.com',
        };
    }

    /** Base URL of the OAuth token service for this environment (no trailing slash). */
    public function oauthBaseUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://sandbox.oauth.api.flute.com',
            self::PRODUCTION => 'https://oauth.api.flute.com',
        };
    }
}
