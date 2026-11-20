import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory } from "vue-router";
import App from "@/App.vue";
import { clearLoginProblem } from "@/auth/loginProblem";
import { createAppRouter } from "@/router";

const MESSAGE = "Twoje konto nie ma roli w panelu. Skontaktuj się z administratorem.";
const ID = "5d0b6f4e-8c1a-4c55-9a43-7a0f8d3c2b11";

const mocks = vi.hoisted(() => ({
  userManager: { getUser: vi.fn(), signinRedirect: vi.fn(), signoutRedirect: vi.fn() },
  get: vi.fn(),
  roles: [] as string[],
}));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => mocks.userManager }));
// /me answers with the roles of the test; every other read is refused, as the API does for an account without a role.
vi.mock("@/api/client", () => ({
  getApiClient: () => ({ GET: mocks.get, POST: mocks.get, PUT: mocks.get, DELETE: mocks.get }),
}));

let wrapper: VueWrapper | undefined;

async function open(path: string) {
  // As in main.ts: pinia is active before the router resolves, so the guards of the routes read /me into the store the layout uses.
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createAppRouter(mocks.userManager, createMemoryHistory());
  await router.push(path);
  await router.isReady();
  wrapper = mount(App, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await flushPromises();
  return { wrapper, router };
}

describe("a staff member without a staff role", () => {
  beforeEach(() => {
    clearLoginProblem();
    sessionStorage.clear();
    mocks.roles = [];
    mocks.userManager.getUser.mockReset().mockResolvedValue({ expired: false });
    mocks.userManager.signinRedirect.mockReset().mockResolvedValue(undefined);
    mocks.userManager.signoutRedirect.mockReset().mockResolvedValue(undefined);
    mocks.get.mockReset().mockImplementation((path: string) =>
      Promise.resolve(
        path === "/api/v1/admin/me"
          ? { data: { id: "1", username: "nowy.pracownik", roles: mocks.roles }, response: { status: 200 } }
          : { error: { type: "about:blank", title: "Forbidden", status: 403 }, response: { status: 403 } },
      ),
    );
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  it.each([
    "/",
    "/products",
    "/products/new",
    `/products/${ID}`,
    "/prices",
    "/promotions",
    "/promotions/new",
    `/promotions/${ID}`,
    "/discount-codes",
    "/discount-codes/new",
    `/discount-codes/${ID}`,
    "/delivery",
    "/delivery/new",
    `/delivery/${ID}`,
    "/inventory",
    "/orders",
    `/orders/${ID}`,
    "/system",
    "/no-such-page",
  ])("sees the no-role message instead of the menu and the content on %s", async (path) => {
    const { wrapper: app } = await open(path);

    const main = app.get("main");
    const message = main.get("[data-testid=no-staff-role]");
    expect(message.attributes("role")).toBe("alert");
    expect(message.text()).toBe(MESSAGE);
    // Nothing of the page: one heading, the shell's own, and no view inside <main>.
    expect(main.findAll("h1")).toHaveLength(1);
    expect(main.get("h1").text()).toBe("Brak dostępu do panelu");
    expect(main.text().replace(/\s+/g, " ").trim()).toBe(`Brak dostępu do panelu ${MESSAGE}`);
    // No menu: neither the sidebar nor the drawer and its „Menu” button.
    expect(document.body.querySelector("nav[aria-label='Menu główne']")).toBeNull();
    expect(document.body.querySelector("button[aria-label=Menu]")).toBeNull();
    // The shell stays: the topbar with the account and the footer.
    expect(app.find("header [data-testid=account]").exists()).toBe(true);
    expect(app.find("footer").text()).toContain("Panel administracyjny");
  });

  it("can still sign out through „Wyloguj”", async () => {
    const { wrapper: app } = await open("/products");

    await app.get("[data-testid=account]").trigger("keydown", { key: "Enter" });
    await flushPromises();
    const item = [...document.body.querySelectorAll<HTMLElement>("[role=menuitem]")].find((candidate) =>
      candidate.textContent?.includes("Wyloguj"),
    );
    expect(item).toBeDefined();
    item?.click();
    await flushPromises();

    expect(mocks.userManager.signoutRedirect).toHaveBeenCalledTimes(1);
  });

  it("puts the focus on the heading of the message when the focus is free", async () => {
    const { wrapper: app } = await open("/products");

    expect(document.activeElement).toBe(app.get("main h1").element);
  });

  it.each(["/orders", "/system", "/delivery", "/products/new", "/promotions/new", "/discount-codes/new"])(
    "puts the focus on the heading of the message on a deep link to the guarded %s, whose guard read /me before the layout mounted",
    async (path) => {
      const { wrapper: app } = await open(path);

      expect(app.find("[data-testid=no-staff-role]").exists()).toBe(true);
      expect(document.activeElement).toBe(app.get("main h1").element);
    },
  );

  it("keeps the focus where the staff member put it in the topbar", async () => {
    let answer: (value: unknown) => void = () => {};
    mocks.get.mockImplementation((path: string) =>
      path === "/api/v1/admin/me"
        ? new Promise((resolve) => (answer = resolve))
        : Promise.resolve({ error: { status: 403 }, response: { status: 403 } }),
    );
    const { wrapper: app } = await open("/products");
    const search = app.get<HTMLInputElement>("header input[type=search]").element;
    search.focus();

    answer({ data: { id: "1", username: "nowy.pracownik", roles: [] }, response: { status: 200 } });
    await flushPromises();

    expect(app.find("[data-testid=no-staff-role]").exists()).toBe(true);
    expect(document.activeElement).toBe(search);
  });

  it("sees the menu and the page once the account has a staff role", async () => {
    mocks.roles = ["marketing"];
    const { wrapper: app } = await open("/products");

    expect(app.find("[data-testid=no-staff-role]").exists()).toBe(false);
    expect(document.body.querySelector("nav[aria-label='Menu główne']")).not.toBeNull();
    expect(app.get("main h1").text()).toBe("Produkty");
  });

  it("does not show the message while /me loads or when it cannot be read", async () => {
    mocks.get.mockImplementation((path: string) =>
      Promise.resolve(path === "/api/v1/admin/me" ? { error: { status: 503 }, response: { status: 503 } } : { error: { status: 403 }, response: { status: 403 } }),
    );
    const { wrapper: app } = await open("/products");

    expect(app.find("[data-testid=no-staff-role]").exists()).toBe(false);
    expect(app.find("[data-testid=staff-error]").exists()).toBe(true);
    expect(document.body.querySelector("nav[aria-label='Menu główne']")).not.toBeNull();
  });
});
