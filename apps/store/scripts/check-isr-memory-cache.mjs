// Checks on the production build that the ISR cache of the store lives only in memory, within its limit
// (docs/features/product-page/spec.md, "Cache ISR"): visits pages of existing products and unique slugs of products
// that do not exist (404 pages, which the ISR cache keeps too) until the entries overflow the memory cache, then checks
// that the cache files on disk did not change and that the oldest entry was evicted (`x-nextjs-cache: MISS` again).
// Run it in the store container: it reads the build in `.next` and asks the server at http://localhost:3000.
// Exit codes: 0 the cache stays in memory; 1 it does not (a cache file was written, or an entry outlived the overflow);
// 2 the check cannot say: STORE_MODE of the container is not `production`, the build or the API cannot be read, a page
// answers an unexpected status, or a fresh page is not kept in the cache at all.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const STORE = "http://localhost:3000";
const DIST = ".next";
// The directories of the incremental cache: pages (`.next/server`) and the fetch cache (`.next/cache/fetch-cache`).
const CACHE_DIRS = [join(DIST, "server"), join(DIST, "cache")];
const CONCURRENCY = 8;
const BATCH = 100;
// Probes: the first unique 404 pages, checked one after each batch; LRU evicts them first.
const PROBES = 100;
// The flood stops at this multiple of the limit of the cache counted in HTML characters alone (an entry is at least its
// HTML), so a cache that never evicts fails instead of running forever.
const OVERFLOW_FACTOR = 1.25;
const TIMEOUT_MS = 30_000;
// A network error or a 5xx of one request (e.g. a busy Varnish or API) is retried this many times before the check
// gives up; the cache does not change on such answers, so a retry cannot hide a write to disk.
const RETRIES = 2;

function cannot(message) {
  console.error(`check-isr-memory-cache: ${message}`);
  process.exit(2);
}

function fail(message) {
  console.error(`check-isr-memory-cache: ${message}`);
  process.exit(1);
}

if (process.env.STORE_MODE !== "production") {
  cannot(`STORE_MODE is "${process.env.STORE_MODE ?? ""}", not "production": there is no ISR cache to check.`);
}

/** Every file under the cache directories with its size, so an added, removed or resized file shows. */
function cacheFiles() {
  const files = new Map();
  const walk = (dir) => {
    let entries;
    try {
      entries = readdirSync(dir, { withFileTypes: true });
    } catch (error) {
      if (error?.code === "ENOENT") return;
      throw error;
    }
    for (const entry of entries) {
      const path = join(dir, entry.name);
      if (entry.isDirectory()) walk(path);
      else files.set(path, statSync(path).size);
    }
  };
  for (const dir of CACHE_DIRS) walk(dir);
  return files;
}

function diff(before, after) {
  const changed = [];
  for (const [path, size] of after) if (before.get(path) !== size) changed.push(path);
  for (const path of before.keys()) if (!after.has(path)) changed.push(path);
  return changed;
}

let limit;
try {
  limit = JSON.parse(readFileSync(join(DIST, "required-server-files.json"), "utf8")).config.cacheMaxMemorySize;
} catch (error) {
  cannot(`cannot read the build config (${error instanceof Error ? error.message : "unknown error"}); run a production build first.`);
}
if (!Number.isInteger(limit) || limit <= 0) cannot(`the build has no positive cacheMaxMemorySize (got ${limit}).`);

async function visit(path, expectedStatus) {
  for (let attempt = 0; ; attempt++) {
    let status;
    let cache;
    let html;
    let failure = null;
    try {
      const response = await fetch(STORE + path, { signal: AbortSignal.timeout(TIMEOUT_MS), redirect: "manual" });
      status = response.status;
      cache = response.headers.get("x-nextjs-cache");
      html = await response.text();
    } catch (error) {
      failure = `failed (${error instanceof Error ? error.message : "unknown error"})`;
    }
    if (failure === null && status === expectedStatus) return { cache, length: html.length };
    const retryable = failure !== null || status >= 500;
    if (!retryable || attempt >= RETRIES) cannot(`GET ${path} ${failure ?? `answered ${status}, expected ${expectedStatus}`}.`);
  }
}

async function productSlugs() {
  const base = process.env.API_CATALOG_URL;
  if (!base) cannot("API_CATALOG_URL is not set.");
  try {
    const response = await fetch(`${base}/api/v1/products?perPage=20`, { signal: AbortSignal.timeout(TIMEOUT_MS) });
    const items = (await response.json()).items;
    if (!response.ok || !Array.isArray(items) || items.length === 0) cannot(`no products from the API (status ${response.status}); seed the stack.`);
    return items.map((item) => item.slug);
  } catch (error) {
    cannot(`cannot read products from the API (${error instanceof Error ? error.message : "unknown error"}).`);
  }
}

/** Visits the paths with CONCURRENCY requests at a time. */
async function visitAll(paths, expectedStatus) {
  let next = 0;
  await Promise.all(
    Array.from({ length: CONCURRENCY }, async () => {
      while (next < paths.length) await visit(paths[next++], expectedStatus);
    }),
  );
}

const run = `${Date.now().toString(36)}-${process.pid}`;
// Slugs in the format of a product slug, so each one is read from the API and kept as a 404 page like a real visit.
const missing = (i) => `/p/isr-check-${run}-${i}`;

const before = cacheFiles();

await visitAll((await productSlugs()).map((slug) => `/p/${slug}`), 200);

const first = await visit(missing(0), 404);
const again = await visit(missing(0), 404);
if (again.cache !== "HIT") cannot(`a fresh 404 page is not kept in the cache (second visit: x-nextjs-cache ${again.cache}).`);

const maxVisits = Math.ceil((OVERFLOW_FACTOR * limit) / first.length);
await visitAll(Array.from({ length: PROBES - 1 }, (_, i) => missing(i + 1)), 404);

let visited = PROBES;
let evicted = null;
for (let probe = 0; probe < PROBES && visited < maxVisits; probe++) {
  await visitAll(Array.from({ length: BATCH }, (_, i) => missing(visited + i)), 404);
  visited += BATCH;
  const { cache } = await visit(missing(probe), 404);
  if (cache === "MISS") {
    evicted = { probe, visited };
    break;
  }
}

const changed = diff(before, cacheFiles());
if (changed.length > 0) {
  fail(`the ISR cache wrote to disk: ${changed.length} files changed, e.g. ${changed.slice(0, 5).join(", ")}`);
}
if (evicted === null) {
  fail(`no entry was evicted after ${visited} unique 404 pages (limit ${limit} bytes): the cache is not bounded by memory.`);
}
console.log(
  `check-isr-memory-cache: no cache file written; the ${evicted.probe + 1}. oldest 404 page was evicted after ${evicted.visited} unique pages (limit ${limit} bytes).`,
);
