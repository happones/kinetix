import { defineConfig } from "vitepress";
import { withMermaid } from "vitepress-plugin-mermaid";

// Project pages are served from https://happones.github.io/kinetix/.
// If you use a custom domain (CNAME) or a different repo name, change `base`.
export default withMermaid(
  defineConfig({
    title: "Kinetix",
    description:
      "A modern UI toolkit for Laravel + Vue 3 + Inertia.js — Filament-style fluent PHP APIs, real-time components, and full i18n.",
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
        ],
      },
      {
        text: "Resources & Data",
        collapsed: false,
        items: [
          { text: "Resources", link: "/resources" },
          { text: "Tables", link: "/tables" },
          { text: "Forms", link: "/forms" },
          { text: "Infolists", link: "/infolists" },
          { text: "Actions", link: "/actions" },
        ],
      },
      {
        text: "Records & Relations",
        collapsed: false,
        items: [
          { text: "Import & Export", link: "/import-export" },
          { text: "Relation Managers", link: "/relation-managers" },
        ],
      },
      {
        text: "UI & Realtime",
        collapsed: false,
        items: [
          { text: "Widgets", link: "/widgets" },
          { text: "Notifications", link: "/notifications" },
        ],
      },
      {
        text: "Authorization",
        collapsed: false,
        items: [
          { text: "Roles & Permissions", link: "/permissions" },
          { text: "Membership & Provisioning", link: "/membership" },
        ],
      },
      {
        text: "Billing",
        collapsed: false,
        items: [{ text: "Billing", link: "/billing" }],
      },
      {
        text: "System",
        collapsed: false,
        items: [
          { text: "Settings", link: "/settings" },
          { text: "Activity Log", link: "/activity" },
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
