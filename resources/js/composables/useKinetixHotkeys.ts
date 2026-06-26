import { onScopeDispose, reactive } from "vue";

/**
 * App-wide keyboard shortcuts — conflict-safe by design:
 *  - single keys (`c`, `/`) and Gmail-style sequences (`g i`) only fire when the
 *    user is NOT typing in an input/textarea/select/contenteditable;
 *  - modifier combos use `mod` (⌘ on macOS, Ctrl elsewhere) and still fire while
 *    typing (e.g. `mod+s`);
 *  - `preventDefault` runs only on a match, so normal typing is untouched.
 *
 * Register declaratively with the `v-kinetix-hotkey` directive, or imperatively
 * with `useKinetixHotkeys().register(...)`. Per-user overrides (persisted via the
 * Settings module) feed in through `setOverrides()`.
 */
export interface KinetixHotkey {
  id: string;
  keys: string;
  label?: string;
  handler: () => void;
}

const bindings = reactive<Map<string, KinetixHotkey>>(new Map());
const overrides = reactive<Record<string, string>>({});
const sequence: { key: string; at: number }[] = [];

const SEQUENCE_WINDOW = 1000;
let counter = 0;
let listening = false;

export function isMac(): boolean {
  if (typeof navigator === "undefined") {
    return false;
  }
  return /mac|iphone|ipad/i.test(
    navigator.platform || navigator.userAgent || "",
  );
}

export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  const tag = target.tagName.toLowerCase();
  return (
    tag === "input" ||
    tag === "textarea" ||
    tag === "select" ||
    target.isContentEditable
  );
}

export function normalizeKey(key: string): string {
  return key === " " ? "space" : key.toLowerCase();
}

/** Does the event satisfy a single step like `c`, `mod+e`, `shift+?`. */
export function eventMatchesStep(event: KeyboardEvent, step: string): boolean {
  const tokens = step.toLowerCase().split("+");
  const key = tokens[tokens.length - 1];
  const wantMod = tokens.includes("mod");
  const wantAlt = tokens.includes("alt");
  const wantShift = tokens.includes("shift");
  const mod = event.metaKey || event.ctrlKey;

  if (wantMod !== mod) {
    return false;
  }
  if (wantAlt !== event.altKey) {
    return false;
  }
  // Shift, when required, must be held; otherwise we don't forbid it (it's part
  // of producing symbol keys like `?`).
  if (wantShift && !event.shiftKey) {
    return false;
  }

  return normalizeKey(event.key) === key;
}

/** Does the recent plain-key buffer end with the given sequence of steps. */
export function sequenceMatches(buffer: string[], steps: string[]): boolean {
  if (steps.length === 0 || buffer.length < steps.length) {
    return false;
  }
  const tail = buffer.slice(-steps.length);

  return steps.every((step, i) => tail[i] === step.toLowerCase());
}

function effectiveKeys(binding: KinetixHotkey): string {
  return overrides[binding.id] ?? binding.keys;
}

function onKeydown(event: KeyboardEvent): void {
  const typing = isTypingTarget(event.target);
  const hasMod = event.metaKey || event.ctrlKey || event.altKey;
  const plainKey = hasMod ? null : normalizeKey(event.key);

  if (plainKey !== null && !typing) {
    const now = Date.now();
    while (sequence.length > 0 && now - sequence[0].at > SEQUENCE_WINDOW) {
      sequence.shift();
    }
    sequence.push({ key: plainKey, at: now });
  }

  for (const binding of bindings.values()) {
    const steps = effectiveKeys(binding).split(/\s+/).filter(Boolean);

    if (steps.length === 1) {
      const isPlain = !/\b(mod|alt)\b/.test(steps[0]);
      if (typing && isPlain) {
        continue;
      }
      if (eventMatchesStep(event, steps[0])) {
        event.preventDefault();
        sequence.length = 0;
        binding.handler();
        return;
      }
      continue;
    }

    // Multi-step sequences are plain keys only and never fire while typing.
    if (
      !typing &&
      plainKey !== null &&
      sequenceMatches(
        sequence.map((entry) => entry.key),
        steps,
      )
    ) {
      event.preventDefault();
      sequence.length = 0;
      binding.handler();
      return;
    }
  }
}

/** Install the single global listener (idempotent). */
export function ensureHotkeysListening(): void {
  if (listening || typeof window === "undefined") {
    return;
  }
  window.addEventListener("keydown", onKeydown);
  listening = true;
}

export function addHotkey(
  binding: Omit<KinetixHotkey, "id"> & { id?: string },
): string {
  ensureHotkeysListening();
  const id = binding.id ?? `hk_${++counter}`;
  bindings.set(id, {
    id,
    keys: binding.keys,
    handler: binding.handler,
    label: binding.label,
  });

  return id;
}

export function removeHotkey(id: string): void {
  bindings.delete(id);
}

export function setHotkeyOverrides(map: Record<string, string>): void {
  Object.assign(overrides, map);
}

/** The registered, labelled shortcuts (effective keys), for the help overlay. */
export function listHotkeys(): KinetixHotkey[] {
  return [...bindings.values()]
    .filter((binding) => binding.label)
    .map((binding) => ({ ...binding, keys: effectiveKeys(binding) }));
}

export function useKinetixHotkeys() {
  function register(
    binding: Omit<KinetixHotkey, "id"> & { id?: string },
  ): string {
    const id = addHotkey(binding);
    onScopeDispose(() => removeHotkey(id));

    return id;
  }

  return {
    register,
    unregister: removeHotkey,
    setOverrides: setHotkeyOverrides,
    shortcuts: listHotkeys,
    isMac,
  };
}
