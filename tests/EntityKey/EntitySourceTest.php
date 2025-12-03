<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\EntityKey;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\EntityKey\EntitySource;

class EntitySourceTest extends TestCase
{
    public function testEntitySourceLocalCase(): void
    {
        $this->assertEquals('local', EntitySource::Local->value);
        $this->assertInstanceOf(EntitySource::class, EntitySource::Local);
    }

    public function testEntitySourceZebraCase(): void
    {
        $this->assertEquals('zebra', EntitySource::Zebra->value);
        $this->assertInstanceOf(EntitySource::class, EntitySource::Zebra);
    }

    public function testEntitySourceFromString(): void
    {
        $local = EntitySource::from('local');
        $this->assertEquals(EntitySource::Local, $local);

        $zebra = EntitySource::from('zebra');
        $this->assertEquals(EntitySource::Zebra, $zebra);
    }

    public function testEntitySourceFromStringThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        EntitySource::from('invalid');
    }

    public function testEntitySourceTryFrom(): void
    {
        $local = EntitySource::tryFrom('local');
        $this->assertEquals(EntitySource::Local, $local);

        $zebra = EntitySource::tryFrom('zebra');
        $this->assertEquals(EntitySource::Zebra, $zebra);

        $invalid = EntitySource::tryFrom('invalid');
        $this->assertNull($invalid);
    }
}
