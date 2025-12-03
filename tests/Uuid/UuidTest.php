<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Uuid;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Uuid\Uuid;

class UuidTest extends TestCase
{
    public function testConstructorGeneratesRandomUuid(): void
    {
        $uuid1 = new Uuid();
        $uuid2 = new Uuid();

        $this->assertNotEquals($uuid1->toString(), $uuid2->toString());
        $this->assertNotEquals($uuid1->getHex(), $uuid2->getHex());
        $this->assertEquals(8, strlen($uuid1->getHex()));
        $this->assertEquals(8, strlen($uuid2->getHex()));
    }

    public function testConstructorWithValidUuidString(): void
    {
        $uuidString = '550e8400';
        $uuid = new Uuid($uuidString);

        $this->assertEquals($uuidString, $uuid->toString());
        $this->assertEquals($uuidString, $uuid->getHex());
    }

    public function testConstructorWithInvalidUuidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID format');

        new Uuid('invalid-uuid');
    }

    public function testConstructorWithWrongLengthThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected exactly 8 hexadecimal characters');

        new Uuid('1234567'); // 7 characters
    }

    public function testToString(): void
    {
        $uuidString = '550e8400';
        $uuid = new Uuid($uuidString);

        $this->assertEquals($uuidString, (string) $uuid);
    }

    public function testGetHex(): void
    {
        $uuidString = '550e8400';
        $uuid = new Uuid($uuidString);

        $this->assertEquals($uuidString, $uuid->getHex());
    }

    public function testRandom(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();

        $this->assertNotEquals($uuid1->toString(), $uuid2->toString());
        $this->assertInstanceOf(Uuid::class, $uuid1);
        $this->assertEquals(8, strlen($uuid1->getHex()));
        $this->assertEquals(8, strlen($uuid2->getHex()));
    }

    public function testFromString(): void
    {
        $uuidString = '550e8400';
        $uuid = Uuid::fromString($uuidString);

        $this->assertEquals($uuidString, $uuid->toString());
    }

    public function testFromStringWithInvalidUuidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uuid::fromString('invalid');
    }

    public function testFromHex(): void
    {
        $hex = '550e8400';
        $uuid = Uuid::fromHex($hex);

        $this->assertEquals($hex, $uuid->getHex());
        $this->assertEquals($hex, $uuid->toString());
    }

    public function testFromHexWithInvalidLengthThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected exactly 8 hexadecimal characters');

        Uuid::fromHex('invalid-length');
    }

    public function testFromHexWithValid8CharacterHex(): void
    {
        $hex = 'a0000000';
        $uuid = Uuid::fromHex($hex);

        $this->assertEquals($hex, $uuid->getHex());
    }

    public function testFromHexWithNonHexadecimalThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 8 hexadecimal characters');

        Uuid::fromHex('invalid!'); // 8 characters but not all hexadecimal
    }

    public function testConstructorRejectsNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID cannot be a string representation of an integer');

        new Uuid('12345678'); // 8 digits, but purely numeric
    }

    public function testFromHexRejectsNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID cannot be a string representation of an integer');

        Uuid::fromHex('12345678'); // 8 digits, but purely numeric
    }

    public function testFromStringRejectsNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID cannot be a string representation of an integer');

        Uuid::fromString('12345678'); // 8 digits, but purely numeric
    }

    public function testConstructorAcceptsUuidWithAtLeastOneLetter(): void
    {
        // Valid UUIDs that contain at least one letter
        $validUuids = ['1234567a', 'a1234567', '12345a67', '550e8400', 'deadbeef', 'ABCDEF01'];

        foreach ($validUuids as $uuidString) {
            $uuid = new Uuid($uuidString);
            $this->assertEquals($uuidString, $uuid->toString());
        }
    }
}
