import { createMemoryHistory } from "vue-router";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { clearLoginProblem, loginProblem } from "@/auth/loginProblem";
import { createAppRouter } from "@/router";

function setup(user: { expired: boolean } | null) {
  const userManager = {
    getUser: vi.fn().mockResolvedValue(user),
    signinRedirect: vi.fn().mockResolvedValue(undefined),
  };
  const router = createAppRouter(userManager, createMemoryHistory());
  return { router, userManager };
}

describe("router guard", () => {
  beforeEach(() => clearLoginProblem());

  it("starts the sign-in redirect for a visitor without a user and keeps the target path", async () => {
    const { router, userManager } = setup(null);

    await router.push("/");

    expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: "/" } });
    expect(router.currentRoute.value.name).not.toBe("dashboard");
  });

  it("treats an expired user as signed out", async () => {
    const { router, userManager } = setup({ expired: true });

    await router.push("/");

    expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
    expect(router.currentRoute.value.name).not.toBe("dashboard");
  });

  it("lets a signed-in user through", async () => {
    const { router, userManager } = setup({ expired: false });

    await router.push("/");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it("does not protect the callback route", async () => {
    const { router, userManager } = setup(null);

    await router.push("/auth/callback?code=c&state=s");

    expect(router.currentRoute.value.name).toBe("auth-callback");
    expect(userManager.getUser).not.toHaveBeenCalled();
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it.each(["/auth/callback-evil", "/auth/callback/extra", "/auth", "/unknown"])(
    "protects %s, which only resembles the callback route",
    async (path) => {
      const { router, userManager } = setup(null);

      await router.push(path);

      expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
      expect(router.currentRoute.value.name).not.toBe("auth-callback");
    },
  );

  it("sends an unknown path of a signed-in user to the dashboard", async () => {
    const { router } = setup({ expired: false });

    await router.push("/unknown");

    expect(router.currentRoute.value.name).toBe("dashboard");
  });

  it("reports a problem instead of leaving a blank page when the sign-in redirect fails", async () => {
    const { router, userManager } = setup(null);
    userManager.signinRedirect.mockRejectedValue(new Error("Keycloak unreachable"));

    await router.push("/");

    expect(loginProblem.value).toContain("serwerem logowania");
    expect(loginProblem.value).not.toContain("unreachable");
    expect(router.currentRoute.value.name).not.toBe("dashboard");
  });

  it("reports a problem when the user cannot be read", async () => {
    const { router, userManager } = setup(null);
    userManager.getUser.mockRejectedValue(new Error("boom"));

    await router.push("/");

    expect(loginProblem.value).not.toBeNull();
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });
});
