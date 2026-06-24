import { fileURLToPath } from "node:url";
import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
    },
  },
  test: {
    environment: "happy-dom",
    // Specs live under tests/js (outside the publishable resources/js tree) so
    // they are never copied into a consumer's app by `vendor:publish`.
    include: ["tests/js/**/*.{test,spec}.ts"],
  },
});
