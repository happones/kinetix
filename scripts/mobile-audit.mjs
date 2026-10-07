import { createServer } from "vite";
import { chromium } from "playwright";
import { fileURLToPath } from "node:url";

// Mobile layout audit: renders every gallery specimen at phone widths (and one
// desktop width) with the frame as wide as the screen, and reports what pushes
// the page sideways or spills out of its box — the bugs a phone shows first.
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

    if (pageOverflow > 0 || culprits.length) {
      findings.push({ specimen: specimen.name, width, pageOverflow, culprits: culprits.join(" | ") });
    }
  } catch (error) {
    findings.push({ specimen: specimen.name, width, pageOverflow: NaN, culprits: error.message.split("\n")[0] });
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

console.log("✓ nothing spills past the screen");
