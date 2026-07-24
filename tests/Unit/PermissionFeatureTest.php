<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Data\PermissionFeatureData;
use Happones\Kinetix\Permissions\Feature;
use Happones\Kinetix\Tests\TestCase;

class PermissionFeatureTest extends TestCase
{
    public function test_access_preset_declares_the_single_access_ability(): void
    {
        $feature = (new Feature('reports'))->access();

        $this->assertSame(['access' => 'Access'], $feature->getAbilities());
        $this->assertSame(['reports.access'], $feature->permissionKeys());
    }

    public function test_group_flows_through_the_frontend_dto(): void
    {
        $feature = (new Feature('employees'))
            ->group('HR')
            ->crud()
            ->ability('viewSalary', 'View salaries');

        $data = PermissionFeatureData::fromFeature($feature);

        $this->assertSame('HR', $data->group);
        $this->assertSame('viewSalary', $data->abilities[5]['key']);
        $this->assertSame('View salaries', $data->abilities[5]['label']);
        $this->assertSame('employees.viewSalary', $data->abilities[5]['permission']);
    }

    public function test_group_is_null_by_default(): void
    {
        $this->assertNull(PermissionFeatureData::fromFeature((new Feature('posts'))->crud())->group);
    }
}
