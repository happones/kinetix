import { fileURLToPath } from "node:url";
import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
      // echo-vue's peer, not installed here: see tests/js/fixtures/pusher-js.ts.
      "pusher-js": fileURLToPath(
        new URL("./tests/js/fixtures/pusher-js.ts", import.meta.url),
      ),
    },
  },
  test: {
    environment: "happy-dom",
    // Specs live under tests/js (outside the publishable resources/js tree) so
    // they are never copied into a consumer's app by `vendor:publish`.
    include: ["tests/js/**/*.{test,spec}.ts"],
    setupFiles: ["tests/js/support/clearSearchCache.ts"],
    // The default 5s is not enough for the heaviest specs once the files run in
    // parallel: the timezone picker builds the full IANA zone list and the
    // import mapping mounts two dozen Reka selects, and either can pass alone in
    // ~1s yet exceed 5s while 160 other files share the CPU. That produced a
    // DIFFERENT spec failing on each full run — a timeout, never an assertion.
    testTimeout: 20000,
    server: {
      deps: {
        // Inlined so a spec can run the real echo-vue with `vi.mock('pusher-js')`
        // (an externalised package resolves its own imports, past the mock).
        inline: ["@laravel/echo-vue"],
      },
    },
  },
});
