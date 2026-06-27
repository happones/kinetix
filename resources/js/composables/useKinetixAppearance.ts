import { computed, onMounted, ref } from "vue";

export type KinetixAppearance = "light" | "dark" | "system";
export type ResolvedAppearance = "light" | "dark";

/**
 * Light/dark/system theme, kept compatible with the official Laravel Vue
 * starter kit: it reads & writes the same `appearance` localStorage key and
 * cookie and toggles `html.dark`, so Kinetix's <KinetixModeToggle> stays in sync
 * with the starter kit's Appearance settings (no import of the host's file).
 */
const STORAGE_KEY = "appearance";

function prefersDark(): boolean {
  return (
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-color-scheme: dark)").matches
  );
}

export function applyAppearance(value: KinetixAppearance): void {
  if (typeof document === "undefined") {
    return;
  }
  const dark = value === "dark" || (value === "system" && prefersDark());
  document.documentElement.classList.toggle("dark", dark);
}

function setCookie(name: string, value: string, days = 365): void {
  if (typeof document === "undefined") {
    return;
  }
  document.cookie = `${name}=${value};path=/;max-age=${days * 86400};SameSite=Lax`;
}

function stored(): KinetixAppearance | null {
  if (typeof window === "undefined") {
    return null;
  }
  return localStorage.getItem(STORAGE_KEY) as KinetixAppearance | null;
}

const appearance = ref<KinetixAppearance>("system");

export function useKinetixAppearance() {
  onMounted(() => {
    const saved = stored();
    if (saved) {
      appearance.value = saved;
    }
  });

  const resolved = computed<ResolvedAppearance>(() =>
    appearance.value === "system"
      ? prefersDark()
        ? "dark"
        : "light"
      : appearance.value,
  );

  function setAppearance(value: KinetixAppearance): void {
    appearance.value = value;
    try {
      localStorage.setItem(STORAGE_KEY, value);
    } catch {
      // ignore storage failures
    }
    setCookie(STORAGE_KEY, value);
    applyAppearance(value);
  }

  return { appearance, resolved, setAppearance };
}
