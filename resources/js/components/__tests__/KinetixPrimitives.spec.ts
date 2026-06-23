import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Card from "../primitives/Card.vue";
import CardContent from "../primitives/CardContent.vue";
import CardFooter from "../primitives/CardFooter.vue";
import CardHeader from "../primitives/CardHeader.vue";
import { cn } from "../primitives/cn";
import {
  badgeVariants,
  buttonVariants,
  inputClass,
} from "@/composables/useShadcnVariants";

describe("cn", () => {
  it("joins truthy classes and drops falsy ones", () => {
    expect(cn("a", false, undefined, null, "b")).toBe("a b");
  });
});

describe("Card primitives (new-york-v4 parity)", () => {
  it("Card uses the v4 structure: gap-6 + py-6, not per-section p-6", () => {
    const wrapper = mount(Card);
    const cls = wrapper.get('[data-slot="card"]').classes();

    expect(cls).toContain("flex");
    expect(cls).toContain("flex-col");
    expect(cls).toContain("gap-6");
    expect(cls).toContain("py-6");
    expect(cls).toContain("rounded-xl");
    expect(cls).toContain("bg-card");
    expect(cls).not.toContain("p-6");
  });

  it("CardHeader / CardContent / CardFooter use px-6", () => {
    expect(
      mount(CardHeader).get('[data-slot="card-header"]').classes(),
    ).toContain("px-6");
    expect(
      mount(CardContent).get('[data-slot="card-content"]').classes(),
    ).toContain("px-6");
    expect(
      mount(CardFooter).get('[data-slot="card-footer"]').classes(),
    ).toContain("px-6");
  });

  it("merges a custom class via the class prop", () => {
    const wrapper = mount(Card, { props: { class: "justify-between" } });

    expect(wrapper.get('[data-slot="card"]').classes()).toContain(
      "justify-between",
    );
  });
});

describe("shadcn variants (new-york-v4 parity)", () => {
  it("buttonVariants default = primary + size default", () => {
    const cls = buttonVariants();
    expect(cls).toContain("bg-primary");
    expect(cls).toContain("text-primary-foreground");
    expect(cls).toContain("h-9");
    expect(cls).toContain("focus-visible:ring-ring/50");
  });

  it("buttonVariants outline + sm", () => {
    const cls = buttonVariants({ variant: "outline", size: "sm" });
    expect(cls).toContain("border-input");
    expect(cls).toContain("h-8");
  });

  it("badgeVariants is a rounded-full pill", () => {
    expect(badgeVariants()).toContain("rounded-full");
    expect(badgeVariants({ variant: "secondary" })).toContain("bg-secondary");
  });

  it("inputClass matches the v4 field (h-9, border-input, ring)", () => {
    expect(inputClass).toContain("h-9");
    expect(inputClass).toContain("border-input");
    expect(inputClass).toContain("focus-visible:ring-ring/50");
  });
});
