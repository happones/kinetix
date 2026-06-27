/**
 * Screen-reader announcements via a shared ARIA live region. Use it to announce
 * async results that have no visible focus change (a saved toast, "12 results",
 * a row removed, etc.) so assistive tech users hear them.
 *
 *   const { announce } = useKinetixAnnounce()
 *   announce('Saved')                 // polite
 *   announce('Upload failed', true)   // assertive (interrupts)
 */
let region: HTMLElement | null = null;

function ensureRegion(): HTMLElement | null {
  if (typeof document === "undefined") {
    return null;
  }
  if (region && document.body.contains(region)) {
    return region;
  }
  region = document.createElement("div");
  region.id = "kinetix-live-region";
  region.setAttribute("aria-live", "polite");
  region.setAttribute("aria-atomic", "true");
  // Visually hidden but available to assistive tech (no Tailwind dependency).
  Object.assign(region.style, {
    position: "absolute",
    width: "1px",
    height: "1px",
    margin: "-1px",
    padding: "0",
    overflow: "hidden",
    clip: "rect(0 0 0 0)",
    whiteSpace: "nowrap",
    border: "0",
  });
  document.body.appendChild(region);
  return region;
}

export function useKinetixAnnounce() {
  function announce(message: string, assertive = false): void {
    const node = ensureRegion();
    if (!node) {
      return;
    }
    node.setAttribute("aria-live", assertive ? "assertive" : "polite");
    // Clearing then setting on the next frame guarantees repeated identical
    // messages are re-announced.
    node.textContent = "";
    requestAnimationFrame(() => {
      node.textContent = message;
    });
  }

  return { announce };
}
