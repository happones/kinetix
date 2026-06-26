/**
 * Stub of @/composables/useKinetixHttp for the gallery. `kinetixFetch` returns
 * canned fixtures based on the URL so self-fetching components (e.g. the
 * onboarding checklist) render fully without a backend.
 */
const fixtures: Array<{ match: RegExp; data: unknown }> = [
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
