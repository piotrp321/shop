import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { "@": new URL("./src", import.meta.url).pathname },
  },
  test: {
    environment: "jsdom",
    setupFiles: ["tests/setup/jsdom-mocks.ts"],
    include: ["src/**/*.test.ts", "tests/**/*.test.ts"],
    // ESLint with the real project config on several fragments is slower than a unit test.
    testTimeout: 30_000,
  },
});
