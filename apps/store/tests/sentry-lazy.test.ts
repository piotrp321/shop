import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * The SDK of Sentry stays out of the bundle of the pages (spec: docs/features/sentry/spec.md, P2): only the module of
 * the browser SDK (`src/observability/browser.ts`, loaded by `import()` from `lazy.ts`) and of the server SDK
 * (`src/observability/server.ts`, loaded by `import()` from `instrumentation.ts`) import the SDK, in any form (static,
 * for side effects, `require()`, `import()`); everything else imports only its types. Both modules are only ever
 * imported with `import()`.
 */

const ROOT = join(import.meta.dirname, "..");
const SOURCES = ["app", "src", "instrumentation.ts"];

function sources(path: string): string[] {
  const full = join(ROOT, path);
  if (path.endsWith(".ts") || path.endsWith(".tsx")) {
    return [path];
  }
  return readdirSync(full, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory() ? sources(join(path, entry.name)) : /\.tsx?$/.test(entry.name) ? [join(path, entry.name)] : [],
  );
}

const code = SOURCES.flatMap(sources)
  .filter((file) => !/\.test\.tsx?$/.test(file))
  .map((file) => ({ file: relative(ROOT, join(ROOT, file)), text: readFileSync(join(ROOT, file), "utf8") }));

const quoted = (module: RegExp) => String.raw`["']${module.source}["']`;

// A static import or re-export of a module, not `import type`: `import … from`, `export … from`, an import for its side
// effects and a `require()`.
const staticImport = (module: RegExp) =>
  new RegExp(
    [
      String.raw`^\s*(?:import|export)\s+(?!type\b)[^;]*?from\s+${quoted(module)}`,
      String.raw`^\s*import\s*${quoted(module)}`,
      String.raw`\brequire\s*\(\s*${quoted(module)}`,
    ].join("|"),
    "m",
  );

// Any import of a module that loads it: a static one or `import()`, but not `typeof import()`.
const anyImport = (module: RegExp) =>
  new RegExp(`${staticImport(module).source}|` + String.raw`(?<!\btypeof\s*)\bimport\s*\(\s*${quoted(module)}`, "m");

describe("the lazy loading of Sentry", () => {
  it("imports the SDK only in the modules of the browser and the server", () => {
    const importing = code.filter(({ text }) => anyImport(/@sentry\/[^"']+/).test(text)).map(({ file }) => file);
    expect(importing.sort()).toEqual(["src/observability/browser.ts", "src/observability/server.ts"]);
  });

  it("never imports the modules of the SDK statically", () => {
    const importing = code
      .filter(({ text }) => staticImport(/(?:@\/observability|\.{1,2}(?:\/[^"']*)?)\/(?:browser|server)/).test(text))
      .map(({ file }) => file);
    expect(importing).toEqual([]);
  });

  it("finds a static import of the SDK when there is one", () => {
    expect(staticImport(/@sentry\/[^"']+/).test('import * as Sentry from "@sentry/nextjs";')).toBe(true);
    expect(staticImport(/@sentry\/[^"']+/).test('import { init } from "@sentry/nextjs";')).toBe(true);
    expect(staticImport(/@sentry\/[^"']+/).test('export { init } from "@sentry/nextjs";')).toBe(true);
    expect(staticImport(/@sentry\/[^"']+/).test('import type { Event } from "@sentry/nextjs";')).toBe(false);
  });

  it("finds a side effect import and a require of the SDK as static", () => {
    expect(staticImport(/@sentry\/[^"']+/).test('import "@sentry/nextjs";')).toBe(true);
    expect(staticImport(/@sentry\/[^"']+/).test("const Sentry = require('@sentry/nextjs');")).toBe(true);
    expect(staticImport(/@sentry\/[^"']+/).test('const { init } = require ( "@sentry/browser" );')).toBe(true);
  });

  it("finds a dynamic import of the SDK, but not a type of it", () => {
    expect(anyImport(/@sentry\/[^"']+/).test('const Sentry = await import("@sentry/nextjs");')).toBe(true);
    expect(anyImport(/@sentry\/[^"']+/).test('void import ( "@sentry/browser" ).then((s) => s.init({}));')).toBe(true);
    expect(anyImport(/@sentry\/[^"']+/).test('import "@sentry/nextjs";')).toBe(true);
    expect(anyImport(/@sentry\/[^"']+/).test('type Sdk = typeof import("@sentry/nextjs");')).toBe(false);
    expect(anyImport(/@sentry\/[^"']+/).test('import type { Event } from "@sentry/nextjs";')).toBe(false);
  });
});
