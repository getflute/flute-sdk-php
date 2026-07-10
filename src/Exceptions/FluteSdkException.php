<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * Abstract base for every exception the SDK throws. Catch this to
 * handle any SDK failure uniformly; catch a subclass to handle one specific
 * failure mode.
 */
abstract class FluteSdkException extends \RuntimeException
{
}
