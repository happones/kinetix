import { createServer } from "vite";
import { chromium } from "playwright";
import { fileURLToPath } from "node:url";

// Mobile layout audit: renders every gallery specimen at phone widths (and one
// desktop width) with the frame as wide as the screen, and reports what pushes
// the page sideways or spills out of its box — the bugs a phone shows first —
// plus every pointer target below WCAG 2.2's 24×24px minimum (2.5.8, with its
// spacing, equivalent-label and inline exceptions; see measureTargets()).
//
//   npm run audit:mobile                 every specimen
//   npm run audit:mobile -- kanban tabs  specimens whose name contains one
//
// Content inside a horizontal scroller (a table, the kanban board, a tab
// strip) is clipped by it and doesn't count. Exits 1 on any finding.
const root = fileURLToPath(new URL("..", import.meta.url));

const VIEWPORTS = [320, 375, 430, 1280];
const FRAME_CSS =
  "body{margin:0} #app>div{display:block!important} #specimen{width:auto!important;padding:16px!important}";

// Specimens whose showcase is wider than a phone on purpose. Keep it SHORT,
// and say why.
const EXEMPT = {
  // Two fixed-width rails (expanded and collapsed) side by side, to compare.
  "onboarding-checklist-sidebar-collapsed": true,
};

const server = await createServer({
  configFile: fileURLToPath(new URL("../vite.gallery.config.ts", import.meta.url)),
  root: root + "/gallery",
  logLevel: "error",
});
await server.listen();
const { specimens: allSpecimens } = await server.ssrLoadModule("/specimens.ts");
const base = `http://localhost:${server.config.server.port}`;

const filters = process.argv.slice(2);
const specimens = allSpecimens.filter(
  (s) => !EXEMPT[s.name] && (!filters.length || filters.some((f) => s.name.includes(f))),
);

/** Runs in the page: what spills past the screen, and from where. */
function measure() {
  const vw = document.documentElement.clientWidth;
  const label = (el) => {
    const classes =
      typeof el.className === "string" ? el.className.trim().split(/\s+/).slice(0, 4).join(".") : "";
    const slot = el.getAttribute("data-slot");

    return `${el.tagName.toLowerCase()}${slot ? `[data-slot=${slot}]` : ""}${classes ? "." + classes : ""}`;
  };
  const invisible = (el) => {
    for (let node = el; node && node !== document.body; node = node.parentElement) {
      const style = getComputedStyle(node);

      if (
        style.visibility === "hidden" ||
        style.display === "none" ||
        style.opacity === "0" ||
        node.classList.contains("sr-only") ||
        node.hasAttribute("inert") ||
        node.getAttribute("aria-hidden") === "true"
      ) {
        return true;
      }
    }

    return false;
  };
  // A scroller (or any overflow-x other than visible) clips what it holds.
  const clipped = (el) => {
    for (let node = el.parentElement; node && node !== document.body; node = node.parentElement) {
      if (getComputedStyle(node).overflowX !== "visible") {
        return true;
      }
    }

    return false;
  };

  const spilling = new Set(
    [...document.body.querySelectorAll("*")].filter((el) => {
      if (el instanceof SVGElement && !(el instanceof SVGSVGElement)) {
        return false;
      }

      const box = el.getBoundingClientRect();

      return (
        box.width >= 1 &&
        box.height >= 1 &&
        (box.right > vw + 1 || box.left < -1) &&
        !invisible(el) &&
        !clipped(el)
      );
    }),
  );
  // The leaf-most culprits: nothing inside them spills on its own.
  const culprits = [...spilling]
    .filter((el) => ![...el.children].some((child) => spilling.has(child)))
    .slice(0, 3)
    .map((el) => {
      const box = el.getBoundingClientRect();

      return `${label(el)} +${Math.round(Math.max(box.right - vw, -box.left))}px`;
    });

  return {
    pageOverflow: document.documentElement.scrollWidth - vw,
    culprits,
  };
}

/**
 * Runs in the page: WCAG 2.2 target size (minimum, 2.5.8). A target passes
 * when a 24×24px square centred on it lands on it everywhere (its padding, an
 * enlarged hit area, a label wrapping it all count — the pointer is probed,
 * not the box), when a label for it is 24×24 on its own (an equivalent
 * target), or when a 24px circle centred on it touches no other target and no
 * other small target's circle (the spacing exception). Links inside a line of
 * text are exempt (the inline exception).
 */
function measureTargets() {
  const SELECTOR = [
    "a[href]",
    "button",
    "input:not([type=hidden])",
    "select",
    "textarea",
    "summary",
    ...["button", "checkbox", "switch", "tab", "menuitem", "menuitemcheckbox", "menuitemradio", "radio", "link", "option", "slider", "spinbutton"].map(
      (role) => `[role=${role}]`,
    ),
  ].join(",");
  const label = (el) => {
    const classes =
      typeof el.className === "string" ? el.className.trim().split(/\s+/).slice(0, 4).join(".") : "";
    const name = el.getAttribute("aria-label") || el.textContent.trim().slice(0, 16);

    return `${el.tagName.toLowerCase()}${classes ? "." + classes : ""}${name ? ` "${name}"` : ""}`;
  };
  const inactive = (el) => {
    if (el.matches(":disabled, [aria-disabled=true]")) {
      return true;
    }

    // A native input painted over by a custom control isn't what the user hits.
    if (el.tagName === "INPUT" && getComputedStyle(el).opacity === "0") {
      return true;
    }

    for (let node = el; node && node !== document.body; node = node.parentElement) {
      const style = getComputedStyle(node);

      if (
        style.visibility === "hidden" ||
        style.display === "none" ||
        style.pointerEvents === "none" ||
        node.classList.contains("sr-only") ||
        node.hasAttribute("inert")
      ) {
        return true;
      }
    }

    return false;
  };
  // The inline exception: a link or button flowing inside a sentence.
  const inline = (el) =>
    getComputedStyle(el).display === "inline" &&
    [...el.parentElement.childNodes].some((node) => node !== el && node.nodeType === Node.TEXT_NODE && node.textContent.trim());

  const targets = [...document.body.querySelectorAll(SELECTOR)].filter((el) => {
    const box = el.getBoundingClientRect();

    return box.width >= 1 && box.height >= 1 && !inactive(el);
  });
  const targetSet = new Set(targets);
  // Who a pointer landing on `node` activates: the nearest target above it,
  // or the control of a label it sits in.
  const owner = (node) => {
    for (; node && node !== document.body; node = node.parentElement) {
      if (targetSet.has(node)) {
        return node;
      }

      if (node.tagName === "LABEL" && node.control && targetSet.has(node.control)) {
        return node.control;
      }
    }

    return null;
  };
  const labelsOf = (el) => [...(el.labels ?? [])].filter((l) => l.getBoundingClientRect().width >= 1);
  const regions = (el) => [el, ...labelsOf(el)].map((node) => node.getBoundingClientRect());
  const centre = (box) => ({ x: box.left + box.width / 2, y: box.top + box.height / 2 });
  const circleHitsBox = (c, box) => {
    const dx = Math.max(box.left - c.x, 0, c.x - box.right);
    const dy = Math.max(box.top - c.y, 0, c.y - box.bottom);

    return dx * dx + dy * dy < 12 * 12;
  };
  const coversSquare = (el) => {
    el.scrollIntoView({ block: "center", inline: "center" });
    const c = centre(el.getBoundingClientRect());

    for (let dx = -11; dx <= 11; dx += 2) {
      for (let dy = -11; dy <= 11; dy += 2) {
        if (owner(document.elementFromPoint(c.x + dx, c.y + dy)) !== el) {
          return false;
        }
      }
    }

    return true;
  };

  const small = targets.filter((el) => {
    const box = el.getBoundingClientRect();

    return (box.width < 24 || box.height < 24) && !inline(el);
  });
  const undersized = small.filter(
    (el) =>
      !labelsOf(el).some((l) => {
        const box = l.getBoundingClientRect();

        return box.width >= 24 && box.height >= 24;
      }) && !coversSquare(el),
  );

  window.scrollTo(0, 0);
  const smallSet = new Set(undersized);
  const failing = undersized.filter((el) => {
    const c = centre(el.getBoundingClientRect());

    return targets.some((other) => {
      if (other === el || other.contains(el) || el.contains(other)) {
        return false;
      }

      if (smallSet.has(other)) {
        const o = centre(other.getBoundingClientRect());

        return Math.hypot(o.x - c.x, o.y - c.y) < 24 || regions(other).some((box) => circleHitsBox(c, box));
      }

      return regions(other).some((box) => circleHitsBox(c, box));
    });
  });

  return failing.slice(0, 3).map((el) => {
    const box = el.getBoundingClientRect();

    return `${label(el)} ${Math.round(box.width)}×${Math.round(box.height)}`;
  });
}

const browser = await chromium.launch();
const findings = [];

async function audit(specimen, width) {
  const page = await browser.newPage({ viewport: { width, height: 800 } });

  try {
    await page.goto(`${base}/?s=${specimen.name}&theme=light`, { waitUntil: "networkidle" });
    await page.addStyleTag({ content: FRAME_CSS });
    await page.locator("#specimen").waitFor({ state: "visible" });
    // Let a component that appears once its data lands settle.
    await page.waitForTimeout(500);

    if (specimen.openSelector) {
      await page.click(specimen.openSelector);
      await page.waitForTimeout(400);
    }

    const { pageOverflow, culprits } = await page.evaluate(measure);
    const targets = await page.evaluate(measureTargets);

    if (pageOverflow > 0 || culprits.length || targets.length) {
      findings.push({
        specimen: specimen.name,
        width,
        pageOverflow,
        culprits: culprits.join(" | "),
        targets: targets.join(" | "),
      });
    }
  } catch (error) {
    findings.push({ specimen: specimen.name, width, pageOverflow: NaN, culprits: error.message.split("\n")[0], targets: "" });
  } finally {
    await page.close();
  }
}

const jobs = specimens.flatMap((specimen) => VIEWPORTS.map((width) => [specimen, width]));
let next = 0;
const worker = async () => {
  while (next < jobs.length) {
    await audit(...jobs[next++]);
  }
};
await Promise.all(Array.from({ length: 6 }, worker));

await browser.close();
await server.close();

console.log(`${specimens.length} specimens × ${VIEWPORTS.join("/")}px`);

if (findings.length) {
  findings.sort((a, b) => a.specimen.localeCompare(b.specimen) || a.width - b.width);
  console.table(findings);
  process.exit(1);
}

console.log("✓ nothing spills past the screen, and every target is at least 24×24px");
