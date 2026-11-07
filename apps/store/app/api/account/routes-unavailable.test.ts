import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { getAuthDeps } from "@/auth/deps";
import { ORDER_ID } from "@/account/test-support";
import { GET as list } from "./orders/route";
import { GET as read } from "./orders/[orderId]/route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

beforeEach(() => {
  // A configuration error (missing env) or anything else while setting up the dependencies.
  vi.mocked(getAuthDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const request = (path: string) => new NextRequest(`http://0.0.0.0:3000${path}`);

describe("/api/account/* without their dependencies", () => {
  it.each([
    ["GET /api/account/orders", () => list(request("/api/account/orders"))],
    ["GET /api/account/orders/[orderId]", () => read(request(`/api/account/orders/${ORDER_ID}`), { params: Promise.resolve({ orderId: ORDER_ID }) })],
  ])("%s answers 503 with no-store", async (_name, handle) => {
    const response = await handle();

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });
});
