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
    match: /\/activity$/,
    data: {
      data: [
        {
          id: 1, event: "updated", description: null,
          causerName: "Ada Lovelace", causerId: 1,
          subjectType: "Order", subjectId: 1042,
          changes: { old: { status: "pending" }, attributes: { status: "paid" } },
          createdAt: "2026-06-20T10:00:00Z",
        },
        {
          id: 2, event: "created", description: null,
          causerName: "Grace Hopper", causerId: 2,
          subjectType: "Order", subjectId: 1043,
          changes: { old: {}, attributes: {} },
          createdAt: "2026-06-19T16:30:00Z",
        },
      ],
      pagination: { current_page: 1, last_page: 1 },
    },
  },
  {
    match: /\/permissions\/features$/,
    data: [
      {
        name: "users", label: "Users",
        abilities: [
          { name: "users.view", label: "View" },
          { name: "users.create", label: "Create" },
          { name: "users.delete", label: "Delete" },
        ],
      },
      {
        name: "orders", label: "Orders",
        abilities: [
          { name: "orders.view", label: "View" },
          { name: "orders.refund", label: "Refund" },
        ],
      },
    ],
  },
  {
    match: /\/permissions\/roles$/,
    data: [
      { id: 1, name: "admin", permissions: ["users.view", "users.create", "users.delete", "orders.view", "orders.refund"] },
      { id: 2, name: "editor", permissions: ["users.view", "orders.view"] },
    ],
  },
  {
    match: /\/members$/,
    data: {
      provisions: [
        { id: 1, email: "ada@example.com", name: "Ada Lovelace", role: "editor", status: "active", expired: false, activatedAt: "2026-06-10T09:00:00Z", expiresAt: null },
        { id: 2, email: "grace@example.com", name: null, role: "viewer", status: "pending", expired: false, activatedAt: null, expiresAt: "2026-07-01T00:00:00Z" },
      ],
      assignable_roles: ["editor", "viewer"],
    },
  },
  {
    match: /\/connected-accounts$/,
    data: {
      accounts: [
        {
          id: 1,
          provider: "github",
          name: "Ada Lovelace",
          nickname: "ada",
          email: "ada@example.com",
          avatar: null,
          createdAt: "2026-06-01T09:00:00Z",
        },
      ],
      providers: [
        { key: "github", label: "GitHub", icon: "github", color: "#181717", linked: true },
        { key: "google", label: "Google", icon: "google", color: "#4285F4", linked: false },
      ],
      hasPassword: false,
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
