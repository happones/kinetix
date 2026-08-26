import { defineConfig } from "vitepress";
import { withMermaid } from "vitepress-plugin-mermaid";

// Project pages are served from https://happones.github.io/kinetix/.
// If you use a custom domain (CNAME) or a different repo name, change `base`.
export default withMermaid(
  defineConfig({
    title: "Kinetix",
    description:
      "A modern UI toolkit for Laravel + Vue 3 + Inertia.js — fluent PHP builders, real-time components, and full i18n.",
    base: "/kinetix/",
    lang: "en-US",
    cleanUrls: true,
    lastUpdated: true,
    head: [
      ["link", { rel: "icon", href: "/kinetix/icon.png" }],
      ["meta", { name: "theme-color", content: "#2bb89a" }],
      ["meta", { property: "og:title", content: "Kinetix" }],
      [
        "meta",
        {
          property: "og:description",
          content: "A modern UI toolkit for Laravel + Vue 3 + Inertia.js.",
        },
      ],
      ["meta", { property: "og:image", content: "/kinetix/logo.png" }],
    ],
    themeConfig: {
      logo: {
        light: "/logo.png",
        dark: "/logo_w.png",
        alt: "Kinetix",
      },
    siteTitle: false,
    nav: [
      { text: "Guide", link: "/installation" },
      {
        text: "Changelog",
        link: "https://github.com/happones/kinetix/blob/main/CHANGELOG.md",
      },
      {
        text: "Packagist",
        link: "https://packagist.org/packages/happones/kinetix",
      },
    ],
    sidebar: [
      {
        text: "Introduction",
        items: [
          { text: "What is Kinetix?", link: "/" },
          { text: "Getting Started", link: "/installation" },
          { text: "With the Laravel starter kit", link: "/starter-kit" },
        ],
      },
      {
        text: "Resources & Data",
        collapsed: false,
        items: [
          { text: "Resources", link: "/resources" },
          { text: "Tables", link: "/tables" },
          { text: "Saved Views", link: "/saved-views" },
          { text: "Kanban", link: "/kanban" },
          { text: "Calendar", link: "/calendar" },
          { text: "Forms", link: "/forms" },
          { text: "Media Library", link: "/media-library" },
          { text: "Table Repeater", link: "/table-repeater" },
          { text: "Wizard", link: "/wizard" },
          { text: "Infolists", link: "/infolists" },
          { text: "Actions", link: "/actions" },
          { text: "Custom Pages", link: "/pages" },
          { text: "Breadcrumbs", link: "/breadcrumbs" },
        ],
      },
      {
        text: "Records & Relations",
        collapsed: false,
        items: [
          { text: "Import & Export", link: "/import-export" },
          { text: "Scheduled Reports", link: "/reports" },
          { text: "Reports Center", link: "/reports-center" },
          { text: "Relation Managers", link: "/relation-managers" },
          { text: "Comments", link: "/comments" },
          { text: "Tags", link: "/tags" },
        ],
      },
      {
        text: "Interface & UX",
        collapsed: false,
        items: [
          { text: "Widgets", link: "/widgets" },
          { text: "Period Filter", link: "/period-filter" },
          { text: "Timezone Picker", link: "/timezone-picker" },
          { text: "Notifications", link: "/notifications" },
          { text: "Mail Templates", link: "/mail-templates" },
          { text: "Notification Preferences", link: "/notification-preferences" },
          { text: "Announcements", link: "/announcements" },
          { text: "Cookie Consent", link: "/cookie-consent" },
          { text: "Spotlight", link: "/spotlight" },
          { text: "Keyboard Shortcuts", link: "/keyboard-shortcuts" },
          { text: "Onboarding", link: "/onboarding" },
          { text: "Product Tours", link: "/tours" },
          { text: "Help Center", link: "/help-center" },
          { text: "Icons", link: "/icons" },
          { text: "Accessibility", link: "/accessibility" },
          { text: "Language Switcher", link: "/locale" },
          { text: "Team Switcher", link: "/team-switcher" },
          { text: "Presence / Online", link: "/presence" },
        ],
      },
      {
        text: "Authorization",
        collapsed: false,
        items: [
          { text: "Roles & Permissions", link: "/permissions" },
          { text: "Membership & Provisioning", link: "/membership" },
          { text: "Impersonation", link: "/impersonation" },
        ],
      },
      {
        text: "Account & Security",
        collapsed: false,
        items: [
          { text: "Connected Accounts", link: "/connected-accounts" },
          { text: "Browser Sessions", link: "/sessions" },
          { text: "Developer Tokens", link: "/tokens" },
          { text: "GDPR self-service", link: "/gdpr" },
          { text: "Confidential Fields", link: "/confidential" },
        ],
      },
      {
        text: "Billing",
        collapsed: false,
        items: [{ text: "Billing", link: "/billing" }],
      },
      {
        text: "Platform",
        collapsed: false,
        items: [
          { text: "Settings", link: "/settings" },
          { text: "Activity Log", link: "/activity" },
          { text: "Feature Flags", link: "/feature-flags" },
          { text: "Webhooks", link: "/webhooks" },
          { text: "Integration Logs", link: "/integration-logs" },
          { text: "PDF Templates", link: "/pdf-templates" },
          { text: "Queue Health", link: "/queue" },
          { text: "System Health", link: "/health" },
        ],
      },
    ],
    socialLinks: [
      { icon: "github", link: "https://github.com/happones/kinetix" },
    ],
    search: { provider: "local" },
    editLink: {
      pattern: "https://github.com/happones/kinetix/edit/main/docs/:path",
      text: "Edit this page on GitHub",
    },
    footer: {
      message: "Released under the MIT License.",
      copyright: "Copyright © 2026 happones",
    },
  },
}));
