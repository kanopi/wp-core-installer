<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Kanopi\Composer\WordPress\DeployManifest;
use Kanopi\Composer\WordPress\DeployPlan;

final class DeployPlanTest extends TestCase
{
    private function plan(): DeployPlan
    {
        return new DeployPlan('/src', '/web', new DeployManifest('p', 'v', 'r', '/web', 'h'));
    }

    public function testAnEmptyPlanHasNoChanges(): void
    {
        $plan            = $this->plan();
        $plan->unchanged = ['wp-load.php'];
        $plan->protected = ['wp-config.php'];

        self::assertFalse($plan->hasChanges());
        self::assertSame('no changes', $plan->summary());
    }

    public function testSummaryListsEachKindOfChange(): void
    {
        $plan               = $this->plan();
        $plan->create       = ['a.php' => '/src/a.php', 'b.php' => '/src/b.php'];
        $plan->update       = ['c.php' => '/src/c.php'];
        $plan->firstInstall = ['.htaccess' => '/src/.htaccess'];
        $plan->stale        = ['old.php'];

        self::assertTrue($plan->hasChanges());
        self::assertSame('2 to create, 1 to update, 1 first-install, 1 stale to delete', $plan->summary());
    }

    public function testStaleFilesAloneAreAChange(): void
    {
        $plan        = $this->plan();
        $plan->stale = ['old.php'];

        self::assertTrue($plan->hasChanges());
        self::assertSame('1 stale to delete', $plan->summary());
    }
}
