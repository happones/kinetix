<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeBillingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:make-billing
                            {--seeder : Also scaffold a PlanSeeder with example plans}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold the Kinetix Billing page (Cashier + Stripe) and optional PlanSeeder';

    public function handle(): int
    {
        $this->createBillingPage();

        if ($this->option('seeder')) {
            $this->createPlanSeeder();
        }

        $this->info("\nKinetix Billing scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line('1. composer require laravel/cashier && php artisan migrate (Cashier tables)');
        $this->line('2. php artisan vendor:publish --tag=kinetix-billing-migrations && php artisan migrate');
        $this->line('3. Add Cashier\'s Billable trait + Happones\\Kinetix\\Billing\\Concerns\\HasPlan to your billable model.');
        $this->line('4. Set KINETIX_BILLING_ENABLED=true (and KINETIX_BILLING_AUTO_ROUTES=true) in .env,');
        $this->line('   or call \\Happones\\Kinetix\\Billing\\BillingRoutes::register() in your routes file.');
        $this->line('5. Ensure your Stripe publishable key is set (cashier.key / services.stripe.key) and Stripe.js is loaded.');

        return self::SUCCESS;
    }

    protected function createBillingPage(): void
    {
        $directory = resource_path('js/pages/Billing');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $template = <<<'VUE'
<script setup lang="ts">
import { ref } from 'vue';
import KinetixPricingTable from '@/components/kinetix/KinetixPricingTable.vue';
import KinetixPaymentMethods from '@/components/kinetix/KinetixPaymentMethods.vue';
import KinetixSubscriptionStatus from '@/components/kinetix/KinetixSubscriptionStatus.vue';
import KinetixUsageMeters from '@/components/kinetix/KinetixUsageMeters.vue';
import KinetixInvoicesTable from '@/components/kinetix/KinetixInvoicesTable.vue';
import KinetixSecurePayments from '@/components/kinetix/KinetixSecurePayments.vue';
import KinetixTrialNotice from '@/components/kinetix/KinetixTrialNotice.vue';
import { useKinetixBilling } from '@/composables/useKinetixBilling';
import type {
  KinetixInvoice,
  KinetixPaymentMethod,
  KinetixPlanData,
  KinetixSubscriptionData,
  KinetixUsageMetricData,
} from '@/types/kinetix';

const props = withDefaults(
  defineProps<{
    plans: KinetixPlanData[];
    currentPlan: KinetixPlanData | null;
    preselectedPlanSlug: string | null;
    intent: { client_secret: string } | null;
    paymentMethods: KinetixPaymentMethod[];
    defaultPaymentMethodId: string | null;
    invoices: KinetixInvoice[];
    subscription: KinetixSubscriptionData | null;
    // Optional with a default below: the controller always sends it, but this
    // is a safety net against a transient server-side hiccup blanking the page.
    usage?: KinetixUsageMetricData[];
    currencySymbol: string;
    publishableKey: string;
    trialGeneric: boolean;
    invoicesUseStripeUrl: boolean;
  }>(),
  { usage: () => [] },
);

// Optional dot-path -> label map for capability rows (customise per app).
const featureLabels: Record<string, string> = {};

const cycle = ref<'monthly' | 'yearly'>('monthly');
const selectedPaymentMethodId = ref<string>(
  props.defaultPaymentMethodId
    ?? (props.paymentMethods[0]?.id ?? 'new'),
);

// Resolve these with Ziggy `route()` / Wayfinder if you prefer named routes.
const billing = useKinetixBilling({
  subscribe: '/billing/subscribe',
  cancel: '/billing/cancel',
  resume: '/billing/resume',
  addPaymentMethod: '/billing/payment-methods',
  removePaymentMethod: (id: string) => `/billing/payment-methods/${id}`,
  downloadInvoice: (id: string) => `/billing/invoices/${id}/download`,
});

const subscribe = (plan: KinetixPlanData) => {
  billing.subscribe(
    plan.slug,
    selectedPaymentMethodId.value === 'new' ? null : selectedPaymentMethodId.value,
    cycle.value,
  );
};
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-10 p-8">
    <KinetixPricingTable
      :plans="plans"
      :current-plan-slug="currentPlan?.slug ?? null"
      :selected-slug="preselectedPlanSlug"
      :cycle="cycle"
      :show-cycle-toggle="true"
      :currency-symbol="currencySymbol"
      :feature-labels="featureLabels"
      :loading="billing.processing.value"
      @subscribe="subscribe"
      @update:cycle="cycle = $event"
    />

    <KinetixTrialNotice v-if="trialGeneric" />

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <KinetixPaymentMethods
          :payment-methods="paymentMethods"
          :selected-id="selectedPaymentMethodId"
          :default-id="defaultPaymentMethodId ?? undefined"
          :publishable-key="publishableKey"
          :setup-client-secret="intent?.client_secret"
          :loading="billing.processing.value"
          @select="selectedPaymentMethodId = $event"
          @remove="billing.removePaymentMethod"
          @added="billing.addPaymentMethod"
        />

        <KinetixInvoicesTable
          :invoices="invoices"
          :download-url="(invoice) => billing.downloadInvoice(invoice.id)"
          :use-stripe-url="invoicesUseStripeUrl"
        />
      </div>

      <div class="space-y-6">
        <KinetixSubscriptionStatus
          :subscription="subscription"
          @cancel="billing.cancel"
          @resume="billing.resume"
        />

        <!-- Only renders when a metered plan reports usage (see meteredUsage() on your billable) -->
        <KinetixUsageMeters :metrics="usage" />

        <KinetixSecurePayments />
      </div>
    </div>
  </div>
</template>
VUE;

        File::put("{$directory}/Index.vue", $template);
        $this->line('Created Vue page: [resources/js/pages/Billing/Index.vue]');
    }

    protected function createPlanSeeder(): void
    {
        $directory = database_path('seeders');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $template = <<<'PHP'
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Happones\Kinetix\Billing\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(['slug' => 'free'], [
            'name'          => 'Free',
            'description'   => 'Get started at no cost.',
            'monthly_price' => 0,
            'is_free'       => true,
            'features'      => [
                'usage'        => ['projects' => 1],
                'capabilities' => ['api' => false, 'priority_support' => false],
            ],
            'highlighted_features' => ['1 project', 'Community support'],
            'sort_order'           => 0,
        ]);

        Plan::updateOrCreate(['slug' => 'pro'], [
            'name'                    => 'Pro',
            'description'             => 'For growing teams.',
            'monthly_price'           => 29,
            'yearly_price'            => 290,
            'is_free'                 => false,
            'stripe_monthly_price_id' => env('STRIPE_PRICE_PRO_MONTHLY'),
            'stripe_yearly_price_id'  => env('STRIPE_PRICE_PRO_YEARLY'),
            'features'                => [
                'usage'        => ['projects' => null],
                'capabilities' => ['api' => true, 'priority_support' => true],
            ],
            'highlighted_features' => ['Unlimited projects', 'API access', 'Priority support'],
            'is_featured'          => true,
            'sort_order'           => 1,
        ]);
    }
}
PHP;

        File::put("{$directory}/PlanSeeder.php", $template);
        $this->line('Created seeder: [database/seeders/PlanSeeder.php]');
    }
}
