import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixTagsInput from "../KinetixTagsInput.vue";

describe("KinetixTagsInput", () => {
  it("renders the existing tags", () => {
    const wrapper = mount(KinetixTagsInput, {
      props: { value: ["php", "vue"] },
    });

    expect(wrapper.text()).toContain("php");
    expect(wrapper.text()).toContain("vue");
  });

  it("adds a tag on Enter and emits the updated array", async () => {
    const wrapper = mount(KinetixTagsInput, { props: { value: [] } });
    const input = wrapper.find("input");

    await input.setValue("design");
    await input.trigger("keydown", { key: "Enter" });

    expect(wrapper.emitted("update:value")?.[0]).toEqual([["design"]]);
  });

  it("does not add a duplicate tag", async () => {
    const wrapper = mount(KinetixTagsInput, { props: { value: ["php"] } });
    const input = wrapper.find("input");

    await input.setValue("php");
    await input.trigger("keydown", { key: "Enter" });

    expect(wrapper.emitted("update:value")).toBeUndefined();
  });

  it("removes a tag when its remove button is clicked", async () => {
    const wrapper = mount(KinetixTagsInput, {
      props: { value: ["php", "vue"] },
    });

    await wrapper.findAll("button")[0].trigger("click");

    expect(wrapper.emitted("update:value")?.[0]).toEqual([["vue"]]);
  });

  it("removes the last tag on Backspace when the input is empty", async () => {
    const wrapper = mount(KinetixTagsInput, {
      props: { value: ["a", "b"] },
    });

    await wrapper.find("input").trigger("keydown", { key: "Backspace" });

    expect(wrapper.emitted("update:value")?.[0]).toEqual([["a"]]);
  });

  it("hides remove buttons when disabled", () => {
    const wrapper = mount(KinetixTagsInput, {
      props: { value: ["php"], disabled: true },
    });

    expect(wrapper.findAll("button")).toHaveLength(0);
  });
});
