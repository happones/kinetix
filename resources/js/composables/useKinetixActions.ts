import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { toast } from "vue-sonner";
import { kinetixFetch } from "@/composables/useKinetixHttp";
import type { KinetixAction } from "@/types";

/**
 * Execute a Kinetix action's behaviour: fire a browser event, perform an
 * Inertia visit, fire a background HTTP request, open a new tab, or navigate.
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

  // Background HTTP request (plain XHR, no Inertia) — e.g. ExportAction. The
  // endpoint may return JSON; show a toast instead of navigating.
  if (action.httpRequest && action.url) {
    const { method = "post", toast: toastMessage } = action.httpRequest;

    kinetixFetch(action.url, { method: String(method), body: extraData ?? {} })
      .then(() => {
        if (toastMessage) {
          toast.success(toastMessage as string);
        }
      })
      .catch(() => toast.error("Request failed."));

    return;
  }

  if (!action.url) {
    return;
  }

  // Open the file in the global preview lightbox instead of navigating.
  if (action.isPreview) {
    window.dispatchEvent(
      new CustomEvent("kinetix:preview", {
        detail: {
          url: action.url,
          type: action.previewType ?? "auto",
          label: action.label,
        },
      }),
    );

    return;
  }

  // Force a file download (attachment) instead of navigating.
  if (action.isDownload) {
    const link = document.createElement("a");
    link.href = action.url;
    link.download = "";
    link.rel = "noopener";
    document.body.appendChild(link);
    link.click();
    link.remove();

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
