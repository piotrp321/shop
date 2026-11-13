import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory } from "vue-router";
import { createAppRouter } from "@/router";
import { type StaffMe, useAuthStore } from "@/stores/auth";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

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
  return { router: createAppRouter(userManager, history), history };
}

describe("order routes guard", () => {
  // No return value: vitest would call the returned mock as a cleanup hook after each test.
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it.each(["admin", "support", "warehouse"] as const)("lets %s open the list and the detail of the orders", async (role) => {
    const { router, history } = setup([role]);

    await router.push("/orders");
    expect(router.currentRoute.value.name).toBe("orders");

    await router.push("/orders/o-1");
    expect(router.currentRoute.value.name).toBe("order-detail");
    expect(router.currentRoute.value.params.orderId).toBe("o-1");
    expect(history.state.denied).toBeUndefined();
  });

  it.each([[["marketing"]], [[]]] as [StaffMe["roles"]][])("sends %j from /orders to the dashboard, marked as denied", async (roles) => {
    const { router, history } = setup(roles);

    await router.push("/orders");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(router.currentRoute.value.path).toBe("/");
    expect(history.state.denied).toBe(true);
  });

  it("sends marketing from /orders/:id to the dashboard, marked as denied", async () => {
    const { router, history } = setup(["marketing"]);

    await router.push("/orders/o-1");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("does not mark the dashboard as denied when it is opened directly", async () => {
    const { router, history } = setup(["marketing"]);

    await router.push("/");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBeUndefined();
  });

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["marketing"] }, response: { status: 200 } });

    await router.push("/orders");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("lets the route through when /me cannot be read: the API checks the access and the view shows the 403", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

    await router.push("/orders/o-1");

    expect(router.currentRoute.value.name).toBe("order-detail");
    expect(history.state.denied).toBeUndefined();
  });

  it("lets the route through when the request for /me fails", async () => {
    const { router } = setup(null);
    mocks.get.mockImplementation(async () => {
      throw new Error("network");
    });

    await router.push("/orders");

    expect(router.currentRoute.value.name).toBe("orders");
  });
});
