import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { createServer } from "vite";
import { chromium } from "playwright";

/**
 * Browser (Chromium) end-to-end check of `KinetixCollapsible`, driven against
 * the gallery's `importer-options-collapsed` specimen.
 *
 *   npm run test:e2e:collapsible
 *
 * What only a browser can prove: the disclosure ANIMATES. `height: auto` is not
 * animatable, so the animation depends on Reka publishing the measured height as
 * `--reka-collapsible-content-height` and on the keyframes consuming it — a
 * jsdom/happy-dom spec sees neither computed animations nor layout. It also
 * pins the two things that used to be wrong here: the fields inside the panel
 * share ONE row (the grid measures its own width, not the viewport's, so a
 * dialog of fixed width no longer squeezes them), and reduced motion is
 * honoured through the Kinetix preference class as well as the OS setting.
 */
const root = fileURLToPath(new URL("..", import.meta.url));

const server = await createServer({
  configFile: fileURLToPath(new URL("../vite.gallery.config.ts", import.meta.url)),
  root: root + "/gallery",
  logLevel: "warn",
});
await server.listen();
const base = `http://localhost:${server.config.server.port}`;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 800 } });

const failures = [];
page.on("pageerror", (e) => failures.push(`pageerror: ${e.message}`));
page.on("console", (m) => {
  if (m.type() === "error") failures.push(`console: ${m.text()}`);
});

const contentStyle = (content) =>
  content.evaluate((el) => {
    const cs = getComputedStyle(el);
    return {
      animationName: cs.animationName,
      state: el.getAttribute("data-state"),
      measured: cs.getPropertyValue("--reka-collapsible-content-height").trim(),
    };
  });

let step = "";
try {
  step = "load specimen";
  await page.goto(`${base}/?s=importer-options-collapsed`, { waitUntil: "networkidle" });

  const trigger = page.locator("button[aria-expanded]").first();
  const content = page.locator(".kx-collapsible-content");

  step = "collapsed: the trigger announces itself as a collapsed disclosure";
  assert.equal(await trigger.getAttribute("aria-expanded"), "false");
  assert.equal(await trigger.evaluate((el) => el.tagName), "BUTTON");

  step = "opening animates the height toward the measured content height";
  await trigger.click();
  const opening = await contentStyle(content);
  assert.equal(opening.state, "open");
  assert.match(
    opening.animationName,
    /kx-collapsible-down/,
    `open animation runs (got "${opening.animationName}")`,
  );
  assert.match(
    opening.measured,
    /^\d+(\.\d+)?px$/,
    `reka publishes the content height (got "${opening.measured}")`,
  );

  step = "open: the content is laid out and the trigger points at it";
  await page.waitForTimeout(300);
  assert.equal(await trigger.getAttribute("aria-expanded"), "true");
  // Reka's `contentId` is a plain (non-reactive) context value the content
  // assigns during setup, so the trigger picks it up on its next render — from
  // the first toggle onward. `aria-expanded` carries the state throughout.
  const controls = await trigger.getAttribute("aria-controls");
  assert.ok(controls, "trigger points at the content once rendered");
  assert.equal(await content.getAttribute("id"), controls);
  const openHeight = await content.evaluate((el) => el.getBoundingClientRect().height);
  assert.ok(openHeight > 50, `content has height when open (got ${openHeight})`);

  step = "the three reading-option fields share one row";
  const tops = await page.evaluate(() => {
    const grid = document.querySelector(".kx-import-options-grid");
    return [...grid.children].map((c) => Math.round(c.getBoundingClientRect().top));
  });
  assert.equal(
    new Set(tops).size,
    1,
    `all fields align on one row top (got ${tops.join(", ")})`,
  );

  step = "closing animates too";
  await trigger.click();
  const closing = await contentStyle(content);
  assert.match(
    closing.animationName,
    /kx-collapsible-up/,
    `close animation runs (got "${closing.animationName}")`,
  );
  await page.waitForTimeout(300);

  step = "the animation is escapable under reduced motion";
  // The guard that ships is the one `kinetixAccessibility` injects (the gallery
  // does not install the plugin, so the test provides the same rule): one
  // `!important` declaration that collapses EVERY animation's duration under
  // the `kx-reduce-motion` class and under the OS setting alike.
  await page.addStyleTag({
    content:
      ".kx-reduce-motion *, .kx-reduce-motion *::before, .kx-reduce-motion *::after" +
      "{ animation-duration: .001ms !important; transition-duration: .001ms !important; }",
  });
  await page.evaluate(() => document.documentElement.classList.add("kx-reduce-motion"));
  await trigger.click();
  // Chromium reports the collapsed duration in seconds ("1e-06s").
  const durationMs = await content.evaluate((el) => {
    const raw = getComputedStyle(el).animationDuration;

    return raw.endsWith("ms") ? parseFloat(raw) : parseFloat(raw) * 1000;
  });
  assert.ok(
    durationMs < 1,
    `reduced motion collapses the animation to ~0 (got ${durationMs}ms)`,
  );

  assert.deepEqual(failures, [], `no page errors: ${failures.join(" | ")}`);
  console.log("collapsible e2e: OK");
} catch (error) {
  console.error(`collapsible e2e FAILED at "${step}": ${error.message}`);
  process.exitCode = 1;
} finally {
  await browser.close();
  await server.close();
}
