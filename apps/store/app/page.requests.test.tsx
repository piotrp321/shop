import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// React's `cache` memoises only inside a server render, and this test runs outside one. This stand-in memoises by
// arguments until `newRequest()` is called, which is what the real `cache` does within one request; the test then
// proves that `generateMetadata` and the page ask the cached loader for the same arguments.
const memo = vi.hoisted(() => {
  const results = new Map<unknown, Map<string, unknown>>();
  return {
    newRequest: () => results.clear(),
    cache: <A extends unknown[], R>(fn: (...args: A) => R) =>
      (...args: A): R => {
        const perFn = results.get(fn) ?? new Map<string, unknown>();
        results.set(fn, perFn);
        const key = JSON.stringify(args);
        if (!perFn.has(key)) perFn.set(key, fn(...args));
        return perFn.get(key) as R;
      },
  };
});
vi.mock("react", async (importOriginal) => ({ ...(await importOriginal<typeof import("react")>()), cache: memo.cache }));

import { TREE, makeProduct } from "@/catalog/fixtures";
import Home, { generateMetadata } from "./page";

const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { "Content-Type": "application/json" } });
const PAGE = { items: [makeProduct(1)], page: 2, perPage: 24, total: 40 };

let requested: string[];

beforeEach(() => {
  memo.newRequest();
  requested = [];
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: Request | string | URL) => {
      const url = new URL(input instanceof Request ? input.url : String(input));
      requested.push(url.pathname);
      if (url.pathname === "/api/v1/categories") return json(TREE);
      if (url.pathname === "/api/v1/prices") return json({ items: [{ productId: makeProduct(1).id, gross: 5490, currency: "PLN" }] });
      if (url.pathname === "/api/v1/availability") return json({ items: [{ productId: makeProduct(1).id, level: "in_stock" }] });
      return json(PAGE);
    }),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

const props = (params: Record<string, string>) =>
  ({ searchParams: Promise.resolve(params) }) as unknown as Parameters<typeof Home>[0];

describe("catalogue page requests", () => {
  it("makes one categories, one products, one prices and one availability request for the metadata and the page together", async () => {
    const request = props({ category: "buty", page: "2" });
    await Promise.all([generateMetadata(request), Home(request)]);
    expect(requested.sort()).toEqual(["/api/v1/availability", "/api/v1/categories", "/api/v1/prices", "/api/v1/products"]);
  });

  it("asks again on the next request", async () => {
    await generateMetadata(props({}));
    memo.newRequest();
    await generateMetadata(props({}));
    expect(requested).toHaveLength(8);
  });
});
