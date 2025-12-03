<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

use Carbon\Carbon;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\User\User;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Factory for creating test entities with sensible defaults.
 */
class TestEntityFactory
{
    /**
     * Create a test Activity with default values.
     *
     * @param EntityKey|null $entityKey Optional entity key (defaults to random Zebra key)
     * @param string|null $name Optional name (defaults to 'Test Activity')
     * @param string|null $description Optional description (defaults to 'Description')
     * @param EntityKey|null $projectEntityKey Optional project entity key (defaults to Zebra 100)
     * @param string|null $alias Optional alias
     * @return Activity
     */
    public static function createActivity(
        ?EntityKey $entityKey = null,
        ?string $name = null,
        ?string $description = null,
        ?EntityKey $projectEntityKey = null,
        ?string $alias = null
    ): Activity {
        return new Activity(
            $entityKey ?? EntityKey::zebra(1),
            $name ?? 'Test Activity',
            $description ?? 'Description',
            $projectEntityKey ?? EntityKey::zebra(100),
            $alias
        );
    }

    /**
     * Create a test Activity with local entity key.
     *
     * @param UuidInterface|null $uuid Optional UUID (defaults to random)
     * @param string|null $name Optional name (defaults to 'Local Activity')
     * @param string|null $description Optional description (defaults to 'Description')
     * @param EntityKey|null $projectEntityKey Optional project entity key (defaults to random local)
     * @param string|null $alias Optional alias
     * @param EntityKey|null $activityEntityKey Optional activity entity key (if provided, overrides uuid)
     * @return Activity
     */
    public static function createLocalActivity(
        ?UuidInterface $uuid = null,
        ?string $name = null,
        ?string $description = null,
        ?EntityKey $projectEntityKey = null,
        ?string $alias = null,
        ?EntityKey $activityEntityKey = null
    ): Activity {
        $activityUuid = $uuid ?? Uuid::random();
        $projectKey = $projectEntityKey ?? EntityKey::local(Uuid::random());
        $entityKey = $activityEntityKey ?? EntityKey::local($activityUuid);

        return new Activity(
            $entityKey,
            $name ?? 'Local Activity',
            $description ?? 'Description',
            $projectKey,
            $alias
        );
    }

    /**
     * Create a test Frame with default values.
     *
     * @param UuidInterface|null $uuid Optional UUID (defaults to random)
     * @param Carbon|null $startTime Optional start time (defaults to 1 hour ago)
     * @param Carbon|null $stopTime Optional stop time (defaults to now, null for active frame)
     * @param Activity|null $activity Optional activity (defaults to test activity)
     * @param bool|null $isIndividual Optional individual flag (defaults to false)
     * @param Role|null $role Optional role (defaults to test role)
     * @param string|null $description Optional description (defaults to empty string)
     * @return Frame
     */
    public static function createFrame(
        ?UuidInterface $uuid = null,
        ?Carbon $startTime = null,
        ?Carbon $stopTime = null,
        ?Activity $activity = null,
        ?bool $isIndividual = null,
        ?Role $role = null,
        ?string $description = null
    ): Frame {
        $frameUuid = $uuid ?? Uuid::random();
        $start = $startTime ?? Carbon::now()->utc()->subHour();
        $stop = $stopTime ?? Carbon::now()->utc();
        $frameActivity = $activity ?? self::createActivity();
        $individual = $isIndividual ?? false;
        $frameRole = $individual ? null : ($role ?? self::createRole());

        return new Frame(
            $frameUuid,
            $start,
            $stop,
            $frameActivity,
            $individual,
            $frameRole,
            $description ?? ''
        );
    }

    /**
     * Create an active (current) Frame.
     *
     * @param UuidInterface|null $uuid Optional UUID (defaults to random)
     * @param Carbon|null $startTime Optional start time (defaults to 1 hour ago)
     * @param Activity|null $activity Optional activity (defaults to test activity)
     * @param bool|null $isIndividual Optional individual flag (defaults to false)
     * @param Role|null $role Optional role (defaults to test role)
     * @param string|null $description Optional description (defaults to empty string)
     * @return Frame
     */
    public static function createActiveFrame(
        ?UuidInterface $uuid = null,
        ?Carbon $startTime = null,
        ?Activity $activity = null,
        ?bool $isIndividual = null,
        ?Role $role = null,
        ?string $description = null
    ): Frame {
        $frameUuid = $uuid ?? Uuid::random();
        $start = $startTime ?? Carbon::now()->utc()->subHour();
        $frameActivity = $activity ?? self::createActivity();
        $individual = $isIndividual ?? false;
        $frameRole = $individual ? null : ($role ?? self::createRole());

        return new Frame(
            $frameUuid,
            $start,
            null, // No stop time = active frame
            $frameActivity,
            $individual,
            $frameRole,
            $description ?? ''
        );
    }

    /**
     * Create a test Role with default values.
     *
     * @param int|null $id Optional ID (defaults to 1)
     * @param int|null $parentId Optional parent ID (defaults to null)
     * @param string|null $name Optional name (defaults to 'Developer')
     * @param string|null $fullName Optional full name (defaults to 'Developer')
     * @param string|null $type Optional type (defaults to 'employee')
     * @param string|null $status Optional status (defaults to 'active')
     * @return Role
     */
    public static function createRole(
        ?int $id = null,
        ?int $parentId = null,
        ?string $name = null,
        ?string $fullName = null,
        ?string $type = null,
        ?string $status = null
    ): Role {
        return new Role(
            $id ?? 1,
            $parentId,
            $name ?? 'Developer',
            $fullName ?? 'Developer',
            $type ?? 'employee',
            $status ?? 'active'
        );
    }

    /**
     * Create a test User with default values.
     *
     * @param int|null $id Optional ID (defaults to 1)
     * @param string|null $username Optional username (defaults to 'testuser')
     * @param string|null $firstname Optional first name (defaults to 'Test')
     * @param string|null $lastname Optional last name (defaults to 'User')
     * @param string|null $name Optional full name (defaults to 'Test User')
     * @param string|null $email Optional email (defaults to 'test@example.com')
     * @param array|null $roles Optional roles array (defaults to single test role)
     * @return User
     */
    public static function createUser(
        ?int $id = null,
        ?string $username = null,
        ?string $firstname = null,
        ?string $lastname = null,
        ?string $name = null,
        ?string $email = null,
        ?array $roles = null
    ): User {
        return new User(
            id: $id ?? 1,
            username: $username ?? 'testuser',
            firstname: $firstname ?? 'Test',
            lastname: $lastname ?? 'User',
            name: $name ?? 'Test User',
            email: $email ?? 'test@example.com',
            roles: $roles ?? [self::createRole()]
        );
    }

    /**
     * Create a test Project with default values.
     *
     * @param EntityKey|null $entityKey Optional entity key (defaults to random local)
     * @param string|null $name Optional name (defaults to 'Test Project')
     * @param string|null $description Optional description (defaults to 'Description')
     * @param int|null $status Optional status (defaults to 1 = active)
     * @param array|null $activities Optional activities array (defaults to empty)
     * @return Project
     */
    public static function createProject(
        ?EntityKey $entityKey = null,
        ?string $name = null,
        ?string $description = null,
        ?int $status = null,
        ?array $activities = null
    ): Project {
        return new Project(
            $entityKey ?? EntityKey::local(Uuid::random()),
            $name ?? 'Test Project',
            $description ?? 'Description',
            $status ?? 1,
            $activities ?? []
        );
    }
}
