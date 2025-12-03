<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Role;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Role\Role;

class RoleTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $role = new Role(
            id: 1,
            parentId: 2,
            name: 'Developer',
            fullName: 'Senior Developer',
            type: 'employee',
            status: 'active'
        );

        $this->assertEquals(1, $role->id);
        $this->assertEquals(2, $role->parentId);
        $this->assertEquals('Developer', $role->name);
        $this->assertEquals('Senior Developer', $role->fullName);
        $this->assertEquals('employee', $role->type);
        $this->assertEquals('active', $role->status);
    }

    public function testConstructorWithMinimalParameters(): void
    {
        $role = new Role(id: 1);

        $this->assertEquals(1, $role->id);
        $this->assertNull($role->parentId);
        $this->assertEquals('', $role->name);
        $this->assertEquals('', $role->fullName);
        $this->assertEquals('', $role->type);
        $this->assertEquals('', $role->status);
    }

    public function testToString(): void
    {
        $role = new Role(
            id: 1,
            name: 'Developer',
            fullName: 'Senior Developer'
        );

        $string = (string) $role;

        $this->assertStringContainsString('id=1', $string);
        $this->assertStringContainsString('name=Developer', $string);
        $this->assertStringContainsString('fullName=Senior Developer', $string);
    }

    public function testToStringWithEmptyNames(): void
    {
        $role = new Role(id: 1);

        $string = (string) $role;

        $this->assertStringContainsString('id=1', $string);
        $this->assertStringContainsString('name=', $string);
        $this->assertStringContainsString('fullName=', $string);
    }
}
