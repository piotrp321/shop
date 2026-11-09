import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory } from "vue-router";
import { beforeEach, describe, expect, it, vi } from "vitest";
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
  return createAppRouter(userManager, createMemoryHistory());
}

describe("product routes", () => {
  beforeEach(() => mocks.get.mockReset());

  it("serves the list at /products and the form at /products/:id to every signed-in staff member", async () => {
    const router = setup(["support"]);

    await router.push("/products");
    expect(router.currentRoute.value.name).toBe("products");

    await router.push("/products/p-1");
    expect(router.currentRoute.value.name).toBe("product");
    expect(router.currentRoute.value.params.id).toBe("p-1");
  });

  it.each([["admin"], ["marketing"]] as const)("lets %s open /products/new", async (role) => {
    const router = setup([role]);

    await router.push("/products/new");

    expect(router.currentRoute.value.name).toBe("product-new");
  });

  it.each([[["support"]], [["warehouse"]], [[]]] as [StaffMe["roles"]][])("sends %j from /products/new to the list", async (roles) => {
    const router = setup(roles);

    await router.push("/products/new");

    expect(router.currentRoute.value.name).toBe("products");
    expect(router.currentRoute.value.path).toBe("/products");
  });

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const router = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["marketing"] }, response: { status: 200 } });

    await router.push("/products/new");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("product-new");
  });

  it("sends a staff member whose roles cannot be read to the list", async () => {
    const router = setup(null);
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

    await router.push("/products/new");

    expect(router.currentRoute.value.name).toBe("products");
  });

  it("does not treat /products/new as the product with the id new", async () => {
    const router = setup(["admin"]);

    await router.push("/products/new");

    expect(router.currentRoute.value.params.id).toBeUndefined();
  });
});

describe("promotion routes", () => {
  beforeEach(() => mocks.get.mockReset());

  it("serves the list at /promotions and the page of a promotion at /promotions/:id to every signed-in staff member", async () => {
    const router = setup(["support"]);

    await router.push("/promotions");
    expect(router.currentRoute.value.name).toBe("promotions");

    await router.push("/promotions/promo-1");
    expect(router.currentRoute.value.name).toBe("promotion");
    expect(router.currentRoute.value.params.id).toBe("promo-1");
  });

  it.each([["admin"], ["marketing"]] as const)("lets %s open /promotions/new", async (role) => {
    const router = setup([role]);

    await router.push("/promotions/new");

    expect(router.currentRoute.value.name).toBe("promotion-new");
  });

  it.each([[["support"]], [["warehouse"]], [[]]] as [StaffMe["roles"]][])("sends %j from /promotions/new to the list", async (roles) => {
    const router = setup(roles);

    await router.push("/promotions/new");

    expect(router.currentRoute.value.name).toBe("promotions");
    expect(router.currentRoute.value.path).toBe("/promotions");
  });

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const router = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["marketing"] }, response: { status: 200 } });

    await router.push("/promotions/new");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("promotion-new");
  });
});

describe("discount code routes", () => {
  beforeEach(() => mocks.get.mockReset());

  it("serves the list at /discount-codes and the page of a code at /discount-codes/:id to every signed-in staff member", async () => {
    const router = setup(["support"]);

    await router.push("/discount-codes");
    expect(router.currentRoute.value.name).toBe("discount-codes");

    await router.push("/discount-codes/code-1");
    expect(router.currentRoute.value.name).toBe("discount-code");
    expect(router.currentRoute.value.params.id).toBe("code-1");
  });

  it.each([["admin"], ["marketing"]] as const)("lets %s open /discount-codes/new", async (role) => {
    const router = setup([role]);

    await router.push("/discount-codes/new");

    expect(router.currentRoute.value.name).toBe("discount-code-new");
  });

  it.each([[["support"]], [["warehouse"]], [[]]] as [StaffMe["roles"]][])("sends %j from /discount-codes/new to the list", async (roles) => {
    const router = setup(roles);

    await router.push("/discount-codes/new");

    expect(router.currentRoute.value.name).toBe("discount-codes");
    expect(router.currentRoute.value.path).toBe("/discount-codes");
  });

  it("reads the roles from the API when the staff member is not loaded yet", async () => {
    const router = setup(null);
    mocks.get.mockResolvedValue({ data: { id: "u1", username: "anna", roles: ["marketing"] }, response: { status: 200 } });

    await router.push("/discount-codes/new");

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(router.currentRoute.value.name).toBe("discount-code-new");
  });
});
