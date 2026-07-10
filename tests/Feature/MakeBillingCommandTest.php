<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

class MakeBillingCommandTest extends TestCase
{
    public function test_it_scaffolds_the_billing_page(): void
    {
        $this->artisan('kinetix:make-billing')->assertSuccessful();

        $pagePath = resource_path('js/pages/Billing/Index.vue');

        $this->assertFileExists($pagePath);

        $contents = File::get($pagePath);
        $this->assertStringContainsString('KinetixPricingTable', $contents);
        $this->assertStringContainsString('KinetixPaymentMethods', $contents);
        $this->assertStringContainsString('KinetixUsageMeters', $contents);
        $this->assertStringContainsString('useKinetixBilling', $contents);

        File::deleteDirectory(resource_path('js/pages/Billing'));
    }

    public function test_it_scaffolds_the_seeder_when_requested(): void
    {
        $this->artisan('kinetix:make-billing', ['--seeder' => true])->assertSuccessful();

        $seederPath = database_path('seeders/PlanSeeder.php');

        $this->assertFileExists($seederPath);
        $this->assertStringContainsString('Plan::updateOrCreate', File::get($seederPath));

        File::delete($seederPath);
        File::deleteDirectory(resource_path('js/pages/Billing'));
    }
}
