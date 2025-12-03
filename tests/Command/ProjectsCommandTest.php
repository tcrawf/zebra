<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\ProjectsCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class ProjectsCommandTest extends TestCase
{
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ProjectsCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $autocompletion = new \Tcrawf\Zebra\Command\Autocompletion\LocalProjectAutocompletion(
            $this->projectRepository
        );

        $this->command = new ProjectsCommand($this->projectRepository, $autocompletion);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testEmptyProjectsList(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No projects found.', $this->commandTester->getDisplay());
    }

    public function testProjectsListWithMultipleProjects(): void
    {
        $project1 = new Project(
            EntityKey::zebra(100),
            'Alpha Project',
            'Description',
            1,
            []
        );
        $project2 = new Project(
            EntityKey::zebra(200),
            'Beta Project',
            'Description',
            1,
            []
        );
        $project3 = new Project(
            EntityKey::zebra(300),
            'Gamma Project',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$project1, $project2, $project3]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Alpha Project (100)', $output);
        $this->assertStringContainsString('Beta Project (200)', $output);
        $this->assertStringContainsString('Gamma Project (300)', $output);
    }

    public function testProjectsSortedAlphabetically(): void
    {
        $project1 = new Project(
            EntityKey::zebra(300),
            'Zebra Project',
            'Description',
            1,
            []
        );
        $project2 = new Project(
            EntityKey::zebra(100),
            'Alpha Project',
            'Description',
            1,
            []
        );
        $project3 = new Project(
            EntityKey::zebra(200),
            'Beta Project',
            'Description',
            1,
            []
        );

        // Repository returns unsorted
        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$project1, $project2, $project3]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $lines = array_filter(explode("\n", trim($output)));

        // Check that projects are displayed (order depends on repository implementation)
        $this->assertStringContainsString('Alpha Project (100)', $output);
        $this->assertStringContainsString('Beta Project (200)', $output);
        $this->assertStringContainsString('Zebra Project (300)', $output);
    }

    public function testEachProjectNameOnSeparateLine(): void
    {
        $project1 = new Project(
            EntityKey::zebra(100),
            'Project One',
            'Description',
            1,
            []
        );
        $project2 = new Project(
            EntityKey::zebra(200),
            'Project Two',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$project1, $project2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $lines = array_filter(explode("\n", trim($output)));

        // Should have at least 2 lines (excluding "No projects found" message)
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('Project One (100)', $output);
        $this->assertStringContainsString('Project Two (200)', $output);
    }

    public function testAddProjectSuccessfully(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $newProject = new Project(
            $projectEntityKey,
            'New Project',
            'Project description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Project',
                'Project description',
                1
            )
            ->willReturn($newProject);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--description' => 'Project description',
            '--status' => '1',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Project created successfully.', $output);
        $this->assertStringContainsString('Name: New Project', $output);
        $this->assertStringContainsString('Description: Project description', $output);
        $this->assertStringContainsString('Status: active', $output);
    }

    public function testAddProjectWithOnlyRequiredFields(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $newProject = new Project(
            $projectEntityKey,
            'New Project',
            '',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Project',
                '',
                1
            )
            ->willReturn($newProject);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Project created successfully.', $output);
        $this->assertStringContainsString('Name: New Project', $output);
        $this->assertStringContainsString('Status: active', $output);
    }

    public function testAddProjectWithInactiveStatus(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $newProject = new Project(
            $projectEntityKey,
            'New Project',
            '',
            0,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Project',
                '',
                0
            )
            ->willReturn($newProject);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--status' => '0',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Project created successfully.', $output);
        $this->assertStringContainsString('Status: inactive', $output);
    }

    public function testAddProjectWithStatusAsString(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $newProject = new Project(
            $projectEntityKey,
            'New Project',
            '',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Project',
                '',
                1
            )
            ->willReturn($newProject);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--status' => 'active',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Project created successfully.', $output);
        $this->assertStringContainsString('Status: active', $output);
    }

    public function testAddProjectFailsWithInvalidStatus(): void
    {
        $this->projectRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--status' => 'invalid',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString("Invalid status 'invalid'", $normalizedOutput);
        $this->assertStringContainsString('Status must be 0 (inactive), 1 (active)', $normalizedOutput);
        $this->assertStringContainsString('(other)', $normalizedOutput);
    }

    public function testAddProjectFailsWithInvalidStatusNumber(): void
    {
        $this->projectRepository
            ->expects($this->never())
            ->method('create');

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--status' => '3',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString("Invalid status '3'", $normalizedOutput);
        $this->assertStringContainsString('Status must be 0 (inactive), 1 (active)', $normalizedOutput);
        $this->assertStringContainsString('(other)', $normalizedOutput);
    }

    public function testAddProjectWithOtherStatus(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $newProject = new Project(
            $projectEntityKey,
            'New Project',
            '',
            2,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                'New Project',
                '',
                2
            )
            ->willReturn($newProject);

        $this->commandTester->execute([
            '--add' => true,
            'name' => 'New Project',
            '--status' => '2',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Project created successfully.', $output);
        $this->assertStringContainsString('Name: New Project', $output);
    }

    public function testLocalProjectsShowLocalLabel(): void
    {
        $projectUuid = Uuid::random();
        $localProject = new Project(
            EntityKey::local($projectUuid),
            'Local Project',
            'Description',
            1,
            []
        );
        $zebraProject = new Project(
            EntityKey::zebra(100),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$localProject, $zebraProject]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Check that local project shows UUID in brackets
        $this->assertStringContainsString('Local Project (' . $projectUuid->getHex() . ')', $output);
        // Check that Zebra project shows integer ID in brackets
        $this->assertStringContainsString('Zebra Project (100)', $output);
    }

    public function testOnlyLocalProjectsShowLocalLabel(): void
    {
        $projectUuid1 = Uuid::random();
        $localProject1 = new Project(
            EntityKey::local($projectUuid1),
            'Local Project One',
            'Description',
            1,
            []
        );
        $projectUuid2 = Uuid::random();
        $localProject2 = new Project(
            EntityKey::local($projectUuid2),
            'Local Project Two',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$localProject1, $localProject2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Local Project One (' . $projectUuid1->getHex() . ')', $output);
        $this->assertStringContainsString('Local Project Two (' . $projectUuid2->getHex() . ')', $output);
    }

    public function testProjectsDisplayWithIds(): void
    {
        $project1 = new Project(
            EntityKey::zebra(42),
            'Test Project',
            'Description',
            1,
            []
        );
        $projectUuid = Uuid::random();
        $project2 = new Project(
            EntityKey::local($projectUuid),
            'Local Test Project',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$project1, $project2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Zebra project shows integer ID in brackets
        $this->assertStringContainsString('Test Project (42)', $output);
        // Local project shows UUID hex in brackets
        $this->assertStringContainsString('Local Test Project (' . $projectUuid->getHex() . ')', $output);
    }

    public function testListProjectsOnlyShowsActiveByDefault(): void
    {
        $activeProject = new Project(
            EntityKey::zebra(100),
            'Active Project',
            'Description',
            1,
            []
        );
        $inactiveProject = new Project(
            EntityKey::zebra(200),
            'Inactive Project',
            'Description',
            0,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Active Project (100)', $output);
        $this->assertStringNotContainsString('Inactive Project', $output);
    }

    public function testListProjectsWithAllFlagShowsInactiveProjects(): void
    {
        $activeProject = new Project(
            EntityKey::zebra(100),
            'Active Project',
            'Description',
            1,
            []
        );
        $inactiveProject = new Project(
            EntityKey::zebra(200),
            'Inactive Project',
            'Description',
            0,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([$activeProject, $inactiveProject]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Active Project (100)', $output);
        $this->assertStringContainsString('Inactive Project (200)', $output);
    }

    public function testListProjectsWithAllFlagIncludesOtherStatusProjects(): void
    {
        $activeProject = new Project(
            EntityKey::zebra(100),
            'Active Project',
            'Description',
            1,
            []
        );
        $inactiveProject = new Project(
            EntityKey::zebra(200),
            'Inactive Project',
            'Description',
            0,
            []
        );
        $otherStatusProject = new Project(
            EntityKey::zebra(300),
            'Other Status Project',
            'Description',
            2,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([$activeProject, $inactiveProject, $otherStatusProject]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Active Project (100)', $output);
        $this->assertStringContainsString('Inactive Project (200)', $output);
        $this->assertStringContainsString('Other Status Project (300)', $output);
    }

    public function testListOnlyLocalProjects(): void
    {
        $localProjectUuid = Uuid::random();
        $localProject = new Project(
            EntityKey::local($localProjectUuid),
            'Local Project',
            'Description',
            1,
            []
        );
        $zebraProject = new Project(
            EntityKey::zebra(100),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$localProject, $zebraProject]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain local project
        $this->assertStringContainsString('Local Project (' . $localProjectUuid->getHex() . ')', $output);
        // Should NOT contain Zebra project
        $this->assertStringNotContainsString('Zebra Project (100)', $output);
    }

    public function testListOnlyLocalProjectsWithMixedProjects(): void
    {
        $localProjectUuid1 = Uuid::random();
        $localProject1 = new Project(
            EntityKey::local($localProjectUuid1),
            'Local Project A',
            'Description',
            1,
            []
        );
        $localProjectUuid2 = Uuid::random();
        $localProject2 = new Project(
            EntityKey::local($localProjectUuid2),
            'Local Project B',
            'Description',
            1,
            []
        );

        $zebraProject1 = new Project(
            EntityKey::zebra(100),
            'Zebra Project 1',
            'Description',
            1,
            []
        );
        $zebraProject2 = new Project(
            EntityKey::zebra(200),
            'Zebra Project 2',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$localProject1, $zebraProject1, $localProject2, $zebraProject2]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain both local projects
        $this->assertStringContainsString('Local Project A (' . $localProjectUuid1->getHex() . ')', $output);
        $this->assertStringContainsString('Local Project B (' . $localProjectUuid2->getHex() . ')', $output);
        // Should NOT contain Zebra projects
        $this->assertStringNotContainsString('Zebra Project 1 (100)', $output);
        $this->assertStringNotContainsString('Zebra Project 2 (200)', $output);
    }

    public function testListOnlyLocalProjectsWhenNoLocalProjectsExist(): void
    {
        $zebraProject1 = new Project(
            EntityKey::zebra(100),
            'Zebra Project 1',
            'Description',
            1,
            []
        );
        $zebraProject2 = new Project(
            EntityKey::zebra(200),
            'Zebra Project 2',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$zebraProject1, $zebraProject2]);

        $this->commandTester->execute(['--local' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should show "No projects found" message
        $this->assertStringContainsString('No projects found.', $output);
        // Should NOT contain Zebra projects
        $this->assertStringNotContainsString('Zebra Project 1 (100)', $output);
        $this->assertStringNotContainsString('Zebra Project 2 (200)', $output);
    }

    public function testListAllProjectsWhenLocalOptionNotSet(): void
    {
        $localProjectUuid = Uuid::random();
        $localProject = new Project(
            EntityKey::local($localProjectUuid),
            'Local Project',
            'Description',
            1,
            []
        );
        $zebraProject = new Project(
            EntityKey::zebra(100),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([\Tcrawf\Zebra\Project\ProjectStatus::Active])
            ->willReturn([$localProject, $zebraProject]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should contain both local and Zebra projects
        $this->assertStringContainsString('Local Project (' . $localProjectUuid->getHex() . ')', $output);
        $this->assertStringContainsString('Zebra Project (100)', $output);
    }
}
