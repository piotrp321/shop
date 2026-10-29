import { afterEach, describe, expect, it, vi } from "vitest";
import { loadAccountSession } from "./client";

afterEach(() => {
  vi.unstubAllGlobals();
});

const stubFetch = (implementation: () => Promise<Response>) => {
  const fetch = vi.fn(implementation);
  vi.stubGlobal("fetch", fetch);
  return fetch;
};

describe("loadAccountSession", () => {
  it("asks the store's own /auth/session, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ name: "Jan" }));

    expect(await loadAccountSession()).toEqual({ status: "ok", session: { name: "Jan" } });
    expect(fetch).toHaveBeenCalledWith("/auth/session", { credentials: "same-origin", cache: "no-store" });
  });

  it("passes null through as signed out", async () => {
    stubFetch(async () => Response.json(null));
    expect(await loadAccountSession()).toEqual({ status: "ok", session: null });
  });

  it("reports an error status as unavailable", async () => {
    stubFetch(async () => Response.json(null, { status: 503 }));
    expect(await loadAccountSession()).toEqual({ status: "unavailable" });
  });

  it("reports a network error as unavailable", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });
    expect(await loadAccountSession()).toEqual({ status: "unavailable" });
  });
});
