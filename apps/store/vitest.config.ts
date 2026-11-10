import { basename } from "node:path";
import react from "@vitejs/plugin-react";
import { defineConfig, type Plugin } from "vitest/config";

/**
 * The image files of the store (`.webp` under `src/home/images/`) in Vitest, one mock for the tests of the module and of
 * the home page. Next.js turns `import photo from "./a.webp"` into a `StaticImageData`; Vite turns it into a string.
 * This plugin gives the tests the shape of Next.js: the address Next would serve (`/_next/static/media/<file>`) and the
 * width from the name (`...-<width>.webp`), no height (the page takes the height from the list of the photos).
 */
function imageFiles(): Plugin {
  return {
    name: "store-image-files",
    enforce: "pre",
    load(id) {
      const path = id.split("?")[0]!;
      if (!path.endsWith(".webp")) return null;
      const file = basename(path);
      const width = Number(/-(\d+)\.webp$/.exec(file)?.[1] ?? 0);
      return `export default ${JSON.stringify({ src: `/_next/static/media/${file}`, width, height: 0 })};`;
    },
  };
}

export default defineConfig({
  plugins: [react(), imageFiles()],
  resolve: {
    alias: {
      "@": new URL("./src", import.meta.url).pathname,
      "next/font/local": new URL("./tests/stubs/next-font-local.ts", import.meta.url).pathname,
    },
  },
  test: {
    environment: "node",
    include: ["src/**/*.test.{ts,tsx}", "app/**/*.test.{ts,tsx}", "tests/**/*.test.{ts,tsx}"],
    // ESLint with the real project config on several fragments is slower than a unit test.
    testTimeout: 30_000,
  },
});
