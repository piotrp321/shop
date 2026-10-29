import { mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { describe, expect, it, vi } from "vitest";
import { createMemoryHistory } from "vue-router";
import App from "@/App.vue";
import { primeVueOptions } from "@/primevue";
import { createAppRouter } from "@/router";

const userManager = vi.hoisted(() => ({
  getUser: vi.fn(),
  signinRedirect: vi.fn(),
  signoutRedirect: vi.fn(),
}));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => userManager }));
vi.mock("@/api/client", () => ({
  getApiClient: () => ({
    GET: vi.fn().mockResolvedValue({ error: { status: 503 }, response: { status: 503 } }),
  }),
}));

describe("App", () => {
  it("mounts with PrimeVue, Pinia and the router and renders the layout around the dashboard", async () => {
    userManager.getUser.mockResolvedValue({ expired: false });
    const router = createAppRouter(userManager, createMemoryHistory());
    await router.push("/");
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });

    expect(wrapper.find("nav").text()).toContain("Dashboard");
    expect(wrapper.find("header button").text()).toContain("Wyloguj");
    wrapper.unmount();
  });
});
