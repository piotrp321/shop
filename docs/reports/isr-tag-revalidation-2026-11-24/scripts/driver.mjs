// Mass tag revalidation against the production store image (measurement for docs/reports). Sends the tags the API
// worker would send for a change of every product: `product-<id>` and `product-slug-<slug>`, 100 per request, to
// POST /api/revalidate. `--mode bucket` maps each tag to one of 65536 names per format that the endpoint accepts today,
// which emulates a bounded tag space without a change of the store.
// Usage: node driver.mjs --tsv <id\tslug file> --store <url> --api <url> --secret <s> --mode exact|bucket --total <tags>
import { createHash, randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (index % 2 === 0 ? [...pairs, [value.replace(/^--/, ""), all[index + 1]]] : pairs), []));
const STORE = args.store;
const API = args.api;
const SECRET = args.secret;
const MODE = args.mode ?? "exact";
const TOTAL = Number(args.total);
const WARM_404 = Number(args.warm404 ?? 2500);
const CONCURRENCY = Number(args.concurrency ?? 4);
const BATCH = 100;
const REPORT_EVERY = 100_000;
const PAUSE_AT = Number(args.pauseAt ?? 0);
const PAUSE_MS = Number(args.pauseMs ?? 60_000);

const products = readFileSync(args.tsv, "utf8").trimEnd().split("\n").map((line) => line.split("\t"));
const log = (event, data = {}) => console.log(JSON.stringify({ t: Date.now(), event, ...data }));

const bucket = (tag) => {
  const hex = createHash("sha256").update(tag).digest("hex").slice(0, 4);
  return tag.startsWith("product-slug-") ? `product-slug-b-${hex}` : `product-00000000-0000-0000-0000-00000000${hex}`;
};

/** The tags of the n-th product; past the catalog the same slugs with a suffix and fresh ids (synthetic products). */
function tagsOf(n) {
  const pass = Math.floor(n / products.length);
  const [id, slug] = products[n % products.length];
  const tags = pass === 0 ? [`product-${id}`, `product-slug-${slug}`] : [`product-${randomUUID()}`, `product-slug-${slug}-${pass}`];
  return MODE === "bucket" ? tags.map(bucket) : tags;
}

async function seedSlugs() {
  const slugs = [];
  for (let page = 1; page <= 20; page += 1) {
    const response = await fetch(`${API}/api/v1/products?page=${page}`);
    if (!response.ok) throw new Error(`GET /api/v1/products?page=${page} answered ${response.status}`);
    const body = await response.json();
    if (body.items.length === 0) break;
    slugs.push(...body.items.map((item) => item.slug));
  }
  return slugs;
}

const statuses = {};
async function visit(slug) {
  try {
    const response = await fetch(`${STORE}/p/${slug}`, { redirect: "manual" });
    await response.arrayBuffer();
    statuses[response.status] = (statuses[response.status] ?? 0) + 1;
  } catch {
    statuses.error = (statuses.error ?? 0) + 1;
  }
}

async function pool(count, work) {
  let next = 0;
  await Promise.all(Array.from({ length: CONCURRENCY }, async () => {
    while (next < count) {
      const index = next;
      next += 1;
      await work(index);
    }
  }));
}

const seed = await seedSlugs();
log("seed", { products: seed.length, catalog: products.length, mode: MODE, total: TOTAL });

// Warm-up: every seeded product page and unique 404 pages of catalog slugs (they carry the tags revalidated below).
await pool(seed.length, (index) => visit(seed[index]));
await pool(WARM_404, (index) => visit(products[index][1]));
log("warm", { statuses: { ...statuses } });

let sent = 0;
let failed = 0;
let reported = 0;
let stop = false;
let paused = PAUSE_AT === 0;
let pause = Promise.resolve();
const batches = Math.ceil(TOTAL / BATCH);
const perBatch = BATCH / 2;
await pool(batches, async (index) => {
  await pause;
  if (stop) return;
  const tags = [];
  for (let n = index * perBatch; n < (index + 1) * perBatch; n += 1) tags.push(...tagsOf(n));
  try {
    const response = await fetch(`${STORE}/api/revalidate`, {
      method: "POST",
      headers: { authorization: `Bearer ${SECRET}`, "content-type": "application/json" },
      body: JSON.stringify({ tags }),
    });
    await response.arrayBuffer();
    if (response.status !== 200) throw new Error(`status ${response.status}`);
    sent += tags.length;
  } catch (error) {
    failed += 1;
    log("revalidate-failed", { sent, message: String(error?.cause?.code ?? error?.message ?? error) });
    if (failed >= 3) stop = true;
    return;
  }
  // Page traffic between the revalidations: a seeded product and a 404 page every 50 batches.
  if (index % 50 === 0) {
    await visit(seed[index % seed.length]);
    await visit(products[index % WARM_404][1]);
  }
  if (!paused && sent >= PAUSE_AT) {
    // A settle period after the tags of the whole catalog: only light page traffic, so the sampler sees the memory at rest.
    paused = true;
    log("pause-start", { sent });
    pause = (async () => {
      const until = Date.now() + PAUSE_MS;
      while (Date.now() < until) {
        await visit(seed[Math.floor(Math.random() * seed.length)]);
        await new Promise((resolve) => setTimeout(resolve, 500));
      }
      log("pause-end", { sent });
    })();
  }
  if (sent - reported >= REPORT_EVERY) {
    reported = sent;
    log("progress", { sent });
  }
});
log("done", { sent, failed, stopped: stop, statuses });
