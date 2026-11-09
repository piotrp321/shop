import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useDiscountCodeForm } from "@/discount-codes/useDiscountCodeForm";

type Code = components["schemas"]["DiscountCodeResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const CODE = "/api/v1/admin/discount-codes/{id}";
const CODES = "/api/v1/admin/discount-codes";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const NOW = new Date("2026-11-15T12:00:00Z");

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

/** A code that started before `NOW`. */
function started(overrides: Partial<Code> = {}): Code {
  return code({ startsAt: "2026-11-10T23:00:00+00:00", status: "active", ...overrides });
}

function ok(data: unknown, status = 200) {
  return { data, response: { status } };
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

function conflict(kind: string, detail = "Szczegóły z API.") {
  return problem(409, { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail });
}

interface Setup {
  id?: string | null;
  canManage?: boolean;
}

async function start(options: Setup = {}) {
  const id = ref<string | null>(options.id === undefined ? "code-1" : options.id);
  const canManage = ref(options.canManage ?? true);
  const onCreated = vi.fn();
  let form!: ReturnType<typeof useDiscountCodeForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useDiscountCodeForm({ discountCodeId: () => id.value, canManage: () => canManage.value, onCreated });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, wrapper, id, canManage, onCreated };
}

function serve(data: Code | null = code()) {
  mocks.get.mockImplementation(async () => (data ? ok(data) : problem(404)));
}

describe("useDiscountCodeForm", () => {
  let wrapper: Awaited<ReturnType<typeof start>>["wrapper"] | undefined;

  beforeEach(() => {
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    vi.useFakeTimers({ toFake: ["Date"], now: NOW });
    serve();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function open(data: Code = code(), options: Setup = {}) {
    serve(data);
    const result = await start(options);
    wrapper = result.wrapper;
    return result;
  }

  describe("loading", () => {
    it("starts an empty form for a new code without asking the API", async () => {
      const result = await start({ id: null });
      wrapper = result.wrapper;

      expect(mocks.get).not.toHaveBeenCalled();
      expect(result.form.loaded.value).toBe(true);
      expect(result.form.form.value).toEqual({
        code: "",
        kind: "percent",
        value: "",
        minimum: "",
        startsAt: "",
        endsAt: "",
        usageLimit: "",
        enabled: true,
      });
      expect(result.form.dirty.value).toBe(false);
      expect(result.form.status.value).toBeNull();
    });

    it("loads a code, shows its times in Polish time and the amounts in złoty", async () => {
      const { form } = await open(
        code({ kind: "amount", value: 5000, minimumOrderGross: 12345, endsAt: "2026-12-31T22:59:59+00:00", usageLimit: 100 }),
      );

      expect(mocks.get).toHaveBeenCalledWith(CODE, { params: { path: { id: "code-1" } } });
      expect(form.form.value).toEqual({
        code: "WITAJ10",
        kind: "amount",
        value: "50,00",
        minimum: "123,45",
        startsAt: "2026-11-21T00:00",
        endsAt: "2026-12-31T23:59:59",
        usageLimit: "100",
        enabled: true,
      });
      expect(form.dirty.value).toBe(false);
    });

    it("shows the percent as a whole number and no minimum, end or limit as empty fields", async () => {
      const { form } = await open();

      expect(form.form.value).toMatchObject({ kind: "percent", value: "10", minimum: "", endsAt: "", usageLimit: "" });
    });

    it.each([
      [404, "Ten kod rabatowy nie istnieje."],
      [500, "Nie udało się pobrać kodu rabatowego (HTTP 500)."],
    ])("says so when the load answers %s", async (status, text) => {
      mocks.get.mockResolvedValue(problem(status));
      const result = await start();
      wrapper = result.wrapper;

      expect(result.form.loadError.value).toBe(text);
      expect(result.form.loaded.value).toBe(false);
    });

    it("says so when the load request fails to leave", async () => {
      mocks.get.mockRejectedValue(new TypeError("network down"));
      const result = await start();
      wrapper = result.wrapper;

      expect(result.form.loadError.value).toContain("połączenie");
    });

    it("loads the other code when the route moves to it", async () => {
      const { id, form } = await open();
      serve(code({ id: "code-2", code: "LATO15" }));

      id.value = "code-2";
      await flushPromises();

      expect(form.form.value.code).toBe("LATO15");
    });
  });

  describe("which fields can change", () => {
    it("lets a new code change everything, the code text included", async () => {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      const { form } = result;

      expect(form.codeLocked.value).toBe(false);
      expect(form.termsLocked.value).toBe(false);
      expect(form.limitLocked.value).toBe(false);
      expect(form.readOnly.value).toBe(false);
    });

    it("lets a planned code change everything but the code text", async () => {
      const { form } = await open(code({ status: "planned" }));

      expect(form.codeLocked.value).toBe(true);
      expect(form.termsLocked.value).toBe(false);
      expect(form.limitLocked.value).toBe(false);
    });

    it.each(["active", "expired"] as const)("%s: only the end, enabled and an existing limit can change", async (status) => {
      const { form } = await open(started({ status, usageLimit: 50 }));

      expect(form.codeLocked.value).toBe(true);
      expect(form.termsLocked.value).toBe(true);
      expect(form.limitLocked.value).toBe(false);
    });

    it.each(["active", "expired"] as const)("%s without a limit: the limit cannot be added", async (status) => {
      const { form } = await open(started({ status, usageLimit: null }));

      expect(form.termsLocked.value).toBe(true);
      expect(form.limitLocked.value).toBe(true);
    });

    it("treats a disabled code whose start has passed like a started one", async () => {
      const { form } = await open(started({ status: "disabled", enabled: false, usageLimit: null }));

      expect(form.termsLocked.value).toBe(true);
      expect(form.limitLocked.value).toBe(true);
    });

    it("treats a disabled code that has not started yet like a planned one", async () => {
      const { form } = await open(code({ status: "disabled", enabled: false }));

      expect(form.termsLocked.value).toBe(false);
      expect(form.limitLocked.value).toBe(false);
    });

    it("is read-only for a staff member who may not change discount codes", async () => {
      const { form } = await open(code(), { canManage: false });

      expect(form.readOnly.value).toBe(true);
      form.form.value.enabled = false;
      expect(form.canSave.value).toBe(false);
      expect(await form.save()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
    });
  });

  describe("creating", () => {
    async function fillNew() {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      Object.assign(result.form.form.value, {
        code: " witaj10 ",
        kind: "amount",
        value: "50",
        minimum: "1 234,50",
        startsAt: "2026-11-21T00:00",
        endsAt: "2026-12-31T23:59",
        usageLimit: "100",
        enabled: false,
      });
      return result;
    }

    it("posts the code with the amounts in grosze and the times converted to UTC, then moves to the created one", async () => {
      const { form, onCreated } = await fillNew();
      mocks.post.mockResolvedValue(ok(code({ version: 1 }), 201));

      expect(await form.save()).toBe("success");

      expect(mocks.post).toHaveBeenCalledWith(CODES, {
        body: {
          code: " witaj10 ",
          kind: "amount",
          value: 5000,
          minimumOrderGross: 123450,
          startsAt: "2026-11-20T23:00:00Z",
          endsAt: "2026-12-31T22:59:00Z",
          usageLimit: 100,
          enabled: false,
        },
      });
      expect(onCreated).toHaveBeenCalledWith("code-1");
      expect(form.message.value).toBe("Kod rabatowy zapisany.");
      expect(form.view.value?.version).toBe(1);
      expect(form.dirty.value).toBe(false);
    });

    it("sends a percent as a whole number and an empty minimum, end and limit as null", async () => {
      const { form } = await fillNew();
      Object.assign(form.form.value, { kind: "percent", value: "15", minimum: "", endsAt: "", usageLimit: "" });
      mocks.post.mockResolvedValue(ok(code(), 201));

      await form.save();

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({
        kind: "percent",
        value: 15,
        minimumOrderGross: null,
        endsAt: null,
        usageLimit: null,
      });
    });

    it("converts a summer time with the summer offset", async () => {
      const { form } = await fillNew();
      form.form.value.startsAt = "2026-07-01T10:00";
      mocks.post.mockResolvedValue(ok(code(), 201));

      await form.save();

      expect(mocks.post.mock.calls[0]![1].body.startsAt).toBe("2026-07-01T08:00:00Z");
    });

    it("does not load the code it has just created", async () => {
      const { form, id } = await fillNew();
      mocks.post.mockResolvedValue(ok(code(), 201));
      await form.save();
      mocks.get.mockClear();

      id.value = "code-1";
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it.each([
      ["value", { value: "10,5", kind: "percent" }, "Podaj procent jako liczbę całkowitą."],
      ["value", { value: "", kind: "amount" }, "Podaj kwotę brutto."],
      ["value", { value: "1,234", kind: "amount" }, "Kwota może mieć najwyżej 2 miejsca po przecinku."],
      ["minimumOrderGross", { minimum: "abc" }, "Wpisz kwotę w złotych, np. 54,90."],
      ["usageLimit", { usageLimit: "1,5" }, "Podaj limit jako liczbę całkowitą."],
      ["startsAt", { startsAt: "" }, "Podaj początek kodu."],
      ["startsAt", { startsAt: "2026-03-29T02:30" }, "Ta godzina nie istnieje w czasie polskim (zmiana czasu) albo data jest niepoprawna."],
      ["endsAt", { endsAt: "2026-02-31T10:00" }, "Ta godzina nie istnieje w czasie polskim (zmiana czasu) albo data jest niepoprawna."],
    ])("does not send a value that cannot be read, and shows the error at %s", async (field, change, text) => {
      const { form } = await fillNew();
      Object.assign(form.form.value, change);

      expect(await form.save()).toBe("failure");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(form.errorsAt(field)).toEqual([text]);
      expect(form.busy.value).toBe(false);
    });

    it("clears the code text error when the next save starts", async () => {
      const { form } = await fillNew();
      mocks.post.mockResolvedValueOnce(conflict("discount-code-taken", "Taki kod już istnieje."));
      await form.save();
      expect(form.errorsAt("code")).toEqual(["Taki kod już istnieje."]);

      mocks.post.mockResolvedValue(ok(code(), 201));
      await form.save();
      expect(form.errorsAt("code")).toEqual([]);
    });
  });

  describe("revising", () => {
    it("puts the whole planned code with the version of the view", async () => {
      const { form } = await open();
      Object.assign(form.form.value, { kind: "amount", value: "20", minimum: "100", usageLimit: "5", endsAt: "2026-12-01T10:00", enabled: false });
      mocks.put.mockResolvedValue(ok(code({ version: 4 })));

      expect(await form.save()).toBe("success");

      expect(mocks.put).toHaveBeenCalledWith(CODE, {
        params: { path: { id: "code-1" } },
        body: {
          kind: "amount",
          value: 2000,
          minimumOrderGross: 10000,
          startsAt: "2026-11-20T23:00:00+00:00",
          endsAt: "2026-12-01T09:00:00Z",
          usageLimit: 5,
          enabled: false,
          version: 3,
        },
      });
      expect(form.view.value?.version).toBe(4);
      expect(form.dirty.value).toBe(false);
    });

    it("does not send the code text", async () => {
      const { form } = await open();
      form.form.value.enabled = false;
      mocks.put.mockResolvedValue(ok(code()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body).not.toHaveProperty("code");
    });

    it("sends the stored moments untouched when the times were not edited, so no seconds are lost", async () => {
      const { form } = await open(code({ startsAt: "2026-11-20T23:00:41+00:00", endsAt: "2026-12-01T10:00:07+00:00" }));
      form.form.value.enabled = false;
      mocks.put.mockResolvedValue(ok(code()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({ startsAt: "2026-11-20T23:00:41+00:00", endsAt: "2026-12-01T10:00:07+00:00" });
    });

    it("sends the stored kind, value, minimum and start of a started code whatever the fields hold", async () => {
      const { form } = await open(started({ kind: "amount", value: 2000, minimumOrderGross: 10000, usageLimit: 50 }));
      Object.assign(form.form.value, { kind: "percent", value: "90", minimum: "", startsAt: "2030-01-01T00:00", endsAt: "2026-12-31T23:59", usageLimit: "40" });
      mocks.put.mockResolvedValue(ok(started()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body).toEqual({
        kind: "amount",
        value: 2000,
        minimumOrderGross: 10000,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-12-31T22:59:00Z",
        usageLimit: 40,
        enabled: true,
        version: 3,
      });
    });

    it("lets a started code lose its limit", async () => {
      const { form } = await open(started({ usageLimit: 50 }));
      form.form.value.usageLimit = "";
      mocks.put.mockResolvedValue(ok(started()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body.usageLimit).toBeNull();
    });

    it("never adds a limit to a started code that has none", async () => {
      const { form } = await open(started({ usageLimit: null }));
      form.form.value.usageLimit = "10";
      form.form.value.enabled = false;
      mocks.put.mockResolvedValue(ok(started({ enabled: false })));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body.usageLimit).toBeNull();
    });

    it("does not send anything when nothing changed", async () => {
      const { form } = await open();

      expect(form.canSave.value).toBe(false);
      expect(await form.save()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("sends one request at a time", async () => {
      const { form } = await open();
      form.form.value.enabled = false;
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const first = form.save();
      expect(form.busy.value).toBe(true);
      expect(await form.save()).toBeNull();
      release(ok(code({ enabled: false })));
      await first;

      expect(mocks.put).toHaveBeenCalledTimes(1);
    });
  });

  describe("conflicts and errors", () => {
    async function openChanged() {
      const result = await open();
      result.form.form.value.enabled = false;
      return result;
    }

    it("puts a 409 discount-code-taken at the code field and keeps what was typed", async () => {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      Object.assign(result.form.form.value, { code: "WITAJ10", value: "10", startsAt: "2026-11-21T00:00" });
      mocks.post.mockResolvedValue(conflict("discount-code-taken", "Taki kod już istnieje."));

      expect(await result.form.save()).toBe("failure");

      expect(result.form.errorsAt("code")).toEqual(["Taki kod już istnieje."]);
      expect(result.form.error.value).toBeNull();
      expect(result.form.form.value.code).toBe("WITAJ10");
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("reloads the code when the version changed meanwhile and shows the detail", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("about:blank", "Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie."));
      serve(code({ enabled: false, endsAt: "2026-12-01T10:00:00+00:00", version: 7 }));
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.view.value?.version).toBe(7);
      expect(form.form.value.endsAt).toBe("2026-12-01T11:00");
      expect(form.error.value).toBe("Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.");
      expect(form.dirty.value).toBe(false);
    });

    it("shows the detail of a 409 discount-code-not-editable and reloads, which locks the fields", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("discount-code-not-editable", "Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty."));
      serve(started({ version: 6 }));
      mocks.get.mockClear();

      await form.save();

      expect(form.error.value).toBe("Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.");
      expect(form.termsLocked.value).toBe(true);
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("falls back to a message when a 409 has no detail", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(409, { type: "about:blank" }));

      await form.save();

      expect(form.error.value).toBe("Kod rabatowy został w międzyczasie zmieniony – pokazujemy jego aktualny stan.");
    });

    it("keeps the message of a 409 when the reload fails", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("discount-code-not-editable", "Nie można."));
      mocks.get.mockRejectedValue(new TypeError("network down"));

      await form.save();

      expect(form.error.value).toBe("Nie można.");
      expect(form.form.value.enabled).toBe(false);
    });

    it("puts the violations of a 422 at their fields", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(
        problem(422, {
          status: 422,
          violations: [
            { propertyPath: "value", title: "Rabat procentowy to 1–90%." },
            { propertyPath: "minimumOrderGross", title: "Minimum to 0,01–1 000 000 zł." },
            { propertyPath: "endsAt", title: "Koniec musi być po początku." },
            { propertyPath: "usageLimit", title: "Limit nie może być mniejszy od liczby użyć (5)." },
          ],
        }),
      );

      expect(await form.save()).toBe("failure");

      expect(form.errorsAt("value")).toEqual(["Rabat procentowy to 1–90%."]);
      expect(form.errorsAt("minimumOrderGross")).toEqual(["Minimum to 0,01–1 000 000 zł."]);
      expect(form.errorsAt("endsAt")).toEqual(["Koniec musi być po początku."]);
      expect(form.errorsAt("usageLimit")).toEqual(["Limit nie może być mniejszy od liczby użyć (5)."]);
      expect(form.error.value).toBeNull();
    });

    it("shows a violation outside the form and a 422 without violations as a message", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(422, { status: 422, violations: [{ propertyPath: "other", title: "Coś innego." }] }));
      await form.save();
      expect(form.error.value).toBe("Coś innego.");

      mocks.put.mockResolvedValue(problem(422, { status: 422, detail: "Dane są niepoprawne." }));
      await form.save();
      expect(form.error.value).toBe("Dane są niepoprawne.");
    });

    it.each([
      [403, "Nie masz uprawnień do zmiany kodów rabatowych."],
      [404, "Ten kod rabatowy już nie istnieje."],
      [503, "Usługa jest chwilowo niedostępna. Spróbuj ponownie."],
      [500, "Nie udało się wykonać operacji (HTTP 500)."],
    ])("says what a %s means and keeps the form", async (status, text) => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(status));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBe(text);
      expect(form.form.value.enabled).toBe(false);
      expect(form.busy.value).toBe(false);
    });

    it("says so when the request fails to leave", async () => {
      const { form } = await openChanged();
      mocks.put.mockRejectedValue(new TypeError("network down"));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toContain("połączenie");
      expect(form.busy.value).toBe(false);
    });

    it("ignores the answer that arrives after the form was left", async () => {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      Object.assign(result.form.form.value, { code: "NOWY", value: "10", startsAt: "2026-11-21T00:00" });
      let release!: (value: unknown) => void;
      mocks.post.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const saving = result.form.save();
      result.wrapper.unmount();
      release(ok(code(), 201));

      expect(await saving).toBeNull();
      expect(result.onCreated).not.toHaveBeenCalled();
    });
  });

  describe("refreshing", () => {
    it("discards the changes and shows the code as the API has it", async () => {
      const { form } = await open();
      form.form.value.enabled = false;
      serve(code({ version: 9 }));

      await form.refresh();

      expect(form.form.value.enabled).toBe(true);
      expect(form.view.value?.version).toBe(9);
      expect(form.dirty.value).toBe(false);
    });
  });
});
