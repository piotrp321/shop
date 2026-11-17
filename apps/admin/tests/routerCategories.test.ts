import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory } from "vue-router";
import { describe, expect, it, vi } from "vitest";
import { createAppRouter } from "@/router";
import { type StaffMe, useAuthStore } from "@/stores/auth";

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: vi.fn() }) }));

describe("the categories route", () => {
  it.each([["admin"], ["marketing"], ["support"], ["warehouse"]] as [StaffMe["roles"][number]][])("serves /categories to %s (the API checks who may change the tree)", async (role) => {
    setActivePinia(createPinia());
    useAuthStore().staff = { id: "u1", username: "anna", roles: [role] };
    const router = createAppRouter({ getUser: vi.fn().mockResolvedValue({ expired: false }), signinRedirect: vi.fn() }, createMemoryHistory());

    await router.push("/categories");

    expect(router.currentRoute.value.name).toBe("categories");
  });
});
