import { flushPromises, mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { clearLoginProblem } from "@/auth/loginProblem";
import AppLayout from "@/layout/AppLayout.vue";
import { primeVueOptions } from "@/primevue";

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn(), get: vi.fn() }));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));
vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const MINUTE = 60_000;

async function mountLayout() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: "/", component: AppLayout, children: [{ path: "", name: "dashboard", component: { template: "<div />" } }] }],
  });
  await router.push("/");
  return mount(RouterView, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });
}

describe("AppLayout idle logout", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    clearLoginProblem();
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
    mocks.get.mockReset().mockResolvedValue({ data: { id: "1", username: "admin", roles: ["admin"] } });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("signs out after 15 minutes without activity", async () => {
    const wrapper = await mountLayout();

    await vi.advanceTimersByTimeAsync(15 * MINUTE);

    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it("keeps idle logout armed when the sign-out fails, so it tries again after another idle period", async () => {
    mocks.signoutRedirect.mockRejectedValueOnce(new Error("Keycloak unreachable"));
    const wrapper = await mountLayout();

    await vi.advanceTimersByTimeAsync(15 * MINUTE);
    await flushPromises();
    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(15 * MINUTE);

    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });
});
