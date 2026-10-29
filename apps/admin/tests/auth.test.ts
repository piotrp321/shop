import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuthStore } from "@/stores/auth";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  signoutRedirect: vi.fn(),
}));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));
vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));

const staff = { id: "u1", username: "support", roles: ["support"] };

describe("auth store", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    mocks.get.mockReset();
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
  });

  it("starts without a staff member", () => {
    expect(useAuthStore().staff).toBeNull();
  });

  it("loads the staff member and the roles from GET /api/v1/admin/me", async () => {
    mocks.get.mockResolvedValue({ data: staff, response: { status: 200 } });
    const store = useAuthStore();

    await store.load();

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/me");
    expect(store.staff).toEqual(staff);
    expect(store.error).toBeNull();
  });

  it("keeps no staff member and sets an error when the API fails", async () => {
    mocks.get.mockResolvedValue({ error: { status: 503 }, response: { status: 503 } });
    const store = useAuthStore();

    await store.load();

    expect(store.staff).toBeNull();
    expect(store.error).not.toBeNull();
  });

  it("sets an error when the request itself fails", async () => {
    mocks.get.mockRejectedValue(new TypeError("Failed to fetch"));
    const store = useAuthStore();

    await store.load();

    expect(store.staff).toBeNull();
    expect(store.error).not.toBeNull();
  });

  it("logs out through signoutRedirect", async () => {
    const store = useAuthStore();

    await store.logout();

    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
  });
});
