import type { App } from "vue";
import {
  applyKinetixAccessibility,
  KINETIX_A11Y_STORAGE,
} from "@/composables/useKinetixAccessibility";

/**
 * Injects the accessibility CSS and applies the user's preferences to <html>
 * as early as possible (before the app mounts) to avoid a flash. Source of
 * truth is the server-shared `kinetix_accessibility` prop on the initial Inertia
 * page; localStorage is a fast mirror for instant re-application.
 *
 *   import { KinetixAccessibility } from '@/plugins/kinetixAccessibility'
 *   app.use(KinetixAccessibility)
 */
const A11Y_CSS = `
.kx-reduce-motion *, .kx-reduce-motion *::before, .kx-reduce-motion *::after {
  animation-duration: .001ms !important; animation-iteration-count: 1 !important;
  transition-duration: .001ms !important; scroll-behavior: auto !important;
}
.kx-text-large { font-size: 112.5%; }
.kx-text-x-large { font-size: 125%; }
.kx-underline-links a { text-decoration: underline !important; }
.kx-enhanced-focus :focus-visible { outline: 3px solid currentColor; outline-offset: 2px; border-radius: .25rem; }
.kx-high-contrast :where(a, button, [role=button], input, select, textarea, [role=tab], [role=menuitem], [role=checkbox], [role=switch]) { outline: 1px solid currentColor; }
.kx-high-contrast a { text-decoration: underline; }
.kx-high-contrast :focus-visible { outline: 3px solid currentColor; outline-offset: 2px; }
`;

function injectStyles(): void {
  if (typeof document === "undefined" || document.getElementById("kinetix-a11y")) {
    return;
  }
  const style = document.createElement("style");
  style.id = "kinetix-a11y";
  style.textContent = A11Y_CSS;
  document.head.appendChild(style);
}

function applyFromBoot(): void {
  if (typeof document === "undefined") {
    return;
  }
  // 1. localStorage (instant, last-known).
  try {
    const stored = localStorage.getItem(KINETIX_A11Y_STORAGE);
    if (stored) {
      applyKinetixAccessibility(JSON.parse(stored));
    }
  } catch {
    // ignore
  }
  // 2. Server-shared prefs from the initial Inertia page (source of truth).
  try {
    const root = document.querySelector("[data-page]") as HTMLElement | null;
    const prefs = root?.dataset.page
      ? JSON.parse(root.dataset.page)?.props?.kinetix_accessibility
      : null;
    if (prefs) {
      applyKinetixAccessibility(prefs);
      localStorage.setItem(KINETIX_A11Y_STORAGE, JSON.stringify(prefs));
    }
  } catch {
    // ignore
  }
}

export const KinetixAccessibility = {
  install(_app: App): void {
    injectStyles();
    applyFromBoot();
  },
};
