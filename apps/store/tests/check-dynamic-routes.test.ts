import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

const SCRIPT = new URL("../scripts/check-dynamic-routes.mjs", import.meta.url).pathname;

let dir: string;

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), "dynamic-routes-"));
});
afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

function writeManifest(manifest: unknown) {
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, "prerender-manifest.json"), typeof manifest === "string" ? manifest : JSON.stringify(manifest));
}

function run(mode: string | undefined) {
  const env = { ...process.env };
  if (mode === undefined) delete env.STORE_MODE;
  else env.STORE_MODE = mode;
  const result = spawnSync(process.execPath, [SCRIPT, dir], { env, encoding: "utf8" });
  return { code: result.status, out: result.stdout, err: result.stderr };
}

const manifest = (routes: string[], dynamicRoutes: string[] = []) => ({
  version: 4,
  routes: Object.fromEntries(routes.map((route) => [route, { initialRevalidateSeconds: false }])),
  dynamicRoutes: Object.fromEntries(dynamicRoutes.map((route) => [route, { fallback: null }])),
  notFoundRoutes: [],
});

describe("check-dynamic-routes", () => {
  it("passes (0) when none of the robots and sitemap routes is prerendered", () => {
    writeManifest(manifest(["/cart", "/_not-found", "/favicon.ico"], ["/p/[slug]"]));
    const { code, out } = run("production");
    expect(code).toBe(0);
    expect(out).toContain("dynamic");
  });

  it.each(["/robots.txt", "/sitemap.xml", "/sitemaps/listing.xml", "/sitemaps/products/abc.xml"])("fails (1) when %s is prerendered, and names it", (route) => {
    writeManifest(manifest(["/cart", route]));
    const { code, err } = run("production");
    expect(code).toBe(1);
    expect(err).toContain(route);
  });

  it("fails (1) when a dynamic route under /sitemaps/ is prerendered with its own params", () => {
    writeManifest(manifest(["/cart"], ["/sitemaps/products/[file]"]));
    const { code, err } = run("production");
    expect(code).toBe(1);
    expect(err).toContain("/sitemaps/products/[file]");
  });

  it("lists every prerendered route, not only the first", () => {
    writeManifest(manifest(["/robots.txt", "/sitemap.xml"]));
    const { err } = run("production");
    expect(err).toContain("/robots.txt");
    expect(err).toContain("/sitemap.xml");
  });

  it("does not fail for a similar route outside the sitemap tree", () => {
    writeManifest(manifest(["/sitemaps-info", "/sitemap", "/c/robots.txt"]));
    expect(run("production").code).toBe(0);
  });

  it("fails (2) without the manifest", () => {
    const { code, err } = run("production");
    expect(code).toBe(2);
    expect(err).toContain("prerender-manifest.json");
  });

  it("fails (2) for a manifest that is not JSON or has no routes", () => {
    writeManifest("not json");
    expect(run("production").code).toBe(2);
    writeManifest({ version: 4 });
    expect(run("production").code).toBe(2);
  });

  it.each(["dev", "", undefined])("fails (2) when STORE_MODE is %j, even with a manifest left by a build", (mode) => {
    writeManifest(manifest(["/cart"]));
    const { code, err } = run(mode);
    expect(code).toBe(2);
    expect(err).toContain("STORE_MODE");
  });
});
