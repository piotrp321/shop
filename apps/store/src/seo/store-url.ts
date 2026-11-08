/**
 * The public address of the store (`STORE_URL`) without a trailing slash. It is read when it is needed, at the request,
 * never at build time, so the build does not fix the address of one deployment into the pages. A missing value is a
 * configuration error (the exception ends as a 500), like on `/` and `/p/`.
 */
export function readStoreUrl(): string {
  const storeUrl = process.env.STORE_URL;
  if (!storeUrl) {
    throw new Error("STORE_URL is not set.");
  }
  return storeUrl.replace(/\/+$/, "");
}
