<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\EntityKey;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Uuid\Uuid;

class EntityKeyTest extends TestCase
{
    public function testCreateLocalWithUuidInterface(): void
    {
        $uuid = Uuid::random();
        $entityKey = EntityKey::local($uuid);

        $this->assertSame(EntitySource::Local, $entityKey->source);
        $this->assertInstanceOf(\Tcrawf\Zebra\Uuid\UuidInterface::class, $entityKey->id);
        $this->assertSame($uuid->getHex(), $entityKey->id->getHex());
    }

    public function testCreateLocalWithString(): void
    {
        $uuidString = 'a1b2c3d4';
        $entityKey = EntityKey::local($uuidString);

        $this->assertSame(EntitySource::Local, $entityKey->source);
        $this->assertInstanceOf(\Tcrawf\Zebra\Uuid\UuidInterface::class, $entityKey->id);
        $this->assertSame($uuidString, $entityKey->id->getHex());
    }

    public function testCreateZebraWithInt(): void
    {
        $id = 123;
        $entityKey = EntityKey::zebra($id);

        $this->assertSame(EntitySource::Zebra, $entityKey->source);
        $this->assertIsInt($entityKey->id);
        $this->assertSame($id, $entityKey->id);
    }

    public function testCreateZebraWithStringInt(): void
    {
        $idString = '456';
        $entityKey = EntityKey::zebra($idString);

        $this->assertSame(EntitySource::Zebra, $entityKey->source);
        $this->assertIsInt($entityKey->id);
        $this->assertSame(456, $entityKey->id);
    }

    public function testCreateZebraWithNegativeIntString(): void
    {
        $idString = '-789';
        $entityKey = EntityKey::zebra($idString);

        $this->assertSame(EntitySource::Zebra, $entityKey->source);
        $this->assertIsInt($entityKey->id);
        $this->assertSame(-789, $entityKey->id);
    }

    public function testLocalRejectsInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Local source requires UuidInterface or valid UUID string');

        new EntityKey(EntitySource::Local, 123);
    }

    public function testLocalRejectsInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID format');

        new EntityKey(EntitySource::Local, 'invalid');
    }

    public function testZebraRejectsUuidInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Zebra source requires int or string representing an int');

        new EntityKey(EntitySource::Zebra, Uuid::random());
    }

    public function testZebraRejectsInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Zebra source requires int or string representing an int');

        new EntityKey(EntitySource::Zebra, 'not-an-int');
    }

    public function testToStringLocal(): void
    {
        $uuid = Uuid::random();
        $entityKey = EntityKey::local($uuid);

        $string = (string) $entityKey;
        $this->assertStringContainsString('EntityKey', $string);
        $this->assertStringContainsString('source=local', $string);
        $this->assertStringContainsString($uuid->getHex(), $string);
    }

    public function testToStringZebra(): void
    {
        $entityKey = EntityKey::zebra(123);

        $string = (string) $entityKey;
        $this->assertStringContainsString('EntityKey', $string);
        $this->assertStringContainsString('source=zebra', $string);
        $this->assertStringContainsString('123', $string);
    }

    public function testToStringMethodLocal(): void
    {
        $uuid = Uuid::random();
        $entityKey = EntityKey::local($uuid);

        $this->assertSame($uuid->getHex(), $entityKey->toString());
    }

    public function testToStringMethodZebra(): void
    {
        $entityKey = EntityKey::zebra(456);

        $this->assertSame('456', $entityKey->toString());
    }
}
