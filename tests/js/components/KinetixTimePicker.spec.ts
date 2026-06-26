import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixTimePicker from "@/components/KinetixTimePicker.vue";

describe("KinetixTimePicker", () => {
  it("renders a native time input when native", () => {
    const wrapper = mount(KinetixTimePicker, {
      props: { native: true, value: "14:30" },
    });
    const input = wrapper.find('input[type="time"]');
    expect(input.exists()).toBe(true);
    expect((input.element as HTMLInputElement).value).toBe("14:30");
  });

  it("emits H:i when a minute is picked", async () => {
    const wrapper = mount(KinetixTimePicker, {
      props: { value: "02:00", minuteStep: 15 },
    });
    // Minute buttons render 00/15/30/45; click "30".
    const btn = wrapper.findAll("button").find((b) => b.text() === "30");
    await btn!.trigger("click");

    expect(wrapper.emitted("update:value")?.[0]).toEqual(["02:30"]);
  });

  it("shows an AM/PM column in 12-hour mode", () => {
    const wrapper = mount(KinetixTimePicker, {
      props: { value: "14:30", hour12: true },
    });
    const labels = wrapper.findAll("button").map((b) => b.text());
    expect(labels).toContain("AM");
    expect(labels).toContain("PM");
  });
});
