import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { useKinetixStripe } from "@/composables/useKinetixStripe";

interface FakeElement {
  type: string;
  opts: any;
  handlers: Record<string, () => void>;
  on: (event: string, cb: () => void) => void;
  mount: ReturnType<typeof vi.fn>;
  update: ReturnType<typeof vi.fn>;
  unmount: ReturnType<typeof vi.fn>;
  destroy: ReturnType<typeof vi.fn>;
}

const created: FakeElement[] = [];
const confirmCardSetup = vi.fn();

function installFakeStripe(): void {
  (window as any).Stripe = () => ({
    elements: () => ({
      create: (type: string, opts: any): FakeElement => {
        const element: FakeElement = {
          type,
          opts,
          handlers: {},
          on(event, cb) {
            this.handlers[event] = cb;
          },
          mount: vi.fn(),
          update: vi.fn(),
          unmount: vi.fn(),
          destroy: vi.fn(),
        };
        created.push(element);

        return element;
      },
    }),
    confirmCardSetup,
  });
}

function makeHarness() {
  return defineComponent({
    setup(_, { expose }) {
      const stripe = useKinetixStripe({ publishableKey: "pk_test_123" });
      expose(stripe);

      return () => null;
    },
  });
}

describe("useKinetixStripe", () => {
  beforeEach(() => {
    created.length = 0;
    confirmCardSetup.mockReset();
    document.documentElement.className = "";
    document.documentElement.style.setProperty("--foreground", "240 10% 3.9%");
    document.documentElement.style.setProperty(
      "--muted-foreground",
      "240 5% 64.9%",
    );
    document.documentElement.style.setProperty("--destructive", "0 72% 50%");
    installFakeStripe();
  });

  afterEach(() => {
    delete (window as any).Stripe;
  });

  it("creates a card element styled from shadcn tokens", async () => {
    const wrapper = mount(makeHarness());
    const node = document.createElement("div");

    await (wrapper.vm as any).mount(node);
    await flushPromises();

    expect(created).toHaveLength(1);
    expect(created[0].type).toBe("card");
    expect(typeof created[0].opts.style.base.color).toBe("string");
    expect(created[0].opts.style.base.color.length).toBeGreaterThan(0);
    expect(created[0].mount).toHaveBeenCalledWith(node);
  });

  it("re-applies the style when the theme class toggles", async () => {
    const wrapper = mount(makeHarness());
    await (wrapper.vm as any).mount(document.createElement("div"));
    await flushPromises();

    // Simulate a dark-mode toggle on <html>.
    document.documentElement.style.setProperty("--foreground", "0 0% 98%");
    document.documentElement.classList.add("dark");
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(created[0].update).toHaveBeenCalled();
    expect(created[0].update.mock.calls[0][0]).toHaveProperty("style");
  });

  it("returns the payment method id from confirmCardSetup", async () => {
    confirmCardSetup.mockResolvedValue({
      setupIntent: { payment_method: "pm_abc" },
    });

    const wrapper = mount(makeHarness());
    await (wrapper.vm as any).mount(document.createElement("div"));

    const result = await (wrapper.vm as any).confirmCardSetup("seti_secret");

    expect(result).toEqual({ paymentMethodId: "pm_abc", error: null });
  });

  it("surfaces Stripe errors from confirmCardSetup", async () => {
    confirmCardSetup.mockResolvedValue({
      error: { message: "Card declined." },
    });

    const wrapper = mount(makeHarness());
    await (wrapper.vm as any).mount(document.createElement("div"));

    const result = await (wrapper.vm as any).confirmCardSetup("seti_secret");

    expect(result.paymentMethodId).toBeNull();
    expect(result.error).toBe("Card declined.");
  });

  it("tears down the element and observer on unmount (no leaks)", async () => {
    const wrapper = mount(makeHarness());
    await (wrapper.vm as any).mount(document.createElement("div"));
    await flushPromises();

    const element = created[0];
    wrapper.unmount();

    expect(element.unmount).toHaveBeenCalled();
    expect(element.destroy).toHaveBeenCalled();
  });

  it("reports an error when Stripe.js is unavailable", async () => {
    delete (window as any).Stripe;

    const wrapper = mount(makeHarness());
    await (wrapper.vm as any).mount(document.createElement("div"));
    await flushPromises();

    expect((wrapper.vm as any).error).toContain("Stripe.js is unavailable");
    expect(created).toHaveLength(0);
  });
});
