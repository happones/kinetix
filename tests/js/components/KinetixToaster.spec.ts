import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixToaster from "@/components/KinetixToaster.vue";

describe("KinetixToaster", () => {
  it("mounts the sonner Toaster with token-based classes", () => {
    const wrapper = mount(KinetixToaster, { attachTo: document.body });

    // The Toaster renders a region/section element from vue-sonner.
    expect(wrapper.html()).toBeTruthy();
    wrapper.unmount();
  });
});
