<?php

declare(strict_types=1);

namespace Flute\Sdk\Enums;

/**
 * Payment method types accepted by Flute payment sessions.
 *
 * A backed string enum: the case values ('card', 'ach') are exactly what the
 * API expects in a payment session's "paymentMethodTypes" list. Wherever the
 * SDK accepts this enum it also accepts a plain string, so a payment method
 * type Flute introduces later can be used without waiting for an SDK release.
 */
enum PaymentMethodType: string
{
    case Card = 'card';
    case Ach = 'ach';
}
