import { describe, expect, it, vi } from "vitest";
import { createApiClient } from "@/api/client";

function setup(options: { token?: string | null; status?: number } = {}) {
  const userManager = {
    getUser: vi.fn().mockResolvedValue(options.token === null ? null : { access_token: options.token ?? "token-1" }),
    signinRedirect: vi.fn().mockResolvedValue(undefined),
  };
  const fetchMock = vi.fn<(request: Request) => Promise<Response>>(
    async () =>
      new Response(JSON.stringify({ id: "1", username: "admin", roles: ["admin"] }), {
        status: options.status ?? 200,
        headers: { "content-type": "application/json" },
      }),
  );
  const client = createApiClient({ baseUrl: "http://api.test", userManager, fetch: fetchMock });
  return { client, userManager, fetchMock };
}

function sentRequest(fetchMock: ReturnType<typeof setup>["fetchMock"], index = 0): Request {
  const request = fetchMock.mock.calls[index]?.[0];
  if (!request) {
    throw new Error("No request was sent.");
  }
  return request;
}

describe("API client", () => {
  it("sends the access token of the signed-in user as a bearer token", async () => {
    const { client, fetchMock, userManager } = setup({ token: "abc.def" });

    const { data } = await client.GET("/api/v1/admin/me");

    expect(data?.username).toBe("admin");
    expect(sentRequest(fetchMock).url).toBe("http://api.test/api/v1/admin/me");
    expect(sentRequest(fetchMock).headers.get("Authorization")).toBe("Bearer abc.def");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it("sends no Authorization header without a signed-in user", async () => {
    const { client, fetchMock } = setup({ token: null });

    await client.GET("/api/v1/admin/me");

    expect(sentRequest(fetchMock).headers.has("Authorization")).toBe(false);
  });

  it("starts the sign-in redirect exactly once on a 401 response", async () => {
    const { client, userManager } = setup({ status: 401 });

    const { response } = await client.GET("/api/v1/admin/me");

    expect(response.status).toBe(401);
    expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
  });

  it("starts a single redirect when several requests get 401 at once or one after another", async () => {
    const { client, userManager } = setup({ status: 401 });

    await Promise.all([client.GET("/api/v1/admin/me"), client.GET("/api/v1/admin/dashboard"), client.GET("/api/v1/admin/me")]);
    await client.GET("/api/v1/admin/dashboard");

    expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
  });

  it.each([403, 500, 503])("does not redirect on a %i response", async (status) => {
    const { client, userManager } = setup({ status });

    await client.GET("/api/v1/admin/me");

    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });
});
