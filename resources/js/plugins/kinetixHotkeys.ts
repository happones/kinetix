import type { App, DirectiveBinding } from "vue";
import {
  addHotkey,
  ensureHotkeysListening,
  removeHotkey,
} from "@/composables/useKinetixHotkeys";

/**
 * `v-kinetix-hotkey` — bind a key/sequence to any element. Register once with
 * `app.use(KinetixHotkeys)`, then:
 *
 *   <button v-kinetix-hotkey="'c'" @click="create">New</button>          // triggers the element's click
 *   <button v-kinetix-hotkey="{ keys: 'g i', handler: goInbox }">…</button>
 *   <button v-kinetix-hotkey:Save="'mod+s'" @click="save">Save</button>  // arg = label for the help overlay
 *
 * String value → the element is `click()`ed on match; object value → its handler
 * runs (defaulting to a click). Usable in any component, Kinetix or not.
 */
type HotkeyValue = string | { keys: string; handler?: () => void };

const ID_KEY = "__kinetixHotkeyId";

function resolve(
  el: HTMLElement,
  binding: DirectiveBinding<HotkeyValue>,
): { keys: string; handler: () => void; label?: string } {
  const value = binding.value;
  const label = typeof binding.arg === "string" ? binding.arg : undefined;

  if (typeof value === "string") {
    return { keys: value, handler: () => el.click(), label };
  }

  return {
    keys: value.keys,
    handler: value.handler ?? (() => el.click()),
    label,
  };
}

export const KinetixHotkeys = {
  install(app: App): void {
    ensureHotkeysListening();

    app.directive<HTMLElement, HotkeyValue>("kinetix-hotkey", {
      mounted(el, binding) {
        const { keys, handler, label } = resolve(el, binding);
        (el as HTMLElement & { [ID_KEY]?: string })[ID_KEY] = addHotkey({
          keys,
          handler,
          label,
        });
      },
      unmounted(el) {
        const id = (el as HTMLElement & { [ID_KEY]?: string })[ID_KEY];
        if (id) {
          removeHotkey(id);
        }
      },
    });
  },
};
