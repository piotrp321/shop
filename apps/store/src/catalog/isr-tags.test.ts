import { describe, expect, it } from "vitest";
import { CATALOG_TAG, nextCacheTagOf } from "./isr-tags";

const BUCKET = /^product-bucket-[0-9a-f]{4}$/;
const BUCKETS = 65_536;

/** A UUID in the shape of the API (version 7, lower case) built from a counter, so the test is deterministic. */
function uuidOf(index: number): string {
  const hex = index.toString(16).padStart(20, "0");
  return `0192f0c4-${hex.slice(0, 4)}-7${hex.slice(4, 7)}-8${hex.slice(7, 10)}-${hex.slice(10, 20).padStart(12, "0")}`;
}

/** A slug like the ones of the demo data (words and a number), built from a counter. */
const WORDS = ["kurtka", "puchowa", "buty", "trekkingowe", "plecak", "miejski", "koszulka", "lniana"];
function slugOf(index: number): string {
  return `${WORDS[index % WORDS.length]}-${WORDS[(index >> 3) % WORDS.length]}-${index}`;
}

describe("the tags of the Next.js cache", () => {
  it("keeps catalog as catalog", () => {
    expect(nextCacheTagOf("catalog")).toBe("catalog");
    expect(CATALOG_TAG).toBe("catalog");
  });

  it("gives the same tag for the same name of the API", () => {
    const id = `product-${uuidOf(42)}`;
    const slug = `product-slug-${slugOf(42)}`;

    expect(nextCacheTagOf(id)).toBe(nextCacheTagOf(id));
    expect(nextCacheTagOf(slug)).toBe(nextCacheTagOf(slug));
  });

  it("turns a product tag and a slug tag into a bucket of the same space, without the id or the slug", () => {
    const id = uuidOf(7);
    const slug = slugOf(7);

    expect(nextCacheTagOf(`product-${id}`)).toMatch(BUCKET);
    expect(nextCacheTagOf(`product-slug-${slug}`)).toMatch(BUCKET);
    expect(nextCacheTagOf(`product-${id}`)).not.toContain(id);
    expect(nextCacheTagOf(`product-slug-${slug}`)).not.toContain(slug);
  });

  it("takes the first 16 bits of SHA-256 of the whole name of the API", () => {
    // printf '%s' product-slug-x | sha256sum → 72c4e2e7…
    expect(nextCacheTagOf("product-slug-x")).toBe("product-bucket-72c4");
    // printf '%s' product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60 | sha256sum → e49810ca…
    expect(nextCacheTagOf("product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60")).toBe("product-bucket-e498");
  });

  it("spreads 1 000 000 names (ids and slugs) over at most 65 536 buckets without clusters", () => {
    const names = 1_000_000;
    const counts = new Map<string, number>();
    for (let index = 0; index < names / 2; index++) {
      for (const name of [`product-${uuidOf(index)}`, `product-slug-${slugOf(index)}`]) {
        const tag = nextCacheTagOf(name);
        counts.set(tag, (counts.get(tag) ?? 0) + 1);
      }
    }

    expect([...counts.keys()].every((tag) => BUCKET.test(tag))).toBe(true);
    expect(counts.size).toBeLessThanOrEqual(BUCKETS);
    const mean = names / counts.size;
    expect(Math.max(...counts.values())).toBeLessThan(3 * mean);
  });
});
