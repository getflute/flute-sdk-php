<?php

declare(strict_types=1);

namespace Flute\Sdk;

/**
 * Single source of truth for the SDK version (consumed by Flute::VERSION and the
 * User-Agent header in ApiClient).
 */
final class Version
{
    public const VERSION = '1.1.0';
}
