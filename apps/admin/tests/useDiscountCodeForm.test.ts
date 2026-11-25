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
  const onLateSaved = vi.fn();
  let form!: ReturnType<typeof useDiscountCodeForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useDiscountCodeForm({ discountCodeId: () => id.value, canManage: () => canManage.value, onCreated, onLateSaved });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, wrapper, id, canManage, onCreated, onLateSaved };
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

    it("keeps the draft, the base and the version after a 409 of the version, says so, and does not read the code (E7)", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("about:blank", "Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie."));
      serve(code({ enabled: false, endsAt: "2026-12-01T10:00:00+00:00", version: 7 }));
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");

      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.form.value.enabled).toBe(false);
      expect(form.form.value.endsAt).toBe("");
      expect(form.view.value?.version).toBe(3);
      expect(form.error.value).toBe("Kod rabatowy został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
      expect(form.dirty.value).toBe(true);
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(form.locked.value).toBe(false);
    });

    it("shows the detail of a 409 discount-code-not-editable and keeps the draft, without reading the code", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("discount-code-not-editable", "Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty."));
      serve(started({ version: 6 }));
      mocks.get.mockClear();

      await form.save();

      expect(form.error.value).toBe("Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.");
      expect(form.termsLocked.value).toBe(false);
      expect(form.status.value).toBe("planned");
      expect(form.form.value.enabled).toBe(false);
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("says the changes stay when a 409 of the version has no detail", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(409, { type: "about:blank" }));

      await form.save();

      expect(form.error.value).toBe("Kod rabatowy został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
    });

    it("does not offer the server state after a 409 discount-code-taken, which keeps what was typed", async () => {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      Object.assign(result.form.form.value, { code: "WITAJ10", value: "10", startsAt: "2026-11-21T00:00" });
      mocks.post.mockResolvedValue(conflict("discount-code-taken", "Taki kod już istnieje."));

      await result.form.save();

      expect(result.form.safety.restoreOffered.value).toBe(false);
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
      [400, "Nie udało się wykonać operacji (HTTP 400)."],
    ])("says what a %s means and keeps the form", async (status, text) => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(status));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBe(text);
      expect(form.form.value.enabled).toBe(false);
      expect(form.busy.value).toBe(false);
    });

    it("says the result is unknown, not that the save failed, when the request fails to leave", async () => {
      const { form } = await openChanged();
      mocks.put.mockRejectedValue(new TypeError("network down"));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBeNull();
      expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown" });
      expect(form.busy.value).toBe(false);
      expect(form.form.value.enabled).toBe(false);
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

      await form.safety.restore();

      expect(form.form.value.enabled).toBe(true);
      expect(form.view.value?.version).toBe(9);
      expect(form.dirty.value).toBe(false);
    });
  });
  describe("discarding", () => {
    it("throws away the unsaved changes and the messages without a request to the API", async () => {
      const { form } = await open(code({ usageLimit: 10 }));
      form.form.value.enabled = false;
      form.form.value.usageLimit = "20";
      form.form.value.value = "x";
      await form.save();
      expect(form.errorsAt("value")).toEqual(["Podaj procent jako liczbę całkowitą."]);
      mocks.get.mockClear();

      form.discard();

      expect(form.form.value.enabled).toBe(true);
      expect(form.form.value.usageLimit).toBe("10");
      expect(form.form.value.value).toBe("10");
      expect(form.dirty.value).toBe(false);
      expect(form.errorsAt("value")).toEqual([]);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does nothing while a save is running", async () => {
      const { form } = await open();
      form.form.value.enabled = false;
      let release!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (release = resolve)));
      const saving = form.save();

      form.discard();

      expect(form.form.value.enabled).toBe(false);
      release(ok(code({ enabled: false, status: "disabled", version: 4 })));
      await saving;
    });
  });

  describe("the draft and the unknown result of a save (stage 2, B1, B3 and B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const NOT_ON_SERVER = "Zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
    const OTHER_VERSION = "Na serwerze jest inna wersja niż ta, którą edytujesz.";
    const LATE = "Wcześniejszy zapis został wykonany.";

    beforeEach(() => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
      vi.setSystemTime(NOW);
    });

    const advance = (ms: number) => vi.advanceTimersByTimeAsync(ms);

    /** Holds every call of a mock; `calls[n].resolve` answers the n-th. */
    function holdCalls(mock: typeof mocks.put) {
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      mock.mockImplementation(
        () =>
          new Promise((resolve, reject) => {
            calls.push({ resolve, reject });
          }),
      );
      return calls;
    }

    /** The edit of the limit typed, and the first save of it started and held. */
    async function savingFirst(limit = "5") {
      const result = await open();
      result.form.form.value.usageLimit = limit;
      const puts = holdCalls(mocks.put);
      const saving = result.form.save();
      return { ...result, puts, saving };
    }

    /** The first save ended with an error that does not reach the API: the result is unknown. */
    async function unknownEdit(limit = "5") {
      const result = await open();
      result.form.form.value.usageLimit = limit;
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      expect(await result.form.save()).toBe("failure");
      mocks.put.mockReset();
      return result;
    }

    describe("B1: the lock while a save runs", () => {
      it("locks the fields from the save to its answer, and a second save sends nothing", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        let release!: (value: unknown) => void;
        mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        expect(form.locked.value).toBe(false);

        const saving = form.save();

        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(await form.save()).toBeNull();
        release(ok(code({ usageLimit: 5, version: 4 })));
        await saving;
        expect(form.locked.value).toBe(false);
        expect(mocks.put).toHaveBeenCalledTimes(1);
      });

      it.each([
        ["a 422", () => mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "usageLimit", title: "Za duży limit." }] }))],
        ["a request that does not reach the API", () => mocks.put.mockRejectedValue(new TypeError("network down"))],
      ])("unlocks the fields with the values that were sent after %s", async (_name, fail) => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        form.form.value.endsAt = "2026-12-01T10:00";
        fail();

        expect(await form.save()).toBe("failure");

        expect(form.locked.value).toBe(false);
        expect(form.form.value.usageLimit).toBe("5");
        expect(form.form.value.endsAt).toBe("2026-12-01T10:00");
      });

      it("ignores the answer of a save that comes after another code was opened: no change, no message, no move", async () => {
        const { form, id, onCreated, onLateSaved } = await open();
        form.form.value.usageLimit = "5";
        const puts = holdCalls(mocks.put);
        const saving = form.save();
        serve(code({ id: "code-2", code: "LATO15", version: 7 }));

        id.value = "code-2";
        await flushPromises();
        // The save of the previous code does not hold this one.
        expect(form.locked.value).toBe(false);
        expect(form.busy.value).toBe(false);
        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));

        expect(await saving).toBeNull();
        expect(form.view.value?.id).toBe("code-2");
        expect(form.form.value.code).toBe("LATO15");
        expect(form.view.value?.version).toBe(7);
        expect(form.message.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
      });

      it("ignores the answer of a save that comes after the form of a new code was opened", async () => {
        const { form, id, onCreated, onLateSaved } = await open();
        form.form.value.usageLimit = "5";
        const puts = holdCalls(mocks.put);
        const saving = form.save();

        id.value = null;
        await flushPromises();
        expect(form.view.value).toBeNull();
        expect(form.locked.value).toBe(false);
        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));

        expect(await saving).toBeNull();
        expect(form.view.value).toBeNull();
        expect(form.form.value.code).toBe("");
        expect(form.message.value).toBeNull();
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
      });

      it("ignores the answer of a create that comes after the route moved to an existing code", async () => {
        const { form, id, onCreated } = await open(code(), { id: null });
        Object.assign(form.form.value, { code: "NOWY", value: "10", startsAt: "2026-11-21T00:00" });
        const posts = holdCalls(mocks.post);
        const saving = form.save();
        serve(code({ id: "code-2", code: "LATO15", version: 7 }));

        id.value = "code-2";
        await flushPromises();
        expect(form.locked.value).toBe(false);
        posts[0]!.resolve(ok(code({ id: "created", code: "NOWY" }), 201));

        expect(await saving).toBeNull();
        expect(form.view.value?.id).toBe("code-2");
        expect(form.message.value).toBeNull();
        expect(onCreated).not.toHaveBeenCalled();
      });

      it("ignores the answer of a create that comes after the form of a new code was opened again", async () => {
        const { form, id, onCreated } = await open(code(), { id: null });
        Object.assign(form.form.value, { code: "NOWY", value: "10", startsAt: "2026-11-21T00:00" });
        const posts = holdCalls(mocks.post);
        const saving = form.save();

        // To an existing code and back to the form of a new one, while the create runs.
        serve(code({ id: "code-2", code: "LATO15", version: 7 }));
        id.value = "code-2";
        await flushPromises();
        id.value = null;
        await flushPromises();
        posts[0]!.resolve(ok(code({ id: "created", code: "NOWY" }), 201));

        expect(await saving).toBeNull();
        expect(form.view.value).toBeNull();
        expect(form.form.value.code).toBe("");
        expect(form.message.value).toBeNull();
        expect(onCreated).not.toHaveBeenCalled();
      });
    });

    describe("B3: a read that replaces the form", () => {
      it("says „Odświeżamy…”, locks the fields, and replaces the draft only with the answer 200", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

        const refreshing = form.safety.restore();

        expect(form.safety.status.value).toBe("Odświeżamy…");
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(form.form.value.usageLimit).toBe("5");
        release(ok(code({ version: 9 })));
        await refreshing;

        expect(form.form.value.usageLimit).toBe("");
        expect(form.view.value?.version).toBe(9);
        expect(form.locked.value).toBe(false);
        expect(form.safety.status.value).toBe("");
      });

      it("unlocks the fields 10 s after the read with a message to try again, keeps the form and ignores the late answer, also after a field was changed", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const refreshing = form.safety.restore();

        await advance(9_999);
        expect(form.locked.value).toBe(true);
        await advance(1);

        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
        form.form.value.usageLimit = "6";
        release(ok(code({ usageLimit: 9, version: 9 })));
        await refreshing;

        expect(form.form.value.usageLimit).toBe("6");
        expect(form.view.value?.version).toBe(3);
        expect(form.locked.value).toBe(false);
      });
    });

    describe("a 409 of a save (E7)", () => {
      it("reads nothing, whatever the 409 is, and the draft is replaced only by „Odrzuć moje zmiany i pokaż stan z serwera” after the answer 200", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        mocks.get.mockClear();
        await form.save();
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
        expect(form.safety.restoreOffered.value).toBe(true);

        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const restoring = form.safety.restore();
        expect(form.form.value.usageLimit).toBe("5");
        expect(form.locked.value).toBe(true);
        release(ok(code({ usageLimit: 8, version: 7 })));
        await restoring;

        expect(form.form.value.usageLimit).toBe("8");
        expect(form.view.value?.version).toBe(7);
        expect(form.error.value).toBeNull();
        expect(form.safety.restoreOffered.value).toBe(false);
        expect(form.dirty.value).toBe(false);
      });

      it.each([
        ["an error", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
        ["a 500", () => mocks.get.mockResolvedValue(problem(500))],
        ["no answer within the limit", () => mocks.get.mockReturnValue(new Promise(() => {}))],
      ])("keeps the draft and the message after %s of „Odrzuć moje zmiany…”, unlocks, and offers the button again", async (_name, fail) => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        await form.save();
        fail();

        const restoring = form.safety.restore();
        await advance(10_000);
        await restoring;

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.view.value?.version).toBe(3);
        expect(form.error.value).toContain("w międzyczasie zmieniony");
        expect(form.locked.value).toBe(false);
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.readFailed.value).toBe(true);
      });

      it("does not read the code after a 409 discount-code-taken either", async () => {
        const result = await start({ id: null });
        wrapper = result.wrapper;
        Object.assign(result.form.form.value, { code: "WITAJ10", value: "10", startsAt: "2026-11-21T00:00" });
        mocks.post.mockResolvedValue(conflict("discount-code-taken", "Taki kod już istnieje."));

        await result.form.save();
        await advance(30_000);

        expect(result.form.errorsAt("code")).toEqual(["Taki kod już istnieje."]);
        expect(mocks.get).not.toHaveBeenCalled();
      });
    });

    describe("B4 (a): the limit of a save", () => {
      it("says after 10 s that the result is unknown, keeps the fields locked, and does not abort the request; a released success is an ordinary success", async () => {
        const { form, puts, saving } = await savingFirst();

        await advance(9_999);
        expect(form.safety.unknown.value).toBeNull();
        await advance(1);

        expect(form.safety.unknown.value).toMatchObject({ pending: true, text: UNKNOWN });
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(mocks.put.mock.calls[0]![1]).not.toHaveProperty("signal");
        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));

        expect(await saving).toBe("success");
        expect(form.view.value?.version).toBe(4);
        expect(form.message.value).toBe("Kod rabatowy zapisany.");
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.dirty.value).toBe(false);
      });

      it("shows the errors of a 422 that comes after the limit at their fields, without the message of an unknown result", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);

        puts[0]!.resolve(problem(422, { violations: [{ propertyPath: "usageLimit", title: "Za duży limit." }] }));

        expect(await saving).toBe("failure");
        expect(form.errorsAt("usageLimit")).toEqual(["Za duży limit."]);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.usageLimit).toBe("5");
      });
    });

    describe("B4 (b): an error, a 502, a 504", () => {
      it.each([
        ["a request that does not reach the API", () => mocks.put.mockRejectedValue(new TypeError("network down"))],
        ["a 502", () => mocks.put.mockResolvedValue(problem(502))],
        ["a 504", () => mocks.put.mockResolvedValue(problem(504))],
        ["a 503", () => mocks.put.mockResolvedValue(problem(503))],
        ["a 500", () => mocks.put.mockResolvedValue(problem(500))],
      ])("makes the result unknown after %s, unlocks with the draft, and says nothing of a failure", async (_name, answer) => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        form.form.value.enabled = false;
        answer();

        expect(await form.save()).toBe("failure");

        expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN });
        expect(form.error.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.usageLimit).toBe("5");
        expect(form.form.value.enabled).toBe(false);
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(true);
      });

      it("puts the errors of a 422 at the fields without that message", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "usageLimit", title: "Za duży limit." }] }));

        await form.save();

        expect(form.errorsAt("usageLimit")).toEqual(["Za duży limit."]);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("keeps saying that the earlier save may have been done when the next one gets a 422", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(problem(502));
        await form.save();
        form.form.value.usageLimit = "6";
        mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "usageLimit", title: "Za duży limit." }] }));

        expect(await form.save()).toBe("failure");

        expect(form.errorsAt("usageLimit")).toEqual(["Za duży limit."]);
        expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: "Nie wiemy, czy wcześniejszy zapis się powiódł. Twoje zmiany zostają w formularzu." });
        expect(form.form.value.usageLimit).toBe("6");
      });
    });

    describe("B4 (c): „Sprawdź stan”", () => {
      it("takes the code from the server when it equals the draft, and sends no write", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 5, version: 4 })));

        await form.safety.check();

        expect(form.view.value?.version).toBe(4);
        expect(form.dirty.value).toBe(false);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.status.value).toBe("Stan na serwerze odpowiada formularzowi.");
        expect(form.locked.value).toBe(false);
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it("leaves the draft, the base and the version when the server has other values with the same version, and says so", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(code({ usageLimit: null, version: 3 })));

        await form.safety.check();

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(true);
        expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
        expect(form.locked.value).toBe(false);
        expect(mocks.put).not.toHaveBeenCalled();
      });

      it("leaves them too and offers the server state when the version differs", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 9, version: 6 })));

        await form.safety.check();

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value).toMatchObject({ kind: "otherVersion", text: OTHER_VERSION });
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(mocks.put).not.toHaveBeenCalled();
      });

      it.each([
        ["an error", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
        ["a 500", () => mocks.get.mockResolvedValue(problem(500))],
        ["the limit", () => mocks.get.mockReturnValue(new Promise(() => {}))],
      ])("keeps the draft and the message of the unknown result after %s", async (_name, fail) => {
        const { form } = await unknownEdit();
        fail();

        const checking = form.safety.check();
        await advance(10_000);
        await checking;

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value).toMatchObject({ kind: "unknown", text: UNKNOWN });
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
      });

      it("locks the fields while it reads", async () => {
        const { form } = await unknownEdit();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

        const checking = form.safety.check();

        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        release(ok(code({ usageLimit: 5, version: 4 })));
        await checking;
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (d): what counts as the same request", () => {
      /** The first save of the limit 5 ended unknown; `change` edits the draft; the read answers `served`. */
      async function checkWith(change: (form: ReturnType<typeof useDiscountCodeForm>) => void, served: Code) {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockRejectedValueOnce(new TypeError("network down"));
        await form.save();
        change(form);
        mocks.get.mockResolvedValue(ok(served));
        await form.safety.check();
        return form;
      }
      const PLAIN = code({ usageLimit: 5, version: 4 });

      it("is not the same when one field differs", async () => {
        const form = await checkWith((current) => (current.form.value.value = "11"), PLAIN);
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when the switch differs", async () => {
        const form = await checkWith((current) => (current.form.value.enabled = false), PLAIN);
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when the limit that was typed is another one", async () => {
        const form = await checkWith((current) => (current.form.value.usageLimit = "50"), PLAIN);
        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.usageLimit).toBe("50");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is the same when the server writes the moments in another form", async () => {
        const form = await checkWith(() => {}, code({ usageLimit: 5, version: 4, startsAt: "2026-11-21T00:00:00+01:00", endsAt: null }));
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("is the same for an end typed in Polish time and the same moment in UTC on the server", async () => {
        const form = await checkWith(
          (current) => (current.form.value.endsAt = "2026-12-01T10:00"),
          code({ usageLimit: 5, version: 4, endsAt: "2026-12-01T09:00:00.000Z" }),
        );
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("is not the same when an end that was typed is another moment than the one on the server", async () => {
        const form = await checkWith(
          (current) => (current.form.value.endsAt = "2026-12-01T10:00"),
          code({ usageLimit: 5, version: 4, endsAt: "2026-12-01T10:00:00+00:00" }),
        );
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when no request can be built from the draft, and the check puts no error at the field", async () => {
        const form = await checkWith((current) => (current.form.value.value = "abc"), PLAIN);
        expect(form.view.value?.version).toBe(3);
        expect(form.errorsAt("value")).toEqual([]);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("compares a started code by the terms it was edited with, so only the end, the switch and the limit of the draft count", async () => {
        const { form } = await open(started({ usageLimit: 50 }));
        form.form.value.endsAt = "2026-12-31T23:59";
        mocks.put.mockRejectedValueOnce(new TypeError("network down"));
        await form.save();
        mocks.get.mockResolvedValue(ok(started({ usageLimit: 50, endsAt: "2026-12-31T22:59:00+00:00", version: 4 })));

        await form.safety.check();

        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
      });
    });

    describe("B4 (e) and (f): the draft changed after the unlock", () => {
      it("(e) keeps what was typed after the unlock, whatever „Sprawdź stan” finds, without the message of a match and without a write", async () => {
        const { form } = await unknownEdit("5");
        form.form.value.usageLimit = "6";
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 5, version: 4 })));

        await form.safety.check();

        expect(form.form.value.usageLimit).toBe("6");
        expect(form.dirty.value).toBe(true);
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.status.value).toBe("");
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it("(f) keeps the changed field after the earlier save is done and the next one gets a 409, and reads nothing by itself", async () => {
        const { form, puts, saving, onLateSaved } = await savingFirst("5");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(code({ version: 3 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");
        form.form.value.usageLimit = "6";
        const second = form.save();
        mocks.get.mockClear();

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;
        puts[1]!.resolve(conflict("about:blank", "Ktoś zmienił."));
        await second;

        expect(form.form.value.usageLimit).toBe("6");
        expect(form.view.value?.version).toBe(3);
        expect(form.error.value).toContain("w międzyczasie zmieniony");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
      });
    });

    describe("B4 (g): „Odrzuć moje zmiany i pokaż stan z serwera” after a check", () => {
      it("replaces the draft only with the answer 200", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 9, version: 6 })));
        await form.safety.check();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

        const restoring = form.safety.restore();
        expect(form.form.value.usageLimit).toBe("5");
        release(ok(code({ usageLimit: 9, version: 6 })));
        await restoring;

        expect(form.form.value.usageLimit).toBe("9");
        expect(form.view.value?.version).toBe(6);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.restoreOffered.value).toBe(false);
      });

      it("keeps the draft and the messages after an error and after the limit", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 9, version: 6 })));
        await form.safety.check();
        mocks.get.mockRejectedValue(new TypeError("network down"));

        await form.safety.restore();

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.locked.value).toBe(false);

        mocks.get.mockReturnValue(new Promise(() => {}));
        const again = form.safety.restore();
        await advance(10_000);
        await again;

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
      });
    });

    describe("B4 (h) and (i): the earlier save answers during or after the check", () => {
      it("(h) keeps the draft and leaves nothing locked when it answers before the answer of the read", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const checking = form.safety.check();

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;
        expect(form.form.value.usageLimit).toBe("5");
        expect(form.view.value?.version).toBe(3);
        release(ok(code({ version: 3 })));
        await checking;

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.locked.value).toBe(false);
        expect(form.safety.writing.value).toBe(false);
      });

      it("(h) keeps the draft and leaves nothing locked when it answers after the answer of the read", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(code({ version: 3 })));
        await form.safety.check();

        puts[0]!.reject(new TypeError("network down"));
        await saving;

        expect(form.form.value.usageLimit).toBe("5");
        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.kind).toBe("missing");
      });

      it("(i) does not take the lock of the newer save and adds no message of the earlier one when its error and end come after the newer save started: the one „Sprawdź stan” found stays as it was", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(code({ version: 3 })));
        await form.safety.check();
        form.form.value.usageLimit = "6";
        const second = form.save();
        expect(form.locked.value).toBe(true);
        const before = form.safety.unknown.value;

        puts[0]!.reject(new TypeError("network down"));
        await saving;

        expect(form.locked.value).toBe(true);
        expect(form.busy.value).toBe(true);
        expect(form.safety.unknown.value).toEqual(before);
        expect(form.error.value).toBeNull();
        puts[1]!.resolve(ok(code({ usageLimit: 6, version: 4 })));
        expect(await second).toBe("success");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (j): the late success of the earlier save", () => {
      async function afterCheck() {
        const result = await savingFirst("5");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(code({ version: 3 })));
        await result.form.safety.check();
        result.form.form.value.usageLimit = "6";
        const second = result.form.save();
        return { ...result, second };
      }

      it("only says so in a toast after the success of the newer save, and changes no field, version or dirty flag", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(ok(code({ usageLimit: 6, version: 5 })));
        await second;

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(form.form.value.usageLimit).toBe("6");
        expect(form.view.value?.version).toBe(5);
        expect(form.dirty.value).toBe(false);
      });

      it("keeps the errors of the newer save after its 422", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(problem(422, { violations: [{ propertyPath: "usageLimit", title: "Za duży limit." }] }));
        await second;

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.errorsAt("usageLimit")).toEqual(["Za duży limit."]);
        expect(form.form.value.usageLimit).toBe("6");
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(true);
      });

      it("keeps the lock of the newer save while it runs", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.locked.value).toBe(true);
        expect(form.form.value.usageLimit).toBe("6");
        puts[1]!.resolve(ok(code({ usageLimit: 6, version: 5 })));
        await second;
      });

      it("says nothing when the staff member moved to another code, and changes nothing there", async () => {
        const { form, id, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(ok(code({ usageLimit: 6, version: 5 })));
        await second;
        serve(code({ id: "code-2", code: "LATO15", version: 2 }));
        id.value = "code-2";
        await flushPromises();

        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.code).toBe("LATO15");
        expect(form.view.value?.version).toBe(2);
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (k): moving to another code", () => {
      it("ignores the answers of a save in progress, with no toast, no message, no move and no lock", async () => {
        const { form, id, puts, saving, onCreated, onLateSaved } = await savingFirst();
        await advance(10_000);
        expect(form.safety.unknown.value).not.toBeNull();
        serve(code({ id: "code-2", code: "LATO15", version: 2 }));
        id.value = "code-2";
        await flushPromises();

        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.code).toBe("LATO15");
        expect(form.message.value).toBeNull();
      });

      it("ignores the answer of a check that comes after that", async () => {
        const { form, id } = await unknownEdit();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const checking = form.safety.check();
        serve(code({ id: "code-2", code: "LATO15", version: 2 }));
        id.value = "code-2";
        await flushPromises();

        release(ok(code({ usageLimit: 5, version: 4 })));
        await checking;

        expect(form.form.value.code).toBe("LATO15");
        expect(form.view.value?.id).toBe("code-2");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("discarding the draft after a 409 or an unknown result", () => {
      it("keeps the way to the server state after a 409 of the version, with the context that the version is another, and nothing unknown", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        await form.save();
        expect(form.safety.restoreOffered.value).toBe(true);

        form.discard();

        expect(form.error.value).toBeNull();
        expect(form.form.value.usageLimit).toBe("");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.restoreNote.value).toBe(OTHER_VERSION);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("gives a 409 of another kind its own context, not the version, and nothing unknown", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(conflict("discount-code-not-editable", "Tej zmiany nie można wprowadzić."));
        await form.save();

        form.discard();

        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.restoreNote.value).toBe("Tej zmiany nie można wprowadzić.");
        expect(form.safety.restoreNote.value).not.toContain("inna wersja");
        expect(form.safety.unknown.value).toBeNull();
      });

      it("keeps the unknown result of a save after a 502, without the sentence that the changes stay, and „Sprawdź stan” compares the restored base", async () => {
        const { form } = await open();
        form.form.value.usageLimit = "5";
        mocks.put.mockResolvedValue(problem(502));
        await form.save();

        form.discard();

        expect(form.form.value.usageLimit).toBe("");
        expect(form.safety.unknown.value?.text).toBe("Nie wiemy, czy zapis się powiódł.");
        mocks.get.mockResolvedValue(ok(code({ usageLimit: 5, version: 4 })));
        await form.safety.check();
        expect(form.safety.unknown.value).toMatchObject({ kind: "otherVersion" });
        expect(form.form.value.usageLimit).toBe("");
        expect(form.view.value?.version).toBe(3);
      });

      it("keeps what „Sprawdź stan” found, and the answer of that save that comes later is only a toast", async () => {
        const { form, puts, saving, onLateSaved } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(code({ version: 3 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");

        form.discard();

        expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
        puts[0]!.resolve(ok(code({ usageLimit: 5, version: 4 })));
        await saving;
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.form.value.usageLimit).toBe("");
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(false);
      });
    });

    describe("B4 (l): a new code", () => {
      const UNKNOWN_NEW =
        "Nie wiemy, czy kod rabatowy został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.";
      const TAKEN = "Taki kod już istnieje.";

      async function startNew() {
        const result = await start({ id: null });
        wrapper = result.wrapper;
        Object.assign(result.form.form.value, { code: "NOWY", value: "10", startsAt: "2026-11-21T00:00" });
        return result;
      }

      async function fillNew() {
        const result = await startNew();
        const posts = holdCalls(mocks.post);
        const saving = result.form.save();
        return { ...result, posts, saving };
      }

      it("unlocks with „Odblokuj formularz”, says the code may still be created, and a changed field is kept", async () => {
        const { form, posts, saving } = await fillNew();
        await advance(10_000);
        expect(form.safety.unknown.value).toMatchObject({ pending: true, created: true, text: UNKNOWN });

        form.safety.unblock();

        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        form.form.value.code = "INNY";
        posts[0]!.resolve(ok(code({ id: "created", code: "NOWY" }), 201));
        await saving;
        expect(form.form.value.code).toBe("INNY");
      });

      it("says the same after an error or a 5xx, with the draft unlocked", async () => {
        const result = await startNew();
        mocks.post.mockResolvedValue(problem(502));

        await result.form.save();

        expect(result.form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        expect(result.form.locked.value).toBe(false);
        expect(result.form.form.value.code).toBe("NOWY");
      });

      it("shows a toast for the late success and does not change the fields or the route", async () => {
        const { form, posts, saving, onCreated, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.code = "INNY";

        posts[0]!.resolve(ok(code({ id: "created", code: "NOWY" }), 201));
        await saving;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(onCreated).not.toHaveBeenCalled();
        expect(form.view.value).toBeNull();
        expect(form.form.value.code).toBe("INNY");
      });

      it("adds that the earlier save may have created it to a 409 discount-code-taken that follows", async () => {
        const { form } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        mocks.post.mockResolvedValue(conflict("discount-code-taken", TAKEN));

        await form.save();

        expect(form.errorsAt("code")).toEqual([`${TAKEN} Możliwe, że utworzył go Twój wcześniejszy zapis.`]);
      });

      it("adds the same note after an earlier save that ended with an error, not only after „Odblokuj formularz”", async () => {
        const result = await startNew();
        mocks.post.mockRejectedValueOnce(new TypeError("network down"));
        await result.form.save();
        mocks.post.mockResolvedValue(conflict("discount-code-taken", TAKEN));

        await result.form.save();

        expect(result.form.errorsAt("code")).toEqual([`${TAKEN} Możliwe, że utworzył go Twój wcześniejszy zapis.`]);
      });

      it("says nothing when the earlier creation answers after a later one made the code and the route moved to it", async () => {
        const { form, id, posts, saving, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.code = "DRUGI";
        const second = form.save();
        posts[1]!.resolve(ok(code({ id: "code-b", code: "DRUGI" }), 201));
        await second;
        serve(code({ id: "code-b", code: "DRUGI" }));
        id.value = "code-b";
        await flushPromises();

        posts[0]!.resolve(ok(code({ id: "code-a", code: "NOWY" }), 201));
        await saving;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.view.value?.id).toBe("code-b");
        expect(form.form.value.code).toBe("DRUGI");
      });

      it("adds no such note to a 409 when no earlier save ended unknown", async () => {
        const result = await startNew();
        mocks.post.mockResolvedValue(conflict("discount-code-taken", TAKEN));

        await result.form.save();

        expect(result.form.errorsAt("code")).toEqual([TAKEN]);
      });
    });
  });
});
