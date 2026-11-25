import { createHash, timingSafeEqual } from "node:crypto";
import { revalidateTag } from "next/cache";
import { nextCacheTagOf } from "@/catalog/isr-tags";
import { isProductSlug } from "@/catalog/load-product";
import { BODY_TOO_LARGE, readBodyText } from "@/cart/json-body";

/**
 * On-demand revalidation of the ISR pages, called by the API worker after it purged Varnish (StorefrontCache in the
 * API): `Authorization: Bearer <STORE_REVALIDATE_SECRET>`, a JSON body `{ tags }` with 1 to 100 tags of the API's
 * dictionary (`catalog`, `product-<uuid in lower case>`, `product-slug-<slug in the format of a product slug>`). Each
 * tag becomes its tag of the Next.js cache (`isr-tags.ts`: `catalog` or a product bucket; a bucket name in the body is
 * outside the dictionary, so it is refused), and each different Next.js tag is revalidated once with `expire: 0`, so
 * the next visitor waits for a fresh render instead of getting the stale page. `revalidated` is the number of tags of
 * the body. The secret is never logged.
 */

const MAX_TAGS = 100;
/** 100 tags of the longest format (`product-slug-` and 200 characters) are about 22 KiB. */
const MAX_BODY_BYTES = 32 * 1024;
const TAG = /^(catalog|product-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/;
const SLUG_TAG_PREFIX = "product-slug-";

const isKnownTag = (tag: string) =>
  TAG.test(tag) || (tag.startsWith(SLUG_TAG_PREFIX) && isProductSlug(tag.slice(SLUG_TAG_PREFIX.length)));

const answer = (status: number, body: unknown) =>
  Response.json(body, { status, headers: { "Cache-Control": "private, no-store" } });

/** Compares digests of equal length, so neither the content nor the length of the secret leaks through the timing. */
function isSecret(authorization: string | null, secret: string): boolean {
  const presented = authorization?.startsWith("Bearer ") ? authorization.slice("Bearer ".length) : null;
  if (presented === null) return false;
  const digest = (value: string) => createHash("sha256").update(value).digest();
  return timingSafeEqual(digest(presented), digest(secret));
}

function isJson(contentType: string | null): boolean {
  return contentType?.split(";")[0].trim().toLowerCase() === "application/json";
}

function tagsOf(body: unknown): string[] | null {
  if (typeof body !== "object" || body === null || !("tags" in body)) return null;
  const { tags } = body;
  if (!Array.isArray(tags) || tags.length < 1 || tags.length > MAX_TAGS) return null;
  return tags.every((tag): tag is string => typeof tag === "string" && isKnownTag(tag)) ? tags : null;
}

export async function POST(request: Request): Promise<Response> {
  const secret = process.env.STORE_REVALIDATE_SECRET;
  if (!secret) return answer(503, { detail: "Revalidation is not configured." });
  if (!isSecret(request.headers.get("authorization"), secret)) return answer(401, { detail: "Unauthorized." });
  if (!isJson(request.headers.get("content-type"))) return answer(400, { detail: "The body must be JSON." });

  const read = await readBodyText(request, MAX_BODY_BYTES);
  if (read.kind === "too_large") return answer(413, { detail: BODY_TOO_LARGE });
  let body: unknown;
  try {
    if (read.kind === "unreadable") throw new Error("The body could not be read.");
    body = JSON.parse(read.text);
  } catch {
    return answer(400, { detail: "The body must be JSON." });
  }
  const tags = tagsOf(body);
  if (tags === null) return answer(400, { detail: `The body must be { tags } with 1 to ${MAX_TAGS} known tags.` });

  for (const tag of new Set(tags.map(nextCacheTagOf))) revalidateTag(tag, { expire: 0 });
  return answer(200, { revalidated: tags.length });
}
