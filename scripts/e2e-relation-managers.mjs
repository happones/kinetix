import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { createServer } from "vite";
import { chromium } from "playwright";

/**
 * Browser (Chromium) end-to-end check of the relation-manager surface, driven
 * against the gallery's `relation-managers` specimen: real KinetixTable, real
 * reka portals, real event wiring — the exact pipeline a host app renders.
 *
 *   npm run test:e2e
 *
 * Asserts, in order: tabs render; the toolbar Create opens the modal from the
 * blueprint; a grouped row Edit fetches + opens the filled form; a grouped
 * Dissociate confirms and fires the relation endpoint with the record id; the
 * tab switch lands in the URL (?relation=); Attach opens the picker with the
 * stubbed options and posts the selected ids.
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
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

const failures = [];
page.on("pageerror", (e) => failures.push(`pageerror: ${e.message}`));
page.on("console", (m) => {
  if (m.type() === "error") failures.push(`console: ${m.text()}`);
});

const calls = () => page.evaluate(() => globalThis.__kinetixCalls ?? []);

let step = "";
try {
  step = "load specimen";
  await page.goto(`${base}/?s=relation-managers`, { waitUntil: "networkidle" });

  step = "tabs render";
  const tabs = page.locator('[role="tab"]');
  await tabs.first().waitFor({ state: "visible" });
  assert.equal(await tabs.count(), 2, "expected a tab per manager");
  assert.match(await tabs.nth(0).innerText(), /Tasks/);
  assert.match(await tabs.nth(1).innerText(), /Tags/);

  step = "toolbar Create opens the modal from the blueprint (no network)";
  await page.getByRole("button", { name: "New task" }).click();
  const createDialog = page.locator('[role="dialog"]');
  await createDialog.waitFor({ state: "visible" });
  const createInput = createDialog.locator("input").first();
  await createInput.waitFor({ state: "visible" });
  assert.equal(await createInput.inputValue(), "", "create form starts blank");

  // The v4 close button must anchor INSIDE the panel (regression: without
  // `relative` on the panel it anchored to the viewport and "disappeared").
  const panelBox = await createDialog.locator('[tabindex="-1"]').boundingBox();
  const closeBox = await createDialog
    .getByRole("button", { name: /close/i })
    .boundingBox();
  assert.ok(panelBox && closeBox, "panel and close button render");
  assert.ok(
    closeBox.x >= panelBox.x &&
      closeBox.x + closeBox.width <= panelBox.x + panelBox.width &&
      closeBox.y >= panelBox.y,
    "close button sits inside the panel",
  );
  await createDialog.getByRole("button", { name: "Cancel" }).click();
  await createDialog.waitFor({ state: "hidden" });

  step = "grouped row Edit fetches the record and opens the filled form";
  await page.locator('[aria-label="More actions"]').first().click();
  await page.getByRole("menuitem", { name: "Edit" }).click();
  const editDialog = page.locator('[role="dialog"]');
  await editDialog.waitFor({ state: "visible" });
  const editInput = editDialog.locator("input").first();
  await editInput.waitFor({ state: "visible" });
  await page.waitForFunction(
    () => document.querySelector('[role="dialog"] input')?.value !== "",
  );
  assert.equal(await editInput.inputValue(), "Ship the audit");
  let seen = await calls();
  assert.ok(
    seen.some(
      (c) =>
        c.url.includes("/tables/relations/record/resolve") &&
        c.body?.mode === "edit" &&
        c.body?.token === "demo-tasks-descriptor",
    ),
    "edit resolved through the RELATION record endpoint",
  );
  await editDialog.getByRole("button", { name: "Cancel" }).click();
  await editDialog.waitFor({ state: "hidden" });

  step = "grouped Dissociate confirms, then fires the relation endpoint with the record id";
  await page.locator('[aria-label="More actions"]').first().click();
  await page.getByRole("menuitem", { name: "Dissociate" }).click();
  const confirm = page.locator('[role="dialog"]');
  await confirm.waitFor({ state: "visible" });
  await confirm.getByRole("button", { name: /Confirm|Dissociate/ }).click();
  await page.waitForFunction(() =>
    (globalThis.__kinetixCalls ?? []).some((c) =>
      c.url.includes("/tables/relations/dissociate"),
    ),
  );
  seen = await calls();
  const dissociate = seen.find((c) => c.url.includes("/tables/relations/dissociate"));
  assert.deepEqual(dissociate.body.ids, [1], "the CLICKED record id rode the event");
  assert.equal(dissociate.body.descriptor, "demo-tasks-descriptor");

  step = "tab switch writes ?relation= into the URL";
  await page.getByRole("tab", { name: /Tags/ }).click();
  await page.waitForFunction(
    () => new URL(location.href).searchParams.get("relation") === "tags",
  );

  step = "Attach opens the picker with the stubbed options and posts the ids";
  await page.getByRole("button", { name: "Attach" }).click();
  const picker = page.locator('[role="dialog"]');
  await picker.waitFor({ state: "visible" });
  await picker.getByText("vue").waitFor({ state: "visible" });
  await picker.getByText("vue").click();
  await picker.getByRole("button", { name: "Attach" }).click();
  await page.waitForFunction(() =>
    (globalThis.__kinetixCalls ?? []).some((c) =>
      c.url.includes("/tables/relations/attach"),
    ),
  );
  seen = await calls();
  const attach = seen.find(
    (c) => c.url.includes("/tables/relations/attach") && !c.url.includes("attachable"),
  );
  assert.deepEqual(attach.body.ids, [2], "the picked option id was attached");
  assert.equal(attach.body.descriptor, "demo-tags-descriptor");

  if (failures.length > 0) {
    throw new Error(`browser errors:\n${failures.join("\n")}`);
  }

  console.log("✓ relation-manager E2E: tabs, create modal, edit modal, dissociate, ?relation=, attach picker");
} catch (error) {
  console.error(`✗ E2E failed at step: ${step}`);
  console.error(error);
  process.exitCode = 1;
} finally {
  await browser.close();
  await server.close();
}
