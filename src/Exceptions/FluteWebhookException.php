<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * verify() or verifySignature() was called with missing or empty parameters.
 * Treat the incoming webhook delivery as malformed and respond 400.
 */
final class FluteWebhookException extends FluteSdkException
{
}
