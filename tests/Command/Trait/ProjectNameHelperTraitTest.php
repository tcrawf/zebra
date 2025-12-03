<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Trait;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\Trait\ProjectNameHelperTrait;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Uuid\Uuid;

class ProjectNameHelperTraitTest extends TestCase
{
    private object $command;
    private ProjectRepositoryInterface $projectRepository;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);

        $this->command = new class ($this->projectRepository) {
            use ProjectNameHelperTrait;

            public function __construct(
                private readonly ProjectRepositoryInterface $projectRepository
            ) {
            }

            protected function getProjectRepository(): ProjectRepositoryInterface
            {
                return $this->projectRepository;
            }
        };
    }

    public function testGetProjectNameReturnsProjectName(): void
    {
        $projectKey = EntityKey::zebra(100);
        $project = new Project($projectKey, 'Test Project', 'Description', 1, []);
        $activity = new Activity(EntityKey::zebra(200), 'Activity', 'Activity Description', $projectKey);
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $activity,
            false,
            $role,
            'Description'
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectKey)
            ->willReturn($project);

        $result = $this->callPrivateMethod('getProjectName', [$frame]);
        $this->assertEquals('Test Project', $result);
    }

    public function testGetProjectNameReturnsFallbackWhenProjectNotFound(): void
    {
        $projectKey = EntityKey::zebra(100);
        $activity = new Activity(EntityKey::zebra(200), 'Activity', 'Activity Description', $projectKey);
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $activity,
            false,
            $role,
            'Description'
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectKey)
            ->willReturn(null);

        $result = $this->callPrivateMethod('getProjectName', [$frame]);
        $this->assertEquals('Project ' . $projectKey->toString(), $result);
    }

    /**
     * Helper to call private methods for testing.
     */
    private function callPrivateMethod(string $methodName, array $args)
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $args);
    }
}
