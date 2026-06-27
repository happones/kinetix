import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixNumberField from "@/components/KinetixNumberField.vue";

describe("KinetixNumberField", () => {
  it("renders the current value and stepper buttons", () => {
    const w = mount(KinetixNumberField, {
      props: { value: 7, config: { min: 0, max: 10, step: 1 } },
    });
    expect(w.find("input").exists()).toBe(true);
    // Decrement + increment buttons.
    expect(w.findAll("button").length).toBe(2);
    expect((w.find("input").element as HTMLInputElement).value).toContain("7");
  });

  it("emits a number when a new value is entered", async () => {
    const w = mount(KinetixNumberField, {
      props: { value: 1, config: { min: 0, max: 10, step: 1 } },
    });
    const input = w.find("input");
    await input.setValue("4");
    await input.trigger("blur");
    const events = w.emitted("update:value");
    expect(events).toBeTruthy();
    expect(events![events!.length - 1][0]).toBe(4);
  });

  it("emits null when the input is cleared", async () => {
    const w = mount(KinetixNumberField, { props: { value: 5 } });
    const input = w.find("input");
    await input.setValue("");
    await input.trigger("blur");
    const events = w.emitted("update:value");
    expect(events?.some((e) => e[0] === null)).toBe(true);
  });
});
