<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute } from "vitepress";
import type { Zoom } from "medium-zoom";

/**
 * Zoom for the docs' visuals, mounted once in the layout:
 *
 * - Mermaid diagrams open in a full-screen viewer with pan and zoom
 *   (svg-pan-zoom): wheel / pinch / + − 0 keys to zoom, drag / one finger /
 *   arrow keys to pan, Esc to close. Each diagram becomes a keyboard-operable
 *   button; focus returns to it on close.
 * - Content images (the component screenshots) enlarge in place with
 *   medium-zoom, from a click or from Enter / Space.
 *
 * Both libraries load on demand: they touch `window` and would break the SSR
 * build if imported at module level, and most pages never open a viewer.
 *
 * The Mermaid plugin re-renders every diagram whenever an attribute on
 * <html> changes, so the scroll lock goes on <body>, never on <html>.
 */
const route = useRoute();

const open = ref(false);
const stage = ref<HTMLElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

type PanZoom = {
  zoomIn(): void;
  zoomOut(): void;
  zoomAtPointBy(scale: number, point: { x: number; y: number }): void;
  panBy(point: { x: number; y: number }): void;
  fit(): void;
  center(): void;
  resize(): void;
  destroy(): void;
};

let panZoom: PanZoom | null = null;
let returnFocus: HTMLElement | null = null;
let imageZoom: Zoom | null = null;
let observer: MutationObserver | null = null;
let scheduled = 0;

const DIAGRAM = ".vp-doc .mermaid";
const IMAGE = ".vp-doc img:not(.no-zoom)";

/**
 * Turn rendered diagrams and content images into zoom triggers. Diagrams
 * render asynchronously (and again on every theme switch), so this runs on
 * DOM changes, coalesced into one pass per frame.
 */
function decorate(): void {
  scheduled = 0;

  document.querySelectorAll<HTMLElement>(DIAGRAM).forEach((diagram) => {
    if (diagram.dataset.kxZoom !== undefined || !diagram.querySelector("svg")) {
      return;
    }

    diagram.dataset.kxZoom = "";
    diagram.tabIndex = 0;
    diagram.setAttribute("role", "button");
    diagram.setAttribute("aria-label", "Open the diagram full screen (zoom and pan)");
  });

  const images = Array.from(
    document.querySelectorAll<HTMLImageElement>(IMAGE),
  ).filter((image) => !image.closest("a") && image.dataset.kxZoom === undefined);

  images.forEach((image) => {
    image.dataset.kxZoom = "";
    image.tabIndex = 0;
    image.setAttribute("role", "button");
    image.setAttribute("aria-label", `Enlarge: ${image.alt}`.trim());
  });

  if (images.length > 0) {
    void attachImages(images);
  }
}

function schedule(): void {
  if (scheduled === 0) {
    scheduled = requestAnimationFrame(decorate);
  }
}

async function attachImages(images: HTMLImageElement[]): Promise<void> {
  if (imageZoom === null) {
    const { default: mediumZoom } = await import("medium-zoom");

    imageZoom = mediumZoom({ background: "var(--vp-c-bg)", margin: 24 });
    // Back to the image it came from, for keyboard users.
    imageZoom.on("closed", (event) => (event.target as HTMLElement).focus());
  }

  imageZoom.attach(...images);
}

/** One finger pans, two fingers pinch — svg-pan-zoom alone has no pinch. */
function touchHandler() {
  let last: { x: number; y: number } | null = null;
  let lastDistance = 0;
  let svg: SVGSVGElement | null = null;
  let instance: PanZoom | null = null;

  const point = (touch: Touch) => {
    const box = svg!.getBoundingClientRect();

    return { x: touch.clientX - box.left, y: touch.clientY - box.top };
  };

  const onStart = (event: TouchEvent) => {
    if (event.touches.length === 1) {
      last = point(event.touches[0]);
    } else if (event.touches.length === 2) {
      const [a, b] = [point(event.touches[0]), point(event.touches[1])];
      last = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      lastDistance = Math.hypot(a.x - b.x, a.y - b.y);
    }
  };

  const onMove = (event: TouchEvent) => {
    if (!instance || !last) {
      return;
    }

    event.preventDefault();

    if (event.touches.length === 1) {
      const p = point(event.touches[0]);
      instance.panBy({ x: p.x - last.x, y: p.y - last.y });
      last = p;
    } else if (event.touches.length === 2) {
      const [a, b] = [point(event.touches[0]), point(event.touches[1])];
      const middle = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      const distance = Math.hypot(a.x - b.x, a.y - b.y);

      if (lastDistance > 0) {
        instance.zoomAtPointBy(distance / lastDistance, middle);
      }

      instance.panBy({ x: middle.x - last.x, y: middle.y - last.y });
      last = middle;
      lastDistance = distance;
    }
  };

  const onEnd = (event: TouchEvent) => {
    last = event.touches.length > 0 ? point(event.touches[0]) : null;
    lastDistance = 0;
  };

  return {
    haltEventListeners: ["touchstart", "touchend", "touchmove", "touchleave", "touchcancel"],
    init(options: { svgElement: SVGSVGElement; instance: PanZoom }) {
      svg = options.svgElement;
      instance = options.instance;
      svg.addEventListener("touchstart", onStart, { passive: true });
      svg.addEventListener("touchmove", onMove, { passive: false });
      svg.addEventListener("touchend", onEnd);
      svg.addEventListener("touchcancel", onEnd);
    },
    destroy() {
      svg?.removeEventListener("touchstart", onStart);
      svg?.removeEventListener("touchmove", onMove);
      svg?.removeEventListener("touchend", onEnd);
      svg?.removeEventListener("touchcancel", onEnd);
      svg = null;
      instance = null;
    },
  };
}

/**
 * A copy of the diagram for the viewer. The id is renamed — Mermaid scopes
 * its <style> rules to `#<id>` — so the page never holds two elements with
 * the same id.
 */
function copyOf(svg: SVGSVGElement): SVGSVGElement {
  const copy = svg.cloneNode(true) as SVGSVGElement;
  const id = svg.id;

  if (id) {
    copy.id = `${id}-zoom`;
    copy.querySelectorAll("style").forEach((style) => {
      style.textContent = (style.textContent ?? "").split(`#${id}`).join(`#${copy.id}`);
    });
  }

  copy.removeAttribute("style");
  copy.setAttribute("width", "100%");
  copy.setAttribute("height", "100%");

  return copy;
}

async function openDiagram(diagram: HTMLElement): Promise<void> {
  const svg = diagram.querySelector("svg");

  if (!svg || open.value) {
    return;
  }

  returnFocus = diagram;
  open.value = true;
  document.body.style.overflow = "hidden";

  await nextTick();

  const copy = copyOf(svg);
  stage.value!.replaceChildren(copy);

  const { default: svgPanZoom } = await import("svg-pan-zoom");

  panZoom = (svgPanZoom as unknown as (svg: SVGSVGElement, options: object) => PanZoom)(copy, {
    zoomEnabled: true,
    panEnabled: true,
    controlIconsEnabled: false,
    dblClickZoomEnabled: true,
    mouseWheelZoomEnabled: true,
    preventMouseEventsDefault: true,
    zoomScaleSensitivity: 0.3,
    minZoom: 0.3,
    maxZoom: 20,
    fit: true,
    center: true,
    customEventsHandler: touchHandler(),
  });

  closeButton.value?.focus();
}

function close(): void {
  if (!open.value) {
    return;
  }

  panZoom?.destroy();
  panZoom = null;
  stage.value?.replaceChildren();
  open.value = false;
  document.body.style.overflow = "";
  returnFocus?.focus();
  returnFocus = null;
}

function reset(): void {
  panZoom?.resize();
  panZoom?.fit();
  panZoom?.center();
}

const PAN_STEP = 60;

function onDialogKeydown(event: KeyboardEvent): void {
  const actions: Record<string, () => void> = {
    Escape: close,
    "+": () => panZoom?.zoomIn(),
    "=": () => panZoom?.zoomIn(),
    "-": () => panZoom?.zoomOut(),
    "0": reset,
    ArrowLeft: () => panZoom?.panBy({ x: PAN_STEP, y: 0 }),
    ArrowRight: () => panZoom?.panBy({ x: -PAN_STEP, y: 0 }),
    ArrowUp: () => panZoom?.panBy({ x: 0, y: PAN_STEP }),
    ArrowDown: () => panZoom?.panBy({ x: 0, y: -PAN_STEP }),
  };

  if (event.key === "Tab") {
    // Keep focus inside the viewer: its only stops are the toolbar buttons.
    const stops = Array.from(dialog.value?.querySelectorAll<HTMLElement>("button") ?? []);
    const index = stops.indexOf(document.activeElement as HTMLElement);
    const next = event.shiftKey ? index - 1 : index + 1;

    event.preventDefault();
    stops[(next + stops.length) % stops.length]?.focus();

    return;
  }

  const action = actions[event.key];

  if (action) {
    event.preventDefault();
    action();
  }
}

/** Diagrams open from a click, or from Enter / Space when focused. */
function onDocumentClick(event: MouseEvent): void {
  const diagram = (event.target as Element | null)?.closest<HTMLElement>(`${DIAGRAM}[data-kx-zoom]`);

  if (diagram) {
    void openDiagram(diagram);
  }
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key !== "Enter" && event.key !== " ") {
    return;
  }

  const target = event.target as HTMLElement | null;

  if (target?.matches(`${DIAGRAM}[data-kx-zoom]`)) {
    event.preventDefault();
    void openDiagram(target);
  } else if (target?.matches("img[data-kx-zoom]") && imageZoom) {
    event.preventDefault();
    void imageZoom.open({ target });
  }
}

function onResize(): void {
  if (open.value) {
    reset();
  }
}

onMounted(() => {
  observer = new MutationObserver(schedule);
  observer.observe(document.body, { childList: true, subtree: true });
  document.addEventListener("click", onDocumentClick);
  document.addEventListener("keydown", onDocumentKeydown);
  window.addEventListener("resize", onResize);
  schedule();
});

onBeforeUnmount(() => {
  observer?.disconnect();
  cancelAnimationFrame(scheduled);
  document.removeEventListener("click", onDocumentClick);
  document.removeEventListener("keydown", onDocumentKeydown);
  window.removeEventListener("resize", onResize);
  close();
  imageZoom?.detach();
});

// A new page: leave the viewer, and forget the images of the old one.
watch(
  () => route.path,
  () => {
    close();
    imageZoom?.detach();
    schedule();
  },
);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="dialog"
      class="kx-diagram-zoom"
      role="dialog"
      aria-modal="true"
      aria-label="Diagram"
      aria-describedby="kx-diagram-zoom-hint"
      @keydown="onDialogKeydown"
    >
      <div class="kx-diagram-zoom__toolbar" role="toolbar" aria-label="Diagram controls">
        <button type="button" aria-label="Zoom in" @click="panZoom?.zoomIn()">+</button>
        <button type="button" aria-label="Zoom out" @click="panZoom?.zoomOut()">−</button>
        <button type="button" aria-label="Fit to screen" @click="reset">Fit</button>
        <p id="kx-diagram-zoom-hint" class="kx-diagram-zoom__hint">
          Scroll or pinch to zoom · drag to move · arrows, + − 0 · Esc to close
        </p>
        <button ref="closeButton" type="button" aria-label="Close" @click="close">✕</button>
      </div>
      <div ref="stage" class="kx-diagram-zoom__stage" />
    </div>
  </Teleport>
</template>
