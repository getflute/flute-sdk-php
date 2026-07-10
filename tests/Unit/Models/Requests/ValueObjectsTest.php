<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Requests;

use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\ContactInfo;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function testAddressSerializesNonNullFieldsOnly(): void
    {
        $address = new Address(line1: '123 Test St', postalCode: '10001');

        self::assertSame(['line1' => '123 Test St', 'postalCode' => '10001'], $address->toArray());
    }

    public function testContactInfoSerializesNonNullFieldsOnly(): void
    {
        $contact = new ContactInfo(firstName: 'Ada', email: 'ada@example.com');

        self::assertSame(['firstName' => 'Ada', 'email' => 'ada@example.com'], $contact->toArray());
    }

    public function testFalsyNonNullValuesSurviveSerialization(): void
    {
        $contact = new ContactInfo(firstName: '', smsNotification: false);
        self::assertSame(['firstName' => '', 'smsNotification' => false], $contact->toArray());

        $address = new Address(line2: '', stateId: 0);
        self::assertSame(['line2' => '', 'stateId' => 0], $address->toArray());
    }
}
