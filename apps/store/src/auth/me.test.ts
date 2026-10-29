import { beforeEach, describe, expect, it, vi } from "vitest";
import { createApiClient } from "@/api/client";
import { fetchMe } from "./me";

vi.mock("@/api/client", () => ({ createApiClient: vi.fn() }));

const GET = vi.fn();

beforeEach(() => {
  GET.mockReset();
  vi.mocked(createApiClient).mockReturnValue({ GET } as unknown as ReturnType<typeof createApiClient>);
});

const reply = (status: number, data?: object) => GET.mockResolvedValue({ data, response: new Response(null, { status }) });

describe("fetchMe", () => {
  it("calls GET /api/v1/me at the internal API URL with the access token", async () => {
    reply(200, { id: "1", email: "jan.kowalski@example.com", name: "Jan Kowalski" });

    expect(await fetchMe("http://api", "access-1")).toEqual({ kind: "ok", name: "Jan Kowalski" });
    expect(createApiClient).toHaveBeenCalledWith({ baseUrl: "http://api", accessToken: "access-1" });
    expect(GET).toHaveBeenCalledWith("/api/v1/me");
  });

  it("falls back to the e-mail when the account has no name", async () => {
    reply(200, { id: "1", email: "jan.kowalski@example.com", name: "" });
    expect(await fetchMe("http://api", "access-1")).toEqual({ kind: "ok", name: "jan.kowalski@example.com" });
  });

  it("reports 401 as unauthorized", async () => {
    reply(401);
    expect(await fetchMe("http://api", "access-1")).toEqual({ kind: "unauthorized" });
  });

  it.each([[503], [500], [403]])("reports %s as unavailable", async (status) => {
    reply(status);
    expect(await fetchMe("http://api", "access-1")).toEqual({ kind: "unavailable" });
  });
});
