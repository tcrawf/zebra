<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\User;

use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Cache\CacheFileStorageFactoryInterface;
use Tcrawf\Zebra\Config\ConfigFileStorage;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Tests\Helper\RepositoryTestCase;
use Tcrawf\Zebra\User\UserApiServiceInterface;
use Tcrawf\Zebra\User\UserRepository;

class UserRepositoryTest extends RepositoryTestCase
{
    private UserApiServiceInterface&MockObject $apiService;
    private CacheFileStorageFactoryInterface&MockObject $cacheStorageFactory;
    private ConfigFileStorage $configStorage;
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(UserApiServiceInterface::class);
        $this->cacheStorageFactory = $this->createMock(CacheFileStorageFactoryInterface::class);
        $this->configStorage = new ConfigFileStorage();
        $this->repository = new UserRepository(
            $this->apiService,
            $this->cacheStorageFactory,
            $this->configStorage
        );
    }

    public function testGetCurrentUserWithConfigUserId(): void
    {
        $userId = 100;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_100.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'jdoe',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $user = $this->repository->getCurrentUser();

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertEquals('jdoe', $user->username);
    }

    public function testGetCurrentUserWithoutConfigUserId(): void
    {
        $this->configStorage->delete('user.id');

        $user = $this->repository->getCurrentUser();

        $this->assertNull($user);
    }

    public function testGetByIdFromCache(): void
    {
        $userId = 200;
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_200.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'asmith',
                'firstname' => 'Alice',
                'lastname' => 'Smith',
                'name' => 'Alice Smith',
                'email' => 'alice.smith@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        // API should not be called when cache exists
        $this->apiService
            ->expects($this->never())
            ->method('fetchById');

        $user = $this->repository->getById($userId);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertEquals('asmith', $user->username);
    }

    public function testGetByIdFromApiWhenCacheEmpty(): void
    {
        $userId = 300;
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->exactly(3))
            ->method('create')
            ->with('user_300.json')
            ->willReturn($cacheStorage);

        // First call: cache is empty
        $cacheStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                [], // First read: empty cache
                [ // Second read: after saving
                    'user' => [
                        'id' => $userId,
                        'username' => 'bjones',
                        'firstname' => 'Bob',
                        'lastname' => 'Jones',
                        'name' => 'Bob Jones',
                        'email' => 'bob.jones@example.com',
                    ],
                    'roles' => []
                ]
            );

        $apiData = [
            'user' => [
                'id' => $userId,
                'username' => 'bjones',
                'firstname' => 'Bob',
                'lastname' => 'Jones',
                'name' => 'Bob Jones',
                'email' => 'bob.jones@example.com',
            ],
            'roles' => []
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($apiData);

        $cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($apiData);

        $user = $this->repository->getById($userId);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertEquals('bjones', $user->username);
    }

    public function testGetByIdFromApiWhenCacheMissingUserKey(): void
    {
        $userId = 400;
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->exactly(3))
            ->method('create')
            ->with('user_400.json')
            ->willReturn($cacheStorage);

        // Cache exists but missing 'user' key
        $cacheStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                ['some' => 'data'], // First read: missing 'user' key
                [ // Second read: after saving
                    'user' => [
                        'id' => $userId,
                        'username' => 'ctaylor',
                        'firstname' => 'Charlie',
                        'lastname' => 'Taylor',
                        'name' => 'Charlie Taylor',
                        'email' => 'charlie.taylor@example.com',
                    ],
                    'roles' => []
                ]
            );

        $apiData = [
            'user' => [
                'id' => $userId,
                'username' => 'ctaylor',
                'firstname' => 'Charlie',
                'lastname' => 'Taylor',
                'name' => 'Charlie Taylor',
                'email' => 'charlie.taylor@example.com',
            ],
            'roles' => []
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($apiData);

        $cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($apiData);

        $user = $this->repository->getById($userId);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
    }

    public function testGetByEmail(): void
    {
        $email = 'david.wilson@example.com';
        $userId = 500;

        // First call: fetchAll to find user by email
        $allUsers = [
            $userId => [
                'email' => $email,
                'username' => 'dwilson',
            ],
            600 => [
                'email' => 'other@example.com',
                'username' => 'other',
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($allUsers);

        // Second call: getById to fetch and cache the user
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->exactly(3))
            ->method('create')
            ->with('user_500.json')
            ->willReturn($cacheStorage);

        $cacheStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                [], // Cache empty
                [ // After saving
                    'user' => [
                        'id' => $userId,
                        'username' => 'dwilson',
                        'firstname' => 'David',
                        'lastname' => 'Wilson',
                        'name' => 'David Wilson',
                        'email' => $email,
                    ],
                    'roles' => []
                ]
            );

        $apiData = [
            'user' => [
                'id' => $userId,
                'username' => 'dwilson',
                'firstname' => 'David',
                'lastname' => 'Wilson',
                'name' => 'David Wilson',
                'email' => $email,
            ],
            'roles' => []
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($apiData);

        $cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($apiData);

        $user = $this->repository->getByEmail($email);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertEquals($email, $user->email);
    }

    public function testGetByEmailCaseInsensitive(): void
    {
        $email = 'EVA.BROWN@EXAMPLE.COM';
        $userId = 700;

        $allUsers = [
            $userId => [
                'email' => 'eva.brown@example.com',
                'username' => 'ebrown',
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($allUsers);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->exactly(3))
            ->method('create')
            ->with('user_700.json')
            ->willReturn($cacheStorage);

        $cacheStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    'user' => [
                        'id' => $userId,
                        'username' => 'ebrown',
                        'firstname' => 'Eva',
                        'lastname' => 'Brown',
                        'name' => 'Eva Brown',
                        'email' => 'eva.brown@example.com',
                    ],
                    'roles' => []
                ]
            );

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn([
                'user' => [
                    'id' => $userId,
                    'username' => 'ebrown',
                    'firstname' => 'Eva',
                    'lastname' => 'Brown',
                    'name' => 'Eva Brown',
                    'email' => 'eva.brown@example.com',
                ],
                'roles' => []
            ]);

        $cacheStorage
            ->expects($this->once())
            ->method('write');

        $user = $this->repository->getByEmail($email);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
    }

    public function testGetByEmailNotFound(): void
    {
        $email = 'nonexistent@example.com';

        $allUsers = [
            100 => [
                'email' => 'other@example.com',
                'username' => 'other',
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($allUsers);

        $this->apiService
            ->expects($this->never())
            ->method('fetchById');

        $user = $this->repository->getByEmail($email);

        $this->assertNull($user);
    }

    public function testUpdateFromApi(): void
    {
        $userId = 800;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_800.json')
            ->willReturn($cacheStorage);

        $apiData = [
            'user' => [
                'id' => $userId,
                'username' => 'fmoore',
                'firstname' => 'Frank',
                'lastname' => 'Moore',
                'name' => 'Frank Moore',
                'email' => 'frank.moore@example.com',
            ],
            'roles' => []
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($apiData);

        $cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($apiData);

        $this->repository->updateFromApi();
    }

    public function testUpdateFromApiWithoutConfigUserId(): void
    {
        $this->configStorage->delete('user.id');

        $this->apiService
            ->expects($this->never())
            ->method('fetchById');

        $this->repository->updateFromApi();
    }

    public function testGetCurrentUserDefaultRole(): void
    {
        $userId = 900;
        $roleId = 10;
        $this->configStorage->set('user.id', $userId);
        $this->configStorage->set('user.defaultRole.id', $roleId);

        $role = new Role($roleId, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $otherRole = new Role(20, null, 'Manager', 'Project Manager', 'employee', 'active');

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_900.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'gthomas',
                'firstname' => 'George',
                'lastname' => 'Thomas',
                'name' => 'George Thomas',
                'email' => 'george.thomas@example.com',
            ],
            'roles' => [
                [
                    'id' => $roleId,
                    'parent_id' => null,
                    'name' => 'Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 20,
                    'parent_id' => null,
                    'name' => 'Manager',
                    'full_name' => 'Project Manager',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $defaultRole = $this->repository->getCurrentUserDefaultRole();

        $this->assertNotNull($defaultRole);
        $this->assertEquals($roleId, $defaultRole->id);
        $this->assertEquals('Developer', $defaultRole->name);
    }

    public function testGetCurrentUserDefaultRoleWithStringRoleId(): void
    {
        $userId = 1000;
        $this->configStorage->set('user.id', $userId);
        $this->configStorage->set('user.defaultRole.id', '15'); // String ID

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1000.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'hjackson',
                'firstname' => 'Henry',
                'lastname' => 'Jackson',
                'name' => 'Henry Jackson',
                'email' => 'henry.jackson@example.com',
            ],
            'roles' => [
                [
                    'id' => 15,
                    'parent_id' => null,
                    'name' => 'Tester',
                    'full_name' => 'QA Tester',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $defaultRole = $this->repository->getCurrentUserDefaultRole();

        $this->assertNotNull($defaultRole);
        $this->assertEquals(15, $defaultRole->id);
    }

    public function testGetCurrentUserDefaultRoleNotFound(): void
    {
        $userId = 1100;
        $this->configStorage->set('user.id', $userId);
        $this->configStorage->set('user.defaultRole.id', 99); // Role ID not in user's roles

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1100.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'ijones',
                'firstname' => 'Iris',
                'lastname' => 'Jones',
                'name' => 'Iris Jones',
                'email' => 'iris.jones@example.com',
            ],
            'roles' => [
                [
                    'id' => 5,
                    'parent_id' => null,
                    'name' => 'Other',
                    'full_name' => 'Other Role',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $defaultRole = $this->repository->getCurrentUserDefaultRole();

        $this->assertNull($defaultRole);
    }

    public function testGetCurrentUserDefaultRoleWithoutUser(): void
    {
        $this->configStorage->delete('user.id');

        $defaultRole = $this->repository->getCurrentUserDefaultRole();

        $this->assertNull($defaultRole);
    }

    public function testGetCurrentUserDefaultRoleWithoutConfigRoleId(): void
    {
        $userId = 1200;
        $this->configStorage->set('user.id', $userId);
        $this->configStorage->delete('user.defaultRole.id');

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1200.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'jwhite',
                'firstname' => 'Jane',
                'lastname' => 'White',
                'name' => 'Jane White',
                'email' => 'jane.white@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $defaultRole = $this->repository->getCurrentUserDefaultRole();

        $this->assertNull($defaultRole);
    }

    public function testGetCurrentUserRoles(): void
    {
        $userId = 1300;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1300.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'kharris',
                'firstname' => 'Kevin',
                'lastname' => 'Harris',
                'name' => 'Kevin Harris',
                'email' => 'kevin.harris@example.com',
            ],
            'roles' => [
                [
                    'id' => 1,
                    'parent_id' => null,
                    'name' => 'Role1',
                    'full_name' => 'Full Role 1',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 2,
                    'parent_id' => 1,
                    'name' => 'Role2',
                    'full_name' => 'Full Role 2',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $roles = $this->repository->getCurrentUserRoles();

        $this->assertCount(2, $roles);
        $this->assertEquals(1, $roles[0]->id);
        $this->assertEquals(2, $roles[1]->id);
    }

    public function testGetCurrentUserRolesWithoutUser(): void
    {
        $this->configStorage->delete('user.id');

        $roles = $this->repository->getCurrentUserRoles();

        $this->assertEmpty($roles);
    }

    public function testGetCurrentUserRolesWithEmptyRoles(): void
    {
        $userId = 1400;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1400.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'lmartin',
                'firstname' => 'Lisa',
                'lastname' => 'Martin',
                'name' => 'Lisa Martin',
                'email' => 'lisa.martin@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $roles = $this->repository->getCurrentUserRoles();

        $this->assertEmpty($roles);
    }

    public function testGetConfigUserIdWithStringValue(): void
    {
        $userId = 1500;
        $this->configStorage->set('user.id', (string) $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1500.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'mlee',
                'firstname' => 'Michael',
                'lastname' => 'Lee',
                'name' => 'Michael Lee',
                'email' => 'michael.lee@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $user = $this->repository->getCurrentUser();

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
    }

    public function testCreateUserFromArrayWithRoles(): void
    {
        $userId = 1600;
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1600.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'nwang',
                'firstname' => 'Nancy',
                'lastname' => 'Wang',
                'name' => 'Nancy Wang',
                'email' => 'nancy.wang@example.com',
                'alternative_email' => 'nwang@example.com',
                'employee_type' => 'full-time',
                'employee_status' => 'active',
            ],
            'roles' => [
                [
                    'id' => 30,
                    'parent_id' => null,
                    'name' => 'Designer',
                    'full_name' => 'UI Designer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $user = $this->repository->getById($userId);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertEquals('nwang', $user->username);
        $this->assertEquals('nwang@example.com', $user->alternativeEmail);
        $this->assertEquals('full-time', $user->employeeType);
        $this->assertEquals('active', $user->employeeStatus);
        $this->assertCount(1, $user->roles);
        $this->assertEquals(30, $user->roles[0]->id);
        $this->assertEquals('Designer', $user->roles[0]->name);
    }

    public function testCreateUserFromArrayWithMissingOptionalFields(): void
    {
        $userId = 1700;
        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1700.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'ozhang',
                'firstname' => 'Oliver',
                'lastname' => 'Zhang',
                'name' => 'Oliver Zhang',
                'email' => 'oliver.zhang@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $user = $this->repository->getById($userId);

        $this->assertNotNull($user);
        $this->assertEquals($userId, $user->id);
        $this->assertNull($user->alternativeEmail);
        $this->assertEquals('', $user->employeeType);
        $this->assertNull($user->employeeStatus);
        $this->assertEmpty($user->roles);
    }

    public function testFindCurrentUserRoleByName(): void
    {
        $userId = 1800;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1800.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'prole',
                'firstname' => 'Paul',
                'lastname' => 'Role',
                'name' => 'Paul Role',
                'email' => 'paul.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 40,
                    'parent_id' => null,
                    'name' => 'Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 41,
                    'parent_id' => null,
                    'name' => 'Project Manager',
                    'full_name' => 'Project Manager',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 42,
                    'parent_id' => null,
                    'name' => 'QA Tester',
                    'full_name' => 'Quality Assurance Tester',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNotNull($role);
        $this->assertEquals(40, $role->id);
        $this->assertEquals('Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNameCaseInsensitive(): void
    {
        $userId = 1900;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_1900.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'qrole',
                'firstname' => 'Quinn',
                'lastname' => 'Role',
                'name' => 'Quinn Role',
                'email' => 'quinn.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 50,
                    'parent_id' => null,
                    'name' => 'Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('DEVELOPER');

        $this->assertNotNull($role);
        $this->assertEquals(50, $role->id);
        $this->assertEquals('Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNamePartialMatch(): void
    {
        $userId = 2000;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2000.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'rrole',
                'firstname' => 'Rachel',
                'lastname' => 'Role',
                'name' => 'Rachel Role',
                'email' => 'rachel.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 60,
                    'parent_id' => null,
                    'name' => 'Project Manager',
                    'full_name' => 'Project Manager',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Manager');

        $this->assertNotNull($role);
        $this->assertEquals(60, $role->id);
        $this->assertEquals('Project Manager', $role->name);
    }

    public function testFindCurrentUserRoleByNameNotFound(): void
    {
        $userId = 2100;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2100.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'srole',
                'firstname' => 'Sam',
                'lastname' => 'Role',
                'name' => 'Sam Role',
                'email' => 'sam.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 70,
                    'parent_id' => null,
                    'name' => 'Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Manager');

        $this->assertNull($role);
    }

    public function testFindCurrentUserRoleByNameWithoutUser(): void
    {
        $this->configStorage->delete('user.id');

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNull($role);
    }

    public function testFindCurrentUserRoleByNameWithEmptyRoles(): void
    {
        $userId = 2200;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2200.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'trole',
                'firstname' => 'Tom',
                'lastname' => 'Role',
                'name' => 'Tom Role',
                'email' => 'tom.role@example.com',
            ],
            'roles' => []
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNull($role);
    }

    public function testFindCurrentUserRoleByNameReturnsFirstMatch(): void
    {
        $userId = 2300;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2300.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'urole',
                'firstname' => 'Uma',
                'lastname' => 'Role',
                'name' => 'Uma Role',
                'email' => 'uma.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 80,
                    'parent_id' => null,
                    'name' => 'Senior Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 81,
                    'parent_id' => null,
                    'name' => 'Junior Developer',
                    'full_name' => 'Junior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNotNull($role);
        // Should return the first match (Senior Developer)
        $this->assertEquals(80, $role->id);
        $this->assertEquals('Senior Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNameWithMixedCaseRoleName(): void
    {
        $userId = 2400;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2400.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'vrole',
                'firstname' => 'Victor',
                'lastname' => 'Role',
                'name' => 'Victor Role',
                'email' => 'victor.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 90,
                    'parent_id' => null,
                    'name' => 'DeVeLoPeR',
                    'full_name' => 'Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('developer');

        $this->assertNotNull($role);
        $this->assertEquals(90, $role->id);
        $this->assertEquals('DeVeLoPeR', $role->name);
    }

    public function testFindCurrentUserRoleByNameWithEmptySearchString(): void
    {
        $userId = 2500;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2500.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'wrole',
                'firstname' => 'Wendy',
                'lastname' => 'Role',
                'name' => 'Wendy Role',
                'email' => 'wendy.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 100,
                    'parent_id' => null,
                    'name' => 'Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        // Empty string should match any role name (contains check)
        $role = $this->repository->findCurrentUserRoleByName('');

        $this->assertNotNull($role);
        $this->assertEquals(100, $role->id);
    }

    public function testFindCurrentUserRoleByNameWithWhitespaceInRoleName(): void
    {
        $userId = 2600;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2600.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'xrole',
                'firstname' => 'Xavier',
                'lastname' => 'Role',
                'name' => 'Xavier Role',
                'email' => 'xavier.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 110,
                    'parent_id' => null,
                    'name' => 'Senior  Developer',
                    'full_name' => 'Senior Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNotNull($role);
        $this->assertEquals(110, $role->id);
        $this->assertEquals('Senior  Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNameWithSpecialCharacters(): void
    {
        $userId = 2700;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2700.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'yrole',
                'firstname' => 'Yvonne',
                'lastname' => 'Role',
                'name' => 'Yvonne Role',
                'email' => 'yvonne.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 120,
                    'parent_id' => null,
                    'name' => 'C++ Developer',
                    'full_name' => 'C++ Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('++');

        $this->assertNotNull($role);
        $this->assertEquals(120, $role->id);
        $this->assertEquals('C++ Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNameWithMultipleMatchesReturnsFirst(): void
    {
        $userId = 2800;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2800.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'zrole',
                'firstname' => 'Zoe',
                'lastname' => 'Role',
                'name' => 'Zoe Role',
                'email' => 'zoe.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 130,
                    'parent_id' => null,
                    'name' => 'Frontend Developer',
                    'full_name' => 'Frontend Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 131,
                    'parent_id' => null,
                    'name' => 'Backend Developer',
                    'full_name' => 'Backend Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
                [
                    'id' => 132,
                    'parent_id' => null,
                    'name' => 'Full Stack Developer',
                    'full_name' => 'Full Stack Developer',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('Developer');

        $this->assertNotNull($role);
        // Should return the first match (Frontend Developer)
        $this->assertEquals(130, $role->id);
        $this->assertEquals('Frontend Developer', $role->name);
    }

    public function testFindCurrentUserRoleByNameWithSingleCharacterMatch(): void
    {
        $userId = 2900;
        $this->configStorage->set('user.id', $userId);

        $cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory
            ->expects($this->once())
            ->method('create')
            ->with('user_2900.json')
            ->willReturn($cacheStorage);

        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'arole',
                'firstname' => 'Alice',
                'lastname' => 'Role',
                'name' => 'Alice Role',
                'email' => 'alice.role@example.com',
            ],
            'roles' => [
                [
                    'id' => 140,
                    'parent_id' => null,
                    'name' => 'Admin',
                    'full_name' => 'Administrator',
                    'type' => 'employee',
                    'status' => 'active',
                ],
            ]
        ];

        $cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($userData);

        $role = $this->repository->findCurrentUserRoleByName('A');

        $this->assertNotNull($role);
        $this->assertEquals(140, $role->id);
        $this->assertEquals('Admin', $role->name);
    }
}
