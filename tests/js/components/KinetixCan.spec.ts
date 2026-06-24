import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

vi.mock("@inertiajs/vue3", () => ({
  usePage: () => ({
    props: {
      kinetix_permissions: {
        enabled: true,
        permissions: ["posts.update"],
        roles: ["editor"],
      },
    },
  }),
}));

import KinetixCan from "@/components/KinetixCan.vue";

describe("KinetixCan", () => {
  it("renders the default slot when the permission is granted", () => {
    const wrapper = mount(KinetixCan, {
      props: { permission: "posts.update" },
      slots: { default: "<span>allowed</span>" },
    });

    expect(wrapper.text()).toContain("allowed");
  });

  it("renders the denied slot when the permission is missing", () => {
    const wrapper = mount(KinetixCan, {
      props: { permission: "posts.delete" },
      slots: { default: "<span>allowed</span>", denied: "<span>nope</span>" },
    });

    expect(wrapper.text()).toContain("nope");
    expect(wrapper.text()).not.toContain("allowed");
  });

  it("supports require-all across multiple permissions", () => {
    const allowed = mount(KinetixCan, {
      props: { permission: ["posts.update"], requireAll: true },
      slots: { default: "yes" },
    });
    expect(allowed.text()).toContain("yes");

    const denied = mount(KinetixCan, {
      props: { permission: ["posts.update", "posts.delete"], requireAll: true },
      slots: { default: "yes" },
    });
    expect(denied.text()).not.toContain("yes");
  });

  it("gates by role", () => {
    const wrapper = mount(KinetixCan, {
      props: { role: "editor" },
      slots: { default: "byrole" },
    });

    expect(wrapper.text()).toContain("byrole");
  });
});
