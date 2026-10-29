import { beforeEach, describe, expect, it, vi } from "vitest";
import { createApiClient } from "@/api/client";
import { clearLoginProblem, loginProblem } from "@/auth/loginProblem";
import { SIGNIN_ATTEMPT_KEY } from "@/auth/signinAttempt";

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
  beforeEach(() => {
    sessionStorage.clear();
    clearLoginProblem();
  });

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

  describe("redirect loop guard", () => {
    it("sets a one-shot marker (no token) before the redirect", async () => {
      const { client } = setup({ status: 401, token: "secret-token" });

      await client.GET("/api/v1/admin/me");

      const marker = sessionStorage.getItem(SIGNIN_ATTEMPT_KEY);
      expect(marker).toMatch(/^\d+$/);
      expect(JSON.stringify({ ...sessionStorage })).not.toContain("secret-token");
      expect(loginProblem.value).toBeNull();
    });

    it("does not redirect again on a 401 after the redirect and reports a problem instead", async () => {
      sessionStorage.setItem(SIGNIN_ATTEMPT_KEY, String(Date.now()));
      const { client, userManager } = setup({ status: 401 });

      await client.GET("/api/v1/admin/me");
      await client.GET("/api/v1/admin/dashboard");

      expect(userManager.signinRedirect).not.toHaveBeenCalled();
      expect(loginProblem.value).toContain("Nie udało się potwierdzić sesji");
    });

    it("keeps one redirect per login cycle across page loads: the second 401 shows the problem", async () => {
      const first = setup({ status: 401 });
      await first.client.GET("/api/v1/admin/me");
      expect(first.userManager.signinRedirect).toHaveBeenCalledTimes(1);

      // The page loads again after Keycloak sent the user back; the API still rejects the token.
      const second = setup({ status: 401 });
      await second.client.GET("/api/v1/admin/me");

      expect(second.userManager.signinRedirect).not.toHaveBeenCalled();
      expect(loginProblem.value).not.toBeNull();
    });

    it("clears the marker on the first successful response, so a later 401 redirects again", async () => {
      sessionStorage.setItem(SIGNIN_ATTEMPT_KEY, String(Date.now()));
      const ok = setup({ status: 200 });
      await ok.client.GET("/api/v1/admin/me");
      expect(sessionStorage.getItem(SIGNIN_ATTEMPT_KEY)).toBeNull();

      const expired = setup({ status: 401 });
      await expired.client.GET("/api/v1/admin/me");

      expect(expired.userManager.signinRedirect).toHaveBeenCalledTimes(1);
    });

    it("does not clear the marker on a 403 or 500 response", async () => {
      sessionStorage.setItem(SIGNIN_ATTEMPT_KEY, "1");
      await setup({ status: 403 }).client.GET("/api/v1/admin/me");
      await setup({ status: 500 }).client.GET("/api/v1/admin/me");

      expect(sessionStorage.getItem(SIGNIN_ATTEMPT_KEY)).toBe("1");
    });

    it("still starts exactly one redirect for concurrent 401s", async () => {
      const { client, userManager } = setup({ status: 401 });

      await Promise.all([client.GET("/api/v1/admin/me"), client.GET("/api/v1/admin/dashboard")]);

      expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
    });

    it("reports a problem, drops the marker and allows a retry when the redirect itself fails", async () => {
      const { client, userManager } = setup({ status: 401 });
      userManager.signinRedirect.mockRejectedValueOnce(new Error("Keycloak unreachable"));

      const { response } = await client.GET("/api/v1/admin/me");

      expect(response.status).toBe(401);
      expect(loginProblem.value).toContain("serwerem logowania");
      expect(loginProblem.value).not.toContain("unreachable");
      expect(sessionStorage.getItem(SIGNIN_ATTEMPT_KEY)).toBeNull();

      await client.GET("/api/v1/admin/me");
      expect(userManager.signinRedirect).toHaveBeenCalledTimes(2);
    });
  });
});
