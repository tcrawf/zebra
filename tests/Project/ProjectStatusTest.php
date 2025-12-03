<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Project\ProjectStatus;

class ProjectStatusTest extends TestCase
{
    public function testProjectStatusInactive(): void
    {
        $this->assertEquals(0, ProjectStatus::Inactive->value);
        $this->assertInstanceOf(ProjectStatus::class, ProjectStatus::Inactive);
    }

    public function testProjectStatusActive(): void
    {
        $this->assertEquals(1, ProjectStatus::Active->value);
        $this->assertInstanceOf(ProjectStatus::class, ProjectStatus::Active);
    }

    public function testProjectStatusOther(): void
    {
        $this->assertEquals(2, ProjectStatus::Other->value);
        $this->assertInstanceOf(ProjectStatus::class, ProjectStatus::Other);
    }

    public function testMatchesMethodReturnsTrueForMatchingStatus(): void
    {
        $this->assertTrue(ProjectStatus::Active->matches(1));
        $this->assertTrue(ProjectStatus::Inactive->matches(0));
        $this->assertTrue(ProjectStatus::Other->matches(2));
    }

    public function testMatchesMethodReturnsFalseForNonMatchingStatus(): void
    {
        $this->assertFalse(ProjectStatus::Active->matches(0));
        $this->assertFalse(ProjectStatus::Inactive->matches(1));
        $this->assertFalse(ProjectStatus::Other->matches(1));
    }

    public function testProjectStatusFromInt(): void
    {
        $inactive = ProjectStatus::from(0);
        $this->assertEquals(ProjectStatus::Inactive, $inactive);

        $active = ProjectStatus::from(1);
        $this->assertEquals(ProjectStatus::Active, $active);

        $other = ProjectStatus::from(2);
        $this->assertEquals(ProjectStatus::Other, $other);
    }

    public function testProjectStatusFromIntThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        ProjectStatus::from(99);
    }

    public function testProjectStatusTryFrom(): void
    {
        $inactive = ProjectStatus::tryFrom(0);
        $this->assertEquals(ProjectStatus::Inactive, $inactive);

        $active = ProjectStatus::tryFrom(1);
        $this->assertEquals(ProjectStatus::Active, $active);

        $invalid = ProjectStatus::tryFrom(99);
        $this->assertNull($invalid);
    }
}
