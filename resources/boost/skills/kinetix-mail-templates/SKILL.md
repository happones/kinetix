---
name: kinetix-mail-templates
description: "Editable email templates (subject + Markdown/HTML body with {{ variable }} placeholders), managed in a UI and stored in DB. Your app sends via KinetixMail::send(). Activates when building manageable/configurable email templates."
license: MIT
metadata:
  author: happones
---

# Kinetix Mail Templates

## When to Apply

Activate this skill when:
- Building editable/configurable email templates (admins edit copy without code).
- Rendering or sending templated emails with variable placeholders.

## Documentation

For full details, reference `docs/mail-templates.md` (published at https://happones.github.io/kinetix/mail-templates).

## Installation

```bash
php artisan vendor:publish --tag=kinetix-mail-templates-migrations
php artisan migrate
```

```php
'mail_templates' => ['enabled' => env('KINETIX_MAIL_TEMPLATES_ENABLED', false)],
Gate::define('viewKinetixMail', fn ($user) => $user->isAdmin()); // prod
```

## Manager

```vue
<KinetixMailTemplates />
```

List + editor (subject + Markdown/HTML body with `{{ variable }}`), live preview
with sample values, and send-test. Gated by `viewKinetixMail`.

## Sending from your app

```php
use Happones\Kinetix\Mail\KinetixMail;

KinetixMail::send($user->email, 'welcome', ['name' => $user->name]);
KinetixMail::render('welcome', [...]); // {subject, html} without sending
```

`send()` returns false if the template is missing/disabled. Variable values are
HTML-escaped in Markdown bodies; HTML templates render as-is; the subject is
plain text.

> i18n note: never put literal `{{ }}` or `@` in vue-i18n translation strings —
> both are reserved (nested placeholder / linked message) and crash compilation.
