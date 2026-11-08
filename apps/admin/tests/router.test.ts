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

  it("serves the price list at /prices to a signed-in user", async () => {
    const { router, userManager } = setup({ expired: false });

    await router.push("/prices");

    expect(router.currentRoute.value.name).toBe("prices");
    expect(router.currentRoute.value.path).toBe("/prices");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it("protects /prices like every other route", async () => {
    const { router, userManager } = setup(null);

    await router.push("/prices");

    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: "/prices" } });
    expect(router.currentRoute.value.name).not.toBe("prices");
  });

  it("serves the stock list at /inventory to a signed-in user", async () => {
    const { router, userManager } = setup({ expired: false });

    await router.push("/inventory");

    expect(router.currentRoute.value.name).toBe("inventory");
    expect(router.currentRoute.value.path).toBe("/inventory");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it("protects /inventory like every other route", async () => {
    const { router, userManager } = setup(null);

    await router.push("/inventory");

    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: "/inventory" } });
    expect(router.currentRoute.value.name).not.toBe("inventory");
  });

  it("serves the order list at /orders to a signed-in user", async () => {
    const { router, userManager } = setup({ expired: false });

    await router.push("/orders");

    expect(router.currentRoute.value.name).toBe("orders");
    expect(router.currentRoute.value.path).toBe("/orders");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it("serves the order detail at /orders/:orderId with the id as a parameter", async () => {
    const { router, userManager } = setup({ expired: false });

    await router.push("/orders/0192f0c4-0000-7000-8000-000000000001");

    expect(router.currentRoute.value.name).toBe("order-detail");
    expect(router.currentRoute.value.params.orderId).toBe("0192f0c4-0000-7000-8000-000000000001");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();
  });

  it.each(["/orders", "/orders/0192f0c4"])("protects %s like every other route", async (path) => {
    const { router, userManager } = setup(null);

    await router.push(path);

    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: path } });
    expect(router.currentRoute.value.name).not.toBe("orders");
    expect(router.currentRoute.value.name).not.toBe("order-detail");
  });

  it.each(["/discount-codes", "/discount-codes/new", "/discount-codes/0192f0c4"])("protects %s like every other route", async (path) => {
    const { router, userManager } = setup(null);

    await router.push(path);

    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: path } });
    expect(router.currentRoute.value.name).not.toBe("discount-codes");
    expect(router.currentRoute.value.name).not.toBe("discount-code");
    expect(router.currentRoute.value.name).not.toBe("discount-code-new");
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
