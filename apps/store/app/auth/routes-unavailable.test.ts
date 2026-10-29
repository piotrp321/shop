import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { getAuthDeps } from "@/auth/deps";
import { GET as callback } from "./callback/route";
import { GET as login } from "./login/route";
import { POST as logout } from "./logout/route";
import { GET as session } from "./session/route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

beforeEach(() => {
  // A configuration error (missing env) or anything else while setting up the dependencies.
  vi.mocked(getAuthDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const request = (path: string, method = "GET") => new NextRequest(`http://0.0.0.0:3000${path}`, { method });

describe("/auth/* without their dependencies", () => {
  it.each([
    ["GET /auth/login", () => login(request("/auth/login"))],
    ["GET /auth/callback", () => callback(request("/auth/callback?code=a&state=b"))],
    ["GET /auth/session", () => session(request("/auth/session"))],
    ["POST /auth/logout", () => logout(request("/auth/logout", "POST"))],
  ])("%s answers 503 with no-store", async (_name, handle) => {
    const response = await handle();

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });
});
