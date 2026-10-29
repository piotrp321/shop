import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { primeVueOptions } from "@/primevue";
import AuthCallbackView from "@/views/AuthCallbackView.vue";

const mocks = vi.hoisted(() => ({ signinRedirectCallback: vi.fn(), signinRedirect: vi.fn() }));

vi.mock("@/auth/userManager", () => ({
  getUserManager: () => ({
    signinRedirectCallback: mocks.signinRedirectCallback,
    signinRedirect: mocks.signinRedirect,
  }),
}));

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: { template: "<div />" } },
      { path: "/auth/callback", name: "auth-callback", component: AuthCallbackView },
    ],
  });
  await router.push("/auth/callback?code=c&state=s");
  const wrapper = mount(AuthCallbackView, { global: { plugins: [router, [PrimeVue, primeVueOptions]] } });
  await flushPromises();
  return { router, wrapper };
}

describe("AuthCallbackView", () => {
  beforeEach(() => {
    mocks.signinRedirectCallback.mockReset();
    mocks.signinRedirect.mockReset().mockResolvedValue(undefined);
  });

  it("completes the sign-in and returns to the page the user came from", async () => {
    mocks.signinRedirectCallback.mockResolvedValue({ state: { returnTo: "/" } });

    const { router } = await mountView();

    expect(mocks.signinRedirectCallback).toHaveBeenCalledTimes(1);
    expect(router.currentRoute.value.name).toBe("dashboard");
  });

  it("falls back to the dashboard when the state carries no valid target", async () => {
    mocks.signinRedirectCallback.mockResolvedValue({ state: { returnTo: "https://evil.example/" } });

    const { router } = await mountView();

    expect(router.currentRoute.value.fullPath).toBe("/");
  });

  it("shows an error with a retry button instead of redirecting again when the sign-in fails", async () => {
    mocks.signinRedirectCallback.mockRejectedValue(new Error("No matching state found in storage"));

    const { wrapper } = await mountView();

    expect(wrapper.find("[data-testid=callback-error] .p-message-error").exists()).toBe(true);
    expect(wrapper.text()).not.toContain("No matching state");
    expect(mocks.signinRedirect).not.toHaveBeenCalled();

    await wrapper.get("[data-testid=retry]").trigger("click");
    expect(mocks.signinRedirect).toHaveBeenCalledTimes(1);
  });
});
