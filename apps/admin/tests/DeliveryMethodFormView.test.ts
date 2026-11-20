import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { useAuthStore } from "@/stores/auth";
import DeliveryMethodFormView from "@/views/DeliveryMethodFormView.vue";

type Method = components["schemas"]["DeliveryMethodResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

function method(overrides: Partial<Method> = {}): Method {
  return {
    id: "method-1",
    name: "Kurier",
    description: "Dostawa w 2–3 dni robocze",
    priceGross: 1999,
    freeFromGross: 29900,
    active: true,
    position: 10,
    trackingUrlTemplate: "https://tracking.example.com/{numer}",
    version: 3,
    ...overrides,
  };
}

function conflict(kind: string, detail: string) {
  return {
    data: undefined,
    error: { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail },
    response: { status: 409 },
  };
}

function violations(...items: [string, string][]) {
  return {
    data: undefined,
    error: { status: 422, violations: items.map(([propertyPath, title]) => ({ propertyPath, title })) },
    response: { status: 422 },
  };
}

function serve(data: Method | null = method()) {
  mocks.get.mockImplementation(async () => (data ? { data, response: { status: 200 } } : { data: undefined, error: {}, response: { status: 404 } }));
}

async function mountForm(path: string) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles: ["admin"] };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/delivery", name: "delivery", component: empty },
      { path: "/delivery/new", name: "delivery-method-new", component: DeliveryMethodFormView },
      { path: "/delivery/:id", name: "delivery-method", component: DeliveryMethodFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount({ components: { RouterView }, template: "<RouterView />" }, { attachTo: document.body, global: { plugins: [pinia, router] } });
  await flushPromises();
  return { wrapper, router };
}

describe("DeliveryMethodFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let successToast: Mock<typeof toast.success>;

  beforeEach(() => {
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
    vi.restoreAllMocks();
  });

  async function open(path = "/delivery/method-1", data?: Method | null) {
    if (data !== undefined) {
      serve(data);
    }
    mounted = await mountForm(path);
    return mounted.wrapper;
  }

  type Wrapper = Awaited<ReturnType<typeof open>>;

  const click = async (wrapper: Wrapper, testid: string) => {
    await wrapper.get(`[data-testid=${testid}]`).trigger("click");
    await flushPromises();
  };
  const input = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).element as HTMLInputElement;
  const isActive = (wrapper: Wrapper) => wrapper.get("[data-testid=active]").attributes("aria-checked") === "true";
  const saveBar = () => document.body.querySelector("section[aria-label='Zapis formularza']");
  const heading = (level: "h1" | "h2", text?: string) =>
    [...document.body.querySelectorAll(level)].find((candidate) => text === undefined || candidate.textContent?.trim() === text);
  const labelled = (label: string) => {
    const element = [...document.body.querySelectorAll("label")].find((candidate) => candidate.textContent?.trim() === label);
    expect(element, `a label „${label}”`).toBeDefined();
    return element!.htmlFor ? document.getElementById(element!.htmlFor) : null;
  };

  async function fillNew(wrapper: Wrapper) {
    await wrapper.get("[data-testid=name-input]").setValue("Paczkomat");
    await wrapper.get("[data-testid=description-input]").setValue("Dostawa w 1–2 dni");
    await wrapper.get("[data-testid=price-input]").setValue("12,99");
    await wrapper.get("[data-testid=free-from-input]").setValue("250");
    await wrapper.get("[data-testid=position-input]").setValue("20");
    await wrapper.get("[data-testid=tracking-input]").setValue("https://paczka.example.com/{numer}");
  }

  describe("the layout", () => {
    it("has the cards of the method, the price, the availability and the tracking, and the crumbs of the list", async () => {
      const wrapper = await open();

      expect([...document.body.querySelectorAll("h2")].map((title) => title.textContent?.trim())).toEqual([
        "Metoda dostawy",
        "Cena",
        "Dostępność",
        "Śledzenie przesyłki",
      ]);
      const crumbs = wrapper.get("nav[aria-label=Okruszki]");
      expect(crumbs.text()).toMatch(/^Pulpit.*Sklep.*Dostawa.*Kurier$/);
      expect(crumbs.get("a[href='/delivery']").text()).toBe("Dostawa");
      expect(wrapper.get("h1").text()).toBe("Kurier");
    });

    it("labels every control and says in the hint of the link where the number goes", async () => {
      const wrapper = await open("/delivery/new");

      expect(wrapper.get("h1").text()).toBe("Nowa metoda dostawy");
      const controls: [string, string][] = [
        ["Nazwa", "name-input"],
        ["Opis (czas dostawy)", "description-input"],
        ["Cena brutto (zł)", "price-input"],
        ["Darmowa od (zł, opcjonalnie)", "free-from-input"],
        ["Aktywna", "active"],
        ["Kolejność", "position-input"],
        ["Link śledzenia (opcjonalnie)", "tracking-input"],
      ];
      for (const [label, testid] of controls) {
        expect(labelled(label), label).toBe(wrapper.get(`[data-testid=${testid}]`).element);
      }
      const tracking = wrapper.get("[data-testid=tracking-input]");
      const hint = document.getElementById(tracking.attributes("aria-describedby")!.split(" ")[0]!);
      expect(hint?.textContent?.trim()).toBe("Adres https:// z {numer} w miejscu numeru przesyłki");
    });
  });

  describe("a new method", () => {
    it("starts with an empty form, active, and shows the save bar with „Anuluj” at once without reading the API", async () => {
      const wrapper = await open("/delivery/new");

      expect(input(wrapper, "name-input").value).toBe("");
      expect(input(wrapper, "price-input").value).toBe("");
      expect(input(wrapper, "free-from-input").value).toBe("");
      expect(isActive(wrapper)).toBe(true);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(saveBar()?.textContent).toContain("Nowa metoda dostawy");
      expect(wrapper.find("[data-testid=cancel]").exists()).toBe(true);
    });

    it("sends the amounts in grosze, the other fields as typed, and goes back to the list with a toast", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue({ data: method({ id: "method-2", name: "Paczkomat" }), response: { status: 201 } });

      await click(wrapper, "save");

      expect(mocks.post).toHaveBeenCalledWith("/api/v1/admin/delivery-methods", {
        body: {
          name: "Paczkomat",
          description: "Dostawa w 1–2 dni",
          priceGross: 1299,
          freeFromGross: 25000,
          active: true,
          position: 20,
          trackingUrlTemplate: "https://paczka.example.com/{numer}",
        },
      });
      expect(successToast).toHaveBeenCalledWith("Metoda dostawy zapisana.");
      expect(mounted!.router.currentRoute.value.name).toBe("delivery");
    });

    it("sends an empty „Darmowa od”, an empty description and an empty link as null, and a price of 0 as 0", async () => {
      const wrapper = await open("/delivery/new");
      await wrapper.get("[data-testid=name-input]").setValue("Odbiór osobisty");
      await wrapper.get("[data-testid=price-input]").setValue("0");
      await wrapper.get("[data-testid=position-input]").setValue("0");
      mocks.post.mockResolvedValue({ data: method(), response: { status: 201 } });

      await click(wrapper, "save");

      expect(mocks.post.mock.calls[0]![1].body).toEqual({
        name: "Odbiór osobisty",
        description: null,
        priceGross: 0,
        freeFromGross: null,
        active: true,
        position: 0,
        trackingUrlTemplate: null,
      });
    });

    it("sends a method that is switched off with active false", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      await click(wrapper, "active");
      mocks.post.mockResolvedValue({ data: method({ active: false }), response: { status: 201 } });

      await click(wrapper, "save");

      expect(mocks.post.mock.calls[0]![1].body.active).toBe(false);
    });

    it("goes back to the list with „Anuluj” without sending anything", async () => {
      const wrapper = await open("/delivery/new");

      await click(wrapper, "cancel");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(mounted!.router.currentRoute.value.name).toBe("delivery");
    });

    it("shows an amount that cannot be read at its field without sending, and focuses the first such field", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      await wrapper.get("[data-testid=price-input]").setValue("abc");
      await wrapper.get("[data-testid=free-from-input]").setValue("12,345");
      await wrapper.get("[data-testid=position-input]").setValue("1,5");

      await click(wrapper, "save");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=price-error]").text()).toBe("Wpisz kwotę w złotych, np. 54,90.");
      expect(wrapper.get("[data-testid=free-from-error]").text()).toBe("Kwota może mieć najwyżej 2 miejsca po przecinku.");
      expect(wrapper.get("[data-testid=position-error]").text()).toBe("Kolejność musi być liczbą całkowitą od 0 do 999.");
      expect(wrapper.get("[data-testid=price-input]").attributes("aria-invalid")).toBe("true");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=price-input]").element);
    });

    it("asks for the price when it is empty, without sending", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      await wrapper.get("[data-testid=price-input]").setValue("");

      await click(wrapper, "save");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=price-error]").text()).toBe("Podaj kwotę brutto.");
    });

    it("puts the 422 violations at their fields with the messages of the API, and focuses the first one in the order of the document", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue(
        violations(
          ["trackingUrlTemplate", "Link śledzenia musi zawierać dokładnie jedno {numer}."],
          ["priceGross", "Cena musi wynosić od 0,00 do 999 999,99 zł."],
          ["name", "Nazwa zawiera niedozwolone znaki."],
          ["description", "Opis może mieć najwyżej 200 znaków."],
          ["freeFromGross", "Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł."],
          ["position", "Kolejność musi być liczbą całkowitą od 0 do 999."],
          ["active", "Wskaż, czy metoda jest aktywna."],
        ),
      );

      await click(wrapper, "save");

      const expected: [string, string][] = [
        ["name-error", "Nazwa zawiera niedozwolone znaki."],
        ["description-error", "Opis może mieć najwyżej 200 znaków."],
        ["price-error", "Cena musi wynosić od 0,00 do 999 999,99 zł."],
        ["free-from-error", "Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł."],
        ["position-error", "Kolejność musi być liczbą całkowitą od 0 do 999."],
        ["active-error", "Wskaż, czy metoda jest aktywna."],
        ["tracking-error", "Link śledzenia musi zawierać dokładnie jedno {numer}."],
      ];
      for (const [testid, message] of expected) {
        expect(wrapper.get(`[data-testid=${testid}]`).text(), testid).toBe(message);
      }
      expect(wrapper.get("[data-testid=name-input]").attributes("aria-describedby")).toContain(wrapper.get("[data-testid=name-error]").attributes("id"));
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=name-input]").element);
      expect(input(wrapper, "name-input").value).toBe("Paczkomat");
    });

    it("shows a 422 that belongs to no field as a message of the form, with the focus on it", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue(violations(["", "Dane są niepoprawne."]));

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toBe("Dane są niepoprawne.");
      expect(alert.attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(alert.element);
    });

    it("clears the messages of the previous save when the next one starts", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValueOnce(violations(["name", "Nazwa zawiera niedozwolone znaki."]));
      await click(wrapper, "save");
      expect(wrapper.find("[data-testid=name-error]").exists()).toBe(true);

      mocks.post.mockResolvedValueOnce({ data: method(), response: { status: 201 } });
      await click(wrapper, "save");

      expect(wrapper.find("[data-testid=name-error]").exists()).toBe(false);
    });

    it.each([
      ["delivery-method-name-taken", "Metoda dostawy o tej nazwie już istnieje."],
      ["delivery-method-limit-reached", "Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę."],
    ])("shows a 409 %s above the form with the focus on the message, and keeps what was typed without reading the method again", async (kind, detail) => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue(conflict(kind, detail));

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toBe(detail);
      expect(alert.attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(alert.element);
      expect(wrapper.find("[data-testid=name-error]").exists()).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Paczkomat");
      expect(input(wrapper, "price-input").value).toBe("12,99");
      expect(mocks.get).not.toHaveBeenCalled();
      expect(successToast).not.toHaveBeenCalled();
      expect(mounted!.router.currentRoute.value.name).toBe("delivery-method-new");
    });

    it("falls back to the Polish message of the 409 when the API gives no detail", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue({ data: undefined, error: { type: `${PREFIX}delivery-method-name-taken` }, response: { status: 409 } });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Metoda dostawy o tej nazwie już istnieje.");
    });

    it.each([
      [403, "Nie masz uprawnień do zmiany metod dostawy."],
      [503, "Usługa jest chwilowo niedostępna. Spróbuj ponownie."],
      [500, "Nie udało się zapisać metody dostawy (HTTP 500)."],
    ])("shows the failure %s above the form and keeps the form", async (status, message) => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockResolvedValue({ data: undefined, error: {}, response: { status } });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe(message);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=form-error]").element);
      expect(input(wrapper, "name-input").value).toBe("Paczkomat");
    });

    it("shows a failure of the connection above the form, and the next save can succeed", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      mocks.post.mockRejectedValueOnce(new Error("network"));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Nie udało się zapisać metody dostawy. Sprawdź połączenie z API.");
      mocks.post.mockResolvedValueOnce({ data: method(), response: { status: 201 } });
      await click(wrapper, "save");
      expect(mounted!.router.currentRoute.value.name).toBe("delivery");
    });

    it("blocks a second save while the first runs, and marks the button busy", async () => {
      const wrapper = await open("/delivery/new");
      await fillNew(wrapper);
      let answer: (value: unknown) => void = () => {};
      mocks.post.mockImplementation(() => new Promise((resolve) => (answer = resolve)));

      await wrapper.get("[data-testid=save]").trigger("click");
      await wrapper.get("[data-testid=save]").trigger("click");

      expect(mocks.post).toHaveBeenCalledTimes(1);
      expect(wrapper.get("[data-testid=save]").attributes("aria-busy")).toBe("true");
      answer({ data: method(), response: { status: 201 } });
      await flushPromises();
      expect(mounted!.router.currentRoute.value.name).toBe("delivery");
    });
  });

  describe("an existing method", () => {
    it("shows a skeleton while the method loads, then fills the form with the amounts in złoty with a comma", async () => {
      let answer: (value: unknown) => void = () => {};
      mocks.get.mockImplementation(() => new Promise((resolve) => (answer = resolve)));
      mounted = await mountForm("/delivery/method-1");
      expect(mounted.wrapper.find("[data-testid=name-input]").exists()).toBe(false);
      expect(mounted.wrapper.find("[class*=animate-pulse]").exists()).toBe(true);

      answer({ data: method(), response: { status: 200 } });
      await flushPromises();

      const wrapper = mounted.wrapper;
      expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/delivery-methods/{id}", { params: { path: { id: "method-1" } } });
      expect(input(wrapper, "name-input").value).toBe("Kurier");
      expect(input(wrapper, "description-input").value).toBe("Dostawa w 2–3 dni robocze");
      expect(input(wrapper, "price-input").value).toBe("19,99");
      expect(input(wrapper, "free-from-input").value).toBe("299,00");
      expect(input(wrapper, "position-input").value).toBe("10");
      expect(input(wrapper, "tracking-input").value).toBe("https://tracking.example.com/{numer}");
      expect(isActive(wrapper)).toBe(true);
    });

    it("shows a method without a threshold and a template with those fields empty, and an inactive one switched off", async () => {
      const wrapper = await open("/delivery/method-1", method({ freeFromGross: null, trackingUrlTemplate: null, active: false, priceGross: 0 }));

      expect(input(wrapper, "free-from-input").value).toBe("");
      expect(input(wrapper, "tracking-input").value).toBe("");
      expect(input(wrapper, "price-input").value).toBe("0,00");
      expect(isActive(wrapper)).toBe(false);
    });

    it("has no save bar until something changes, and then sends the whole method with the version of the one it shows", async () => {
      const wrapper = await open();
      expect(saveBar()).toBeNull();

      await wrapper.get("[data-testid=price-input]").setValue("21,50");
      expect(saveBar()?.textContent).toContain("Niezapisane zmiany");
      mocks.put.mockResolvedValue({ data: method({ priceGross: 2150, version: 4 }), response: { status: 200 } });
      await click(wrapper, "save");

      expect(mocks.put).toHaveBeenCalledWith("/api/v1/admin/delivery-methods/{id}", {
        params: { path: { id: "method-1" } },
        body: {
          name: "Kurier",
          description: "Dostawa w 2–3 dni robocze",
          priceGross: 2150,
          freeFromGross: 29900,
          active: true,
          position: 10,
          trackingUrlTemplate: "https://tracking.example.com/{numer}",
          version: 3,
        },
      });
      expect(successToast).toHaveBeenCalledWith("Metoda dostawy zapisana.");
      expect(mounted!.router.currentRoute.value.name).toBe("delivery");
    });

    it("sends a cleared „Darmowa od” and a cleared link as null", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=free-from-input]").setValue("  ");
      await wrapper.get("[data-testid=tracking-input]").setValue("");
      mocks.put.mockResolvedValue({ data: method(), response: { status: 200 } });

      await click(wrapper, "save");

      const body = mocks.put.mock.calls[0]![1].body;
      expect(body.freeFromGross).toBeNull();
      expect(body.trackingUrlTemplate).toBeNull();
    });

    it("switches the method off and sends active false", async () => {
      const wrapper = await open();
      await click(wrapper, "active");
      mocks.put.mockResolvedValue({ data: method({ active: false, version: 4 }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.active).toBe(false);
    });

    it("throws away the changes with „Odrzuć” without a request and puts the focus on the H1", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");
      (wrapper.get("[data-testid=discard]").element as HTMLElement).focus();

      await click(wrapper, "discard");

      expect(input(wrapper, "name-input").value).toBe("Kurier");
      expect(saveBar()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("puts the 422 violations at their fields and keeps the typed values", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("   ");
      mocks.put.mockResolvedValue(violations(["name", "Nazwa jest wymagana."]));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=name-error]").text()).toBe("Nazwa jest wymagana.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=name-input]").element);
      expect(input(wrapper, "name-input").value).toBe("   ");
    });

    it("shows a 409 on the name above the form with the focus on it, keeps the typed values and does not reload", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("kurier2");
      mocks.put.mockResolvedValue(conflict("delivery-method-name-taken", "Metoda dostawy o tej nazwie już istnieje."));
      mocks.get.mockClear();

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toBe("Metoda dostawy o tej nazwie już istnieje.");
      expect(document.activeElement).toBe(alert.element);
      expect(input(wrapper, "name-input").value).toBe("kurier2");
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("shows a 409 on the limit above the form when the method is switched on, and keeps the switch", async () => {
      const wrapper = await open("/delivery/method-1", method({ active: false }));
      await click(wrapper, "active");
      mocks.put.mockResolvedValue(conflict("delivery-method-limit-reached", "Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę."));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę.");
      expect(isActive(wrapper)).toBe(true);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=form-error]").element);
    });

    it("shows a 409 on the version above the form with the focus on it and shows the method as the API has it now", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Moja nazwa");
      const detail = "Metoda dostawy została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.";
      mocks.put.mockResolvedValue(conflict("about:blank", detail));
      serve(method({ name: "Nazwa kogoś innego", version: 4 }));

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toBe(detail);
      expect(document.activeElement).toBe(alert.element);
      expect(input(wrapper, "name-input").value).toBe("Nazwa kogoś innego");
      expect(saveBar()).toBeNull();

      mocks.put.mockResolvedValue({ data: method({ name: "Nazwa kogoś innego", priceGross: 100, version: 5 }), response: { status: 200 } });
      await wrapper.get("[data-testid=price-input]").setValue("1");
      await click(wrapper, "save");
      expect(mocks.put.mock.calls[1]![1].body.version).toBe(4);
    });

    it("shows the failure of a method that no longer exists", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue({ data: undefined, error: {}, response: { status: 404 } });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Ta metoda dostawy już nie istnieje.");
    });
  });

  describe("a method that cannot be loaded", () => {
    it("says that the method does not exist on a 404, with the title of the page and a way back", async () => {
      const wrapper = await open("/delivery/missing", null);

      expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać metody dostawy");
      const alert = wrapper.get("[data-testid=load-error]");
      expect(alert.text()).toContain("Ta metoda dostawy nie istnieje.");
      expect(wrapper.get("[data-testid=back-to-list]").attributes("href")).toBe("/delivery");
      expect(wrapper.find("[data-testid=name-input]").exists()).toBe(false);
      expect(saveBar()).toBeNull();
    });

    it("shows the status of another failure and of a lost connection", async () => {
      mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 500 } });
      let wrapper = await open("/delivery/method-1");
      expect(wrapper.get("[data-testid=load-error]").text()).toContain("Nie udało się pobrać metody dostawy (HTTP 500).");
      mounted!.wrapper.unmount();

      mocks.get.mockRejectedValue(new Error("network"));
      wrapper = await open("/delivery/method-1");
      expect(wrapper.get("[data-testid=load-error]").text()).toContain("Nie udało się pobrać metody dostawy. Sprawdź połączenie z API.");
    });

    it("says that the access is denied on a 403", async () => {
      mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 403 } });
      const wrapper = await open("/delivery/method-1");

      expect(wrapper.get("[data-testid=load-error]").text()).toContain("Nie masz dostępu do metod dostawy.");
    });
  });
});
