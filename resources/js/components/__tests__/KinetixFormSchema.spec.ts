import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import KinetixFormSchema from "../KinetixFormSchema.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  messages: { en: { kinetix: { add_item: "Add item", not_set: "Not set" } } },
});

const mountSchema = (schema: any[], values: Record<string, any> = {}) =>
  mount(KinetixFormSchema, {
    props: { schema, values, errors: {} },
    global: { plugins: [i18n] },
  });

describe("KinetixFormSchema", () => {
  it("renders a tokenized text input", () => {
    const wrapper = mountSchema(
      [
        {
          type: "text-input",
          name: "title",
          label: "Title",
          columnSpan: "full",
          inputType: "text",
          isDisabled: false,
        },
      ],
      { title: "hi" },
    );

    expect(wrapper.find('input[type="text"]').exists()).toBe(true);
  });

  it("renders a Reka switch for toggle fields and emits on change", async () => {
    const wrapper = mountSchema(
      [
        {
          type: "toggle",
          name: "active",
          label: "Active",
          columnSpan: "full",
          isDisabled: false,
        },
      ],
      { active: false },
    );

    const toggle = wrapper.get('[role="switch"]');
    await toggle.trigger("click");

    expect(wrapper.emitted("update:value")?.[0]).toEqual(["active", true]);
  });
});
