import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { onMounted, ref } from "vue";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { clearLoginProblem } from "@/auth/loginProblem";
import AppLayout from "@/layout/AppLayout.vue";
import { primeVueOptions } from "@/primevue";

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn(), get: vi.fn() }));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));
vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

// docs/features/admin-design/spec.md, „Dostępność”: the focus never falls to <body> after a change of page; „Standard
// formularza i okien”: an action that moved to another route leaves the focus on the H1 of the new page.

const page = (title: string, extra = "") => ({ template: `<div><h1 tabindex="-1">${title}</h1>${extra}</div>` });

const routes = [
  {
    path: "/",
    component: AppLayout,
    children: [
      { path: "", name: "dashboard", component: page("Pulpit", `<a id="to-products" href="/products">Produkty</a>`) },
      { path: "products", name: "products", component: page("Produkty", `<button id="filter" type="button">Filtr</button>`) },
      { path: "products/:id", name: "product", component: page("Produkt", `<input id="field" />`) },
      // Targets of the menu that the tests do not visit.
      { path: "products/new", name: "product-new", component: page("Nowy produkt") },
      { path: "prices", name: "prices", component: page("Ceny") },
      { path: "inventory", name: "inventory", component: page("Stany") },
      { path: "discount-codes", name: "discount-codes", component: page("Kody") },
      // A screen that is not migrated yet: its H1 has no tabindex.
      { path: "orders", name: "orders", component: { template: "<div><h1>Zamówienia</h1></div>" } },
      // The H1 shows only after the data has loaded.
      {
        path: "promotions",
        name: "promotions",
        component: {
          setup() {
            const loaded = ref(false);
            onMounted(() => setTimeout(() => (loaded.value = true), 40));
            return { loaded };
          },
          template: `<div><input id="field" /><h1 v-if="loaded" tabindex="-1">Promocje</h1></div>`,
        },
      },
      // A view that moves the focus itself as it opens.
      {
        path: "system",
        name: "system",
        component: {
          setup() {
            onMounted(() => document.getElementById("own")?.focus());
          },
          template: `<div><h1 tabindex="-1">Stan systemu</h1><button id="own" type="button">Własny</button></div>`,
        },
      },
    ],
  },
];

describe("AppLayout: the focus after a change of page", () => {
  let wrapper: VueWrapper | undefined;
  let router: ReturnType<typeof createRouter>;

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
    router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path);
    wrapper = mount(RouterView, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] }, attachTo: document.body });
    await flushPromises();
  }

  const active = () => document.activeElement;
  const h1 = () => document.querySelector("main h1") as HTMLElement;
  const menuButton = () => document.body.querySelector<HTMLButtonElement>("button[aria-label=Menu]") as HTMLButtonElement;

  it("leaves the focus alone when the page is first loaded", async () => {
    await mountShell("/products");

    expect(active()).toBe(document.body);
  });

  it("moves the focus to the H1 of the new page when the link that had it is gone", async () => {
    await mountShell("/");
    const link = document.querySelector<HTMLElement>("#to-products")!;
    link.focus();

    await router.push("/products");
    await flushPromises();

    expect(h1().textContent).toBe("Produkty");
    expect(active()).toBe(h1());
  });

  it("moves the focus to the H1 when only a parameter of the route changes", async () => {
    await mountShell("/products/1");
    document.querySelector<HTMLElement>("#field")!.focus();

    await router.push("/products/2");
    await flushPromises();

    expect(active()).toBe(h1());
  });

  it("does not move the focus when only the query or the hash changes (filters, pager, search of a list)", async () => {
    await mountShell("/products");
    const filter = document.querySelector<HTMLElement>("#filter")!;
    filter.focus();

    await router.replace({ path: "/products", query: { page: "2" } });
    await flushPromises();
    await router.replace({ path: "/products", query: { page: "2" }, hash: "#top" });
    await flushPromises();

    expect(active()).toBe(filter);
  });

  it("does not move the focus when the navigation is cancelled by a guard (e.g. „Zostań”)", async () => {
    await mountShell("/products");
    const filter = document.querySelector<HTMLElement>("#filter")!;
    filter.focus();
    const remove = router.beforeEach(() => false);

    await router.push("/");
    await flushPromises();
    remove();

    expect(active()).toBe(filter);
    expect(router.currentRoute.value.name).toBe("products");
  });

  it("does not take the focus from a view that moved it itself", async () => {
    await mountShell("/");

    await router.push("/system");
    await flushPromises();

    expect(active()).toBe(document.getElementById("own"));
  });

  it("keeps the focus in the topbar (the search field) when the page changes from there", async () => {
    await mountShell("/");
    const search = document.querySelector<HTMLInputElement>("header input[type=search]")!;
    search.focus();

    await router.push({ name: "products", query: { q: "buty" } });
    await flushPromises();

    expect(active()).toBe(search);
  });

  it("puts the focus on <main> while the H1 is not there yet, and on the H1 once it appears", async () => {
    await mountShell("/");
    document.querySelector<HTMLElement>("#to-products")!.focus();

    await router.push("/promotions");
    await flushPromises();
    expect(active()).toBe(document.querySelector("main"));

    await vi.waitFor(() => expect(active()).toBe(h1()));
    expect(h1().textContent).toBe("Promocje");
  });

  it("does not take the focus from a field the staff member moved to while the page was loading", async () => {
    await mountShell("/");
    document.querySelector<HTMLElement>("#to-products")!.focus();

    await router.push("/promotions");
    await flushPromises();
    const field = document.querySelector<HTMLElement>("#field")!;
    field.focus();
    await new Promise((resolve) => setTimeout(resolve, 80));

    expect(h1().textContent).toBe("Promocje");
    expect(active()).toBe(field);
  });

  it("focuses the H1 of a screen that is not migrated yet, which has no tabindex of its own", async () => {
    await mountShell("/");
    document.querySelector<HTMLElement>("#to-products")!.focus();

    await router.push("/orders");
    await flushPromises();

    expect(h1().getAttribute("tabindex")).toBe("-1");
    expect(active()).toBe(h1());
  });

  it("puts the focus on the H1 of the new page, not on „Menu”, when an item chosen in the drawer changed the page", async () => {
    await mountShell("/");
    menuButton().click();
    await flushPromises();

    document.body.querySelector<HTMLAnchorElement>("[role=dialog] a[href='/products']")!.click();
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(document.body.querySelector("[role=dialog]")).toBeNull();
    expect(router.currentRoute.value.name).toBe("products");
    expect(active()).toBe(h1());
  });

  it("returns the focus to „Menu” when the drawer is left with the item of the page the staff member is on", async () => {
    await mountShell("/products");
    menuButton().click();
    await flushPromises();

    document.body.querySelector<HTMLAnchorElement>("[role=dialog] a[href='/products']")!.click();
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(active()).toBe(menuButton());
  });
});
