import { createHash } from "node:crypto";

declare const nextCacheTagBrand: unique symbol;

/**
 * A tag of the Next.js cache (`next: { tags }` of a read, `revalidateTag`). Its values come from this module only, so
 * no read carries a name built elsewhere: Next.js keeps every revalidated tag in memory until the process restarts,
 * and a tag with the id of an entity would grow that list without a bound.
 */
export type NextCacheTag = string & { readonly [nextCacheTagBrand]: true };

export const CATALOG_TAG = "catalog" as NextCacheTag;

/**
 * The tag of the Next.js cache for a tag of the API's dictionary (`CacheTags`, the `xkey` of Varnish): `catalog` stays
 * `catalog`; a product tag (`product-<id>`, `product-slug-<slug>`) becomes one of 65 536 buckets,
 * `product-bucket-<4 hex digits>` from the first 16 bits of SHA-256 of the whole name. Ids and slugs share the buckets, so the list of revalidated tags of Next.js has at most
 * 65 537 entries; a change of a product also revalidates the other entries of its bucket.
 */
export function nextCacheTagOf(apiTag: string): NextCacheTag {
  if (apiTag === "catalog") return CATALOG_TAG;
  return `product-bucket-${createHash("sha256").update(apiTag).digest("hex").slice(0, 4)}` as NextCacheTag;
}
