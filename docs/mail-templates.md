# Mail Templates

Editable email templates — a subject + **Markdown or HTML** body with
`{{ variable }}` placeholders — managed from a UI and stored in the database.
Your application supplies the variable values and triggers the sends; Kinetix
handles storage, rendering and a test-send. Non-developers can edit the copy
without touching code.

<Screenshot name="mail-templates" alt="Mail templates manager" />

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-mail-templates-migrations
php artisan migrate
```

```php
'mail_templates' => ['enabled' => env('KINETIX_MAIL_TEMPLATES_ENABLED', true)],
```

The manager and test endpoints are gated by the `viewKinetixMail` ability
(defaults to allow in `local` — define your own for production):

```php
Gate::define('viewKinetixMail', fn ($user) => $user->isAdmin());
```

---

## The manager

```vue
<script setup lang="ts">
import KinetixMailTemplates from '@/components/KinetixMailTemplates.vue';
</script>

<template>
    <KinetixMailTemplates />
</template>
```

Pick a template (or **New template**), edit its name/key/subject, choose
**Markdown** or **HTML**, write the body with `{{ variable }}` placeholders,
declare the variables (with sample values), and watch the **live preview** render
with the samples. **Send test** delivers it to any address. Strings are localized
(`mail_*`, en/es/fr/pt).

---

## Sending from your app

The actual sends live in your app's logic — call `KinetixMail::send()` with the
template key and the data:

```php
use Happones\Kinetix\Mail\KinetixMail;

KinetixMail::send($user->email, 'welcome', [
    'name'       => $user->name,
    'trial_ends' => $user->trial_ends_at->toFormattedDateString(),
]);
```

- `KinetixMail::send($to, string $key, array $data = []): bool` — renders and
  mails the template (returns `false` if it's missing or disabled, so you can
  fall back).
- `KinetixMail::render(string $key, array $data = []): array|null` — `{ subject,
  html }` without sending.
- `KinetixMail::test(string $key, string $to, array $data = []): bool` — sends a
  `[TEST]` email using the template's sample data.

Variable values are **HTML-escaped** in Markdown templates; HTML templates are
rendered as-is (you control the markup). The subject is always plain text.

---

## Endpoints

Registered under your Kinetix prefix (team-aware), all gated by `viewKinetixMail`:

| Method   | Route                              | Purpose |
| -------- | ---------------------------------- | ------- |
| `GET`    | `{prefix}/mail-templates`          | List |
| `POST`   | `{prefix}/mail-templates`          | Create |
| `PUT`    | `{prefix}/mail-templates/{id}`     | Update |
| `DELETE` | `{prefix}/mail-templates/{id}`     | Delete |
| `POST`   | `{prefix}/mail-templates/preview`  | Render unsaved content (live preview) |
| `POST`   | `{prefix}/mail-templates/{id}/test`| Send a test |
