import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixMemberProvisioner from "@/components/KinetixMemberProvisioner.vue";
import { i18n } from "./i18n";

function mountProvisioner(assignableRoles: string[] = ["editor", "viewer"]) {
  return mount(KinetixMemberProvisioner, {
    props: { assignableRoles },
    global: { plugins: [i18n] },
  });
}

describe("KinetixMemberProvisioner", () => {
  it("only offers the assignable roles in the dropdown", () => {
    const wrapper = mountProvisioner(["editor", "viewer"]);
    const options = wrapper.findAll("option").map((o) => o.element.value);

    // Crucially, a privileged role like "admin" is never selectable here.
    expect(options).toEqual(["editor", "viewer"]);
  });

  it("emits submit with the email and selected role", async () => {
    const wrapper = mountProvisioner(["editor", "viewer"]);

    await wrapper.get('input[type="email"]').setValue("new@example.com");
    await wrapper.get("select").setValue("viewer");
    await wrapper.get("form").trigger("submit");

    expect(wrapper.emitted("submit")?.[0]).toEqual(["new@example.com", "viewer"]);
  });

  it("does not emit when the email is empty", async () => {
    const wrapper = mountProvisioner();

    await wrapper.get("form").trigger("submit");

    expect(wrapper.emitted("submit")).toBeUndefined();
  });
});
