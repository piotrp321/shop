import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import DiscountCodeFormView from "@/views/DiscountCodeFormView.vue";

type Code = components["schemas"]["DiscountCodeResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const CODE = "/api/v1/admin/discount-codes/{id}";
const CODES = "/api/v1/admin/discount-codes";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

function code(overrides: Partial<Code> = {}): Code {
  return {
    id: "code-1",
    code: "WITAJ10",
    kind: "percent",
    value: 10,
    minimumOrderGross: null,
    startsAt: "2026-11-20T23:00:00+00:00",
    endsAt: null,
    usageLimit: null,
    activeUses: 0,
    enabled: true,
    status: "planned",
    version: 3,
    ...overrides,
  };
}

/** A code that started before the fake clock. */
function started(overrides: Partial<Code> = {}): Code {
  return code({ startsAt: "2026-11-10T23:00:00+00:00", status: "active", usageLimit: 50, activeUses: 7, ...overrides });
}

function conflict(kind: string, detail: string) {
  return {
    data: undefined,
    error: { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail },
    response: { status: 409 },
  };
}

function serve(data: Code | null = code()) {
  mocks.get.mockImplementation(async () =>
    data ? { data, response: { status: 200 } } : { data: undefined, error: {}, response: { status: 404 } },
  );
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountForm(path: string, roles: StaffMe["roles"] = ["marketing"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/discount-codes", name: "discount-codes", component: empty },
      { path: "/discount-codes/new", name: "discount-code-new", component: DiscountCodeFormView },
      { path: "/discount-codes/:id", name: "discount-code", component: DiscountCodeFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount(
    { components: { RouterView }, template: "<RouterView />" },
    { attachTo: document.body, global: { plugins: [pinia, router] } },
  );
  await settle();
  return { wrapper, router };
}

describe("DiscountCodeFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let successToast: Mock<typeof toast.success>;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    vi.setSystemTime(new Date("2026-11-15T10:20:30.000Z"));
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    // The toast store is global; the tests read what was shown from the calls.
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  async function open(path = "/discount-codes/code-1", roles?: StaffMe["roles"], data?: Code | null) {
    if (data !== undefined) {
      serve(data);
    }
    mounted = await mountForm(path, roles);
    return mounted.wrapper;
  }

  type Wrapper = Awaited<ReturnType<typeof open>>;

  const click = async (wrapper: Wrapper, testid: string) => {
    await wrapper.get(`[data-testid=${testid}]`).trigger("click");
    await settle();
  };
  const input = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).element as HTMLInputElement;
  const disabled = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).attributes("disabled") !== undefined;
  const pickKind = async (wrapper: Wrapper, kind: "percent" | "amount") => {
    await click(wrapper, `kind-${kind}`);
  };
  const isEnabled = (wrapper: Wrapper) => wrapper.get("[data-testid=enabled]").attributes("aria-checked") === "true";
  const toggleEnabled = async (wrapper: Wrapper, value: boolean) => {
    if (isEnabled(wrapper) !== value) {
      await click(wrapper, "enabled");
    }
  };
  const saveBar = () => document.body.querySelector("section[aria-label='Zapis formularza']");
  const barButton = (name: string) => [...(saveBar()?.querySelectorAll("button") ?? [])].find((button) => button.textContent?.trim() === name) as HTMLButtonElement | undefined;
  const dialog = () => document.body.querySelector<HTMLElement>("[role=alertdialog]");
  const dialogButton = (name: string) => [...(dialog()?.querySelectorAll("button") ?? [])].find((button) => button.textContent?.trim() === name);
  const heading = (level: "h1" | "h2", text?: string) => [...document.body.querySelectorAll(level)].find((candidate) => text === undefined || candidate.textContent?.trim() === text);
  /** The control a visible label points at. */
  const labelled = (label: string) => {
    const element = [...document.body.querySelectorAll("label")].find((candidate) => candidate.textContent?.trim() === label);
    expect(element, `a label „${label}”`).toBeDefined();
    return element!.htmlFor ? document.getElementById(element!.htmlFor) : null;
  };

  describe("the layout", () => {
    it("has the card „Status” before the cards „Kod i rabat” and „Okres i limit” in the document, and the crumbs of the list", async () => {
      const wrapper = await open();

      const titles = [...document.body.querySelectorAll("h2")].map((title) => title.textContent?.trim());
      expect(titles).toEqual(["Status", "Kod i rabat", "Okres i limit"]);
      const crumbs = wrapper.get("nav[aria-label=Okruszki]");
      expect(crumbs.text()).toMatch(/^Pulpit.*Marketing.*Kody rabatowe.*WITAJ10$/);
      expect(crumbs.get("a[href='/discount-codes']").text()).toBe("Kody rabatowe");
    });

    it("puts the status, the switch „Aktywny” and the active uses in the card „Status”", async () => {
      const wrapper = await open("/discount-codes/code-1", undefined, started({ activeUses: 7 }));

      const status = heading("h2", "Status")!.closest("section")!;
      expect(status.querySelector("[data-testid=code-status]")?.textContent?.trim()).toBe("Aktywny");
      expect(status.querySelector("[data-testid=enabled]")).not.toBeNull();
      expect(labelled("Aktywny")).toBe(wrapper.get("[data-testid=enabled]").element);
      expect(status.querySelector("[data-testid=active-uses]")?.textContent).toBe("7");
    });
  });

  describe("a new code", () => {
    async function fill(wrapper: Wrapper) {
      await wrapper.get("[data-testid=code-input]").setValue("minus50");
      await pickKind(wrapper, "amount");
      await wrapper.get("[data-testid=value-input]").setValue("50");
      await wrapper.get("[data-testid=minimum-input]").setValue("300,00");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-21T00:00");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-12-31T23:59");
      await wrapper.get("[data-testid=limit-input]").setValue("100");
    }

    it("starts empty, with the code field editable and every field labelled", async () => {
      const wrapper = await open("/discount-codes/new");

      expect(wrapper.get("h1").text()).toBe("Nowy kod rabatowy");
      expect(input(wrapper, "code-input").value).toBe("");
      expect(disabled(wrapper, "code-input")).toBe(false);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(wrapper.text()).toContain("Czas w strefie Europe/Warsaw");
      const controls: [string, string][] = [
        ["Kod", "code-input"],
        ["Rabat (%)", "value-input"],
        ["Minimalna wartość zamówienia (zł)", "minimum-input"],
        ["Początek", "starts-input"],
        ["Koniec", "ends-input"],
        ["Limit użyć", "limit-input"],
        ["Aktywny", "enabled"],
        ["Procent", "kind-percent"],
        ["Kwota", "kind-amount"],
      ];
      for (const [label, testid] of controls) {
        expect(labelled(label), label).toBe(wrapper.get(`[data-testid=${testid}]`).element);
      }
      const group = wrapper.get("[role=radiogroup]");
      expect(document.getElementById(group.attributes("aria-labelledby")!)?.textContent).toBe("Rodzaj rabatu");
    });

    it("chooses the kind of the discount with a group of radio buttons: percent is chosen", async () => {
      const wrapper = await open("/discount-codes/new");

      expect(wrapper.get("[data-testid=kind-percent]").attributes("aria-checked")).toBe("true");
      expect(wrapper.get("[data-testid=kind-amount]").attributes("aria-checked")).toBe("false");

      await pickKind(wrapper, "amount");

      expect(wrapper.get("[data-testid=kind-percent]").attributes("aria-checked")).toBe("false");
      expect(wrapper.get("[data-testid=kind-amount]").attributes("aria-checked")).toBe("true");
    });

    it("labels the value by the kind: a percent or złoty", async () => {
      const wrapper = await open("/discount-codes/new");
      expect(labelled("Rabat (%)")).toBe(wrapper.get("[data-testid=value-input]").element);

      await pickKind(wrapper, "amount");

      expect(labelled("Kwota rabatu (zł)")).toBe(wrapper.get("[data-testid=value-input]").element);
    });

    it("shows the save bar „Nowy kod rabatowy” with „Anuluj” from the start, and „Anuluj” goes back to the list", async () => {
      const wrapper = await open("/discount-codes/new");

      expect(saveBar()?.textContent).toContain("Nowy kod rabatowy");
      expect(barButton("Odrzuć")).toBeUndefined();
      barButton("Anuluj")!.click();
      await settle();

      expect(mounted!.router.currentRoute.value.name).toBe("discount-codes");
      expect(wrapper.exists()).toBe(true);
    });

    it("posts the code with amounts in grosze and times in UTC, then moves to the page of the created code", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue({ data: code({ kind: "amount", value: 5000, version: 1 }), response: { status: 201 } });

      await click(wrapper, "save");

      expect(mocks.post).toHaveBeenCalledWith(CODES, {
        body: {
          code: "minus50",
          kind: "amount",
          value: 5000,
          minimumOrderGross: 30000,
          startsAt: "2026-11-20T23:00:00Z",
          endsAt: "2026-12-31T22:59:00Z",
          usageLimit: 100,
          enabled: true,
        },
      });
      expect(mounted!.router.currentRoute.value.name).toBe("discount-code");
      expect(mounted!.router.currentRoute.value.params.id).toBe("code-1");
      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(wrapper.find("[data-testid=success-message]").exists()).toBe(false);
    });

    it("moves the focus to the H1 after the code is created, on the page of the created code, and the save bar goes away", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue({ data: code({ kind: "amount", value: 5000, version: 1 }), response: { status: 201 } });

      (wrapper.get("[data-testid=save]").element as HTMLElement).focus();
      await click(wrapper, "save");

      expect(mounted!.router.currentRoute.value.name).toBe("discount-code");
      expect(saveBar()).toBeNull();
      expect(document.activeElement).toBe(heading("h1"));
      expect(heading("h1")?.textContent?.trim()).toBe("WITAJ10");
    });

    it("puts a 409 discount-code-taken at the code field, keeps the form and moves the focus to the field", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue(conflict("discount-code-taken", "Taki kod już istnieje."));

      await click(wrapper, "save");

      const error = wrapper.get("[data-testid=code-error]");
      expect(error.text()).toBe("Taki kod już istnieje.");
      expect(wrapper.get("[data-testid=code-input]").attributes("aria-invalid")).toBe("true");
      expect(wrapper.get("[data-testid=code-input]").attributes("aria-describedby")).toContain(error.attributes("id"));
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      expect(input(wrapper, "code-input").value).toBe("minus50");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=code-input]").element);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(successToast).not.toHaveBeenCalled();
    });

    it("shows what cannot be read at the field without sending, and focuses the first such field", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      await wrapper.get("[data-testid=value-input]").setValue("abc");
      await wrapper.get("[data-testid=limit-input]").setValue("1,5");

      await click(wrapper, "save");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=value-error]").text()).toBe("Wpisz kwotę w złotych, np. 54,90.");
      expect(wrapper.get("[data-testid=limit-error]").text()).toBe("Podaj limit jako liczbę całkowitą.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=value-input]").element);
    });

    it("puts the 422 violations at their fields and focuses the first one in the order of the document", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue({
        data: undefined,
        error: {
          status: 422,
          violations: [
            { propertyPath: "usageLimit", title: "Limit to 1–1 000 000 użyć." },
            { propertyPath: "code", title: "Kod ma 3–32 znaki: litery, cyfry i myślnik." },
            { propertyPath: "minimumOrderGross", title: "Kod kwotowy wymaga minimum większego od kwoty rabatu." },
            { propertyPath: "endsAt", title: "Koniec musi być po początku." },
          ],
        },
        response: { status: 422 },
      });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=code-error]").text()).toBe("Kod ma 3–32 znaki: litery, cyfry i myślnik.");
      expect(wrapper.get("[data-testid=minimum-error]").text()).toBe("Kod kwotowy wymaga minimum większego od kwoty rabatu.");
      expect(wrapper.get("[data-testid=ends-error]").text()).toBe("Koniec musi być po początku.");
      expect(wrapper.get("[data-testid=limit-error]").text()).toBe("Limit to 1–1 000 000 użyć.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=code-input]").element);
    });

    it("shows a 422 that has no field as a message of the form, with the focus on it", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue({ data: undefined, error: { status: 422, violations: [{ propertyPath: "", title: "Dane są niepoprawne." }] }, response: { status: 422 } });

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toBe("Dane są niepoprawne.");
      expect(document.activeElement).toBe(alert.element);
    });

    it("shows a 403 as a message of the form", async () => {
      const wrapper = await open("/discount-codes/new");
      await fill(wrapper);
      mocks.post.mockResolvedValue({ data: undefined, error: {}, response: { status: 403 } });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Nie masz uprawnień do zmiany kodów rabatowych.");
    });
  });

  describe("a planned code", () => {
    it("shows its fields, its times in Polish time and the status, with the code text fixed", async () => {
      const wrapper = await open(
        "/discount-codes/code-1",
        undefined,
        code({ kind: "amount", value: 5000, minimumOrderGross: 12345, endsAt: "2026-12-31T22:59:00+00:00", usageLimit: 100 }),
      );

      expect(wrapper.get("h1").text()).toBe("WITAJ10");
      expect(wrapper.get("[data-testid=code-status]").text()).toBe("Zaplanowany");
      expect(input(wrapper, "code-input").value).toBe("WITAJ10");
      expect(disabled(wrapper, "code-input")).toBe(true);
      expect(wrapper.get("[data-testid=kind-amount]").attributes("aria-checked")).toBe("true");
      expect(input(wrapper, "value-input").value).toBe("50,00");
      expect(input(wrapper, "minimum-input").value).toBe("123,45");
      expect(input(wrapper, "starts-input").value).toBe("2026-11-21T00:00");
      expect(input(wrapper, "ends-input").value).toBe("2026-12-31T23:59");
      expect(input(wrapper, "limit-input").value).toBe("100");
      expect(wrapper.get("[data-testid=active-uses]").text()).toBe("0");
    });

    it("lets everything but the code text change", async () => {
      const wrapper = await open();

      for (const field of ["value-input", "minimum-input", "starts-input", "ends-input", "limit-input", "kind-percent", "kind-amount"]) {
        expect(disabled(wrapper, field), field).toBe(false);
      }
      expect(wrapper.find("[data-testid=terms-locked-note]").exists()).toBe(false);
    });

    it("has no save bar until something changes, then saves with the version", async () => {
      const wrapper = await open();
      expect(saveBar()).toBeNull();

      await wrapper.get("[data-testid=value-input]").setValue("15");
      expect(saveBar()?.textContent).toContain("Niezapisane zmiany");
      expect(disabled(wrapper, "save")).toBe(false);
      mocks.put.mockResolvedValue({ data: code({ value: 15, version: 4 }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put).toHaveBeenCalledWith(CODE, {
        params: { path: { id: "code-1" } },
        body: {
          kind: "percent",
          value: 15,
          minimumOrderGross: null,
          startsAt: "2026-11-20T23:00:00+00:00",
          endsAt: null,
          usageLimit: null,
          enabled: true,
          version: 3,
        },
      });
      expect(successToast).toHaveBeenCalledTimes(1);
      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(saveBar()).toBeNull();
    });

    it("moves the focus to the H1 after a save, as the save bar and its button go away", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=value-input]").setValue("15");
      mocks.put.mockResolvedValue({ data: code({ value: 15, version: 4 }), response: { status: 200 } });

      (wrapper.get("[data-testid=save]").element as HTMLElement).focus();
      await click(wrapper, "save");

      expect(document.activeElement).toBe(heading("h1"));
      expect(heading("h1")?.getAttribute("tabindex")).toBe("-1");
    });

    it("#580: the focus cannot move to a field while the save runs, as the fields are off, so it goes to the H1 after the save", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=value-input]").setValue("15");
      let release!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (release = resolve)));

      await wrapper.get("[data-testid=save]").trigger("click");
      (wrapper.get("[data-testid=limit-input]").element as HTMLElement).focus();
      expect(document.activeElement).not.toBe(wrapper.get("[data-testid=limit-input]").element);
      release({ data: code({ value: 15, version: 4 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("shows the save bar when only the switch „Aktywny” changes, and sends the change only with „Zapisz”", async () => {
      const wrapper = await open();
      expect(saveBar()).toBeNull();

      await toggleEnabled(wrapper, false);

      expect(isEnabled(wrapper)).toBe(false);
      expect(saveBar()?.textContent).toContain("Niezapisane zmiany");
      expect(mocks.put).not.toHaveBeenCalled();
      mocks.put.mockResolvedValue({ data: code({ enabled: false, status: "disabled" }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put).toHaveBeenCalledTimes(1);
      expect(mocks.put.mock.calls[0]![1].body.enabled).toBe(false);
      expect(wrapper.get("[data-testid=code-status]").text()).toBe("Wyłączony");
      expect(saveBar()).toBeNull();
    });

    it("hides the save bar again when the switch goes back to what is stored", async () => {
      const wrapper = await open();

      await toggleEnabled(wrapper, false);
      await toggleEnabled(wrapper, true);

      expect(saveBar()).toBeNull();
    });

    it("throws away the changes with „Odrzuć” without a request, and puts the focus on the H1", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=value-input]").setValue("15");
      await toggleEnabled(wrapper, false);
      mocks.get.mockClear();

      barButton("Odrzuć")!.click();
      await settle();

      expect(input(wrapper, "value-input").value).toBe("10");
      expect(isEnabled(wrapper)).toBe(true);
      expect(saveBar()).toBeNull();
      expect(mocks.get).not.toHaveBeenCalled();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(heading("h1"));
    });
  });

  describe("a started code", () => {
    it.each(["active", "expired"] as const)("%s: locks the kind, the value, the minimum and the start and says so", async (status) => {
      const wrapper = await open("/discount-codes/code-1", undefined, started({ status }));

      for (const field of ["kind-percent", "kind-amount", "value-input", "minimum-input", "starts-input"]) {
        expect(disabled(wrapper, field), field).toBe(true);
      }
      for (const field of ["ends-input", "limit-input", "enabled"]) {
        expect(disabled(wrapper, field), field).toBe(false);
      }
      expect(wrapper.get("[data-testid=terms-locked-note]").text()).toContain("rodzaj, wartość, minimum i początek są zablokowane. Możesz zmienić koniec, „Aktywny” i limit.");
      expect(wrapper.get("[data-testid=active-uses]").text()).toBe("7");
    });

    it("sends the stored terms and the changed end, enabled and limit", async () => {
      const wrapper = await open("/discount-codes/code-1", undefined, started({ kind: "amount", value: 2000, minimumOrderGross: 10000 }));
      await wrapper.get("[data-testid=ends-input]").setValue("2026-12-31T23:59");
      await wrapper.get("[data-testid=limit-input]").setValue("40");
      await toggleEnabled(wrapper, false);
      mocks.put.mockResolvedValue({ data: started(), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body).toEqual({
        kind: "amount",
        value: 2000,
        minimumOrderGross: 10000,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-12-31T22:59:00Z",
        usageLimit: 40,
        enabled: false,
        version: 3,
      });
    });

    it("lets the limit be removed", async () => {
      const wrapper = await open("/discount-codes/code-1", undefined, started());
      await wrapper.get("[data-testid=limit-input]").setValue("");
      mocks.put.mockResolvedValue({ data: started({ usageLimit: null }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.usageLimit).toBeNull();
    });

    it("cannot add a limit to a code that has none, and says so", async () => {
      const wrapper = await open("/discount-codes/code-1", undefined, started({ usageLimit: null }));

      expect(disabled(wrapper, "limit-input")).toBe(true);
      expect(wrapper.get("[data-testid=limit-locked-note]").text()).toBe("Limitu nie można dodać kodowi, który już obowiązuje.");
      expect(wrapper.get("[data-testid=limit-input]").attributes("aria-describedby")).toContain(wrapper.get("[data-testid=limit-locked-note]").element.parentElement!.id);
      expect(disabled(wrapper, "ends-input")).toBe(false);
    });

    it("does not note the limit of a planned code without a limit", async () => {
      const wrapper = await open();

      expect(wrapper.find("[data-testid=limit-locked-note]").exists()).toBe(false);
    });

    it("treats a disabled code whose start has passed as started, and one that has not as planned", async () => {
      const after = await open("/discount-codes/code-1", undefined, started({ status: "disabled", enabled: false, usageLimit: null }));
      expect(disabled(after, "value-input")).toBe(true);
      expect(disabled(after, "limit-input")).toBe(true);
      mounted!.wrapper.unmount();

      const before = await open("/discount-codes/code-1", undefined, code({ status: "disabled", enabled: false }));
      expect(disabled(before, "value-input")).toBe(false);
      expect(disabled(before, "limit-input")).toBe(false);
    });

    it("shows the detail of a 409 not-editable, keeps the draft and does not read the code, with the focus on the message", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=value-input]").setValue("15");
      mocks.put.mockResolvedValue(
        conflict("discount-code-not-editable", "Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty."),
      );
      serve(started({ version: 6 }));
      mocks.get.mockClear();

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe(
        "Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.",
      );
      expect(wrapper.get("[data-testid=code-status]").text()).toBe("Zaplanowany");
      expect(disabled(wrapper, "value-input")).toBe(false);
      expect(input(wrapper, "value-input").value).toBe("15");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=form-error]").element);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(successToast).not.toHaveBeenCalled();
    });
  });

  describe("leaving with unsaved changes", () => {
    it("asks in a dialog and stays, with the form as it was, when the staff member stays", async () => {
      const wrapper = await open();
      const confirm = vi.spyOn(window, "confirm");

      await wrapper.get("[data-testid=value-input]").setValue("15");
      const navigation = mounted!.router.push("/discount-codes");
      await settle();

      expect(dialog()?.textContent).toContain("Masz niezapisane zmiany");
      expect(document.activeElement).toBe(dialogButton("Zostań"));
      dialogButton("Zostań")!.click();
      await navigation;
      await settle();

      expect(confirm).not.toHaveBeenCalled();
      expect(dialog()).toBeNull();
      expect(mounted!.router.currentRoute.value.name).toBe("discount-code");
      expect(input(wrapper, "value-input").value).toBe("15");
      expect(saveBar()).not.toBeNull();
    });

    it("asks also when only the switch „Aktywny” changed, and leaves with „Opuść bez zapisu”", async () => {
      const wrapper = await open();

      await toggleEnabled(wrapper, false);
      const navigation = mounted!.router.push("/discount-codes");
      await settle();
      expect(dialog()?.textContent).toContain("Masz niezapisane zmiany");
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await settle();

      expect(mounted!.router.currentRoute.value.name).toBe("discount-codes");
      expect(mocks.put).not.toHaveBeenCalled();
      expect(wrapper.exists()).toBe(true);
    });

    it("does not ask when nothing changed", async () => {
      await open();

      await mounted!.router.push("/discount-codes");
      await settle();

      expect(dialog()).toBeNull();
      expect(mounted!.router.currentRoute.value.name).toBe("discount-codes");
    });
  });

  describe("conflicts and read-only", () => {
    it("says the changes stay after a 409 on the version, and does not refresh the form (E7)", async () => {
      const wrapper = await open();
      await toggleEnabled(wrapper, false);
      mocks.put.mockResolvedValue(conflict("about:blank", "Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie."));
      serve(code({ usageLimit: 10, version: 8 }));
      mocks.get.mockClear();

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toBe("Kod rabatowy został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
      expect(input(wrapper, "limit-input").value).toBe("");
      expect(isEnabled(wrapper)).toBe(false);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("says so when the code does not exist: the heading, the message with the colour of an error, and a link to the list (#446)", async () => {
      const wrapper = await open("/discount-codes/nope", undefined, null);

      const alert = wrapper.get("[data-testid=load-error]");
      expect(alert.text()).toContain("Ten kod rabatowy nie istnieje.");
      expect(alert.attributes("role")).toBe("alert");
      expect(alert.classes().join(" ")).toContain("text-status-danger");
      expect(alert.get("a").attributes("href")).toBe("/discount-codes");
      expect(wrapper.find("[data-testid=value-input]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać kodu rabatowego");
      expect(saveBar()).toBeNull();
    });

    it("keeps that heading for an existing code that fails to load, not „Nowy kod rabatowy”", async () => {
      mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 500 } });
      const wrapper = await open("/discount-codes/code-1");

      expect(wrapper.find("[data-testid=load-error]").exists()).toBe(true);
      expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać kodu rabatowego");
    });

    it("keeps the heading of an existing code until it is loaded", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      const wrapper = await open("/discount-codes/code-1");

      expect(wrapper.get("h1").text()).toBe("Kod rabatowy");
    });

    it.each([["support"], ["warehouse"]] as StaffMe["roles"][])("shows the code read-only to %s, without a save bar", async (...roles) => {
      const wrapper = await open("/discount-codes/code-1", roles, code({ usageLimit: 10 }));

      for (const field of ["value-input", "minimum-input", "starts-input", "ends-input", "limit-input", "code-input", "kind-percent", "kind-amount", "enabled"]) {
        expect(disabled(wrapper, field), field).toBe(true);
      }
      expect(saveBar()).toBeNull();
      expect(wrapper.find("[data-testid=save]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=read-only-note]").text()).toContain("tylko do odczytu");
      expect(wrapper.get("[data-testid=active-uses]").text()).toBe("0");
    });

    it("has no save bar for a new code of a role that cannot change codes", async () => {
      await open("/discount-codes/new", ["support"]);

      expect(saveBar()).toBeNull();
    });

    it.each([["admin"], ["marketing"]] as StaffMe["roles"][])("offers saving to %s", async (...roles) => {
      const wrapper = await open("/discount-codes/code-1", roles);
      await wrapper.get("[data-testid=value-input]").setValue("11");

      expect(wrapper.find("[data-testid=save]").exists()).toBe(true);
      expect(wrapper.find("[data-testid=read-only-note]").exists()).toBe(false);
    });
  });

  describe("the lock while a save runs (B1)", () => {
    const FIELDS = ["code-input", "kind-percent", "kind-amount", "value-input", "minimum-input", "starts-input", "ends-input", "limit-input", "enabled"];
    // Whether the browser would refuse input in every element of the test id now (its own `disabled` or a disabled fieldset).
    const off = (wrapper: Wrapper, testid: string) => {
      const elements = wrapper.findAll(`[data-testid=${testid}]`);
      expect(elements.length, `an element ${testid}`).toBeGreaterThan(0);
      return elements.every((element) => element.element.matches(":disabled"));
    };
    const lockedState = (wrapper: Wrapper, testids = FIELDS) => Object.fromEntries(testids.map((testid) => [testid, off(wrapper, testid)]));
    const allAre = (testids: string[], value: boolean) => Object.fromEntries(testids.map((testid) => [testid, value]));

    /** A new code with the fields filled and a save whose answer waits for the test. */
    async function createHeld() {
      const wrapper = await open("/discount-codes/new");
      await wrapper.get("[data-testid=code-input]").setValue("minus50");
      await wrapper.get("[data-testid=value-input]").setValue("50");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-21T00:00");
      let release!: (value: unknown) => void;
      let reject!: (reason: unknown) => void;
      mocks.post.mockImplementation(() => new Promise((resolve, fail) => ((release = resolve), (reject = fail))));
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, release: (value: unknown) => release(value), reject: (reason: unknown) => reject(reason) };
    }

    /** A planned code with a changed end and limit and a save whose answer waits for the test. */
    async function saveHeld() {
      const wrapper = await open();
      await wrapper.get("[data-testid=ends-input]").setValue("2026-12-31T23:59");
      await wrapper.get("[data-testid=limit-input]").setValue("5");
      let release!: (value: unknown) => void;
      let reject!: (reason: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve, fail) => ((release = resolve), (reject = fail))));
      wrapper.get<HTMLButtonElement>("[data-testid=save]").element.focus();
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, release: (value: unknown) => release(value), reject: (reason: unknown) => reject(reason) };
    }

    it("keeps the focus on „Zapisz” while the save runs, and pressing it again sends nothing", async () => {
      const { wrapper } = await saveHeld();
      const save = wrapper.get<HTMLButtonElement>("[data-testid=save]").element;
      const writes = () => mocks.put.mock.calls.length + mocks.post.mock.calls.length;

      expect(document.activeElement).toBe(save);
      expect(save.disabled).toBe(false);
      expect(save.getAttribute("aria-disabled")).toBe("true");
      await wrapper.get("[data-testid=save]").trigger("click");
      expect(writes()).toBe(1);
      expect(document.activeElement).toBe(save);
    });

    it("disables every field, the switch and the kind of a new code from the save to its answer, and a second „Zapisz” sends nothing", async () => {
      const { wrapper, release } = await createHeld();

      expect(lockedState(wrapper)).toEqual(allAre(FIELDS, true));
      expect(barButton("Zapisz")?.getAttribute("aria-disabled")).toBe("true");
      barButton("Zapisz")!.click();
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      expect(mocks.post).toHaveBeenCalledTimes(1);

      release({ data: code({ id: "created", code: "MINUS50", version: 1 }), response: { status: 201 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
    });

    it("disables every field of a planned code that the staff member edits, and the field „Koniec” cannot be typed in", async () => {
      const { wrapper, release } = await saveHeld();

      expect(lockedState(wrapper, FIELDS.filter((testid) => testid !== "code-input"))).toEqual(allAre(FIELDS.filter((testid) => testid !== "code-input"), true));
      (wrapper.get("[data-testid=ends-input]").element as HTMLElement).focus();
      expect(document.activeElement).not.toBe(wrapper.get("[data-testid=ends-input]").element);

      release({ data: code({ endsAt: "2026-12-31T22:59:00+00:00", usageLimit: 5, version: 4 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(input(wrapper, "ends-input").value).toBe("2026-12-31T23:59");
      expect(off(wrapper, "ends-input")).toBe(false);
    });

    it("unlocks the fields with the values that were sent after a 422, with the focus on the field with the error", async () => {
      const { wrapper, release } = await saveHeld();

      release({
        data: undefined,
        error: { status: 422, violations: [{ propertyPath: "usageLimit", title: "Limit nie może być mniejszy od liczby użyć (5)." }] },
        response: { status: 422 },
      });
      await settle();

      expect(lockedState(wrapper, FIELDS.filter((testid) => testid !== "code-input"))).toEqual(allAre(FIELDS.filter((testid) => testid !== "code-input"), false));
      expect(input(wrapper, "ends-input").value).toBe("2026-12-31T23:59");
      expect(input(wrapper, "limit-input").value).toBe("5");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=limit-input]").element);
    });

    it("unlocks the fields with the values that were sent after a save that does not reach the API, and says the result is unknown", async () => {
      const { wrapper, reject } = await saveHeld();

      reject(new TypeError("network down"));
      await settle();

      expect(off(wrapper, "ends-input")).toBe(false);
      expect(input(wrapper, "ends-input").value).toBe("2026-12-31T23:59");
      expect(input(wrapper, "limit-input").value).toBe("5");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      const alert = wrapper.get("[data-testid=unknown-result]");
      expect(alert.text()).toContain("Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.");
      expect(alert.attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(alert.element);
    });

    it("ignores the answer of a save that comes after another code was opened: no change, no toast, no move", async () => {
      const { wrapper, release } = await saveHeld();
      serve(code({ id: "code-2", code: "LATO15", version: 7 }));
      const navigation = mounted!.router.push("/discount-codes/code-2");
      await settle();
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await settle();
      expect(off(wrapper, "ends-input")).toBe(false);

      release({ data: code({ usageLimit: 5, version: 4 }), response: { status: 200 } });
      await settle();

      expect(successToast).not.toHaveBeenCalled();
      expect(wrapper.get("h1").text()).toBe("LATO15");
      expect(mounted!.router.currentRoute.value.params.id).toBe("code-2");
      expect(input(wrapper, "limit-input").value).toBe("");
    });
  });

  describe("the unknown result of a save (stage 2, B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const alert = (wrapper: Wrapper) => wrapper.get("[data-testid=unknown-result]");

    /** A planned code with a changed end and a save whose answer waits for the test; `calls[n]` answers the n-th. */
    async function saveHeld(path = "/discount-codes/code-1") {
      const wrapper = await open(path);
      if (path.endsWith("/new")) {
        await wrapper.get("[data-testid=code-input]").setValue("NOWY");
        await wrapper.get("[data-testid=value-input]").setValue("10");
        await wrapper.get("[data-testid=starts-input]").setValue("2026-11-21T00:00");
      } else {
        await wrapper.get("[data-testid=limit-input]").setValue("5");
      }
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      const hold = () =>
        new Promise((resolve, reject) => {
          calls.push({ resolve, reject });
        });
      mocks.put.mockImplementation(hold);
      mocks.post.mockImplementation(hold);
      wrapper.get<HTMLButtonElement>("[data-testid=save]").element.focus();
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, calls };
    }

    it("says after 10 s that the result is unknown, with „Sprawdź stan” and the focus on the message, and keeps the fields locked", async () => {
      const { wrapper } = await saveHeld();

      await vi.advanceTimersByTimeAsync(9_999);
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      await settle();

      expect(alert(wrapper).text()).toContain(UNKNOWN);
      expect(alert(wrapper).attributes("role")).toBe("alert");
      expect(alert(wrapper).get("[data-testid=check-state]").text()).toBe("Sprawdź stan");
      expect(wrapper.find("[data-testid=unblock-form]").exists()).toBe(false);
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(true);
      expect(wrapper.get("[data-testid=save]").attributes("aria-disabled")).toBe("true");
    });

    it("„Sprawdź stan” takes the code from the server when it equals the form, says so in a status region and sends no write", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      serve(code({ usageLimit: 5, version: 4 }));

      await click(wrapper, "check-state");

      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
      expect(wrapper.get("[data-testid=safety-status]").attributes("role")).toBe("status");
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(false);
      expect(input(wrapper, "limit-input").value).toBe("5");
      expect(saveBar()).toBeNull();
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("„Sprawdź stan” says the write is not on the server when the version is the same and the values are not", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();

      await click(wrapper, "check-state");

      expect(alert(wrapper).text()).toContain("Zapisu nie widać na serwerze. Może jeszcze dotrzeć.");
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "limit-input").value).toBe("5");
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(false);
    });

    it("„Sprawdź stan” offers „Odrzuć moje zmiany i pokaż stan z serwera” when the version on the server is another", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      serve(code({ usageLimit: 9, version: 8 }));

      await click(wrapper, "check-state");

      expect(alert(wrapper).text()).toContain("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      expect(input(wrapper, "limit-input").value).toBe("5");
      await click(wrapper, "restore-state");
      expect(input(wrapper, "limit-input").value).toBe("9");
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
    });

    it("says in a toast that the earlier save was done when its answer comes after „Sprawdź stan”, and changes nothing in the form", async () => {
      const { wrapper, calls } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      await click(wrapper, "check-state");
      await wrapper.get("[data-testid=limit-input]").setValue("6");

      calls[0]!.resolve({ data: code({ usageLimit: 5, version: 4 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
      expect(successToast).not.toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(input(wrapper, "limit-input").value).toBe("6");
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(false);
    });

    it("gives an ordinary result, with the toast, when the answer comes after the limit but before „Sprawdź stan”", async () => {
      const { wrapper, calls } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();

      calls[0]!.resolve({ data: code({ usageLimit: 5, version: 4 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Kod rabatowy zapisany.");
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(false);
    });

    it("moves the focus to the message after an error that does not reach the API, with the fields unlocked", async () => {
      const { wrapper, calls } = await saveHeld();

      calls[0]!.reject(new TypeError("network down"));
      await settle();

      expect(alert(wrapper).text()).toContain(UNKNOWN);
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "limit-input").matches(":disabled")).toBe(false);
      expect(input(wrapper, "limit-input").value).toBe("5");
    });

    describe("a new code", () => {
      it("says after 10 s that the result is unknown, with „Odblokuj formularz” instead of „Sprawdź stan”", async () => {
        const { wrapper } = await saveHeld("/discount-codes/new");
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();

        expect(alert(wrapper).text()).toContain(UNKNOWN);
        expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
        expect(alert(wrapper).get("[data-testid=unblock-form]").text()).toBe("Odblokuj formularz");
        expect(input(wrapper, "code-input").matches(":disabled")).toBe(true);
      });

      it("unlocks the form with the message about the list and a link to it in a new tab, and a late success only gives a toast", async () => {
        const { wrapper, calls } = await saveHeld("/discount-codes/new");
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();

        await click(wrapper, "unblock-form");

        expect(alert(wrapper).text()).toContain(
          "Nie wiemy, czy kod rabatowy został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.",
        );
        const link = alert(wrapper).get("[data-testid=list-link]");
        expect(link.attributes("href")).toBe("/discount-codes");
        expect(link.attributes("target")).toBe("_blank");
        expect(link.attributes("rel")).toContain("noopener");
        expect(link.text()).toContain("Lista kodów rabatowych");
        expect(input(wrapper, "code-input").matches(":disabled")).toBe(false);
        await wrapper.get("[data-testid=code-input]").setValue("INNY");

        calls[0]!.resolve({ data: code({ id: "created", code: "NOWY" }), response: { status: 201 } });
        await settle();

        expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
        expect(mounted!.router.currentRoute.value.name).toBe("discount-code-new");
        expect(input(wrapper, "code-input").value).toBe("INNY");
      });
    });
  });

  describe("a 409 of a save and the way to the server state (E7)", () => {
    it("keeps the form, shows „Odrzuć moje zmiany i pokaż stan z serwera” and reads nothing", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=limit-input]").setValue("5");
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      serve(code({ usageLimit: 9, version: 9 }));
      mocks.get.mockClear();

      await click(wrapper, "save");
      await vi.advanceTimersByTimeAsync(30_000);

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("w międzyczasie zmieniony");
      expect(input(wrapper, "limit-input").value).toBe("5");
      expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("shows the state of the server only after „Odrzuć moje zmiany i pokaż stan z serwera”, with the focus on the H1", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=limit-input]").setValue("5");
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      await click(wrapper, "save");
      serve(code({ usageLimit: 9, version: 9 }));
      (wrapper.get("[data-testid=restore-state]").element as HTMLElement).focus();

      await click(wrapper, "restore-state");

      expect(input(wrapper, "limit-input").value).toBe("9");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=restore-state]").exists()).toBe(false);
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("keeps the way to the server state with its context after „Odrzuć”, the label as it was, and the focus on the message", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=limit-input]").setValue("5");
      mocks.put.mockResolvedValue(conflict("discount-code-not-editable", "Kodu, który już obowiązuje, nie można tak zmienić."));
      await click(wrapper, "save");

      barButton("Odrzuć")!.click();
      await settle();

      expect(input(wrapper, "limit-input").value).toBe("");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      const note = wrapper.get("[data-testid=restore-note]");
      expect(note.text()).toContain("Kodu, który już obowiązuje, nie można tak zmienić.");
      expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
      expect(document.activeElement).toBe(note.element);
    });

    it("puts a 409 discount-code-taken at the code field with the note of an earlier unknown creation", async () => {
      const wrapper = await open("/discount-codes/new");
      await wrapper.get("[data-testid=code-input]").setValue("NOWY");
      await wrapper.get("[data-testid=value-input]").setValue("10");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-21T00:00");
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));
      await click(wrapper, "save");
      mocks.post.mockResolvedValue(conflict("discount-code-taken", "Taki kod już istnieje."));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=code-error]").text()).toBe("Taki kod już istnieje. Możliwe, że utworzył go Twój wcześniejszy zapis.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=code-input]").element);
    });
  });
});
