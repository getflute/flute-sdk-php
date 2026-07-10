<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * Token acquisition failed, or a request was still rejected with HTTP 401 after
 * the automatic token refresh and retry. Credentials or permissions are
 * wrong; retrying without fixing them will not help.
 */
final class FluteAuthException extends FluteSdkException
{
}
