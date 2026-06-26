# Component previews

Visual previews of the main Kinetix components. These images are **generated
automatically** from the real components (see
[How previews are generated](#how-previews-are-generated)), so they stay in sync
with the code. Light and dark variants are captured for each.

## Forms & data

### Form layout

A schema-driven form (`Section` → grid of fields) — see [Forms](/forms).

![Form with a section and fields](/screenshots/form-schema.png)

### Date range calendar

`<KinetixRangeCalendar>` (shadcn calendar, Reka UI).

![Date range calendar](/screenshots/range-calendar.png)

### File upload

`<KinetixFileUpload>` dropzone.

![File upload dropzone](/screenshots/file-upload.png)

### Form controls

`<KinetixSelect>`, `<KinetixRadioGroup>`, `<KinetixTagsInput>`,
`<KinetixKeyValue>`, `<KinetixCalendar>`.

![Select](/screenshots/select.png)

![Radio group](/screenshots/radio-group.png)

![Tags input](/screenshots/tags-input.png)

![Key-value](/screenshots/key-value.png)

![Calendar](/screenshots/calendar.png)

### Table with summaries

A `Table` with a `Sum` summarizer in the footer — see [Tables → Summaries](/tables#summaries).

![Table with a summary footer](/screenshots/table-summaries.png)

### Infolist

Read-only record display — see [Infolists](/infolists).

![Infolist](/screenshots/infolist.png)

## Flows & onboarding

### Wizard

The standalone `<KinetixWizard>` — see the [Wizard guide](/wizard).

![Wizard — gradient variant](/screenshots/wizard-gradient.png)

![Wizard — panels variant](/screenshots/wizard-panels.png)

![Wizard — default variant](/screenshots/wizard-default.png)

### Onboarding checklist

`<KinetixOnboardingChecklist>` — see [Onboarding](/onboarding).

![Onboarding checklist](/screenshots/onboarding-checklist.png)

### Empty state

`<KinetixEmptyState>` — a reusable "no data yet" block.

![Empty state](/screenshots/empty-state.png)

### GDPR self-service

`<KinetixGdprPanel>` — see [GDPR self-service](/gdpr).

![GDPR self-service panel](/screenshots/gdpr-panel.png)

### Member list & activation

`<KinetixMemberList>` and `<KinetixMemberActivation>` — see [Membership](/membership).

![Member list](/screenshots/member-list.png)

![Member activation](/screenshots/member-activation.png)

### Impersonation banner

`<KinetixImpersonationBanner>` — see [Impersonation](/impersonation).

![Impersonation banner](/screenshots/impersonation-banner.png)

## SaaS platform

### Pricing table

`<KinetixPricingTable>` — see [Billing](/billing).

![Pricing table](/screenshots/pricing-table.png)

### Stats overview widget

`<KinetixStatsOverviewWidget>` — see [Widgets](/widgets).

![Stats overview widget](/screenshots/stats-widget.png)

### API token manager

`<KinetixTokenManager>` — see [Developer Tokens](/tokens).

![API token manager](/screenshots/token-manager.png)

### Webhook manager

`<KinetixWebhookManager>` — see [Webhooks](/webhooks).

![Webhook manager](/screenshots/webhook-manager.png)

### Roles & permissions

`<KinetixRoleManager>` and `<KinetixPermissionMatrix>` — see [Permissions](/permissions).

![Role manager](/screenshots/role-manager.png)

![Permission matrix](/screenshots/permission-matrix.png)

### Activity log

`<KinetixActivityLog>` — see [Activity](/activity).

![Activity log](/screenshots/activity-log.png)

### Subscription & invoices

`<KinetixSubscriptionStatus>` and `<KinetixInvoicesTable>` — see [Billing](/billing).

![Subscription status](/screenshots/subscription-status.png)

![Invoices table](/screenshots/invoices-table.png)

### Dashboard widgets

`<KinetixChartWidget>`, `<KinetixTableWidget>`, `<KinetixCustomWidget>` — see [Widgets](/widgets).

![Chart widget](/screenshots/chart-widget.png)

![Table widget](/screenshots/table-widget.png)

![Custom widget](/screenshots/custom-widget.png)

### Page header & action menu

`<KinetixPageHeader>` and `<KinetixActionDropdown>`.

![Page header](/screenshots/page-header.png)

![Action dropdown](/screenshots/action-dropdown.png)

---

## How previews are generated

Previews are captured with a small, repeatable pipeline — no manual screenshots:

1. **A Vite "gallery"** (`gallery/`) renders one component per request
   (`?s=<name>&theme=light|dark`) with realistic mock props. Inertia and the
   Kinetix HTTP composable are aliased to lightweight stubs so components mount
   without a backend; real `en` translations and the shadcn design tokens are
   loaded so the output matches production. Bare components are wrapped in a card.
2. **A Playwright script** (`scripts/screenshots.mjs`) boots the gallery, visits
   each entry in light and dark themes, and saves a cropped 2× PNG to
   `docs/public/screenshots/`.

Regenerate them with:

```bash
npm run screenshots
```

To preview a single component interactively while authoring:

```bash
npm run gallery:dev   # then open http://localhost:5733/?s=wizard-gradient&theme=dark
```

Add a new preview by appending an entry to `gallery/specimens.ts`
(`{ name, title, component, props, slots?, width?, frame? }`) and re-running the
command.
