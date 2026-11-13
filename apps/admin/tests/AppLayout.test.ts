import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "vue-sonner";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { clearLoginProblem } from "@/auth/loginProblem";
import AppLayout from "@/layout/AppLayout.vue";
import { primeVueOptions } from "@/primevue";

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn(), get: vi.fn() }));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));
vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const MINUTE = 60_000;

async function mountLayout(path = "/", attach = false) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: "/",
        component: AppLayout,
        children: [
          { path: "", name: "dashboard", component: { template: "<div />" } },
          { path: "products", name: "products", component: { template: "<div />" } },
          { path: "products/new", name: "product-new", component: { template: "<div />" } },
          { path: "prices", name: "prices", component: { template: "<div />" } },
          { path: "promotions", name: "promotions", component: { template: "<div />" } },
          { path: "discount-codes", name: "discount-codes", component: { template: "<div />" } },
          { path: "inventory", name: "inventory", component: { template: "<div />" } },
          { path: "orders", name: "orders", component: { template: "<div />" } },
        ],
      },
    ],
  });
  await router.push(path);
  const wrapper = mount(RouterView, {
    global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] },
    attachTo: attach ? document.body : undefined,
  });
  return Object.assign(wrapper, { router });
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


describe("AppLayout shell", () => {
  let wrapper: (VueWrapper & { router: ReturnType<typeof createRouter> }) | undefined;

  beforeEach(() => {
    clearLoginProblem();
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
    mocks.get.mockReset().mockResolvedValue({ data: { id: "1", username: "admin", roles: ["admin"] } });
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  async function mountShell(path = "/") {
    wrapper = await mountLayout(path, true);
    await flushPromises();
    return wrapper;
  }

  function menuButton() {
    return document.body.querySelector<HTMLButtonElement>("button[aria-label=Menu]") as HTMLButtonElement;
  }

  function drawer() {
    return document.body.querySelector<HTMLElement>("[role=dialog]");
  }

  it("has „Przejdź do treści” as the first element in the Tab order, and it moves the focus to <main>", async () => {
    const layout = await mountShell();

    const first = layout.element.querySelector("a[href], button, input, select, textarea, [tabindex='0']");
    expect(first?.textContent?.trim()).toBe("Przejdź do treści");
    const main = layout.get("main");
    expect(main.attributes("tabindex")).toBe("-1");

    await layout.get("a[href='#main']").trigger("click");

    expect(document.activeElement).toBe(main.element);
    expect(layout.router.currentRoute.value.fullPath).toBe("/");
  });

  it("shows the skip link only when it has the focus", async () => {
    const layout = await mountShell();

    expect(layout.get("a[href='#main']").classes()).toContain("sr-only");
    expect(layout.get("a[href='#main']").classes()).toContain("focus:not-sr-only");
  });

  it("has the menu in the sidebar, the topbar, the main area and a footer with the year and the store link", async () => {
    const layout = await mountShell();

    expect(layout.findAll("nav[aria-label='Menu główne']")).toHaveLength(1);
    expect(layout.find("header").exists()).toBe(true);
    expect(layout.find("main").exists()).toBe(true);
    const footer = layout.get("footer");
    expect(footer.text()).toContain(`© ${new Date().getFullYear()} Worf · Panel administracyjny`);
    expect(footer.get("a").attributes("href")).toBe("http://store.test");
    expect(footer.get("a").text()).toBe("Sklep");
  });

  it("hides the sidebar below 1024 px with a class, so the drawer is the only menu there", async () => {
    const layout = await mountShell();

    const sidebar = layout.get("aside");
    expect(sidebar.classes()).toContain("hidden");
    expect(sidebar.classes()).toContain("lg:flex");
  });

  it("opens the menu as a drawer from the button „Menu”, with the focus inside it", async () => {
    await mountShell();

    menuButton().click();
    await flushPromises();

    const open = drawer();
    expect(open).not.toBeNull();
    expect(open?.querySelector("nav[aria-label='Menu główne']")).not.toBeNull();
    expect(open?.contains(document.activeElement)).toBe(true);
    expect(document.getElementById(open?.getAttribute("aria-labelledby") ?? "")?.textContent).toBe("Menu");
  });

  it("closes the drawer on Escape and gives the focus back to „Menu”", async () => {
    await mountShell();
    menuButton().focus();
    menuButton().click();
    await flushPromises();

    (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
    await flushPromises();

    expect(drawer()).toBeNull();
    expect(document.activeElement).toBe(menuButton());
  });

  it("closes the drawer when an item is chosen, and shows the page", async () => {
    const layout = await mountShell();
    menuButton().click();
    await flushPromises();

    drawer()?.querySelector<HTMLAnchorElement>("a[href='/promotions']")?.click();
    await flushPromises();

    expect(drawer()).toBeNull();
    expect(layout.router.currentRoute.value.name).toBe("promotions");
  });

  it("closes the drawer when the staff member chooses the item of the page they are on", async () => {
    await mountShell("/promotions");
    menuButton().click();
    await flushPromises();

    drawer()?.querySelector<HTMLAnchorElement>("a[href='/promotions']")?.click();
    await flushPromises();

    expect(drawer()).toBeNull();
  });

  it("keeps the focus inside the open drawer when Tab goes past its last control", async () => {
    await mountShell();
    menuButton().click();
    await flushPromises();
    const focusable = [...(drawer()?.querySelectorAll<HTMLElement>("a[href], button") ?? [])];
    focusable.at(-1)?.focus();

    document.activeElement?.dispatchEvent(new KeyboardEvent("keydown", { key: "Tab", bubbles: true }));
    await flushPromises();

    expect(drawer()?.contains(document.activeElement)).toBe(true);
  });

  it("puts every toast in a polite live region", async () => {
    await mountShell();

    toast.success("Produkt zapisany.");
    await flushPromises();

    const region = document.body.querySelector("section[aria-live=polite]");
    expect(region).not.toBeNull();
    expect(region?.textContent).toContain("Produkt zapisany.");
  });

  it("shows the error of loading the staff member as an alert", async () => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 503 } });

    const layout = await mountShell();

    const error = layout.get("[data-testid=staff-error]");
    expect(error.attributes("role")).toBe("alert");
    expect(error.text()).toBe("Nie udało się pobrać danych pracownika.");
  });
});
