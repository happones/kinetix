/**
 * Stub of @/composables/useKinetixHttp for the gallery. `kinetixFetch` returns
 * canned fixtures based on the URL so self-fetching components (e.g. the
 * onboarding checklist) render fully without a backend.
 */
const fixtures: Array<{ match: RegExp; data: unknown }> = [
  {
    match: /\/tokens$/,
    data: {
      tokens: [
        {
          id: 1,
          name: "CI deploy",
          abilities: ["posts.read", "posts.write"],
          lastUsedAt: "2026-06-20T10:00:00Z",
          createdAt: "2026-06-01T09:00:00Z",
        },
        {
          id: 2,
          name: "Zapier",
          abilities: ["*"],
          lastUsedAt: null,
          createdAt: "2026-06-18T14:30:00Z",
        },
      ],
      scopes: { "posts.read": "Read posts", "posts.write": "Write posts" },
    },
  },
  {
    match: /\/webhooks$/,
    data: {
      endpoints: [
        {
          id: 1,
          name: "Order events",
          url: "https://example.com/hooks/orders",
          events: ["order.created", "order.shipped"],
          active: true,
          createdAt: "2026-06-10T09:00:00Z",
        },
        {
          id: 2,
          name: "Billing sync",
          url: "https://example.com/hooks/billing",
          events: ["invoice.paid"],
          active: false,
          createdAt: "2026-06-12T11:00:00Z",
        },
      ],
      events: {
        "order.created": "Order created",
        "order.shipped": "Order shipped",
        "invoice.paid": "Invoice paid",
      },
    },
  },
  {
    match: /\/onboarding$/,
    data: {
      steps: [
        {
          key: "verify-email",
          title: "Verify your email",
          description: "Confirm your address to unlock everything.",
          ctaLabel: "Resend",
          ctaHref: "#",
          icon: "mail",
          completed: true,
          manual: false,
        },
        {
          key: "invite",
          title: "Invite a teammate",
          description: "Collaboration is better together.",
          ctaLabel: "Invite",
          ctaHref: "#",
          icon: "user",
          completed: false,
          manual: false,
        },
        {
          key: "read-docs",
          title: "Read the quickstart",
          description: null,
          ctaLabel: null,
          ctaHref: null,
          icon: null,
          completed: false,
          manual: true,
        },
      ],
      completedCount: 1,
      total: 3,
      complete: false,
      dismissed: false,
    },
  },
];

export async function kinetixFetch<T = unknown>(url: string): Promise<T | null> {
  const hit = fixtures.find((f) => f.match.test(url));
  return (hit ? (hit.data as T) : null) ?? null;
}

export function kinetixRoutePrefix(): string {
  return "_kinetix";
}
