import { mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory } from "vue-router";
import App from "@/App.vue";
import { clearLoginProblem, reportLoginProblem } from "@/auth/loginProblem";
import { SIGNIN_ATTEMPT_KEY } from "@/auth/signinAttempt";
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
  beforeEach(() => {
    clearLoginProblem();
    sessionStorage.clear();
    userManager.signinRedirect.mockReset().mockResolvedValue(undefined);
  });

  it("shows a login problem as an error Message with a manual sign-in action", async () => {
    userManager.getUser.mockResolvedValue({ expired: false });
    const router = createAppRouter(userManager, createMemoryHistory());
    await router.push("/auth/callback");
    reportLoginProblem("Nie udało się potwierdzić sesji.");
    sessionStorage.setItem(SIGNIN_ATTEMPT_KEY, "1");

    const wrapper = mount(App, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });

    expect(wrapper.get("[data-testid=login-problem]").attributes("role")).toBe("alert");
    expect(wrapper.get("[data-testid=login-problem]").text()).toContain("Nie udało się potwierdzić sesji.");
    expect(wrapper.get("[data-testid=login-problem-card]").text()).toContain("Worf");
    expect(userManager.signinRedirect).not.toHaveBeenCalled();

    await wrapper.get("[data-testid=login-retry]").trigger("click");

    expect(sessionStorage.getItem(SIGNIN_ATTEMPT_KEY)).toBeNull();
    expect(userManager.signinRedirect).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it("mounts with PrimeVue, Pinia and the router and renders the layout around the dashboard", async () => {
    userManager.getUser.mockResolvedValue({ expired: false });
    const router = createAppRouter(userManager, createMemoryHistory());
    await router.push("/");
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });

    expect(wrapper.find("nav").text()).toContain("Pulpit");
    expect(wrapper.find("header [data-testid=account]").exists()).toBe(true);
    wrapper.unmount();
  });
});
