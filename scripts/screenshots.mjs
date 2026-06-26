import { createServer } from "vite";
import { chromium } from "playwright";
import { fileURLToPath } from "node:url";
import { mkdir } from "node:fs/promises";

// Specimen list is defined in TS; mirror just the names + capture widths here
// by importing the compiled module through Vite's SSR loader.
const root = fileURLToPath(new URL("..", import.meta.url));
const outDir = fileURLToPath(new URL("../docs/public/screenshots", import.meta.url));

const server = await createServer({
  configFile: fileURLToPath(new URL("../vite.gallery.config.ts", import.meta.url)),
  root: root + "/gallery",
  logLevel: "warn",
});
await server.listen();
const { specimens } = await server.ssrLoadModule("/specimens.ts");
const base = `http://localhost:${server.config.server.port}`;

await mkdir(outDir, { recursive: true });

const browser = await chromium.launch();
const themes = ["light", "dark"];

for (const specimen of specimens) {
  for (const theme of themes) {
    const page = await browser.newPage({
      deviceScaleFactor: 2,
      // Open-popover specimens capture the viewport (not the #specimen crop),
      // so give them a snug viewport instead of the default 1280×720.
      viewport: specimen.openSelector
        ? { width: (specimen.width ?? 760) + 64, height: 560 }
        : undefined,
    });
    page.on("pageerror", (e) => console.error(`  [pageerror ${specimen.name}]`, e.message));
    page.on("console", (m) => {
      if (m.type() === "error") console.error(`  [console ${specimen.name}]`, m.text());
    });
    await page.goto(`${base}/?s=${specimen.name}&theme=${theme}`, {
      waitUntil: "networkidle",
    });
    const frame = page.locator("#specimen");
    await frame.waitFor({ state: "visible" });
    // Let fonts/transitions settle.
    await page.waitForTimeout(250);

    const suffix = theme === "dark" ? "-dark" : "";
    const file = `${outDir}/${specimen.name}${suffix}.png`;

    // Specimens with a teleported overlay (popover/menu) open it first and
    // capture the full page, since the panel renders outside #specimen.
    if (specimen.openSelector) {
      await page.click(specimen.openSelector);
      await page.waitForTimeout(400);
      await page.screenshot({ path: file });
      await page.close();
      console.log(`✓ ${specimen.name}${suffix}.png (opened)`);
      continue;
    }

    await frame.screenshot({ path: file });
    console.log(`✓ ${specimen.name}${suffix}.png`);
    await page.close();
  }
}

await browser.close();
await server.close();
console.log(`\nSaved ${specimens.length * themes.length} screenshots to docs/public/screenshots`);
