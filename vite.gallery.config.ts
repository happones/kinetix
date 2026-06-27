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
  plugins: [vue(), tailwindcss()],
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
