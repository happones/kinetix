import { h, type Component } from 'vue';
import KinetixWizard from '@/components/KinetixWizard.vue';
import KinetixEmptyState from '@/components/KinetixEmptyState.vue';
import KinetixOnboardingChecklist from '@/components/KinetixOnboardingChecklist.vue';
import KinetixTable from '@/components/KinetixTable.vue';
import KinetixFormSchema from '@/components/KinetixFormSchema.vue';
import KinetixInfolist from '@/components/KinetixInfolist.vue';
import KinetixGdprPanel from '@/components/KinetixGdprPanel.vue';
import KinetixTokenManager from '@/components/KinetixTokenManager.vue';
import KinetixWebhookManager from '@/components/KinetixWebhookManager.vue';
import KinetixConnectedAccounts from '@/components/KinetixConnectedAccounts.vue';
import KinetixSessions from '@/components/KinetixSessions.vue';
import KinetixSocialButton from '@/components/KinetixSocialButton.vue';
import KinetixRichEditor from '@/components/KinetixRichEditor.vue';
import KinetixNumberField from '@/components/KinetixNumberField.vue';
import KinetixCopyableInput from '@/components/KinetixCopyableInput.vue';
import KinetixMediaLibrary from '@/components/KinetixMediaLibrary.vue';
import KinetixSlider from '@/components/KinetixSlider.vue';
import KinetixRating from '@/components/KinetixRating.vue';
import KinetixPinInput from '@/components/KinetixPinInput.vue';
import KinetixSlugInput from '@/components/KinetixSlugInput.vue';
import KinetixSignaturePad from '@/components/KinetixSignaturePad.vue';
import KinetixPhoneInput from '@/components/KinetixPhoneInput.vue';
import KinetixModeToggle from '@/components/KinetixModeToggle.vue';
import KinetixAccessibilityMenu from '@/components/KinetixAccessibilityMenu.vue';
import KinetixAnnouncements from '@/components/KinetixAnnouncements.vue';
import KinetixLanguageSwitcher from '@/components/KinetixLanguageSwitcher.vue';
import KinetixTeamSwitcher from '@/components/KinetixTeamSwitcher.vue';
import KinetixOnlineUsers from '@/components/KinetixOnlineUsers.vue';
import KinetixQueueStats from '@/components/KinetixQueueStats.vue';
import KinetixHealthStatus from '@/components/KinetixHealthStatus.vue';
import KinetixTableRepeater from '@/components/KinetixTableRepeater.vue';
import KinetixComments from '@/components/KinetixComments.vue';
import KinetixTags from '@/components/KinetixTags.vue';
import KinetixNotificationPreferences from '@/components/KinetixNotificationPreferences.vue';

// Slug + signature showcase for the gallery.
const SlugSignatureShowcase: Component = {
    render() {
        return h('div', { class: 'flex flex-col gap-4' }, [
            h(KinetixSlugInput, {
                value: 'my-first-post',
                config: { from: 'title', separator: '-' },
            }),
            h(KinetixSignaturePad, { config: { height: 140 } }),
        ]);
    },
};

// A showcase of the new interactive input fields for the gallery.
const InputFieldsShowcase: Component = {
    render() {
        return h('div', { class: 'flex flex-col gap-5' }, [
            h(KinetixSlider, {
                value: 60,
                config: { min: 0, max: 100, step: 5 },
            }),
            h(KinetixRating, {
                value: 3.5,
                config: { max: 5, allowHalf: true },
            }),
            h(KinetixPinInput, {
                value: '1234',
                config: { length: 6, otp: true },
            }),
        ]);
    },
};

// A showcase of NumberField formats for the gallery.
const NumberFieldShowcase: Component = {
    render() {
        return h('div', { class: 'flex flex-col gap-3' }, [
            h(KinetixNumberField, {
                value: 12,
                config: { min: 0, max: 99, step: 1 },
            }),
            h(KinetixNumberField, {
                value: 0.075,
                config: {
                    format: 'percent',
                    step: 0.005,
                    decimals: { min: 1, max: 2 },
                },
            }),
            h(KinetixNumberField, {
                value: 1499.99,
                config: { format: 'currency', currency: 'USD', step: 1 },
            }),
        ]);
    },
};

// Period filter — segmented + select variants.
const PeriodFilterShowcase: Component = {
    render() {
        return h('div', { class: 'flex flex-col items-start gap-4' }, [
            h(KinetixPeriodFilter, {
                modelValue: '7d',
                periods: ['90d', '30d', '7d'],
                variant: 'segmented',
            }),
            h(KinetixPeriodFilter, {
                modelValue: '90d',
                periods: ['7d', '30d', '90d', 'year'],
                variant: 'select',
            }),
        ]);
    },
};

// Copyable / revealable text inputs for the gallery.
const CopyableInputShowcase: Component = {
    render() {
        return h('div', { class: 'flex flex-col gap-3' }, [
            h(KinetixCopyableInput, {
                value: 'https://app.example.com/invite/7Hk9',
                copyable: true,
            }),
            h(KinetixCopyableInput, {
                value: 'sk_live_8f2c4a1e9b',
                copyable: true,
                revealable: true,
            }),
        ]);
    },
};

// A small showcase of social-auth login buttons for the gallery.
const SocialButtonsShowcase: Component = {
    render() {
        return h(
            'div',
            { class: 'flex flex-col gap-3' },
            ['github', 'google', 'microsoft', 'apple'].map((provider) =>
                h(KinetixSocialButton, { provider, mode: 'login' }),
            ),
        );
    },
};
import KinetixSpotlightTrigger from '@/components/KinetixSpotlightTrigger.vue';
import KinetixAccessibilityPanel from '@/components/KinetixAccessibilityPanel.vue';
import KinetixPricingTable from '@/components/KinetixPricingTable.vue';
import KinetixStatsOverviewWidget from '@/components/KinetixStatsOverviewWidget.vue';
import KinetixListWidget from '@/components/KinetixListWidget.vue';
import KinetixPeriodFilter from '@/components/KinetixPeriodFilter.vue';
import KinetixRangeCalendar from '@/components/KinetixRangeCalendar.vue';
import KinetixDateTimePicker from '@/components/KinetixDateTimePicker.vue';
import KinetixTimePicker from '@/components/KinetixTimePicker.vue';
import KinetixMonthPicker from '@/components/KinetixMonthPicker.vue';
import KinetixYearPicker from '@/components/KinetixYearPicker.vue';
import KinetixWeekPicker from '@/components/KinetixWeekPicker.vue';
import KinetixDateRangePicker from '@/components/KinetixDateRangePicker.vue';
import KinetixAddressPicker from '@/components/KinetixAddressPicker.vue';
import KinetixFileUpload from '@/components/KinetixFileUpload.vue';
import KinetixSelect from '@/components/KinetixSelect.vue';
import KinetixRadioGroup from '@/components/KinetixRadioGroup.vue';
import KinetixTagsInput from '@/components/KinetixTagsInput.vue';
import KinetixKeyValue from '@/components/KinetixKeyValue.vue';
import KinetixCalendar from '@/components/KinetixCalendar.vue';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';
import KinetixImpersonationBanner from '@/components/KinetixImpersonationBanner.vue';
import KinetixActionDropdown from '@/components/KinetixActionDropdown.vue';
import KinetixMemberActivation from '@/components/KinetixMemberActivation.vue';
import KinetixPermissionMatrix from '@/components/KinetixPermissionMatrix.vue';
import KinetixActivityLog from '@/components/KinetixActivityLog.vue';
import KinetixRoleManager from '@/components/KinetixRoleManager.vue';
import KinetixMemberList from '@/components/KinetixMemberList.vue';
import KinetixSubscriptionStatus from '@/components/KinetixSubscriptionStatus.vue';
import KinetixInvoicesTable from '@/components/KinetixInvoicesTable.vue';
import KinetixChartWidget from '@/components/KinetixChartWidget.vue';
import KinetixCustomWidget from '@/components/KinetixCustomWidget.vue';
import KinetixTableWidget from '@/components/KinetixTableWidget.vue';
import KinetixKanban from '@/components/KinetixKanban.vue';
import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

export interface Specimen {
    name: string;
    title: string;
    component: Component;
    props?: Record<string, unknown>;
    slots?: Record<string, () => unknown>;
    /** Capture width in px (the gallery frame). */
    width?: number;
    /** Wrap in a card so bare components show realistic in-app chrome. */
    frame?: 'card' | 'bare';
    /** Click this selector then capture the full page (for teleported popovers). */
    openSelector?: string;
}

// Form layout fixtures (rendered through KinetixFormSchema).
const ti = (name: string, label: string, columnSpan: number | string) => ({
    type: 'text-input',
    name,
    label,
    columnSpan,
    inputType: 'text',
    isDisabled: false,
});

const layouts = {
    grid: [
        {
            type: 'grid',
            columnSpan: 'full',
            columns: 12,
            schema: [
                ti('first', 'First name', 4),
                ti('last', 'Last name', 4),
                ti('mi', 'M.I.', 4),
            ],
        },
    ],
    fieldset: [
        {
            type: 'fieldset',
            heading: 'Address',
            columnSpan: 'full',
            columns: 12,
            schema: [ti('city', 'City', 8), ti('zip', 'ZIP', 4)],
        },
    ],
    tabs: [
        {
            type: 'tabs',
            columnSpan: 'full',
            schema: [
                {
                    type: 'tab',
                    heading: 'Profile',
                    columns: 12,
                    schema: [ti('name', 'Name', 12)],
                },
                {
                    type: 'tab',
                    heading: 'Security',
                    icon: 'settings',
                    columns: 12,
                    schema: [ti('password', 'Password', 12)],
                },
            ],
        },
    ],
    split: [
        {
            type: 'split',
            columnSpan: 'full',
            schema: [ti('first', 'First', 1), ti('last', 'Last', 1)],
        },
    ],
    placeholder: [
        {
            type: 'placeholder',
            label: 'Account ID',
            content: 'usr_1a2b3c',
            columnSpan: 'full',
        },
        ti('nickname', 'Nickname', 12),
    ],
};

const layoutValues = {
    first: 'Ada',
    last: 'Lovelace',
    mi: 'A',
    city: 'London',
    zip: 'EC1',
    name: 'Ada Lovelace',
    password: '••••••••',
    nickname: 'ada',
};

const col = (name: string, extra: Record<string, unknown> = {}) => ({
    name,
    label: name.charAt(0).toUpperCase() + name.slice(1),
    isSearchable: false,
    isSortable: false,
    alignment: 'left',
    isToggleable: false,
    isToggledHiddenByDefault: false,
    type: 'text',
    ...extra,
});

const wizardSteps = [
    { key: 'account', label: 'Account', icon: 'user' },
    { key: 'plan', label: 'Plan', icon: 'credit-card' },
    { key: 'done', label: 'Finish', icon: 'check' },
];

const stepBody = (text: string) => () =>
    h('div', { class: 'py-6 text-sm text-muted-foreground' }, text);

const wizardSlots = {
    account: stepBody('Account details — name, email, password…'),
    plan: stepBody('Choose a plan that fits your team.'),
    done: stepBody("You're all set! Review and finish."),
};

// --- Form schema fixture (section → grid of fields) -------------------------
const formSchema = [
    {
        type: 'section',
        heading: 'Profile',
        description: 'Your public account information.',
        columnSpan: 'full',
        columns: 12,
        schema: [
            {
                type: 'text-input',
                name: 'name',
                label: 'Name',
                columnSpan: 6,
                inputType: 'text',
                isDisabled: false,
            },
            {
                type: 'text-input',
                name: 'email',
                label: 'Email',
                columnSpan: 6,
                inputType: 'email',
                isDisabled: false,
            },
            {
                type: 'select',
                name: 'role',
                label: 'Role',
                columnSpan: 6,
                isDisabled: false,
                options: { admin: 'Admin', editor: 'Editor', viewer: 'Viewer' },
            },
            {
                type: 'toggle',
                name: 'active',
                label: 'Active',
                columnSpan: 6,
                isDisabled: false,
            },
            {
                type: 'textarea',
                name: 'bio',
                label: 'Bio',
                columnSpan: 12,
                isDisabled: false,
            },
        ],
    },
];

const formValues = {
    name: 'Ada Lovelace',
    email: 'ada@example.com',
    role: 'editor',
    active: true,
    bio: 'Mathematician & first programmer.',
};

// --- Infolist fixture --------------------------------------------------------
const entry = (extra: Record<string, unknown>) => ({
    columnSpan: 1,
    openUrlInNewTab: false,
    isInline: false,
    ...extra,
});

const infolist = {
    columns: 2,
    operation: 'view',
    schema: [
        {
            type: 'section',
            heading: 'Order #1042',
            description: 'Placed on June 18, 2026',
            columnSpan: 'full',
            columns: 2,
            openUrlInNewTab: false,
            isInline: false,
            schema: [
                entry({
                    type: 'text',
                    label: 'Customer',
                    state: 'Ada Lovelace',
                }),
                entry({
                    type: 'text',
                    label: 'Email',
                    state: 'ada@example.com',
                }),
                entry({
                    type: 'text',
                    label: 'Status',
                    state: 'Paid',
                    isBadge: true,
                    color: 'success',
                }),
                entry({ type: 'text', label: 'Total', state: '$490.00' }),
            ],
        },
    ],
};

// --- Pricing plans fixture ---------------------------------------------------
const plans = [
    {
        id: 1,
        name: 'Starter',
        slug: 'starter',
        description: 'For side projects.',
        monthlyPrice: 0,
        yearlyPrice: 0,
        features: { Projects: '3', Seats: '1' },
        highlightedFeatures: ['Projects', 'Seats'],
        isFeatured: false,
        isFree: true,
        sortOrder: 1,
    },
    {
        id: 2,
        name: 'Pro',
        slug: 'pro',
        description: 'For growing teams.',
        monthlyPrice: 29,
        yearlyPrice: 290,
        features: { Projects: 'Unlimited', Seats: '10' },
        highlightedFeatures: ['Projects', 'Seats'],
        isFeatured: true,
        isFree: false,
        sortOrder: 2,
    },
    {
        id: 3,
        name: 'Business',
        slug: 'business',
        description: 'For organizations.',
        monthlyPrice: 99,
        yearlyPrice: 990,
        features: { Projects: 'Unlimited', Seats: 'Unlimited' },
        highlightedFeatures: ['Projects', 'Seats'],
        isFeatured: false,
        isFree: false,
        sortOrder: 3,
    },
];

// --- Stats widget fixture ----------------------------------------------------
const statsWidget = {
    id: 'stats',
    type: 'stats',
    columnSpan: 12,
    sort: 0,
    title: null,
    description: null,
    data: {
        stats: [
            {
                label: 'Sales today',
                value: '$502.30',
                icon: 'dollar-sign',
                iconColor: 'info',
                description: '+12.5% vs yesterday',
                descriptionIcon: 'arrow-up',
                descriptionColor: 'success',
            },
            {
                label: 'Transactions',
                value: '3',
                icon: 'shopping-cart',
                iconColor: 'warning',
                description: '+8.3% vs yesterday',
                descriptionIcon: 'arrow-up',
                descriptionColor: 'success',
            },
            {
                label: 'Customers',
                value: '3',
                icon: 'users',
                iconColor: 'success',
                description: '+3.1% vs yesterday',
                descriptionIcon: 'arrow-up',
                descriptionColor: 'success',
            },
            {
                label: 'Products',
                value: '8',
                icon: 'package',
                iconColor: 'info',
                description: '-1.2% vs yesterday',
                descriptionIcon: 'arrow-down',
                descriptionColor: 'danger',
            },
        ],
    },
};

const statsLinkWidget = {
    id: 'stats-link',
    type: 'stats',
    columnSpan: 12,
    sort: 0,
    title: null,
    description: null,
    data: {
        stats: [
            {
                label: 'Monthly recurring revenue',
                value: '$34.1K',
                badge: '+6.1%',
                badgeColor: 'success',
                descriptionIcon: 'arrow-up',
                linkLabel: 'View more',
                linkUrl: '#',
            },
            {
                label: 'Users',
                value: '500.1K',
                badge: '+19.2%',
                badgeColor: 'success',
                descriptionIcon: 'arrow-up',
                linkLabel: 'View more',
                linkUrl: '#',
            },
            {
                label: 'User growth',
                value: '11.3%',
                badge: '-1.2%',
                badgeColor: 'danger',
                descriptionIcon: 'arrow-down',
                linkLabel: 'View more',
                linkUrl: '#',
            },
        ],
    },
};

const listWidget = {
    id: 'recent',
    type: 'list',
    columnSpan: 4,
    sort: 0,
    title: 'Recent sales',
    description: null,
    headerActions: [{ label: 'Export', url: '#', icon: 'download' }],
    data: {
        icon: 'clock',
        actionLabel: 'View all sales',
        actionUrl: '#',
        emptyState: 'No sales yet',
        items: [
            {
                title: 'V001',
                subtitle: '2 products',
                icon: 'shopping-cart',
                iconColor: 'gray',
                value: '$97.44',
                badge: 'Cash',
                badgeColor: 'success',
            },
            {
                title: 'V002',
                subtitle: '2 products · María',
                icon: 'shopping-cart',
                iconColor: 'gray',
                value: '$270.30',
                badge: 'Card',
                badgeColor: 'info',
            },
            {
                title: 'Sabritas 45g',
                subtitle: 'Low stock',
                icon: 'alert-triangle',
                iconColor: 'warning',
                progress: 20,
                value: '3',
            },
        ],
    },
};

// --- Misc fixtures -----------------------------------------------------------
const permissionFeatures = [
    {
        name: 'users',
        label: 'Users',
        abilities: [
            { name: 'users.view', label: 'View' },
            { name: 'users.create', label: 'Create' },
            { name: 'users.delete', label: 'Delete' },
        ],
    },
    {
        name: 'orders',
        label: 'Orders',
        abilities: [
            { name: 'orders.view', label: 'View' },
            { name: 'orders.refund', label: 'Refund' },
        ],
    },
];

const pageActions = [
    {
        label: 'New order',
        icon: 'plus',
        color: 'primary',
        type: 'button',
        openUrlInNewTab: false,
    },
    {
        label: 'Export',
        icon: 'download',
        color: null,
        type: 'button',
        openUrlInNewTab: false,
    },
];

const actionGroup = {
    // No label → renders as the shadcn ghost "⋮" icon trigger.
    type: 'group',
    icon: 'ellipsis-vertical',
    color: null,
    actions: [
        {
            label: 'Edit',
            icon: 'edit',
            color: null,
            type: 'button',
            openUrlInNewTab: false,
        },
        {
            label: 'Delete',
            icon: 'trash',
            color: 'danger',
            type: 'button',
            openUrlInNewTab: false,
        },
    ],
};

const invoices = [
    {
        id: 'in_1042',
        date: 'Jun 1, 2026',
        total: '$29.00',
        status: 'paid',
        url: '#',
    },
    {
        id: 'in_1010',
        date: 'May 1, 2026',
        total: '$29.00',
        status: 'paid',
        url: '#',
    },
];

const chartWidget = {
    id: 'chart',
    type: 'chart',
    columnSpan: 12,
    sort: 0,
    title: 'Revenue',
    description: null,
    data: {
        chartType: 'line',
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{ label: '2026', data: [12, 19, 15, 22, 28, 26] }],
    },
};

const areaChartWidget = {
    id: 'area-chart',
    type: 'chart',
    columnSpan: 12,
    sort: 0,
    title: 'Total visitors',
    description: 'Last 6 months',
    data: {
        chartType: 'area',
        legend: true,
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [
            { label: 'Desktop', data: [12, 19, 15, 22, 28, 26] },
            { label: 'Mobile', data: [8, 11, 9, 14, 18, 16] },
        ],
    },
};

const hBarChartWidget = {
    id: 'hbar-chart',
    type: 'chart',
    columnSpan: 6,
    sort: 0,
    title: 'By category',
    description: 'Sales by product type',
    data: {
        chartType: 'horizontalBar',
        labels: ['Drinks', 'Food', 'Stationery', 'Cleaning', 'Other'],
        datasets: [{ label: 'Sales', data: [3200, 2400, 1600, 900, 600] }],
    },
};

const donutChartWidget = {
    id: 'donut-chart',
    type: 'chart',
    columnSpan: 6,
    sort: 0,
    title: 'Store visits by source',
    description: null,
    data: {
        chartType: 'doughnut',
        legend: true,
        centerValue: '10.2K',
        centerLabel: 'Visitors',
        labels: ['Direct', 'Social', 'Email', 'Referrals', 'Other'],
        datasets: [{ label: 'Visits', data: [4200, 2600, 1800, 1000, 600] }],
    },
};

const tableWidget = {
    id: 'tw',
    type: 'table',
    columnSpan: 12,
    sort: 0,
    title: 'Top products',
    description: null,
    data: {
        headers: ['Product', 'Sales'],
        rows: [
            ['Widget A', '1,204'],
            ['Widget B', '980'],
            ['Widget C', '610'],
        ],
    },
};

const customWidget = {
    id: 'cw',
    type: 'custom',
    columnSpan: 12,
    sort: 0,
    title: 'Release notes',
    description: 'Latest changes',
    data: {},
};

export const specimens: Specimen[] = [
    {
        name: 'wizard-stepper',
        title: 'Wizard — stepper',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: { steps: wizardSteps, variant: 'stepper', step: 1 },
        slots: wizardSlots,
    },
    {
        name: 'wizard-stepper-vertical',
        title: 'Wizard — stepper (vertical)',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: {
            steps: wizardSteps,
            variant: 'stepper',
            orientation: 'vertical',
            step: 1,
        },
        slots: wizardSlots,
    },
    {
        name: 'wizard-default',
        title: 'Wizard — default',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: { steps: wizardSteps, variant: 'default', step: 1 },
        slots: wizardSlots,
    },
    {
        name: 'wizard-gradient',
        title: 'Wizard — gradient',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: { steps: wizardSteps, variant: 'gradient', step: 1 },
        slots: wizardSlots,
    },
    {
        name: 'wizard-panels',
        title: 'Wizard — panels',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: { steps: wizardSteps, variant: 'panels', step: 0 },
        slots: wizardSlots,
    },
    {
        name: 'wizard-compact',
        title: 'Wizard — compact (full-width off)',
        component: KinetixWizard,
        frame: 'card',
        width: 640,
        props: {
            steps: wizardSteps,
            variant: 'stepper',
            fullWidth: false,
            step: 1,
        },
        slots: wizardSlots,
    },
    {
        name: 'empty-state',
        title: 'Empty state',
        component: KinetixEmptyState,
        width: 520,
        props: {
            icon: 'user',
            title: 'No members yet',
            description:
                'Invite a teammate to start collaborating on this project.',
        },
    },
    {
        name: 'onboarding-checklist',
        title: 'Onboarding checklist',
        component: KinetixOnboardingChecklist,
        width: 560,
    },
    {
        name: 'form-schema',
        title: 'Form (section + fields)',
        component: KinetixFormSchema,
        width: 700,
        props: { schema: formSchema, values: formValues, errors: {} },
    },
    {
        name: 'infolist',
        title: 'Infolist',
        component: KinetixInfolist,
        width: 640,
        props: { infolist },
    },
    {
        name: 'pricing-table',
        title: 'Pricing table',
        component: KinetixPricingTable,
        width: 880,
        props: {
            plans,
            currentPlanSlug: 'starter',
            cycle: 'monthly',
            currencySymbol: '$',
        },
    },
    {
        name: 'stats-widget',
        title: 'Stats overview widget',
        component: KinetixStatsOverviewWidget,
        width: 980,
        props: { widget: statsWidget },
    },
    {
        name: 'stats-link-widget',
        title: 'Stat cards — badge + View more link',
        component: KinetixStatsOverviewWidget,
        width: 760,
        props: { widget: statsLinkWidget },
    },
    {
        name: 'list-widget',
        title: 'List widget (feed / alerts)',
        component: KinetixListWidget,
        width: 380,
        props: { widget: listWidget },
    },
    {
        name: 'range-calendar',
        title: 'Date range calendar',
        component: KinetixRangeCalendar,
        frame: 'card',
        width: 560,
        props: {
            value: { from: '2026-06-10', to: '2026-06-18' },
            numberOfMonths: 1,
        },
    },
    {
        name: 'time-picker',
        title: 'Time picker (open)',
        component: KinetixTimePicker,
        width: 360,
        openSelector: '#specimen button',
        props: { value: '14:30', minuteStep: 15 },
    },
    {
        name: 'datetime-picker',
        title: 'Date-time picker (open)',
        component: KinetixDateTimePicker,
        width: 760,
        openSelector: '#specimen button',
        props: { value: '2026-06-15T14:30', minuteStep: 30 },
    },
    {
        name: 'month-picker',
        title: 'Month picker (open)',
        component: KinetixMonthPicker,
        width: 340,
        openSelector: '#specimen button',
        props: { value: '2026-06' },
    },
    {
        name: 'year-picker',
        title: 'Year picker (open)',
        component: KinetixYearPicker,
        width: 340,
        openSelector: '#specimen button',
        props: { value: '2026' },
    },
    {
        name: 'week-picker',
        title: 'Week picker (open)',
        component: KinetixWeekPicker,
        width: 420,
        openSelector: '#specimen button',
        props: { value: '2026-W25' },
    },
    {
        name: 'date-range-picker',
        title: 'Date range picker (open)',
        component: KinetixDateRangePicker,
        width: 460,
        openSelector: '#specimen button',
        props: { value: { from: '2026-06-10', to: '2026-06-18' } },
    },
    {
        name: 'address-picker',
        title: 'Address picker',
        component: KinetixAddressPicker,
        frame: 'card',
        width: 560,
        props: {
            value: {
                line1: '1600 Amphitheatre Pkwy',
                city: 'Mountain View',
                state: 'California',
                postalCode: '94043',
                country: 'US',
            },
            countries: {
                US: 'United States',
                MX: 'Mexico',
                PT: 'Portugal',
                FR: 'France',
            },
        },
    },

    // --- Form layouts ----------------------------------------------------------
    {
        name: 'layout-grid',
        title: 'Grid layout',
        component: KinetixFormSchema,
        frame: 'card',
        width: 640,
        props: { schema: layouts.grid, values: layoutValues, errors: {} },
    },
    {
        name: 'layout-fieldset',
        title: 'Fieldset layout',
        component: KinetixFormSchema,
        frame: 'card',
        width: 560,
        props: { schema: layouts.fieldset, values: layoutValues, errors: {} },
    },
    {
        name: 'layout-tabs',
        title: 'Tabs layout',
        component: KinetixFormSchema,
        frame: 'card',
        width: 560,
        props: { schema: layouts.tabs, values: layoutValues, errors: {} },
    },
    {
        name: 'layout-split',
        title: 'Split layout',
        component: KinetixFormSchema,
        frame: 'card',
        width: 560,
        props: { schema: layouts.split, values: layoutValues, errors: {} },
    },
    {
        name: 'layout-placeholder',
        title: 'Placeholder',
        component: KinetixFormSchema,
        frame: 'card',
        width: 520,
        props: {
            schema: layouts.placeholder,
            values: layoutValues,
            errors: {},
        },
    },
    {
        name: 'file-upload',
        title: 'File upload',
        component: KinetixFileUpload,
        frame: 'card',
        width: 560,
        props: { uploadToken: 'preview-token', isImage: true },
    },
    {
        name: 'token-manager',
        title: 'API token manager',
        component: KinetixTokenManager,
        width: 720,
    },
    {
        name: 'connected-accounts',
        title: 'Connected accounts',
        component: KinetixConnectedAccounts,
        frame: 'card',
        width: 620,
    },
    {
        name: 'sessions',
        title: 'Browser sessions',
        component: KinetixSessions,
        frame: 'card',
        width: 620,
    },
    {
        name: 'social-buttons',
        title: 'Social auth buttons',
        component: SocialButtonsShowcase,
        frame: 'card',
        width: 360,
    },
    {
        name: 'rich-editor-basic',
        title: 'Rich editor (basic)',
        component: KinetixRichEditor,
        frame: 'card',
        width: 560,
        props: {
            editor: 'basic',
            value: '<h2>Release notes</h2><p>A <strong>rich</strong> text field with a <em>zero-dependency</em> toolbar.</p><ul><li>Bold &amp; italic</li><li>Lists &amp; links</li></ul>',
        },
    },
    {
        name: 'rich-editor-tiptap',
        title: 'Rich editor (Tiptap)',
        component: KinetixRichEditor,
        frame: 'card',
        width: 560,
        props: {
            editor: 'tiptap',
            value: '<h2>Tiptap</h2><p>The headless WYSIWYG, styled with your shadcn tokens.</p><blockquote>Loaded lazily — an optional dependency.</blockquote>',
        },
    },
    {
        name: 'rich-editor-markdown',
        title: 'Rich editor (Markdown)',
        component: KinetixRichEditor,
        frame: 'card',
        width: 560,
        props: {
            editor: 'markdown',
            value: '# Markdown\n\nWrite in **Markdown** with a live *preview*.\n\n- Zero dependencies\n- Stores the raw source',
        },
    },
    {
        name: 'number-field',
        title: 'Number field (decimal · percent · currency)',
        component: NumberFieldShowcase,
        frame: 'card',
        width: 320,
    },
    {
        name: 'input-fields',
        title: 'Slider · Rating · PIN',
        component: InputFieldsShowcase,
        frame: 'card',
        width: 420,
    },
    {
        name: 'slug-signature',
        title: 'Slug input · Signature pad',
        component: SlugSignatureShowcase,
        frame: 'card',
        width: 420,
    },
    {
        name: 'phone-input',
        title: 'Phone input (international)',
        component: KinetixPhoneInput,
        frame: 'card',
        width: 480,
        props: {
            value: '+5215512345678',
            config: {
                defaultCountry: 'MX',
                countries: [
                    { code: 'US', name: 'United States', dial: '1' },
                    { code: 'MX', name: 'Mexico', dial: '52' },
                    { code: 'GB', name: 'United Kingdom', dial: '44' },
                    { code: 'ES', name: 'Spain', dial: '34' },
                ],
            },
        },
    },
    {
        name: 'mode-toggle',
        title: 'Dark-mode toggle (open)',
        component: KinetixModeToggle,
        width: 220,
        openSelector: '#specimen button',
    },
    {
        name: 'accessibility-menu',
        title: 'Accessibility quick-menu (open)',
        component: KinetixAccessibilityMenu,
        width: 340,
        openSelector: '#specimen button',
    },
    {
        name: 'announcements',
        title: 'Announcements (open)',
        component: KinetixAnnouncements,
        width: 420,
        openSelector: '#specimen button',
    },
    {
        name: 'language-switcher',
        title: 'Language switcher (open)',
        component: KinetixLanguageSwitcher,
        width: 240,
        openSelector: '#specimen button',
    },
    {
        name: 'team-switcher',
        title: 'Team switcher (open)',
        component: KinetixTeamSwitcher,
        width: 280,
        openSelector: '#specimen button',
    },
    {
        name: 'online-users',
        title: 'Online users (presence)',
        component: KinetixOnlineUsers,
        frame: 'card',
        width: 300,
    },
    {
        name: 'queue-stats',
        title: 'Queue health (Horizon widget)',
        component: KinetixQueueStats,
        width: 560,
    },
    {
        name: 'health-status',
        title: 'System health (spatie/laravel-health)',
        component: KinetixHealthStatus,
        width: 420,
    },
    {
        name: 'copyable-input',
        title: 'Copyable / revealable inputs',
        component: CopyableInputShowcase,
        frame: 'card',
        width: 380,
    },
    {
        name: 'period-filter',
        title: 'Period filter (segmented + select)',
        component: PeriodFilterShowcase,
        frame: 'card',
        width: 420,
    },
    {
        name: 'media-library',
        title: 'Media library (grid, reorder, upload)',
        component: KinetixMediaLibrary,
        frame: 'card',
        width: 560,
        props: {
            uploadToken: 'demo',
            value: [
                {
                    id: 1,
                    name: 'hero.jpg',
                    size: 248000,
                    mime: 'image/svg+xml',
                    url: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Crect width='160' height='160' fill='%232bb89a'/%3E%3C/svg%3E",
                },
                {
                    id: 2,
                    name: 'banner.png',
                    size: 512000,
                    mime: 'image/svg+xml',
                    url: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Crect width='160' height='160' fill='%236366f1'/%3E%3C/svg%3E",
                },
                {
                    id: 3,
                    name: 'specs.pdf',
                    size: 96000,
                    mime: 'application/pdf',
                    url: '#',
                },
            ],
        },
    },
    {
        name: 'table-repeater',
        title: 'Table repeater (editable rows)',
        component: KinetixTableRepeater,
        frame: 'card',
        width: 840,
        props: {
            errors: {},
            comp: {
                name: 'items',
                addActionLabel: 'Add product',
                exportable: true,
                summarize: { qty: 'sum', price: 'sum' },
                schema: [
                    { name: 'name', label: 'Product', type: 'text-input' },
                    { name: 'qty', label: 'Qty', type: 'number-field' },
                    { name: 'price', label: 'Price', type: 'number-field' },
                ],
            },
            modelValue: [
                { id: 1, name: 'Keyboard', qty: 2, price: 49 },
                { id: 2, name: 'Mouse', qty: 5, price: 25 },
                { id: 3, name: 'Monitor', qty: 1, price: 320 },
            ],
        },
    },
    {
        name: 'comments',
        title: 'Comments (threaded)',
        component: KinetixComments,
        frame: 'card',
        width: 600,
        props: { commentableType: 'App\\Models\\Post', commentableId: 1 },
    },
    {
        name: 'tags',
        title: 'Tags (polymorphic)',
        component: KinetixTags,
        frame: 'card',
        width: 480,
        props: { taggableType: 'App\\Models\\Post', taggableId: 1 },
    },
    {
        name: 'notification-preferences',
        title: 'Notification preferences',
        component: KinetixNotificationPreferences,
        frame: 'card',
        width: 640,
    },
    {
        name: 'webhook-manager',
        title: 'Webhook manager',
        component: KinetixWebhookManager,
        width: 760,
    },
    {
        name: 'gdpr-panel',
        title: 'GDPR self-service panel',
        component: KinetixGdprPanel,
        width: 640,
        props: { requirePassword: true },
    },
    {
        name: 'accessibility-panel',
        title: 'Accessibility panel',
        component: KinetixAccessibilityPanel,
        frame: 'card',
        width: 560,
    },

    // --- Form controls ---------------------------------------------------------
    {
        name: 'select',
        title: 'Select',
        component: KinetixSelect,
        frame: 'card',
        width: 420,
        props: {
            value: 'editor',
            options: { admin: 'Admin', editor: 'Editor', viewer: 'Viewer' },
        },
    },
    {
        name: 'radio-group',
        title: 'Radio group',
        component: KinetixRadioGroup,
        frame: 'card',
        width: 420,
        props: {
            value: 'yearly',
            options: { monthly: 'Monthly', yearly: 'Yearly' },
            inline: true,
        },
    },
    {
        name: 'tags-input',
        title: 'Tags input',
        component: KinetixTagsInput,
        frame: 'card',
        width: 480,
        props: { value: ['vue', 'laravel', 'inertia'] },
    },
    {
        name: 'key-value',
        title: 'Key-value',
        component: KinetixKeyValue,
        frame: 'card',
        width: 520,
        props: { value: { Plan: 'Pro', Seats: '10' } },
    },
    {
        name: 'calendar',
        title: 'Calendar',
        component: KinetixCalendar,
        frame: 'card',
        width: 460,
        props: { value: '2026-06-15', numberOfMonths: 1 },
    },

    // --- Feature UIs -----------------------------------------------------------
    {
        name: 'page-header',
        title: 'Page header',
        component: KinetixPageHeader,
        frame: 'card',
        width: 720,
        props: {
            heading: 'Orders',
            description: "Manage your store's orders.",
            actions: pageActions,
        },
    },
    {
        name: 'spotlight-trigger',
        title: 'Spotlight trigger',
        component: KinetixSpotlightTrigger,
        frame: 'card',
        width: 360,
    },
    {
        name: 'impersonation-banner',
        title: 'Impersonation banner',
        component: KinetixImpersonationBanner,
        width: 720,
    },
    {
        name: 'action-dropdown',
        title: 'Action dropdown',
        component: KinetixActionDropdown,
        frame: 'card',
        width: 360,
        props: { group: actionGroup },
    },
    {
        name: 'permission-matrix',
        title: 'Permission matrix',
        component: KinetixPermissionMatrix,
        frame: 'card',
        width: 680,
        props: {
            features: permissionFeatures,
            modelValue: ['users.view', 'orders.view'],
        },
    },
    {
        name: 'member-activation',
        title: 'Member activation',
        component: KinetixMemberActivation,
        frame: 'card',
        width: 520,
        props: { email: 'grace@example.com', action: '#' },
    },
    {
        name: 'activity-log',
        title: 'Activity log',
        component: KinetixActivityLog,
        frame: 'card',
        width: 640,
    },
    {
        name: 'role-manager',
        title: 'Role manager',
        component: KinetixRoleManager,
        frame: 'card',
        width: 720,
    },
    {
        name: 'member-list',
        title: 'Member list',
        component: KinetixMemberList,
        frame: 'card',
        width: 760,
    },

    // --- Billing ---------------------------------------------------------------
    {
        name: 'subscription-status',
        title: 'Subscription status',
        component: KinetixSubscriptionStatus,
        frame: 'card',
        width: 560,
        props: {
            subscription: {
                active: true,
                onGracePeriod: false,
                status: 'active',
                endsAt: null,
                stripePrice: 'price_pro',
            },
        },
    },
    {
        name: 'invoices-table',
        title: 'Invoices table',
        component: KinetixInvoicesTable,
        frame: 'card',
        width: 640,
        props: { invoices },
    },

    // --- Widgets ---------------------------------------------------------------
    {
        name: 'chart-widget',
        title: 'Chart widget',
        component: KinetixChartWidget,
        width: 700,
        props: { widget: chartWidget },
    },
    {
        name: 'chart-area',
        title: 'Chart — stacked area + legend',
        component: KinetixChartWidget,
        width: 700,
        props: { widget: areaChartWidget },
    },
    {
        name: 'chart-hbar',
        title: 'Chart — horizontal bars',
        component: KinetixChartWidget,
        width: 480,
        props: { widget: hBarChartWidget },
    },
    {
        name: 'chart-donut',
        title: 'Chart — donut with center label',
        component: KinetixChartWidget,
        width: 480,
        props: { widget: donutChartWidget },
    },
    {
        name: 'table-widget',
        title: 'Table widget',
        component: KinetixTableWidget,
        width: 560,
        props: { widget: tableWidget },
    },
    {
        name: 'custom-widget',
        title: 'Custom widget',
        component: KinetixCustomWidget,
        width: 520,
        props: { widget: customWidget },
        slots: {
            default: () =>
                h(
                    'p',
                    { class: 'text-sm text-muted-foreground' },
                    'Anything can go inside a custom widget.',
                ),
        },
    },
    {
        name: 'table-reorderable',
        title: 'Table — reorderable + selectable',
        component: KinetixTable,
        width: 760,
        props: {
            table: {
                heading: 'Sections',
                description: null,
                poll: null,
                isStriped: false,
                model: 'token',
                columns: [
                    col('header', { label: 'Header' }),
                    col('type', { label: 'Section type' }),
                    col('status', { label: 'Status', isBadge: true }),
                ],
                filters: [],
                recordActions: [actionGroup],
                toolbarActions: [],
                bulkActions: [
                    {
                        label: 'Delete',
                        icon: 'trash',
                        color: 'danger',
                        type: 'button',
                        openUrlInNewTab: false,
                    },
                ],
                footerActions: [],
                records: [
                    {
                        header: 'Technical approach',
                        type: 'Narrative',
                        status: 'Done',
                        _c: 'success',
                    },
                    {
                        header: 'Table of contents',
                        type: 'Table of contents',
                        status: 'Done',
                        _c: 'success',
                    },
                    {
                        header: 'Design',
                        type: 'Narrative',
                        status: 'In Process',
                        _c: 'warning',
                    },
                ].map((r, i) => ({
                    id: i + 1,
                    values: {
                        header: r.header,
                        type: r.type,
                        status: r.status,
                    },
                    icons: {},
                    iconColors: {},
                    badgeColors: { status: r._c },
                    descriptions: {},
                    recordUrl: null,
                    actions: [actionGroup],
                })),
                isPaginated: false,
                paginationPageOptions: [10],
                pagination: null,
                state: {
                    search: '',
                    sort: '',
                    direction: 'asc',
                    filters: {},
                    perPage: 10,
                },
                queryPrefix: '',
                summaries: {},
                hasSummaries: false,
                reorderable: true,
                savedViewsKey: 'App\\Models\\Product',
            },
        },
    },
    {
        name: 'event-calendar',
        title: 'Event calendar',
        component: KinetixEventCalendar,
        frame: 'card',
        width: 860,
        props: {
            locale: 'en-US',
            calendar: {
                heading: null,
                events: [
                    {
                        id: 1,
                        title: 'Product launch',
                        start: '2026-06-04',
                        end: null,
                        color: '#22c55e',
                        url: null,
                    },
                    {
                        id: 2,
                        title: 'Design sprint',
                        start: '2026-06-10',
                        end: '2026-06-13',
                        color: '#3b82f6',
                        url: null,
                    },
                    {
                        id: 3,
                        title: '1:1 with Ada',
                        start: '2026-06-18',
                        end: null,
                        color: '#a855f7',
                        url: null,
                    },
                    {
                        id: 4,
                        title: 'Release v2',
                        start: '2026-06-18',
                        end: null,
                        color: '#f59e0b',
                        url: null,
                    },
                    {
                        id: 5,
                        title: 'Retro',
                        start: '2026-06-26',
                        end: null,
                        color: null,
                        url: null,
                    },
                ],
            },
        },
    },
    {
        name: 'kanban',
        title: 'Kanban board',
        component: KinetixKanban,
        frame: 'card',
        width: 920,
        props: {
            kanban: {
                heading: null,
                model: 'demo',
                columns: [
                    {
                        key: 'todo',
                        label: 'To Do',
                        color: '#64748b',
                        cards: [
                            {
                                id: 1,
                                title: 'Draft the proposal',
                                description: 'Due Friday',
                            },
                            {
                                id: 2,
                                title: 'Collect requirements',
                                description: null,
                            },
                        ],
                    },
                    {
                        key: 'doing',
                        label: 'In Progress',
                        color: '#3b82f6',
                        cards: [
                            {
                                id: 3,
                                title: 'Design the dashboard',
                                description: 'Ada',
                            },
                        ],
                    },
                    {
                        key: 'done',
                        label: 'Done',
                        color: '#22c55e',
                        cards: [
                            {
                                id: 4,
                                title: 'Kickoff meeting',
                                description: null,
                            },
                        ],
                    },
                ],
            },
        },
    },
    {
        name: 'table-summaries',
        title: 'Table with summaries',
        component: KinetixTable,
        width: 720,
        props: {
            table: {
                heading: 'Orders',
                description: null,
                poll: null,
                isStriped: false,
                model: 'token',
                columns: [
                    col('reference'),
                    col('status'),
                    col('total', { alignment: 'right', hasSummary: true }),
                ],
                filters: [],
                recordActions: [],
                toolbarActions: [],
                bulkActions: [],
                footerActions: [],
                records: [
                    { reference: 'INV-1001', status: 'Paid', total: '$150.00' },
                    { reference: 'INV-1002', status: 'Paid', total: '$250.00' },
                    {
                        reference: 'INV-1003',
                        status: 'Pending',
                        total: '$90.00',
                    },
                ].map((values, i) => ({
                    id: i + 1,
                    values,
                    icons: {},
                    iconColors: {},
                    badgeColors: {},
                    descriptions: {},
                    recordUrl: null,
                    actions: [],
                })),
                isPaginated: false,
                paginationPageOptions: [10],
                pagination: null,
                state: {
                    search: '',
                    sort: '',
                    direction: 'asc',
                    filters: {},
                    perPage: 10,
                },
                queryPrefix: '',
                summaries: { total: [{ label: 'Total', value: '$490.00' }] },
                hasSummaries: true,
            },
        },
    },
];
