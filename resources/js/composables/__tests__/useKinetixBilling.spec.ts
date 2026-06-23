import { beforeEach, describe, expect, it, vi } from "vitest";

const post = vi.fn();
const del = vi.fn();

vi.mock("@inertiajs/vue3", () => ({
  router: {
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}));

import { useKinetixBilling } from "../useKinetixBilling";

const endpoints = {
  subscribe: "/billing/subscribe",
  cancel: "/billing/cancel",
  resume: "/billing/resume",
  addPaymentMethod: "/billing/payment-methods",
  removePaymentMethod: (id: string) => `/billing/payment-methods/${id}`,
};

describe("useKinetixBilling", () => {
  beforeEach(() => {
    post.mockReset();
    del.mockReset();
  });

  it("posts subscribe with plan slug, payment method and cycle", () => {
    useKinetixBilling(endpoints).subscribe("pro", "pm_1", "yearly");

    expect(post).toHaveBeenCalledWith(
      "/billing/subscribe",
      { plan_slug: "pro", payment_method: "pm_1", cycle: "yearly" },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("deletes the payment method by id", () => {
    useKinetixBilling(endpoints).removePaymentMethod("pm_9");

    expect(del).toHaveBeenCalledWith(
      "/billing/payment-methods/pm_9",
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("toggles processing across the visit lifecycle", () => {
    const billing = useKinetixBilling(endpoints);
    billing.cancel();

    const options = post.mock.calls[0][2] as {
      onStart: () => void;
      onFinish: () => void;
    };

    options.onStart();
    expect(billing.processing.value).toBe(true);
    options.onFinish();
    expect(billing.processing.value).toBe(false);
  });
});
