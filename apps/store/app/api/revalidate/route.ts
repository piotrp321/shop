import { createHash, timingSafeEqual } from "node:crypto";
import { revalidateTag } from "next/cache";

/**
 * On-demand revalidation of the ISR pages, called by the API worker after it purged Varnish (StorefrontCache in the
 * API): `Authorization: Bearer <STORE_REVALIDATE_SECRET>`, a JSON body `{ tags }` with 1 to 100 tags of the API's
 * dictionary (`catalog`, `product-<uuid in lower case>`). Each tag is revalidated with `expire: 0`, so the next
 * visitor waits for a fresh render instead of getting the stale page. The secret is never logged.
 */

const MAX_TAGS = 100;
const TAG = /^(catalog|product-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/;

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
  return tags.every((tag): tag is string => typeof tag === "string" && TAG.test(tag)) ? tags : null;
}

export async function POST(request: Request): Promise<Response> {
  const secret = process.env.STORE_REVALIDATE_SECRET;
  if (!secret) return answer(503, { detail: "Revalidation is not configured." });
  if (!isSecret(request.headers.get("authorization"), secret)) return answer(401, { detail: "Unauthorized." });
  if (!isJson(request.headers.get("content-type"))) return answer(400, { detail: "The body must be JSON." });

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return answer(400, { detail: "The body must be JSON." });
  }
  const tags = tagsOf(body);
  if (tags === null) return answer(400, { detail: `The body must be { tags } with 1 to ${MAX_TAGS} known tags.` });

  for (const tag of tags) revalidateTag(tag, { expire: 0 });
  return answer(200, { revalidated: tags.length });
}
