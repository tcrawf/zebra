<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\ActivitiesCommand;
use Tcrawf\Zebra\Command\Autocompletion\LocalActivityAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class ActivitiesCommandTest extends TestCase
{
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private ActivitiesCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $autocompletion = new LocalActivityAutocompletion($this->projectRepository);

        $this->command = new ActivitiesCommand(
            $this->projectRepository,
            $this->activityRepository,
            $autocompletion
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testDisplayActivitiesWithProjectName(): void
    {
        $activity1 = new Activity(EntityKey::zebra(1), 'Development', 'Development activity', EntityKey::zebra(100));
        $activity2 = new Activity(EntityKey::zebra(2), 'Testing', 'Testing activity', EntityKey::zebra(100), 'test');
        $activity3 = new Activity(EntityKey::zebra(3), 'Design', 'Design activity', EntityKey::zebra(200));

        $project1 = new Project(EntityKey::zebra(100), 'Project Alpha', 'Alpha project', 1, [$activity1, $activity2]);
        $project2 = new Project(EntityKey::zebra(200), 'Project Beta', 'Beta project', 1, [$activity3]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$project1, $project2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Check that project names and activity names are displayed with IDs
        $this->assertStringContainsString('[1] Project Alpha - Development', $output);
        $this->assertStringContainsString('[2] Project Alpha - Testing (test)', $output);
        $this->assertStringContainsString('[3] Project Beta - Design', $output);
    }

    public function testActivitiesSortedByProjectThenActivityName(): void
    {
        $activity1 = new Activity(EntityKey::zebra(1), 'Zebra Activity', 'Z activity', EntityKey::zebra(200));
        $activity2 = new Activity(EntityKey::zebra(2), 'Alpha Activity', 'A activity', EntityKey::zebra(100));
        $activity3 = new Activity(EntityKey::zebra(3), 'Beta Activity', 'B activity', EntityKey::zebra(100));

        $project1 = new Project(EntityKey::zebra(100), 'Project Z', 'Z project', 1, [$activity2, $activity3]);
        $project2 = new Project(EntityKey::zebra(200), 'Project A', 'A project', 1, [$activity1]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$project1, $project2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $lines = array_filter(explode("\n", trim($output)));

        // Should be sorted: Project A first, then Project Z
        // Within Project A: Zebra Activity
        // Within Project Z: Alpha Activity, then Beta Activity
        $this->assertStringContainsString('[1] Project A - Zebra Activity', $lines[0] ?? '');
        $this->assertStringContainsString('[2] Project Z - Alpha Activity', $lines[1] ?? '');
        $this->assertStringContainsString('[3] Project Z - Beta Activity', $lines[2] ?? '');
    }

    public function testNoActivitiesFound(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No activities found.', $this->commandTester->getDisplay());
    }

    public function testActivitiesWithAliases(): void
    {
        $activity1 = new Activity(
            EntityKey::zebra(1),
            'Development',
            'Development activity',
            EntityKey::zebra(100),
            'dev'
        );
        $activity2 = new Activity(EntityKey::zebra(2), 'Testing', 'Testing activity', EntityKey::zebra(100));

        $project = new Project(EntityKey::zebra(100), 'My Project', 'My project', 1, [$activity1, $activity2]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$project]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Activity with alias should show alias in parentheses
        $this->assertStringContainsString('[1] My Project - Development (dev)', $output);
        // Activity without alias should not show parentheses
        $this->assertStringContainsString('[2] My Project - Testing', $output);
        $this->assertStringNotContainsString('[2] My Project - Testing (', $output);
    }

    public function testAddActivitySuccessfully(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $project = new Project($projectEntityKey, 'Local Project', 'Local project description', 1, []);

        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $newActivity = new Activity(
            $activityEntityKey,
            'New Activity',
            'Activity description',
            $projectEntityKey,
            'new'
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Local Project')
            ->willReturn([$project]);

        $this->activityRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Activity',
                'Activity description',
                $projectEntityKey,
                'new'
            )
            ->willReturn($newActivity);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Local Project',
            '--description' => 'Activity description',
            '--alias' => 'new',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Activity created successfully.', $output);
        $this->assertStringContainsString('Activity: New Activity', $output);
        $this->assertStringContainsString('Project: Local Project', $output);
        $this->assertStringContainsString('Alias: new', $output);
        $this->assertStringContainsString('Description: Activity description', $output);
    }

    public function testAddActivityWithOnlyRequiredFields(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $project = new Project($projectEntityKey, 'Local Project', 'Local project description', 1, []);

        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $newActivity = new Activity(
            $activityEntityKey,
            'New Activity',
            '',
            $projectEntityKey,
            null
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Local Project')
            ->willReturn([$project]);

        $this->activityRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Activity',
                '',
                $projectEntityKey,
                null
            )
            ->willReturn($newActivity);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Local Project',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Activity created successfully.', $output);
        $this->assertStringContainsString('Activity: New Activity', $output);
        $this->assertStringContainsString('Project: Local Project', $output);
    }

    public function testAddActivityFailsWhenProjectNotFound(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Non-existent Project')
            ->willReturn([]);

        $this->activityRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Non-existent Project',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString("No local project found matching 'Non-existent Project'", $normalizedOutput);
    }

    public function testAddActivityFailsWhenProjectIsZebraProject(): void
    {
        $zebraProject = new Project(EntityKey::zebra(100), 'Zebra Project', 'Zebra project description', 1, []);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Zebra Project')
            ->willReturn([$zebraProject]);

        $this->activityRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Zebra Project',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("No local project found matching 'Zebra Project'.", $output);
    }

    public function testAddActivityFailsWhenMultipleLocalProjectsMatch(): void
    {
        $projectUuid1 = Uuid::random();
        $projectEntityKey1 = EntityKey::local($projectUuid1);
        $project1 = new Project($projectEntityKey1, 'Local Project Alpha', 'Alpha description', 1, []);

        $projectUuid2 = Uuid::random();
        $projectEntityKey2 = EntityKey::local($projectUuid2);
        $project2 = new Project($projectEntityKey2, 'Local Project Beta', 'Beta description', 1, []);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Local Project')
            ->willReturn([$project1, $project2]);

        $this->activityRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Local Project',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString("Multiple local projects found matching 'Local Project'", $normalizedOutput);
        $this->assertStringContainsString('Local Project Alpha', $output);
        $this->assertStringContainsString('Local Project Beta', $output);
        $this->assertStringContainsString('Please specify the exact project name.', $output);
    }

    public function testAddActivityWithExactProjectNameMatch(): void
    {
        $projectUuid1 = Uuid::random();
        $projectEntityKey1 = EntityKey::local($projectUuid1);
        $project1 = new Project($projectEntityKey1, 'Local Project Alpha', 'Alpha description', 1, []);

        $projectUuid2 = Uuid::random();
        $projectEntityKey2 = EntityKey::local($projectUuid2);
        $project2 = new Project($projectEntityKey2, 'Local Project Beta', 'Beta description', 1, []);

        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $newActivity = new Activity(
            $activityEntityKey,
            'New Activity',
            '',
            $projectEntityKey1,
            null
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Local Project Alpha')
            ->willReturn([$project1, $project2]);

        $this->activityRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Activity',
                '',
                $projectEntityKey1,
                null
            )
            ->willReturn($newActivity);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            '--project' => 'Local Project Alpha',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Activity created successfully.', $output);
    }

    public function testAddActivityFailsWhenNoLocalProjectsAvailable(): void
    {
        // Return empty array when all() is called (no local projects)
        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([]);

        $this->activityRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Activity',
            // No --project option provided
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString(
            'No local projects found. Please create a local project first',
            $normalizedOutput
        );
    }

    public function testListOnlyLocalActivities(): void
    {
        $localProjectUuid = Uuid::random();
        $localProjectEntityKey = EntityKey::local($localProjectUuid);
        $localActivityUuid = Uuid::random();
        $localActivity = new Activity(
            EntityKey::local($localActivityUuid),
            'Local Activity',
            'Local activity description',
            $localProjectEntityKey
        );
        $localProject = new Project($localProjectEntityKey, 'Local Project', 'Local project', 1, [$localActivity]);

        $zebraActivity = new Activity(EntityKey::zebra(1), 'Zebra Activity', 'Zebra activity', EntityKey::zebra(100));
        $zebraProject = new Project(EntityKey::zebra(100), 'Zebra Project', 'Zebra project', 1, [$zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$localProject, $zebraProject]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain local activity
        $this->assertStringContainsString('Local Project - Local Activity', $output);
        // Should NOT contain Zebra activity
        $this->assertStringNotContainsString('Zebra Project - Zebra Activity', $output);
    }

    public function testListOnlyLocalActivitiesWithMixedProjects(): void
    {
        $localProjectUuid1 = Uuid::random();
        $localProjectEntityKey1 = EntityKey::local($localProjectUuid1);
        $localActivityUuid1 = Uuid::random();
        $localActivity1 = new Activity(
            EntityKey::local($localActivityUuid1),
            'Local Activity 1',
            'Local activity 1',
            $localProjectEntityKey1
        );

        $localProjectUuid2 = Uuid::random();
        $localProjectEntityKey2 = EntityKey::local($localProjectUuid2);
        $localActivityUuid2 = Uuid::random();
        $localActivity2 = new Activity(
            EntityKey::local($localActivityUuid2),
            'Local Activity 2',
            'Local activity 2',
            $localProjectEntityKey2
        );

        $localProject1 = new Project(
            $localProjectEntityKey1,
            'Local Project A',
            'Local project A',
            1,
            [$localActivity1]
        );
        $localProject2 = new Project(
            $localProjectEntityKey2,
            'Local Project B',
            'Local project B',
            1,
            [$localActivity2]
        );

        $zebraActivity1 = new Activity(
            EntityKey::zebra(1),
            'Zebra Activity 1',
            'Zebra activity 1',
            EntityKey::zebra(100)
        );
        $zebraActivity2 = new Activity(
            EntityKey::zebra(2),
            'Zebra Activity 2',
            'Zebra activity 2',
            EntityKey::zebra(200)
        );
        $zebraProject1 = new Project(
            EntityKey::zebra(100),
            'Zebra Project 1',
            'Zebra project 1',
            1,
            [$zebraActivity1]
        );
        $zebraProject2 = new Project(
            EntityKey::zebra(200),
            'Zebra Project 2',
            'Zebra project 2',
            1,
            [$zebraActivity2]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$localProject1, $zebraProject1, $localProject2, $zebraProject2]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain both local activities
        $this->assertStringContainsString('Local Project A - Local Activity 1', $output);
        $this->assertStringContainsString('Local Project B - Local Activity 2', $output);
        // Should NOT contain Zebra activities
        $this->assertStringNotContainsString('Zebra Project 1 - Zebra Activity 1', $output);
        $this->assertStringNotContainsString('Zebra Project 2 - Zebra Activity 2', $output);
    }

    public function testListOnlyLocalActivitiesWhenNoLocalActivitiesExist(): void
    {
        $zebraActivity1 = new Activity(
            EntityKey::zebra(1),
            'Zebra Activity 1',
            'Zebra activity 1',
            EntityKey::zebra(100)
        );
        $zebraActivity2 = new Activity(
            EntityKey::zebra(2),
            'Zebra Activity 2',
            'Zebra activity 2',
            EntityKey::zebra(200)
        );
        $zebraProject1 = new Project(
            EntityKey::zebra(100),
            'Zebra Project 1',
            'Zebra project 1',
            1,
            [$zebraActivity1]
        );
        $zebraProject2 = new Project(
            EntityKey::zebra(200),
            'Zebra Project 2',
            'Zebra project 2',
            1,
            [$zebraActivity2]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$zebraProject1, $zebraProject2]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should show "No activities found" message
        $this->assertStringContainsString('No activities found.', $output);
        // Should NOT contain Zebra activities
        $this->assertStringNotContainsString('Zebra Project 1 - Zebra Activity 1', $output);
        $this->assertStringNotContainsString('Zebra Project 2 - Zebra Activity 2', $output);
    }

    public function testListAllActivitiesWhenLocalOptionNotSet(): void
    {
        $localProjectUuid = Uuid::random();
        $localProjectEntityKey = EntityKey::local($localProjectUuid);
        $localActivityUuid = Uuid::random();
        $localActivity = new Activity(
            EntityKey::local($localActivityUuid),
            'Local Activity',
            'Local activity description',
            $localProjectEntityKey
        );
        $localProject = new Project($localProjectEntityKey, 'Local Project', 'Local project', 1, [$localActivity]);

        $zebraActivity = new Activity(EntityKey::zebra(1), 'Zebra Activity', 'Zebra activity', EntityKey::zebra(100));
        $zebraProject = new Project(EntityKey::zebra(100), 'Zebra Project', 'Zebra project', 1, [$zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$localProject, $zebraProject]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain both local and Zebra activities
        $this->assertStringContainsString('Local Project - Local Activity', $output);
        $this->assertStringContainsString('Zebra Project - Zebra Activity', $output);
    }
}
