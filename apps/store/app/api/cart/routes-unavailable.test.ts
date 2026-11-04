import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { getCartDeps } from "@/cart/deps";
import { VARIANT_ID } from "@/cart/test-support";
import { POST as addItem } from "./items/route";
import { DELETE as removeItem, PATCH as changeItem } from "./items/[variantId]/route";
import { GET as cart } from "./route";
import { GET as summary } from "./summary/route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

beforeEach(() => {
  // A configuration error (missing env) or anything else while setting up the dependencies.
  vi.mocked(getCartDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const request = (path: string, method = "GET") => new NextRequest(`http://0.0.0.0:3000${path}`, { method });
const context = { params: Promise.resolve({ variantId: VARIANT_ID }) };

describe("/api/cart/* without their dependencies", () => {
  it.each([
    ["GET /api/cart", () => cart(request("/api/cart"))],
    ["GET /api/cart/summary", () => summary(request("/api/cart/summary"))],
    ["POST /api/cart/items", () => addItem(request("/api/cart/items", "POST"))],
    ["PATCH /api/cart/items/[variantId]", () => changeItem(request(`/api/cart/items/${VARIANT_ID}`, "PATCH"), context)],
    ["DELETE /api/cart/items/[variantId]", () => removeItem(request(`/api/cart/items/${VARIANT_ID}`, "DELETE"), context)],
  ])("%s answers 503 with no-store", async (_name, handle) => {
    const response = await handle();

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });
});
