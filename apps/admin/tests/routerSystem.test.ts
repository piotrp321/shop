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

describe("system route guard", () => {
  // No return value: vitest would call the returned mock as a cleanup hook after each test.
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it("lets admin open /system", async () => {
    const { router, history } = setup(["admin"]);

    await router.push("/system");

    expect(router.currentRoute.value.name).toBe("system");
    expect(history.state.denied).toBeUndefined();
  });

  it.each([[["marketing"]], [["support"]], [["warehouse"]], [[]]] as [StaffMe["roles"]][])(
    "sends %j from /system to the dashboard, marked as denied",
    async (roles) => {
      const { router, history } = setup(roles);

      await router.push("/system");

      expect(router.currentRoute.value.name).toBe("dashboard");
      expect(router.currentRoute.value.path).toBe("/");
      expect(history.state.denied).toBe(true);
    },
  );

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["marketing"] }, response: { status: 200 } });

    await router.push("/system");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(history.state.denied).toBe(true);
  });

  it("lets the route through when /me cannot be read: the API gives no system data and the view says so", async () => {
    const { router, history } = setup(null);
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

    await router.push("/system");

    expect(router.currentRoute.value.name).toBe("system");
    expect(history.state.denied).toBeUndefined();
  });

  it("lets the route through when the request for /me fails", async () => {
    const { router } = setup(null);
    mocks.get.mockImplementation(async () => {
      throw new Error("network");
    });

    await router.push("/system");

    expect(router.currentRoute.value.name).toBe("system");
  });
});
