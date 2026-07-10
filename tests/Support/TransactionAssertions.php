<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Support;

use Flute\Sdk\Models\Responses\TransactionResponse;
use PHPUnit\Framework\Assert;

/**
 * Transaction status assertions with processor diagnostics on failure.
 */
trait TransactionAssertions
{
    public static function assertTransactionStatus(
        string $expected,
        TransactionResponse $response,
        string $message = '',
    ): void {
        $diagnostics = sprintf(
            'status=%s statusId=%s details.code=%s details.message=%s',
            $response->status ?? 'null',
            $response->statusId ?? 'null',
            $response->details->code ?? 'null',
            $response->details->message ?? 'null',
        );

        Assert::assertSame(
            $expected,
            $response->status,
            trim($message . ' [' . $diagnostics . ']'),
        );
    }
}
