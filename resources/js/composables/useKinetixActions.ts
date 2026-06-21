import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import type { KinetixAction } from "@/types";

/**
 * Execute a Kinetix action's behaviour: fire a browser event, perform an
 * Inertia visit, open a new tab, or navigate (internal SPA visit vs external).
 *
 * Shared by tables, page action bars, and any component that renders actions.
 */
export function executeAction(
  action: KinetixAction,
  extraData: Record<string, any> = {},
): void {
  if (action.dispatchEvent) {
    window.dispatchEvent(
      new CustomEvent(`kinetix:${action.dispatchEvent}`, {
        detail: { ...(action.dispatchData ?? {}), ...extraData },
        bubbles: true,
      }),
    );
  }

  if (action.inertiaVisit && action.url) {
    const { method = "get", ...visitOptions } = action.inertiaVisit;
    router.visit(action.url, {
      method: method as any,
      data: extraData,
      ...visitOptions,
    });

    return;
  }

  if (!action.url) {
    return;
  }

  if (action.shouldOpenInNewTab) {
    window.open(action.url, "_blank");

    return;
  }

  const isInternal =
    action.url.startsWith("/") || action.url.startsWith(window.location.origin);

  if (isInternal) {
    router.visit(action.url);

    return;
  }

  window.location.href = action.url;
}

/**
 * Reactive confirmation gate for actions. Actions flagged with
 * `requiresConfirmation` open a modal; everything else runs immediately.
 *
 * Returns the modal state plus `requestAction` (entry point on click),
 * `confirm`, and `cancel` handlers to wire into `KinetixConfirmModal`.
 */
export function useActionConfirmation() {
  const pendingAction = ref<KinetixAction | null>(null);
  const isConfirmOpen = ref(false);

  const requestAction = (action: KinetixAction): void => {
    if (action.requiresConfirmation) {
      pendingAction.value = action;
      isConfirmOpen.value = true;

      return;
    }

    executeAction(action);
  };

  const confirm = (): void => {
    if (pendingAction.value) {
      executeAction(pendingAction.value);
    }

    pendingAction.value = null;
  };

  const cancel = (): void => {
    pendingAction.value = null;
  };

  return { pendingAction, isConfirmOpen, requestAction, confirm, cancel };
}
