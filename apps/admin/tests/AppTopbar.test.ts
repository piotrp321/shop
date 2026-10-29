import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";
import AppTopbar from "@/layout/AppTopbar.vue";
import { primeVueOptions } from "@/primevue";
import { useAuthStore } from "@/stores/auth";

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: vi.fn() }) }));
vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));

function mountTopbar() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const store = useAuthStore();
  store.staff = { id: "u1", username: "jan.admin", roles: ["admin", "support"] };
  return mount(AppTopbar, { global: { plugins: [pinia, [PrimeVue, primeVueOptions]] } });
}

describe("AppTopbar", () => {
  beforeEach(() => {
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
  });

  it("shows the login and the roles of the staff member from the auth store", () => {
    const wrapper = mountTopbar();

    expect(wrapper.text()).toContain("jan.admin");
    const roles = wrapper.findAll("[data-testid=role-tag]").map((tag) => tag.text());
    expect(roles).toEqual(["admin", "support"]);
  });

  it("signs out through signoutRedirect", async () => {
    const wrapper = mountTopbar();

    await wrapper.get("button").trigger("click");

    expect(wrapper.get("button").text()).toContain("Wyloguj");
    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
  });

  it("renders without a staff member while it loads", () => {
    const pinia = createPinia();
    setActivePinia(pinia);

    const wrapper = mount(AppTopbar, { global: { plugins: [pinia, [PrimeVue, primeVueOptions]] } });

    expect(wrapper.findAll("[data-testid=role-tag]")).toHaveLength(0);
    expect(wrapper.get("button").text()).toContain("Wyloguj");
  });
});
