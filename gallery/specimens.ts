import { h, type Component } from "vue";
import KinetixWizard from "@/components/KinetixWizard.vue";
import KinetixEmptyState from "@/components/KinetixEmptyState.vue";
import KinetixOnboardingChecklist from "@/components/KinetixOnboardingChecklist.vue";
import KinetixTable from "@/components/KinetixTable.vue";

export interface Specimen {
  name: string;
  title: string;
  component: Component;
  props?: Record<string, unknown>;
  slots?: Record<string, () => unknown>;
  /** Capture width in px (the gallery frame). */
  width?: number;
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

const wizardSlots = {
  account: () => h("div", { class: "text-sm text-muted-foreground py-4" }, "Account details step…"),
  plan: () => h("div", { class: "text-sm text-muted-foreground py-4" }, "Choose a plan step…"),
  done: () => h("div", { class: "text-sm text-muted-foreground py-4" }, "All set!"),
};

export const specimens: Specimen[] = [
  {
    name: "wizard-default",
    title: "Wizard — default variant",
    component: KinetixWizard,
    props: { steps: wizardSteps, variant: "default", step: 1 },
    slots: wizardSlots,
    width: 640,
  },
  {
    name: "wizard-gradient",
    title: "Wizard — gradient variant",
    component: KinetixWizard,
    props: { steps: wizardSteps, variant: "gradient", step: 1 },
    slots: wizardSlots,
    width: 640,
  },
  {
    name: "wizard-panels",
    title: "Wizard — panels variant",
    component: KinetixWizard,
    props: { steps: wizardSteps, variant: "panels", step: 0 },
    slots: wizardSlots,
    width: 640,
  },
  {
    name: "empty-state",
    title: "Empty state",
    component: KinetixEmptyState,
    props: {
      icon: "user",
      title: "No members yet",
      description: "Invite a teammate to start collaborating on this project.",
    },
    width: 520,
  },
  {
    name: "onboarding-checklist",
    title: "Onboarding checklist",
    component: KinetixOnboardingChecklist,
    width: 560,
  },
  {
    name: "table-summaries",
    title: "Table with summaries",
    component: KinetixTable,
    width: 720,
    props: {
      table: {
        heading: "Orders",
        description: null,
        poll: null,
        isStriped: false,
        model: "token",
        columns: [col("reference"), col("status"), col("total", { alignment: "right", hasSummary: true })],
        filters: [],
        recordActions: [],
        toolbarActions: [],
        bulkActions: [],
        footerActions: [],
        records: [
          { reference: "INV-1001", status: "Paid", total: "$150.00" },
          { reference: "INV-1002", status: "Paid", total: "$250.00" },
          { reference: "INV-1003", status: "Pending", total: "$90.00" },
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
        state: { search: "", sort: "", direction: "asc", filters: {}, perPage: 10 },
        queryPrefix: "",
        summaries: { total: [{ label: "Total", value: "$490.00" }] },
        hasSummaries: true,
      },
    },
  },
];
