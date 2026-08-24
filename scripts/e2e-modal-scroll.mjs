import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { createServer } from "vite";
import { chromium } from "playwright";

/**
 * Browser (Chromium) end-to-end check of the dialog shells with a form that is
 * TALLER than the viewport, driven against the gallery's `modal-scroll`
 * specimen.
 *
 *   npm run test:e2e:modals
 *
 * The regression it locks down: the shell used to have no bound of its own, so
 * `scrollBody` was the only thing between a long form and content stranded off
 * screen — the panel grew past the viewport and its top (title, first fields)
 * and bottom (footer actions) were unreachable, with nothing to scroll.
 *
 * Asserts, per shell: the panel never overflows off screen; every field and
 * the footer actions are reachable; `scrollBody` keeps header/footer pinned
 * with the body scrolling in a shadcn ScrollArea whose overlay bar sits in the
 * panel's padding gutter instead of on top of the fields.
 */
const root = fileURLToPath(new URL("..", import.meta.url));

const server = await createServer({
  configFile: fileURLToPath(new URL("../vite.gallery.config.ts", import.meta.url)),
  root: root + "/gallery",
  logLevel: "warn",
});
await server.listen();
const base = `http://localhost:${server.config.server.port}`;

// Deliberately short: the form must not fit, on purpose.
const viewport = { width: 1024, height: 600 };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport });

const failures = [];
page.on("pageerror", (e) => failures.push(`pageerror: ${e.message}`));
page.on("console", (m) => {
  if (m.type() === "error") failures.push(`console: ${m.text()}`);
});

/**
 * The v4 shell opens with a 200ms fade+zoom, so geometry read too early is the
 * mid-animation transform, not the layout. Let it settle before measuring.
 */
const settle = () => page.waitForTimeout(350);

const box = async (locator) => {
  const b = await locator.boundingBox();
  assert.ok(b, "element has a box");
  return b;
};

/** The dialog's own scroll container (the fixed wrapper). */
const wrapperMetrics = () =>
  page.evaluate(() => {
    const el = document.querySelector('[role="dialog"]');
    return {
      scrollHeight: el.scrollHeight,
      clientHeight: el.clientHeight,
      scrollTop: el.scrollTop,
    };
  });

let step = "";
try {
  step = "load specimen";
  await page.goto(`${base}/?s=modal-scroll`, { waitUntil: "networkidle" });

  // ─── Default shell: no scrollBody, panel outgrows the viewport ───────────
  step = "default shell opens";
  await page.getByTestId("open-default").click();
  const dialog = page.locator('[role="dialog"]');
  await dialog.waitFor({ state: "visible" });
  await settle();
  const panel = dialog.locator('[tabindex="-1"]');

  step = "default shell: the panel is taller than the viewport (fixture sanity)";
  const panelBox = await box(panel);
  assert.ok(
    panelBox.height > viewport.height,
    `fixture must overflow the viewport (panel ${panelBox.height}px vs ${viewport.height}px)`,
  );

  step = "default shell: nothing starts off screen above the fold";
  assert.ok(
    panelBox.y >= 0,
    `panel top must be on screen, got y=${panelBox.y} (the old bug: negative, unreachable)`,
  );
  assert.ok(
    (await box(dialog.getByText("Long form — default shell"))).y >= 0,
    "the title is on screen",
  );
  const firstField = await box(page.getByTestId("default-field-1"));
  assert.ok(firstField.y >= 0, "the first field is on screen");

  step = "default shell: the WRAPPER is the scroll container";
  const wrapper = await wrapperMetrics();
  assert.ok(
    wrapper.scrollHeight > wrapper.clientHeight,
    "the fixed wrapper scrolls the overflowing panel",
  );
  assert.equal(wrapper.scrollTop, 0, "it opens at the top of the panel");

  step = "default shell: the footer actions are reachable by scrolling";
  await page.evaluate(() => {
    const el = document.querySelector('[role="dialog"]');
    el.scrollTop = el.scrollHeight;
  });
  const save = await box(page.getByTestId("default-save"));
  assert.ok(
    save.y >= 0 && save.y + save.height <= viewport.height,
    `Save must land inside the viewport, got y=${save.y}`,
  );
  const lastField = await box(page.getByTestId("default-field-16"));
  assert.ok(
    lastField.y + lastField.height <= viewport.height,
    "the last field is reachable",
  );

  step = "default shell: focusing a field keeps it on screen";
  await page.getByTestId("default-field-1").focus();
  const refocused = await box(page.getByTestId("default-field-1"));
  assert.ok(
    refocused.y >= 0 && refocused.y + refocused.height <= viewport.height,
    "focus scrolls the field into view",
  );

  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  // ─── scrollBody: bounded panel, pinned header/footer, ScrollArea body ────
  step = "scrollBody shell opens";
  await page.getByTestId("open-pinned").click();
  await dialog.waitFor({ state: "visible" });
  await settle();

  step = "scrollBody: the panel fits the viewport";
  const pinnedBox = await box(panel);
  assert.ok(
    pinnedBox.y >= 0 && pinnedBox.height <= viewport.height,
    `panel must be bounded, got y=${pinnedBox.y} h=${pinnedBox.height}`,
  );

  step = "scrollBody: the body scrolls in a shadcn ScrollArea";
  const viewportEl = dialog.locator('[data-slot="scroll-area-viewport"]');
  await viewportEl.waitFor({ state: "visible" });
  const scroll = await page.evaluate(() => {
    const el = document.querySelector('[data-slot="scroll-area-viewport"]');
    return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight };
  });
  assert.ok(
    scroll.scrollHeight > scroll.clientHeight,
    "the ScrollArea viewport is the scroller",
  );
  // `type="auto"` mounts the bar once reka MEASURES the overflow, so wait for
  // it instead of racing the resize observer.
  const scrollbar = dialog.locator('[data-slot="scroll-area-scrollbar"]');
  await scrollbar.first().waitFor({ state: "visible" });

  step = "scrollBody: the footer is visible without scrolling";
  const pinnedSave = await box(page.getByTestId("pinned-save"));
  assert.ok(
    pinnedSave.y + pinnedSave.height <= viewport.height,
    "Save is on screen the moment the modal opens",
  );

  step = "scrollBody: the header stays pinned while the body scrolls";
  const headerBefore = await box(dialog.getByText("Long form — scrollBody"));
  const footerBefore = pinnedSave;
  await page.evaluate(() => {
    const el = document.querySelector('[data-slot="scroll-area-viewport"]');
    el.scrollTop = el.scrollHeight;
  });
  await page.waitForTimeout(50);
  const headerAfter = await box(dialog.getByText("Long form — scrollBody"));
  const footerAfter = await box(page.getByTestId("pinned-save"));
  assert.ok(
    Math.abs(headerAfter.y - headerBefore.y) < 1,
    `the header does not move (${headerBefore.y} → ${headerAfter.y})`,
  );
  assert.ok(
    Math.abs(footerAfter.y - footerBefore.y) < 1,
    `the footer does not move (${footerBefore.y} → ${footerAfter.y})`,
  );

  step = "scrollBody: the last field is reachable";
  const pinnedLast = await box(page.getByTestId("pinned-field-16"));
  assert.ok(
    pinnedLast.y >= headerAfter.y &&
      pinnedLast.y + pinnedLast.height <= viewport.height,
    "the last field lands inside the panel",
  );

  step = "scrollBody: the overlay scrollbar clears the fields";
  const bar = await box(scrollbar.first());
  const field = await box(page.getByTestId("pinned-field-16"));
  assert.ok(
    bar.x >= field.x + field.width - 1,
    `the bar must sit in the padding gutter, not over the inputs (bar x=${bar.x}, field right=${field.x + field.width})`,
  );

  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  // ─── Sheet: same contract, edge-anchored panel ───────────────────────────
  step = "sheet opens with a pinned footer";
  await page.getByTestId("open-sheet").click();
  await dialog.waitFor({ state: "visible" });
  await settle();
  const sheetSave = await box(page.getByTestId("sheet-save"));
  assert.ok(
    sheetSave.y + sheetSave.height <= viewport.height,
    "the sheet footer is on screen without scrolling",
  );

  step = "sheet: the body scrolls in a ScrollArea and reaches the last field";
  await page.evaluate(() => {
    const el = document.querySelector('[data-slot="scroll-area-viewport"]');
    el.scrollTop = el.scrollHeight;
  });
  await page.waitForTimeout(50);
  const sheetLast = await box(page.getByTestId("sheet-field-16"));
  assert.ok(
    sheetLast.y + sheetLast.height <= viewport.height,
    "the last sheet field is reachable",
  );
  const sheetSaveAfter = await box(page.getByTestId("sheet-save"));
  assert.ok(
    Math.abs(sheetSaveAfter.y - sheetSave.y) < 1,
    `the sheet footer stays pinned (${sheetSave.y} → ${sheetSaveAfter.y})`,
  );

  assert.deepEqual(failures, [], "no console errors or page exceptions");
  console.log("modal-scroll e2e: OK");
} catch (error) {
  console.error(`modal-scroll e2e FAILED at step: ${step}`);
  console.error(error);
  process.exitCode = 1;
} finally {
  await browser.close();
  await server.close();
}
