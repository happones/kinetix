// Kinetix Help Center screenshot runner.
//
// Driven by `php artisan kinetix:help-screenshots`, which writes a JSON
// manifest and invokes this script — but it can also be run by hand:
//
//     node scripts/kinetix-help-screenshots.mjs /path/to/manifest.json
//
// Requires Playwright installed in the HOST app (a documented Help Center
// dependency):  npm i -D playwright && npx playwright install chromium
//
// Credentials come from the environment (never argv):
//     KINETIX_SCREENSHOT_EMAIL / KINETIX_SCREENSHOT_PASSWORD
//
// Manifest shape:
// {
//   "base_url": "http://localhost",
//   "out_dir": "/abs/path/for/pngs",
//   "viewport": { "width": 1440, "height": 900 },
//   "delay": 700,
//   "selectors": { "email": "#email", "password": "#password",
//                  "submit": "button[type=submit]", "logged_in_url": "**/dashboard" },
//   "pages": [ { "name": "dashboard", "path": "/dashboard",
//                "full_page": true, "delay": 700 } ]
// }
//
// `{team}` in a page path is replaced with the first URL segment after login
// (the team slug/route key in team-scoped apps).
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const manifestPath = process.argv[2];

if (!manifestPath) {
    console.error('Usage: node kinetix-help-screenshots.mjs <manifest.json>');
    process.exit(2);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

const BASE = manifest.base_url || process.env.APP_URL || 'http://localhost';
const OUT = manifest.out_dir;
const DELAY = manifest.delay ?? 700;
const SELECTORS = manifest.selectors ?? {};
const EMAIL = process.env.KINETIX_SCREENSHOT_EMAIL || '';
const PASSWORD = process.env.KINETIX_SCREENSHOT_PASSWORD || '';

if (!OUT) {
    console.error('Manifest is missing "out_dir".');
    process.exit(2);
}

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({
    viewport: manifest.viewport ?? { width: 1440, height: 900 },
});

let failures = 0;

// `waitUntil: 'load'` + a settle delay on purpose: apps holding websockets
// open (Echo/Reverb presence) never reach `networkidle`.
const visit = async (name, path, options = {}) => {
    try {
        await page.goto(`${BASE}${path}`, {
            waitUntil: 'load',
            timeout: 20000,
        });
        await page.waitForTimeout(options.delay ?? DELAY);
        await page.screenshot({
            path: `${OUT}/${name}.png`,
            fullPage: options.full_page ?? true,
        });
        console.log(`  ok ${name}`);
    } catch (error) {
        failures++;
        console.log(
            `  FAIL ${name} (${path}) — ${error.message.split('\n')[0]}`,
        );
    }
};

try {
    let teamSegment = '';

    if (EMAIL && PASSWORD) {
        await page.goto(`${BASE}/login`, { waitUntil: 'load' });
        await page.fill(SELECTORS.email ?? '#email', EMAIL);
        await page.fill(SELECTORS.password ?? '#password', PASSWORD);
        await Promise.all([
            page
                .waitForURL(SELECTORS.logged_in_url ?? '**/dashboard', {
                    timeout: 20000,
                })
                .catch(() => {}),
            page.click(SELECTORS.submit ?? 'button[type=submit]'),
        ]);
        await page.waitForTimeout(DELAY);

        const segment = new URL(page.url()).pathname.split('/')[1] ?? '';

        if (!segment || segment === 'login') {
            throw new Error(`login failed — still at ${page.url()}`);
        }

        teamSegment = segment;
        console.log(`Logged in (first URL segment: ${teamSegment})`);
    } else {
        console.log(
            'No KINETIX_SCREENSHOT_EMAIL/PASSWORD set — capturing without logging in.',
        );
    }

    for (const entry of manifest.pages ?? []) {
        const path = String(entry.path).replaceAll('{team}', teamSegment);
        await visit(entry.name, path, entry);
    }
} finally {
    await browser.close();
}

if (failures > 0) {
    console.error(`${failures} page(s) failed.`);
    process.exit(1);
}

console.log('Done.');
