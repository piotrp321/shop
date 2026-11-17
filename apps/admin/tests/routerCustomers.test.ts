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

describe("customer routes guard", () => {
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it.each(["admin", "support"] as const)("lets %s open the list and the card of a customer", async (role) => {
    const { router, history } = setup([role]);

    await router.push("/customers");
    expect(router.currentRoute.value.name).toBe("customers");

    await router.push("/customers/c-1");
    expect(router.currentRoute.value.name).toBe("customer");
    expect(router.currentRoute.value.params.customerId).toBe("c-1");
    expect(history.state.denied).toBeUndefined();
  });

  it.each([[["warehouse"]], [["marketing"]], [[]]] as [StaffMe["roles"]][])("sends %j from /customers to the dashboard, marked as denied", async (roles) => {
    const { router, history } = setup(roles);

    await router.push("/customers");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(router.currentRoute.value.path).toBe("/");
    expect(history.state.denied).toBe(true);
  });

  it("sends warehouse from /customers/:id to the dashboard, marked as denied", async () => {
    const { router, history } = setup(["warehouse"]);

    await router.push("/customers/c-1");

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["warehouse"] }, response: { status: 200 } });

    await router.push("/customers");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("lets the route through when /me cannot be read: the API checks the access and the view shows the 403", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

    await router.push("/customers/c-1");

    expect(router.currentRoute.value.name).toBe("customer");
    expect(history.state.denied).toBeUndefined();
  });
});
