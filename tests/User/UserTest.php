<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\User;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\User\User;

class UserTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $role1 = new Role(1, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $role2 = new Role(2, 1, 'Manager', 'Project Manager', 'employee', 'active');
        $roles = [$role1, $role2];

        $user = new User(
            id: 100,
            username: 'jdoe',
            firstname: 'John',
            lastname: 'Doe',
            name: 'John Doe',
            email: 'john.doe@example.com',
            alternativeEmail: 'jdoe@example.com',
            employeeType: 'full-time',
            employeeStatus: 'active',
            roles: $roles
        );

        $this->assertEquals(100, $user->id);
        $this->assertEquals('jdoe', $user->username);
        $this->assertEquals('John', $user->firstname);
        $this->assertEquals('Doe', $user->lastname);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john.doe@example.com', $user->email);
        $this->assertEquals('jdoe@example.com', $user->alternativeEmail);
        $this->assertEquals('full-time', $user->employeeType);
        $this->assertEquals('active', $user->employeeStatus);
        $this->assertCount(2, $user->roles);
        $this->assertEquals($role1, $user->roles[0]);
        $this->assertEquals($role2, $user->roles[1]);
    }

    public function testConstructorWithMinimalParameters(): void
    {
        $user = new User(
            id: 200,
            username: 'asmith',
            firstname: 'Alice',
            lastname: 'Smith',
            name: 'Alice Smith',
            email: 'alice.smith@example.com'
        );

        $this->assertEquals(200, $user->id);
        $this->assertEquals('asmith', $user->username);
        $this->assertEquals('Alice', $user->firstname);
        $this->assertEquals('Smith', $user->lastname);
        $this->assertEquals('Alice Smith', $user->name);
        $this->assertEquals('alice.smith@example.com', $user->email);
        $this->assertNull($user->alternativeEmail);
        $this->assertEquals('', $user->employeeType);
        $this->assertNull($user->employeeStatus);
        $this->assertEmpty($user->roles);
    }

    public function testToString(): void
    {
        $user = new User(
            id: 300,
            username: 'bjones',
            firstname: 'Bob',
            lastname: 'Jones',
            name: 'Bob Jones',
            email: 'bob.jones@example.com'
        );

        $string = (string) $user;
        $this->assertStringContainsString('User(id=300', $string);
        $this->assertStringContainsString('username=bjones', $string);
        $this->assertStringContainsString('name=Bob Jones', $string);
        $this->assertStringContainsString('email=bob.jones@example.com', $string);
    }

    public function testUserWithRoles(): void
    {
        $role = new Role(5, null, 'Admin', 'Administrator', 'employee', 'active');
        $user = new User(
            id: 400,
            username: 'admin',
            firstname: 'Admin',
            lastname: 'User',
            name: 'Admin User',
            email: 'admin@example.com',
            roles: [$role]
        );

        $this->assertCount(1, $user->roles);
        $this->assertEquals(5, $user->roles[0]->id);
        $this->assertEquals('Admin', $user->roles[0]->name);
    }

    public function testUserWithNullAlternativeEmail(): void
    {
        $user = new User(
            id: 500,
            username: 'test',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            alternativeEmail: null
        );

        $this->assertNull($user->alternativeEmail);
    }

    public function testUserWithEmptyRoles(): void
    {
        $user = new User(
            id: 600,
            username: 'empty',
            firstname: 'Empty',
            lastname: 'Roles',
            name: 'Empty Roles',
            email: 'empty@example.com',
            roles: []
        );

        $this->assertEmpty($user->roles);
    }

    public function testFindRoleByName(): void
    {
        $role1 = new Role(10, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $role2 = new Role(11, null, 'Project Manager', 'Project Manager', 'employee', 'active');
        $role3 = new Role(12, null, 'QA Tester', 'Quality Assurance Tester', 'employee', 'active');
        $user = new User(
            id: 700,
            username: 'frole',
            firstname: 'Frank',
            lastname: 'Role',
            name: 'Frank Role',
            email: 'frank.role@example.com',
            roles: [$role1, $role2, $role3]
        );

        $found = $user->findRoleByName('Developer');

        $this->assertNotNull($found);
        $this->assertEquals(10, $found->id);
        $this->assertEquals('Developer', $found->name);
    }

    public function testFindRoleByNameCaseInsensitive(): void
    {
        $role = new Role(20, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $user = new User(
            id: 800,
            username: 'grole',
            firstname: 'Grace',
            lastname: 'Role',
            name: 'Grace Role',
            email: 'grace.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('DEVELOPER');

        $this->assertNotNull($found);
        $this->assertEquals(20, $found->id);
        $this->assertEquals('Developer', $found->name);
    }

    public function testFindRoleByNamePartialMatch(): void
    {
        $role = new Role(30, null, 'Project Manager', 'Project Manager', 'employee', 'active');
        $user = new User(
            id: 900,
            username: 'hrole',
            firstname: 'Henry',
            lastname: 'Role',
            name: 'Henry Role',
            email: 'henry.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('Manager');

        $this->assertNotNull($found);
        $this->assertEquals(30, $found->id);
        $this->assertEquals('Project Manager', $found->name);
    }

    public function testFindRoleByNameNotFound(): void
    {
        $role = new Role(40, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $user = new User(
            id: 1000,
            username: 'irole',
            firstname: 'Iris',
            lastname: 'Role',
            name: 'Iris Role',
            email: 'iris.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('Manager');

        $this->assertNull($found);
    }

    public function testFindRoleByNameWithEmptyRoles(): void
    {
        $user = new User(
            id: 1100,
            username: 'jrole',
            firstname: 'Jack',
            lastname: 'Role',
            name: 'Jack Role',
            email: 'jack.role@example.com',
            roles: []
        );

        $found = $user->findRoleByName('Developer');

        $this->assertNull($found);
    }

    public function testFindRoleByNameReturnsFirstMatch(): void
    {
        $role1 = new Role(50, null, 'Senior Developer', 'Senior Developer', 'employee', 'active');
        $role2 = new Role(51, null, 'Junior Developer', 'Junior Developer', 'employee', 'active');
        $user = new User(
            id: 1200,
            username: 'krole',
            firstname: 'Kevin',
            lastname: 'Role',
            name: 'Kevin Role',
            email: 'kevin.role@example.com',
            roles: [$role1, $role2]
        );

        $found = $user->findRoleByName('Developer');

        $this->assertNotNull($found);
        // Should return the first match (Senior Developer)
        $this->assertEquals(50, $found->id);
        $this->assertEquals('Senior Developer', $found->name);
    }

    public function testFindRoleByNameWithMixedCaseRoleName(): void
    {
        $role = new Role(60, null, 'DeVeLoPeR', 'Developer', 'employee', 'active');
        $user = new User(
            id: 1300,
            username: 'lrole',
            firstname: 'Lisa',
            lastname: 'Role',
            name: 'Lisa Role',
            email: 'lisa.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('developer');

        $this->assertNotNull($found);
        $this->assertEquals(60, $found->id);
        $this->assertEquals('DeVeLoPeR', $found->name);
    }

    public function testFindRoleByNameWithEmptySearchString(): void
    {
        $role = new Role(70, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $user = new User(
            id: 1400,
            username: 'mrole',
            firstname: 'Mike',
            lastname: 'Role',
            name: 'Mike Role',
            email: 'mike.role@example.com',
            roles: [$role]
        );

        // Empty string should match any role name (contains check)
        $found = $user->findRoleByName('');

        $this->assertNotNull($found);
        $this->assertEquals(70, $found->id);
    }

    public function testFindRoleByNameWithSpecialCharacters(): void
    {
        $role = new Role(80, null, 'C++ Developer', 'C++ Developer', 'employee', 'active');
        $user = new User(
            id: 1500,
            username: 'nrole',
            firstname: 'Nancy',
            lastname: 'Role',
            name: 'Nancy Role',
            email: 'nancy.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('++');

        $this->assertNotNull($found);
        $this->assertEquals(80, $found->id);
        $this->assertEquals('C++ Developer', $found->name);
    }

    public function testFindRoleByNameWithSingleCharacterMatch(): void
    {
        $role = new Role(90, null, 'Admin', 'Administrator', 'employee', 'active');
        $user = new User(
            id: 1600,
            username: 'orole',
            firstname: 'Oliver',
            lastname: 'Role',
            name: 'Oliver Role',
            email: 'oliver.role@example.com',
            roles: [$role]
        );

        $found = $user->findRoleByName('A');

        $this->assertNotNull($found);
        $this->assertEquals(90, $found->id);
        $this->assertEquals('Admin', $found->name);
    }
}
