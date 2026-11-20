/** The text the store answers with when a request body is over its limit (docs/features/abuse-limits/spec.md, "Komunikaty"). */
export const BODY_TOO_LARGE = "Treść żądania jest za duża.";

/** The limit of a JSON body of the cart, checkout and payment routes. */
export const MAX_JSON_BODY_BYTES = 16 * 1024;

/** `text` is the body as UTF-8; `unreadable` is a body that could not be read to its end (the connection broke). */
export type BodyText = { kind: "text"; text: string } | { kind: "too_large" } | { kind: "unreadable" };

/**
 * Reads the body of a request as text, never more than `maxBytes` bytes. A `Content-Length` over the limit refuses the
 * request before anything is read; a body without that header, or longer than it says, is read chunk by chunk and
 * stopped at the first byte over the limit.
 */
export async function readBodyText(request: Request, maxBytes: number): Promise<BodyText> {
  const declared = request.headers.get("content-length")?.trim();
  if (declared !== undefined && /^\d+$/.test(declared) && Number(declared) > maxBytes) {
    return { kind: "too_large" };
  }
  if (request.body === null) {
    return { kind: "text", text: "" };
  }
  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > maxBytes) {
        await reader.cancel().catch(() => undefined);
        return { kind: "too_large" };
      }
      chunks.push(value);
    }
  } catch {
    return { kind: "unreadable" };
  }
  const bytes = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return { kind: "text", text: new TextDecoder().decode(bytes) };
}
