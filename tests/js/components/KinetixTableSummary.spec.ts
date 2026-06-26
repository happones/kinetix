import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

vi.mock("@inertiajs/vue3", () => ({
  usePage: () => ({ props: { kinetix_config: { route_prefix: "_kinetix" } } }),
  router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixTable from "@/components/KinetixTable.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  fallbackWarn: false,
  messages: { en: { kinetix: { summary_total: "Total" } } },
});

const col = (name: string, extra: Record<string, any> = {}) => ({
  name,
  label: name,
  isSearchable: false,
  isSortable: false,
  alignment: "left",
  isToggleable: false,
  isToggledHiddenByDefault: false,
  type: "text",
  ...extra,
});

const table = {
  heading: null,
  description: null,
  poll: null,
  isStriped: false,
  model: "token",
  columns: [col("name"), col("price", { hasSummary: true })],
  filters: [],
  recordActions: [],
  toolbarActions: [],
  bulkActions: [],
  footerActions: [],
  records: [],
  isPaginated: false,
  paginationPageOptions: [10],
  pagination: null,
  state: { search: "", sort: "", direction: "asc", filters: {}, perPage: 10 },
  queryPrefix: "",
  summaries: {
    price: [
      { label: "Sum", value: "600" },
      { label: "Avg", value: "200" },
    ],
  },
  hasSummaries: true,
};

const mountTable = (t: any) =>
  mount(KinetixTable, {
    props: { table: t },
    global: {
      plugins: [i18n],
      stubs: {
        KinetixTableHead: true,
        KinetixTableCell: true,
        KinetixActionDropdown: true,
        KinetixTablePagination: true,
      },
    },
  });

describe("KinetixTable summary footer", () => {
  it("renders a tfoot with each summarizer value when hasSummaries", () => {
    const wrapper = mountTable(table);

    const foot = wrapper.find("tfoot");
    expect(foot.exists()).toBe(true);
    expect(foot.text()).toContain("Sum: 600");
    expect(foot.text()).toContain("Avg: 200");
    // The leading summary-less column shows the Total label.
    expect(foot.text()).toContain("Total");
  });

  it("renders no tfoot when the table has no summaries", () => {
    const wrapper = mountTable({
      ...table,
      summaries: {},
      hasSummaries: false,
    });

    expect(wrapper.find("tfoot").exists()).toBe(false);
  });
});
