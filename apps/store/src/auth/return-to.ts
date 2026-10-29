// Control characters (C0 and DEL) and backslashes, which browsers treat like a slash.
const FORBIDDEN = /[\u0000-\u001f\u007f\\]/;

/**
 * Accepts `value` only as a path within the store: a single leading `/` (not `//`), no backslash, no control
 * characters, and resolving to the store's own origin. Anything else, missing values included, becomes `/`.
 * The result is the normalised path, query and fragment.
 */
export function sanitizeReturnTo(value: string | null | undefined, storeUrl: string): string {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//") || FORBIDDEN.test(value)) {
    return "/";
  }
  const origin = new URL(storeUrl).origin;
  let url: URL;
  try {
    url = new URL(value, origin);
  } catch {
    return "/";
  }
  if (url.origin !== origin) {
    return "/";
  }
  const path = `${url.pathname}${url.search}${url.hash}`;
  // Normalisation must not produce a protocol-relative path either.
  return path.startsWith("//") ? "/" : path;
}
