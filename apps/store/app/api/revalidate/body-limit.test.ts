import { revalidateTag } from "next/cache";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { POST } from "./route";

vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));

const SECRET = "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90";
const LIMIT = 32 * 1024;
const encoder = new TextEncoder();

beforeEach(() => {
  vi.mocked(revalidateTag).mockReset();
  vi.stubEnv("STORE_REVALIDATE_SECRET", SECRET);
});

afterEach(() => vi.unstubAllEnvs());

function send(body: string | ReadableStream<Uint8Array>, headers: Record<string, string> = {}): Promise<Response> {
  return POST(
    new Request("http://store:3000/api/revalidate", {
      method: "POST",
      headers: { authorization: `Bearer ${SECRET}`, "content-type": "application/json", ...headers },
      body,
      ...(typeof body === "string" ? {} : { duplex: "half" }),
    } as RequestInit),
  );
}

function jsonOf(bytes: number): string {
  const head = JSON.stringify({ tags: ["catalog"], pad: "" });
  return head.slice(0, -2) + "x".repeat(bytes - encoder.encode(head).length) + '"}';
}

function stream(text: string, onPull: () => void = () => {}) {
  const bytes = encoder.encode(text);
  let sent = false;
  return new ReadableStream<Uint8Array>({
    pull(controller) {
      onPull();
      if (!sent) {
        sent = true;
        controller.enqueue(bytes.slice(0, bytes.length >> 1));
        controller.enqueue(bytes.slice(bytes.length >> 1));
      }
      controller.close();
    },
  });
}

async function expectTooLarge(response: Response) {
  expect(response.status).toBe(413);
  expect(await response.json()).toEqual({ detail: "Treść żądania jest za duża." });
  expect(revalidateTag).not.toHaveBeenCalled();
}

describe("the limit of the body of /api/revalidate (32 KiB)", () => {
  it("answers 413 by the Content-Length alone, without reading the body", async () => {
    let pulled = false;
    await expectTooLarge(await send(stream(jsonOf(LIMIT + 1), () => (pulled = true)), { "content-length": String(LIMIT + 1) }));

    expect(pulled).toBe(false);
  });

  it("answers 413 for a streamed body without Content-Length, one byte over", async () => {
    await expectTooLarge(await send(stream(jsonOf(LIMIT + 1))));
  });

  it("answers 413 when Content-Length understates the body", async () => {
    await expectTooLarge(await send(stream(jsonOf(LIMIT + 1)), { "content-length": "20" }));
  });

  it("answers 401 before it looks at the size when the secret is wrong", async () => {
    const response = await send(jsonOf(LIMIT + 1), { authorization: "Bearer nope", "content-length": String(LIMIT + 1) });

    expect(response.status).toBe(401);
  });

  it("takes a body of exactly the limit", async () => {
    const response = await send(stream(jsonOf(LIMIT)));

    expect(response.status).toBe(200);
  });

  it("takes 100 tags of the longest allowed format", async () => {
    const tags = Array.from({ length: 100 }, (_, index) => `product-slug-${String(index).padStart(3, "0")}${"a".repeat(197)}`);
    const body = JSON.stringify({ tags });
    expect(encoder.encode(body).length).toBeLessThan(LIMIT);

    const response = await send(body);

    expect(response.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledTimes(100);
  });
});
