import { h, type Component } from "vue";
import KinetixWizard from "@/components/KinetixWizard.vue";
import KinetixEmptyState from "@/components/KinetixEmptyState.vue";
import KinetixOnboardingChecklist from "@/components/KinetixOnboardingChecklist.vue";
import KinetixTable from "@/components/KinetixTable.vue";
import KinetixFormSchema from "@/components/KinetixFormSchema.vue";
import KinetixInfolist from "@/components/KinetixInfolist.vue";
import KinetixGdprPanel from "@/components/KinetixGdprPanel.vue";
import KinetixTokenManager from "@/components/KinetixTokenManager.vue";
import KinetixWebhookManager from "@/components/KinetixWebhookManager.vue";
import KinetixPricingTable from "@/components/KinetixPricingTable.vue";
import KinetixStatsOverviewWidget from "@/components/KinetixStatsOverviewWidget.vue";
import KinetixRangeCalendar from "@/components/KinetixRangeCalendar.vue";
import KinetixFileUpload from "@/components/KinetixFileUpload.vue";

export interface Specimen {
  name: string;
  title: string;
  component: Component;
  props?: Record<string, unknown>;
  slots?: Record<string, () => unknown>;
  /** Capture width in px (the gallery frame). */
  width?: number;
  /** Wrap in a card so bare components show realistic in-app chrome. */
  frame?: "card" | "bare";
}

const col = (name: string, extra: Record<string, unknown> = {}) => ({
  name,
  label: name.charAt(0).toUpperCase() + name.slice(1),
  isSearchable: false,
  isSortable: false,
  alignment: "left",
  isToggleable: false,
  isToggledHiddenByDefault: false,
  type: "text",
  ...extra,
});

const wizardSteps = [
  { key: "account", label: "Account", icon: "user" },
  { key: "plan", label: "Plan", icon: "credit-card" },
  { key: "done", label: "Finish", icon: "check" },
];

const stepBody = (text: string) => () =>
  h("div", { class: "py-6 text-sm text-muted-foreground" }, text);

const wizardSlots = {
  account: stepBody("Account details — name, email, password…"),
  plan: stepBody("Choose a plan that fits your team."),
  done: stepBody("You're all set! Review and finish."),
};

// --- Form schema fixture (section → grid of fields) -------------------------
const formSchema = [
  {
    type: "section",
    heading: "Profile",
    description: "Your public account information.",
    columnSpan: "full",
    columns: 12,
    schema: [
      { type: "text-input", name: "name", label: "Name", columnSpan: 6, inputType: "text", isDisabled: false },
      { type: "text-input", name: "email", label: "Email", columnSpan: 6, inputType: "email", isDisabled: false },
      {
        type: "select",
        name: "role",
        label: "Role",
        columnSpan: 6,
        isDisabled: false,
        options: { admin: "Admin", editor: "Editor", viewer: "Viewer" },
      },
      { type: "toggle", name: "active", label: "Active", columnSpan: 6, isDisabled: false },
      { type: "textarea", name: "bio", label: "Bio", columnSpan: 12, isDisabled: false },
    ],
  },
];

const formValues = {
  name: "Ada Lovelace",
  email: "ada@example.com",
  role: "editor",
  active: true,
  bio: "Mathematician & first programmer.",
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
  operation: "view",
  schema: [
    {
      type: "section",
      heading: "Order #1042",
      description: "Placed on June 18, 2026",
      columnSpan: "full",
      columns: 2,
      openUrlInNewTab: false,
      isInline: false,
      schema: [
        entry({ type: "text", label: "Customer", state: "Ada Lovelace" }),
        entry({ type: "text", label: "Email", state: "ada@example.com" }),
        entry({ type: "text", label: "Status", state: "Paid", isBadge: true, color: "success" }),
        entry({ type: "text", label: "Total", state: "$490.00" }),
      ],
    },
  ],
};

// --- Pricing plans fixture ---------------------------------------------------
const plans = [
  {
    id: 1, name: "Starter", slug: "starter", description: "For side projects.",
    monthlyPrice: 0, yearlyPrice: 0, features: { Projects: "3", Seats: "1" },
    highlightedFeatures: ["Projects", "Seats"], isFeatured: false, isFree: true, sortOrder: 1,
  },
  {
    id: 2, name: "Pro", slug: "pro", description: "For growing teams.",
    monthlyPrice: 29, yearlyPrice: 290, features: { Projects: "Unlimited", Seats: "10" },
    highlightedFeatures: ["Projects", "Seats"], isFeatured: true, isFree: false, sortOrder: 2,
  },
  {
    id: 3, name: "Business", slug: "business", description: "For organizations.",
    monthlyPrice: 99, yearlyPrice: 990, features: { Projects: "Unlimited", Seats: "Unlimited" },
    highlightedFeatures: ["Projects", "Seats"], isFeatured: false, isFree: false, sortOrder: 3,
  },
];

// --- Stats widget fixture ----------------------------------------------------
const statsWidget = {
  id: "stats", type: "stats", columnSpan: 12, sort: 0, title: null, description: null,
  data: {
    stats: [
      { label: "Revenue", value: "$24,580", chart: [10, 14, 12, 18, 22, 20, 26] },
      { label: "New users", value: "1,204", chart: [5, 6, 8, 7, 10, 12, 14] },
      { label: "Churn", value: "1.8%", chart: [4, 3, 3, 2, 2, 2, 1] },
    ],
  },
};

export const specimens: Specimen[] = [
  { name: "wizard-default", title: "Wizard — default", component: KinetixWizard, frame: "card", width: 640, props: { steps: wizardSteps, variant: "default", step: 1 }, slots: wizardSlots },
  { name: "wizard-gradient", title: "Wizard — gradient", component: KinetixWizard, frame: "card", width: 640, props: { steps: wizardSteps, variant: "gradient", step: 1 }, slots: wizardSlots },
  { name: "wizard-panels", title: "Wizard — panels", component: KinetixWizard, frame: "card", width: 640, props: { steps: wizardSteps, variant: "panels", step: 0 }, slots: wizardSlots },
  {
    name: "empty-state", title: "Empty state", component: KinetixEmptyState, width: 520,
    props: { icon: "user", title: "No members yet", description: "Invite a teammate to start collaborating on this project." },
  },
  { name: "onboarding-checklist", title: "Onboarding checklist", component: KinetixOnboardingChecklist, width: 560 },
  {
    name: "form-schema", title: "Form (section + fields)", component: KinetixFormSchema, width: 700,
    props: { schema: formSchema, values: formValues, errors: {} },
  },
  { name: "infolist", title: "Infolist", component: KinetixInfolist, width: 640, props: { infolist } },
  { name: "pricing-table", title: "Pricing table", component: KinetixPricingTable, width: 880, props: { plans, currentPlanSlug: "starter", cycle: "monthly", currencySymbol: "$" } },
  { name: "stats-widget", title: "Stats overview widget", component: KinetixStatsOverviewWidget, width: 980, props: { widget: statsWidget } },
  { name: "range-calendar", title: "Date range calendar", component: KinetixRangeCalendar, frame: "card", width: 560, props: { value: { from: "2026-06-10", to: "2026-06-18" }, numberOfMonths: 1 } },
  { name: "file-upload", title: "File upload", component: KinetixFileUpload, frame: "card", width: 560, props: { uploadToken: "preview-token", isImage: true } },
  { name: "token-manager", title: "API token manager", component: KinetixTokenManager, width: 720 },
  { name: "webhook-manager", title: "Webhook manager", component: KinetixWebhookManager, width: 760 },
  { name: "gdpr-panel", title: "GDPR self-service panel", component: KinetixGdprPanel, width: 640, props: { requirePassword: true } },
  {
    name: "table-summaries", title: "Table with summaries", component: KinetixTable, width: 720,
    props: {
      table: {
        heading: "Orders", description: null, poll: null, isStriped: false, model: "token",
        columns: [col("reference"), col("status"), col("total", { alignment: "right", hasSummary: true })],
        filters: [], recordActions: [], toolbarActions: [], bulkActions: [], footerActions: [],
        records: [
          { reference: "INV-1001", status: "Paid", total: "$150.00" },
          { reference: "INV-1002", status: "Paid", total: "$250.00" },
          { reference: "INV-1003", status: "Pending", total: "$90.00" },
        ].map((values, i) => ({
          id: i + 1, values, icons: {}, iconColors: {}, badgeColors: {}, descriptions: {}, recordUrl: null, actions: [],
        })),
        isPaginated: false, paginationPageOptions: [10], pagination: null,
        state: { search: "", sort: "", direction: "asc", filters: {}, perPage: 10 },
        queryPrefix: "", summaries: { total: [{ label: "Total", value: "$490.00" }] }, hasSummaries: true,
      },
    },
  },
];
