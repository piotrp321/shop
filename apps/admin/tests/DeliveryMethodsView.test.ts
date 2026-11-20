import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import DeliveryMethodsView from "@/views/DeliveryMethodsView.vue";

type Method = components["schemas"]["DeliveryMethodResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const METHODS = "/api/v1/admin/delivery-methods";

function method(name: string, overrides: Partial<Method> = {}): Method {
  return {
    id: `id-${name}`,
    name,
    description: "Dostawa w 2–3 dni robocze",
    priceGross: 1999,
    freeFromGross: 29900,
    active: true,
    position: 10,
    trackingUrlTemplate: null,
    version: 1,
    ...overrides,
  };
}

function serve(items: Method[]) {
  mocks.get.mockImplementation(async () => ({ data: { items }, response: { status: 200 } }));
}

async function mountView(roles: StaffMe["roles"] = ["admin"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/delivery", name: "delivery", component: empty },
      { path: "/delivery/new", name: "delivery-method-new", component: empty },
      { path: "/delivery/:id", name: "delivery-method", component: empty },
    ],
  });
  await router.push("/delivery");
  const wrapper = mount(DeliveryMethodsView, { attachTo: document.body, global: { plugins: [pinia, router] } });
  await flushPromises();
  return wrapper;
}

const text = (value: string) => value.replace(/\s/g, " ");

describe("DeliveryMethodsView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  it("has the H1 „Dostawa” and the crumbs „Pulpit › Sklep › Dostawa”, and reads the list from the API without a page", async () => {
    serve([method("Kurier")]);
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Dostawa");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Sklep.*Dostawa$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Dostawa");
    expect(mocks.get).toHaveBeenCalledTimes(1);
    expect(mocks.get).toHaveBeenCalledWith(METHODS);
  });

  it("has the columns Nazwa, Cena, Darmowa od, Kolejność and Status, and the threshold and the position as lines of the cell Nazwa below 1024 px", async () => {
    serve([method("Kurier")]);
    wrapper = await mountView();

    const headers = wrapper.findAll("th[scope=col]").map((header) => header.text());
    expect(headers).toEqual(["Nazwa", "Cena", "Darmowa od", "Kolejność", "Status", "Akcje"]);
    const row = wrapper.get("[data-testid=delivery-row]");
    expect(text(row.get("[data-testid=line-freeFrom]").text())).toBe("Darmowa od: 299,00 zł");
    expect(row.get("[data-testid=line-position]").text()).toBe("Kolejność: 10");
  });

  it("shows a row per method in the order of the API: the active ones first, the inactive ones below with the label „Nieaktywna”", async () => {
    serve([
      method("Kurier", { priceGross: 1999, freeFromGross: 29900, position: 10 }),
      method("Paczkomat", { priceGross: 1299, freeFromGross: null, position: 20 }),
      method("Odbiór osobisty", { priceGross: 0, freeFromGross: null, position: 5, active: false }),
    ]);
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=delivery-row]");
    expect(rows.map((row) => row.get("[data-testid=method-link]").text())).toEqual(["Kurier", "Paczkomat", "Odbiór osobisty"]);
    const first = rows[0]!;
    expect(first.get("[data-testid=method-link]").attributes("href")).toBe("/delivery/id-Kurier");
    expect(text(first.get("[data-testid=method-price]").text())).toBe("19,99 zł");
    expect(text(first.get("[data-testid=method-free-from]").text())).toBe("299,00 zł");
    expect(first.get("[data-testid=method-position]").text()).toBe("10");
    expect(first.get("[data-testid=method-status]").text()).toBe("Aktywna");
    const second = rows[1]!;
    expect(second.get("[data-testid=method-free-from]").text()).toBe("—");
    const inactive = rows[2]!;
    expect(inactive.get("[data-testid=method-status]").text()).toBe("Nieaktywna");
    expect(text(inactive.get("[data-testid=method-price]").text())).toBe("0,00 zł");
    expect(rows.filter((row) => row.text().includes("Nieaktywna"))).toHaveLength(1);
  });

  it("says how many methods are active of the 20 allowed, counting only the active ones", async () => {
    serve([method("A"), method("B"), method("C", { active: false }), method("D", { active: false })]);
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=active-count]").text()).toBe("Aktywne: 2 z 20");
  });

  it("links „Dodaj metodę” to the form of a new method and „Edytuj” of a row to its form, labelled with the name of the method", async () => {
    serve([method("Kurier")]);
    wrapper = await mountView();

    const add = wrapper.get("[data-testid=add-delivery-method]");
    expect(add.text()).toBe("Dodaj metodę");
    expect(add.attributes("href")).toBe("/delivery/new");
    const edit = wrapper.get("[data-testid=delivery-row] a[aria-label='Edytuj: Kurier']");
    expect(edit.attributes("href")).toBe("/delivery/id-Kurier");
  });

  it("shows a skeleton while the list loads, with no counter and no table", async () => {
    mocks.get.mockImplementation(() => new Promise(() => {}));
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=list-loading]").exists()).toBe(true);
    expect(wrapper.find("[data-testid=delivery-row]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=active-count]").exists()).toBe(false);
  });

  it("says that there are no methods, with the reason to add one and a button, and counts 0 of 20", async () => {
    serve([]);
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak metod dostawy. Dodaj pierwszą, żeby sklep przyjmował zamówienia.");
    expect(wrapper.get("[data-testid=empty-add-delivery-method]").attributes("href")).toBe("/delivery/new");
    expect(wrapper.get("[data-testid=active-count]").text()).toBe("Aktywne: 0 z 20");
  });

  it.each([
    [500, "Nie udało się pobrać metod dostawy (HTTP 500)."],
    [503, "Nie udało się pobrać metod dostawy (HTTP 503)."],
  ])("shows the error of a %s with „Spróbuj ponownie”, and the retry loads the list", async (status, message) => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status } });
    wrapper = await mountView();

    const alert = wrapper.get("[data-testid=delivery-error]");
    expect(alert.attributes("role")).toBe("alert");
    expect(alert.text()).toContain(message);
    expect(wrapper.find("[data-testid=delivery-row]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=active-count]").exists()).toBe(false);

    serve([method("Kurier")]);
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-testid=delivery-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=delivery-row]")).toHaveLength(1);
  });

  it("shows a network failure as an error with the retry", async () => {
    mocks.get.mockRejectedValue(new Error("network"));
    wrapper = await mountView();

    const alert = wrapper.get("[data-testid=delivery-error]");
    expect(alert.text()).toContain("Nie udało się pobrać metod dostawy. Sprawdź połączenie z API.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(true);
  });

  it("says that the access is denied on a 403, with no retry and no counter", async () => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 403 } });
    wrapper = await mountView(["support"]);

    expect(wrapper.get("[data-testid=delivery-error]").text()).toBe("Nie masz dostępu do metod dostawy.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=active-count]").exists()).toBe(false);
  });
});
