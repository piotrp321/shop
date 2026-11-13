import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { initialsOf, roleLabel } from "@/layout/account";
import AppTopbar from "@/layout/AppTopbar.vue";
import { type StaffMe, useAuthStore } from "@/stores/auth";

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: vi.fn() }) }));
vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));

const HINT = "Wpisz od 2 do 100 znaków.";
const empty = { template: "<div />" };

let wrapper: VueWrapper | undefined;

async function mountTopbar(staff: StaffMe | null = { id: "u1", username: "jan.admin", roles: ["admin", "support"] }, path = "/") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push(path);
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = staff;
  wrapper = mount(AppTopbar, { global: { plugins: [pinia, router] }, attachTo: document.body });
  return { wrapper, router };
}

async function search(topbar: VueWrapper, text: string) {
  const input = topbar.get("input[type=search]");
  await input.setValue(text);
  await topbar.get("form[role=search]").trigger("submit");
  await flushPromises();
}

describe("AppTopbar: search", () => {
  beforeEach(() => {
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  it("is a search form with a searchbox named „Szukaj produktu”", async () => {
    const { wrapper: topbar } = await mountTopbar();

    const input = topbar.get("form[role=search] input[type=search]");
    expect(input.attributes("aria-label") ?? topbar.get(`label[for='${input.attributes("id")}']`).text()).toBe("Szukaj produktu");
    expect(input.attributes("placeholder")).toBe("Szukaj produktu…");
  });

  it("goes to the product list with the trimmed phrase of 2 to 100 characters", async () => {
    const { wrapper: topbar, router } = await mountTopbar();

    await search(topbar, "  buty  ");

    expect(router.currentRoute.value.fullPath).toBe("/products?q=buty");
    expect(topbar.get("[role=status]").text()).toBe("");
  });

  it("goes to the product list also when it is already open, with the new phrase", async () => {
    const { wrapper: topbar, router } = await mountTopbar(undefined, "/products?q=namiot");

    await search(topbar, "buty");

    expect(router.currentRoute.value.fullPath).toBe("/products?q=buty");
  });

  it.each([
    ["two characters", "ab"],
    ["a hundred characters", "a".repeat(100)],
    ["Polish letters", "żółć"],
    ["two code points that are four UTF-16 units", "😀😀"],
    ["a hundred code points of two UTF-16 units each", "😀".repeat(100)],
  ])("accepts %s", async (_name, phrase) => {
    const { wrapper: topbar, router } = await mountTopbar();

    await search(topbar, phrase);

    expect(router.currentRoute.value.name).toBe("products");
    expect(router.currentRoute.value.query.q).toBe(phrase);
  });

  it.each([
    ["one character", "b"],
    ["one code point of two UTF-16 units", "😀"],
    ["a hundred and one characters", "a".repeat(101)],
    ["a hundred and one code points", "😀".repeat(101)],
    ["a letter among control characters", "\u0000b "],
  ])("does not navigate for %s: it says „Wpisz od 2 do 100 znaków.” and keeps the focus in the field", async (_name, phrase) => {
    const { wrapper: topbar, router } = await mountTopbar();
    (topbar.get("input[type=search]").element as HTMLInputElement).focus();

    await search(topbar, phrase);

    expect(router.currentRoute.value.name).toBe("dashboard");
    const hint = topbar.get("[role=status]");
    expect(hint.text()).toBe(HINT);
    expect(topbar.get("input[type=search]").attributes("aria-describedby")).toBe(hint.attributes("id"));
    expect(hint.attributes("id")).toBeTruthy();
    expect(document.activeElement).toBe(topbar.get("input[type=search]").element);
  });

  it.each([
    ["empty", ""],
    ["spaces", "   "],
    ["no-break and ideographic spaces", " 　"],
    ["control characters and line separators", "\u0000  \t"],
  ])("does nothing for a %s phrase: no navigation and no hint", async (_name, phrase) => {
    const { wrapper: topbar, router } = await mountTopbar();

    await search(topbar, phrase);

    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(topbar.get("[role=status]").text()).toBe("");
    expect(topbar.get("input[type=search]").attributes("aria-describedby")).toBeUndefined();
  });

  it("treats a control character inside the phrase as a separator", async () => {
    const { wrapper: topbar, router } = await mountTopbar();

    await search(topbar, "bu\u0000ty");

    expect(router.currentRoute.value.query.q).toBe("bu ty");
  });

  it("removes the hint when the staff member types again", async () => {
    const { wrapper: topbar } = await mountTopbar();
    await search(topbar, "b");
    expect(topbar.get("[role=status]").text()).toBe(HINT);

    await topbar.get("input[type=search]").setValue("bu");

    expect(topbar.get("[role=status]").text()).toBe("");
    expect(topbar.get("input[type=search]").attributes("aria-describedby")).toBeUndefined();
  });
});

describe("AppTopbar: store link, menu button and account", () => {
  beforeEach(() => {
    mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  it("links the store from VITE_STORE_URL in a new tab, with a name that says so", async () => {
    const { wrapper: topbar } = await mountTopbar();

    const link = topbar.get("a[target=_blank]");
    expect(link.attributes("href")).toBe("http://store.test");
    expect(link.attributes("rel")).toContain("noopener");
    expect(link.attributes("aria-label")).toBe("Sklep (otwiera się w nowej karcie)");
    expect(link.text()).toContain("Sklep");
  });

  it("has the button „Menu” only below 1024 px, which asks the layout to open the drawer", async () => {
    const { wrapper: topbar } = await mountTopbar();

    const button = topbar.get("button[aria-label=Menu]");
    expect(button.classes()).toContain("lg:hidden");

    await button.trigger("click");

    expect(topbar.emitted("open-menu")).toHaveLength(1);
  });

  it("shows the login, the initials and the Polish names of the roles", async () => {
    const { wrapper: topbar } = await mountTopbar();

    expect(topbar.get("[data-testid=username]").text()).toBe("jan.admin");
    expect(topbar.get("[data-testid=initials]").text()).toBe("JA");
    expect(topbar.get("[data-testid=roles]").text()).toBe("Administrator, Obsługa klienta");
  });

  it("signs out through „Wyloguj” in the account menu", async () => {
    const { wrapper: topbar } = await mountTopbar();

    await topbar.get("[data-testid=account]").trigger("keydown", { key: "Enter" });
    await flushPromises();
    const item = [...document.body.querySelectorAll("[role=menuitem]")].find((candidate) => candidate.textContent?.includes("Wyloguj"));
    expect(item).toBeDefined();
    (item as HTMLElement).click();
    await flushPromises();

    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
  });

  it("renders the account and „Wyloguj” without a staff member while it loads or when it cannot be read", async () => {
    const { wrapper: topbar } = await mountTopbar(null);

    expect(topbar.get("[data-testid=account]").text()).toContain("Konto");
    expect(topbar.find("[data-testid=roles]").exists()).toBe(false);

    await topbar.get("[data-testid=account]").trigger("keydown", { key: "Enter" });
    await flushPromises();
    expect(document.body.textContent).toContain("Wyloguj");
  });
});

describe("account helpers", () => {
  it.each([
    ["jan.admin", "JA"],
    ["anna", "AN"],
    ["anna-maria_nowak", "AM"],
    ["żaneta kowalska", "ŻK"],
    ["a", "A"],
    ["😀x", "😀X"],
    ["", "?"],
  ])("takes the initials of %s: %s", (username, initials) => {
    expect(initialsOf(username)).toBe(initials);
  });

  it.each([
    ["admin", "Administrator"],
    ["support", "Obsługa klienta"],
    ["warehouse", "Magazyn"],
    ["marketing", "Marketing"],
  ] as const)("names the role %s %s", (role, label) => {
    expect(roleLabel(role)).toBe(label);
  });
});
