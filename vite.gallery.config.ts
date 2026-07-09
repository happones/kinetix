import { fileURLToPath } from "node:url";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

const r = (p: string) => fileURLToPath(new URL(p, import.meta.url));

/**
 * Standalone Vite app that renders one Kinetix component per request
 * (`?s=<name>&theme=light|dark`) for the screenshot tooling. Inertia and the
 * Kinetix HTTP composable are aliased to stubs so components mount without a
 * backend.
 */
export default defineConfig({
  root: r("./gallery"),
  plugins: [
    vue(),
    tailwindcss(),
    {
      // Serve the PDF-template preview iframe with a static sample document so
      // <KinetixPdfTemplate> screenshots show a real-looking page.
      name: "kinetix-pdf-preview-stub",
      configureServer(server) {
        server.middlewares.use((req, res, next) => {
          if (!req.url?.includes("/pdf-templates/") || !req.url.includes("/preview")) {
            return next();
          }

          const url = new URL(req.url, "http://localhost");
          const accent = url.searchParams.get("accent") ?? "#6366f1";
          const title = url.searchParams.get("doc_title") ?? "Quotation";
          res.setHeader("Content-Type", "text/html; charset=utf-8");
          res.end(`<!DOCTYPE html><html><head><meta charset="utf-8"></head>
<body style="font-family:Helvetica,Arial,sans-serif;color:#0f172a;font-size:12px;margin:36px 42px;background:#fff;">
<table width="100%"><tr><td><div style="font-size:22px;font-weight:bold;color:${accent};">${title}</div>
<div style="font-size:12px;color:#6b7280;">Q-0001</div><div style="font-size:11px;color:#6b7280;">2026-07-10</div></td>
<td style="text-align:right;vertical-align:top;"><span style="display:inline-block;padding:3px 10px;border:1px solid ${accent};color:${accent};border-radius:99px;font-size:10px;text-transform:uppercase;letter-spacing:1px;">Draft</span></td></tr></table>
<div style="height:3px;background:${accent};margin:14px 0 20px;"></div>
<table><tr><td style="padding-right:24px;vertical-align:top;"><div style="font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:${accent};margin-bottom:4px;">FROM</div><div style="font-weight:bold;">Kinetix</div><div style="font-size:11px;color:#6b7280;">hello@example.com</div></td>
<td style="vertical-align:top;"><div style="font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:${accent};margin-bottom:4px;">FOR</div><div style="font-weight:bold;">Acme Inc.</div><div style="font-size:11px;color:#6b7280;">Jane Doe<br>jane@acme.dev</div></td></tr></table>
<table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:11px;">
<thead><tr><th style="border-bottom:2px solid ${accent};padding:7px 8px;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:${accent};text-align:left;">SKU</th><th style="border-bottom:2px solid ${accent};padding:7px 8px;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:${accent};text-align:left;">Item</th><th style="border-bottom:2px solid ${accent};padding:7px 8px;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:${accent};text-align:right;">Qty</th><th style="border-bottom:2px solid ${accent};padding:7px 8px;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:${accent};text-align:right;">Total</th></tr></thead>
<tbody><tr><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;color:#6b7280;">SKU-1</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;">Product A</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;text-align:right;">2</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;text-align:right;">100.00</td></tr>
<tr><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;color:#6b7280;background:#f8fafc;">SKU-2</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;background:#f8fafc;">Product B</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;text-align:right;background:#f8fafc;">1</td><td style="border-bottom:1px solid #e5e7eb;padding:7px 8px;text-align:right;background:#f8fafc;">75.00</td></tr></tbody></table>
<table align="right" style="margin-top:14px;"><tr><td style="padding:4px 16px 4px 0;font-size:11px;color:#6b7280;">Subtotal</td><td style="text-align:right;font-size:11px;">175.00</td></tr>
<tr><td style="padding:4px 16px 4px 0;font-size:13px;font-weight:bold;color:${accent};">Total</td><td style="text-align:right;font-size:13px;font-weight:bold;color:${accent};">203.00</td></tr></table>
</body></html>`);
        });
      },
    },
  ],
  resolve: {
    alias: [
      // More specific aliases first.
      { find: "@/composables/useKinetixHttp", replacement: r("./gallery/stubs/http.ts") },
      { find: "@inertiajs/vue3", replacement: r("./gallery/stubs/inertia.ts") },
      { find: "@laravel/echo-vue", replacement: r("./gallery/stubs/echo.ts") },
      { find: "@", replacement: r("./resources/js") },
    ],
  },
  server: { port: 5733 },
});
