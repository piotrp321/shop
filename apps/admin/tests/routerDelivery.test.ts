import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory } from "vue-router";
import { canManageDelivery } from "@/delivery/permissions";
import { createAppRouter } from "@/router";
import { type StaffMe, useAuthStore } from "@/stores/auth";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PATHS = ["/delivery", "/delivery/new", "/delivery/0192f0c4"];

function setup(roles: StaffMe["roles"] | null) {
  setActivePinia(createPinia());
  if (roles !== null) {
    useAuthStore().staff = { id: "u1", username: "anna", roles };
  }
  const userManager = {
    getUser: vi.fn().mockResolvedValue({ expired: false }),
    signinRedirect: vi.fn().mockResolvedValue(undefined),
  };
  const history = createMemoryHistory();
  return { router: createAppRouter(userManager, history), history, userManager };
}

describe("canManageDelivery", () => {
  it("is true only for the role admin, also among other roles", () => {
    expect(canManageDelivery({ id: "1", username: "a", roles: ["admin"] })).toBe(true);
    expect(canManageDelivery({ id: "1", username: "a", roles: ["marketing", "admin"] })).toBe(true);
    for (const roles of [["marketing"], ["support"], ["warehouse"], ["support", "warehouse"], []] as StaffMe["roles"][]) {
      expect(canManageDelivery({ id: "1", username: "a", roles })).toBe(false);
    }
    expect(canManageDelivery(null)).toBe(false);
  });
});

describe("delivery route guard", () => {
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it.each(PATHS)("lets admin open %s", async (path) => {
    const { router, history } = setup(["admin"]);

    await router.push(path);

    expect(router.currentRoute.value.path).toBe(path);
    expect(history.state.denied).toBeUndefined();
  });

  it("names the routes of the list, of the new method and of a method", async () => {
    const { router } = setup(["admin"]);

    await router.push("/delivery");
    expect(router.currentRoute.value.name).toBe("delivery");
    await router.push("/delivery/new");
    expect(router.currentRoute.value.name).toBe("delivery-method-new");
    await router.push("/delivery/0192f0c4");
    expect(router.currentRoute.value.name).toBe("delivery-method");
    expect(router.currentRoute.value.params.id).toBe("0192f0c4");
  });

  it.each(PATHS.flatMap((path) => [[["marketing"], path], [["support"], path], [["warehouse"], path], [[], path]] as [StaffMe["roles"], string][]))(
    "sends %j from %s to the dashboard, marked as denied",
    async (roles, path) => {
      const { router, history } = setup(roles);

      await router.push(path);

      expect(router.currentRoute.value.name).toBe("dashboard");
      expect(history.state.denied).toBe(true);
    },
  );

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["support"] }, response: { status: 200 } });

    await router.push("/delivery");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("lets the route through when /me cannot be read: the API refuses the others and the view says so", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

    await router.push("/delivery");

    expect(router.currentRoute.value.name).toBe("delivery");
    expect(history.state.denied).toBeUndefined();
  });

  it("protects the routes like every other: without a session the user is sent to sign in", async () => {
    const { router, userManager } = setup(["admin"]);
    userManager.getUser.mockResolvedValue(null);

    await router.push("/delivery");

    expect(userManager.signinRedirect).toHaveBeenCalledWith({ state: { returnTo: "/delivery" } });
    expect(router.currentRoute.value.name).not.toBe("delivery");
  });
});
