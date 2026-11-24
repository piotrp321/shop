import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useDeliveryMethodForm } from "@/delivery/useDeliveryMethodForm";

type Method = components["schemas"]["DeliveryMethodResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
const NOT_ON_SERVER = "Zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
const OTHER_VERSION = "Na serwerze jest inna wersja niż ta, którą edytujesz.";
const LATE = "Wcześniejszy zapis został wykonany.";
const MODIFIED = "Metoda dostawy została w międzyczasie zmieniona. Twoje zmiany zostają w formularzu.";
const UNKNOWN_NEW =
  "Nie wiemy, czy metoda dostawy została utworzona. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.";
const TAKEN = "Metoda dostawy o tej nazwie już istnieje.";

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

function ok(data: unknown, status = 200) {
  return { data, response: { status } };
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

function conflict(kind: string, detail = "Szczegóły z API.") {
  return problem(409, { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail });
}

function serve(data: Method | null = method()) {
  mocks.get.mockImplementation(async () => (data ? ok(data) : problem(404)));
}

async function start(options: { id?: string | null } = {}) {
  const id = ref<string | null>(options.id === undefined ? "method-1" : options.id);
  const onSaved = vi.fn();
  const onLateSaved = vi.fn();
  let form!: ReturnType<typeof useDeliveryMethodForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useDeliveryMethodForm({ methodId: () => id.value, onSaved, onLateSaved });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, wrapper, id, onSaved, onLateSaved };
}

describe("useDeliveryMethodForm: the draft and the unknown result of a save (stage 2, B1, B3 and B4)", () => {
  let wrapper: Awaited<ReturnType<typeof start>>["wrapper"] | undefined;

  beforeEach(() => {
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    serve();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  const advance = (ms: number) => vi.advanceTimersByTimeAsync(ms);

  async function open(data: Method = method(), options: { id?: string | null } = {}) {
    serve(data);
    const result = await start(options);
    wrapper = result.wrapper;
    return result;
  }

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

  /** The price typed, and the first save of it started and held. */
  async function savingFirst(price = "25,00") {
    const result = await open();
    result.form.form.value.price = price;
    const puts = holdCalls(mocks.put);
    const saving = result.form.save();
    return { ...result, puts, saving };
  }

  /** The first save ended with an error that does not reach the API: the result is unknown. */
  async function unknownEdit(price = "25,00") {
    const result = await open();
    result.form.form.value.price = price;
    mocks.put.mockRejectedValueOnce(new TypeError("network down"));
    expect(await result.form.save()).toBe("failure");
    mocks.put.mockReset();
    return result;
  }

  describe("B1: the lock while a save runs", () => {
    it("locks the fields from the save to its answer, and a second save sends nothing", async () => {
      const { form, puts, saving } = await savingFirst();

      expect(form.locked.value).toBe(true);
      expect(form.busy.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(await form.save()).toBeNull();
      expect(mocks.put).toHaveBeenCalledTimes(1);
      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      expect(await saving).toBe("success");
      expect(form.locked.value).toBe(false);
    });

    it("unlocks the fields with the values that were sent after a 422", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "priceGross", title: "Za wysoka cena." }] }));

      expect(await form.save()).toBe("failure");

      expect(form.locked.value).toBe(false);
      expect(form.form.value.price).toBe("25,00");
      expect(form.errorsAt("priceGross")).toEqual(["Za wysoka cena."]);
      expect(form.safety.unknown.value).toBeNull();
    });

    it("keeps saying that the earlier save may have been done when the next one gets a 422", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(problem(502));
      await form.save();
      form.form.value.price = "99999,00";
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "priceGross", title: "Za wysoka cena." }] }));

      expect(await form.save()).toBe("failure");

      expect(form.errorsAt("priceGross")).toEqual(["Za wysoka cena."]);
      expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: "Nie wiemy, czy wcześniejszy zapis się powiódł. Twoje zmiany zostają w formularzu." });
      expect(form.form.value.price).toBe("99999,00");
    });
  });

  describe("B3: a read that replaces the form", () => {
    it("says „Odświeżamy…”, locks the fields, and replaces the draft only with the answer 200", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const restoring = form.safety.restore();

      expect(form.safety.status.value).toBe("Odświeżamy…");
      expect(form.locked.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(form.form.value.price).toBe("25,00");
      release(ok(method({ version: 9 })));
      await restoring;

      expect(form.form.value.price).toBe("19,99");
      expect(form.view.value?.version).toBe(9);
      expect(form.locked.value).toBe(false);
    });

    it("unlocks the fields 10 s after the read with a message to try again, and ignores the late answer, also after a field was changed", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const restoring = form.safety.restore();

      await advance(9_999);
      expect(form.locked.value).toBe(true);
      await advance(1);

      expect(form.locked.value).toBe(false);
      expect(form.safety.readFailed.value).toBe(true);
      form.form.value.price = "26,00";
      release(ok(method({ priceGross: 100, version: 9 })));
      await restoring;

      expect(form.form.value.price).toBe("26,00");
      expect(form.view.value?.version).toBe(3);
    });
  });

  describe("a 409 of a save (E7)", () => {
    it("keeps the draft, the base and the version after a 409 of the version, says the changes stay, and reads nothing", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(conflict("about:blank", "Odśwież ją i spróbuj ponownie."));
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");
      await advance(30_000);

      expect(form.error.value).toBe(MODIFIED);
      expect(form.form.value.price).toBe("25,00");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("says the changes stay when the 409 of the version has no detail, too", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(problem(409, { type: "about:blank", status: 409 }));

      await form.save();

      expect(form.error.value).toBe(MODIFIED);
    });

    it("replaces the draft only after „Odrzuć moje zmiany i pokaż stan z serwera” got the answer 200", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(conflict("about:blank"));
      await form.save();
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const restoring = form.safety.restore();
      expect(form.form.value.price).toBe("25,00");
      expect(form.locked.value).toBe(true);
      release(ok(method({ priceGross: 800, version: 7 })));
      await restoring;

      expect(form.form.value.price).toBe("8,00");
      expect(form.view.value?.version).toBe(7);
      expect(form.error.value).toBeNull();
      expect(form.safety.restoreOffered.value).toBe(false);
      expect(form.dirty.value).toBe(false);
    });

    it("does not offer the server state after a 409 on the name or on the limit, which keep what was typed", async () => {
      const { form } = await open();
      form.form.value.name = "Kurier 2";
      mocks.put.mockResolvedValue(conflict("delivery-method-name-taken", TAKEN));
      mocks.get.mockClear();

      await form.save();
      await advance(30_000);

      expect(form.error.value).toBe(TAKEN);
      expect(form.safety.restoreOffered.value).toBe(false);
      expect(form.form.value.name).toBe("Kurier 2");
      expect(mocks.get).not.toHaveBeenCalled();
    });
  });

  describe("B4 (a): the limit of a save", () => {
    it("says after 10 s that the result is unknown, keeps the fields locked, and does not abort the request; a released success is an ordinary success", async () => {
      const { form, puts, saving, onSaved } = await savingFirst();

      await advance(9_999);
      expect(form.safety.unknown.value).toBeNull();
      await advance(1);

      expect(form.safety.unknown.value).toMatchObject({ pending: true, text: UNKNOWN });
      expect(form.locked.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(mocks.put.mock.calls[0]![1]).not.toHaveProperty("signal");
      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));

      expect(await saving).toBe("success");
      expect(form.view.value?.version).toBe(4);
      expect(form.message.value).toBe("Metoda dostawy zapisana.");
      expect(form.safety.unknown.value).toBeNull();
      expect(form.locked.value).toBe(false);
      expect(form.dirty.value).toBe(false);
      expect(onSaved).toHaveBeenCalledTimes(1);
    });

    it("shows the errors of a 422 that comes after the limit at their fields, without the message of an unknown result", async () => {
      const { form, puts, saving } = await savingFirst();
      await advance(10_000);

      puts[0]!.resolve(problem(422, { violations: [{ propertyPath: "priceGross", title: "Za wysoka cena." }] }));

      expect(await saving).toBe("failure");
      expect(form.errorsAt("priceGross")).toEqual(["Za wysoka cena."]);
      expect(form.safety.unknown.value).toBeNull();
      expect(form.locked.value).toBe(false);
      expect(form.form.value.price).toBe("25,00");
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
      form.form.value.price = "25,00";
      form.form.value.active = false;
      answer();

      expect(await form.save()).toBe("failure");

      expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN });
      expect(form.error.value).toBeNull();
      expect(form.locked.value).toBe(false);
      expect(form.form.value.price).toBe("25,00");
      expect(form.form.value.active).toBe(false);
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
    });

    it("puts the errors of a 422 at the fields without that message", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "priceGross", title: "Za wysoka cena." }] }));

      await form.save();

      expect(form.errorsAt("priceGross")).toEqual(["Za wysoka cena."]);
      expect(form.safety.unknown.value).toBeNull();
    });
  });

  describe("B4 (c): „Sprawdź stan”", () => {
    it("takes the method from the server when it equals the draft, and sends no write", async () => {
      const { form } = await unknownEdit();
      mocks.get.mockResolvedValue(ok(method({ priceGross: 2500, version: 4 })));

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
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));

      await form.safety.check();

      expect(form.form.value.price).toBe("25,00");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
      expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
      expect(form.locked.value).toBe(false);
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("leaves them too and offers the server state when the version differs", async () => {
      const { form } = await unknownEdit();
      mocks.get.mockResolvedValue(ok(method({ priceGross: 900, version: 6 })));

      await form.safety.check();

      expect(form.form.value.price).toBe("25,00");
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

      expect(form.form.value.price).toBe("25,00");
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
      release(ok(method({ priceGross: 2500, version: 4 })));
      await checking;
      expect(form.locked.value).toBe(false);
    });
  });

  describe("B4 (d): what counts as the same request", () => {
    /** The first save of the price 25,00 ended unknown; `change` edits the draft; the read answers `served`. */
    async function checkWith(change: (form: ReturnType<typeof useDeliveryMethodForm>) => void, served: Method) {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      change(form);
      mocks.get.mockResolvedValue(ok(served));
      await form.safety.check();
      return form;
    }
    const PLAIN = method({ priceGross: 2500, version: 4 });

    it("is the same when the draft equals the object on the server", async () => {
      const form = await checkWith(() => {}, PLAIN);
      expect(form.view.value?.version).toBe(4);
      expect(form.safety.unknown.value).toBeNull();
    });

    it.each([
      ["the name", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.name = "Kurier DPD")],
      ["the description", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.description = "Inny opis")],
      ["the threshold", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.freeFrom = "300,00")],
      ["the switch", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.active = false)],
      ["the position", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.position = "11")],
      ["the link", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.trackingUrlTemplate = "https://tracking.example.com/t/{numer}")],
    ])("is not the same when one field differs: %s", async (_name, change) => {
      const form = await checkWith(change, PLAIN);
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
      expect(form.safety.unknown.value?.kind).toBe("otherVersion");
    });

    it.each([
      ["the name", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.name = "Kurier ")],
      ["the description", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.description = " Dostawa w 2–3 dni robocze")],
      ["the link", (form: ReturnType<typeof useDeliveryMethodForm>) => (form.form.value.trackingUrlTemplate = "https://tracking.example.com/{numer} ")],
    ])("is not the same when a space that is not trimmed makes the difference, as the text is sent as typed: %s", async (_name, change) => {
      const form = await checkWith(change, PLAIN);
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
      expect(form.safety.unknown.value?.kind).toBe("otherVersion");
    });

    it("sends no list: the request of the form holds only scalar fields, so there is no order of a list to compare", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();

      const body = mocks.put.mock.calls[0]![1].body as Record<string, unknown>;

      expect(Object.values(body).some((value) => Array.isArray(value))).toBe(false);
    });

    it("is the same when an empty description and an empty link are null on the server", async () => {
      const { form } = await open(method({ description: "Dostawa", trackingUrlTemplate: null, freeFromGross: null }));
      form.form.value.description = "   ";
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      mocks.get.mockResolvedValue(ok(method({ description: "", trackingUrlTemplate: null, freeFromGross: null, version: 4 })));

      await form.safety.check();

      expect(form.view.value?.version).toBe(4);
      expect(form.safety.unknown.value).toBeNull();
    });

    it("is not the same when no request can be built from the draft, and the check puts no error at the field", async () => {
      const form = await checkWith((current) => (current.form.value.price = "abc"), PLAIN);
      expect(form.view.value?.version).toBe(3);
      expect(form.errorsAt("priceGross")).toEqual([]);
      expect(form.safety.unknown.value?.kind).toBe("otherVersion");
    });
  });

  describe("B4 (e) and (f): the draft changed after the unlock", () => {
    it("(e) keeps what was typed after the unlock, whatever „Sprawdź stan” finds, without the message of a match and without a write", async () => {
      const { form } = await unknownEdit("25,00");
      form.form.value.name = "Druga";
      mocks.get.mockResolvedValue(ok(method({ priceGross: 2500, version: 4 })));

      await form.safety.check();

      expect(form.form.value.name).toBe("Druga");
      expect(form.dirty.value).toBe(true);
      expect(form.view.value?.version).toBe(3);
      expect(form.safety.status.value).toBe("");
      expect(mocks.put).not.toHaveBeenCalled();
      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("(e) with the name „Pierwsza” sent and „Druga” typed after the unlock", async () => {
      const { form } = await open();
      form.form.value.name = "Pierwsza";
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      form.form.value.name = "Druga";
      mocks.get.mockResolvedValue(ok(method({ name: "Pierwsza", version: 4 })));

      await form.safety.check();

      expect(form.form.value.name).toBe("Druga");
      expect(form.dirty.value).toBe(true);
      expect(form.view.value?.version).toBe(3);
      expect(form.safety.status.value).toBe("");
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("(f) keeps the changed field after the earlier save is done and the next one gets a 409, and reads nothing by itself", async () => {
      const { form, puts, saving, onLateSaved } = await savingFirst("25,00");
      await advance(10_000);
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));
      await form.safety.check();
      expect(form.safety.unknown.value?.kind).toBe("missing");
      form.form.value.price = "26,00";
      const second = form.save();
      mocks.get.mockClear();

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;
      puts[1]!.resolve(conflict("about:blank", "Ktoś zmienił."));
      await second;

      expect(form.form.value.price).toBe("26,00");
      expect(form.view.value?.version).toBe(3);
      expect(form.error.value).toBe(MODIFIED);
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(onLateSaved).toHaveBeenCalledTimes(1);
      await advance(30_000);
      expect(mocks.get).not.toHaveBeenCalled();
    });
  });

  describe("B4 (g): „Odrzuć moje zmiany i pokaż stan z serwera” after a check", () => {
    it("replaces the draft only with the answer 200", async () => {
      const { form } = await unknownEdit();
      mocks.get.mockResolvedValue(ok(method({ priceGross: 900, version: 6 })));
      await form.safety.check();
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const restoring = form.safety.restore();
      expect(form.form.value.price).toBe("25,00");
      release(ok(method({ priceGross: 900, version: 6 })));
      await restoring;

      expect(form.form.value.price).toBe("9,00");
      expect(form.view.value?.version).toBe(6);
      expect(form.safety.unknown.value).toBeNull();
      expect(form.safety.restoreOffered.value).toBe(false);
    });

    it("keeps the draft and the messages after an error and after the limit", async () => {
      const { form } = await unknownEdit();
      mocks.get.mockResolvedValue(ok(method({ priceGross: 900, version: 6 })));
      await form.safety.check();
      mocks.get.mockRejectedValue(new TypeError("network down"));

      await form.safety.restore();

      expect(form.form.value.price).toBe("25,00");
      expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(form.locked.value).toBe(false);

      mocks.get.mockReturnValue(new Promise(() => {}));
      const again = form.safety.restore();
      await advance(10_000);
      await again;

      expect(form.form.value.price).toBe("25,00");
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

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;
      expect(form.form.value.price).toBe("25,00");
      expect(form.view.value?.version).toBe(3);
      release(ok(method({ version: 3 })));
      await checking;

      expect(form.form.value.price).toBe("25,00");
      expect(form.locked.value).toBe(false);
      expect(form.safety.writing.value).toBe(false);
    });

    it("(h) keeps the draft and leaves nothing locked when it answers after the answer of the read", async () => {
      const { form, puts, saving } = await savingFirst();
      await advance(10_000);
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));
      await form.safety.check();

      puts[0]!.reject(new TypeError("network down"));
      await saving;

      expect(form.form.value.price).toBe("25,00");
      expect(form.locked.value).toBe(false);
      expect(form.safety.unknown.value?.kind).toBe("missing");
    });

    it("(i) does not take the lock of the newer save and adds no message of the earlier one when its error and end come after the newer save started: the one „Sprawdź stan” found stays as it was", async () => {
      const { form, puts, saving } = await savingFirst();
      await advance(10_000);
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));
      await form.safety.check();
      form.form.value.price = "26,00";
      const second = form.save();
      expect(form.locked.value).toBe(true);
      const before = form.safety.unknown.value;

      puts[0]!.reject(new TypeError("network down"));
      await saving;

      expect(form.locked.value).toBe(true);
      expect(form.busy.value).toBe(true);
      expect(form.safety.unknown.value).toEqual(before);
      expect(form.error.value).toBeNull();
      puts[1]!.resolve(ok(method({ priceGross: 2600, version: 4 })));
      expect(await second).toBe("success");
      expect(form.locked.value).toBe(false);
    });
  });

  describe("B4 (j): the late success of the earlier save", () => {
    async function afterCheck() {
      const result = await savingFirst("25,00");
      await advance(10_000);
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));
      await result.form.safety.check();
      result.form.form.value.price = "26,00";
      const second = result.form.save();
      return { ...result, second };
    }

    it("only says so in a toast after the success of the newer save, and changes no field, version or dirty flag", async () => {
      const { form, puts, saving, second, onLateSaved } = await afterCheck();
      puts[1]!.resolve(ok(method({ priceGross: 2600, version: 5 })));
      await second;

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;

      expect(onLateSaved).toHaveBeenCalledWith(LATE);
      expect(form.form.value.price).toBe("26,00");
      expect(form.view.value?.version).toBe(5);
      expect(form.dirty.value).toBe(false);
    });

    it("keeps the errors of the newer save after its 422", async () => {
      const { form, puts, saving, second, onLateSaved } = await afterCheck();
      puts[1]!.resolve(problem(422, { violations: [{ propertyPath: "priceGross", title: "Za wysoka cena." }] }));
      await second;

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;

      expect(onLateSaved).toHaveBeenCalledTimes(1);
      expect(form.errorsAt("priceGross")).toEqual(["Za wysoka cena."]);
      expect(form.form.value.price).toBe("26,00");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
    });

    it("keeps the lock of the newer save while it runs", async () => {
      const { form, puts, saving, second, onLateSaved } = await afterCheck();

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;

      expect(onLateSaved).toHaveBeenCalledTimes(1);
      expect(form.locked.value).toBe(true);
      expect(form.form.value.price).toBe("26,00");
      puts[1]!.resolve(ok(method({ priceGross: 2600, version: 5 })));
      await second;
    });

    it("says nothing when the staff member moved to another method, and changes nothing there", async () => {
      const { form, id, puts, saving, second, onLateSaved } = await afterCheck();
      puts[1]!.resolve(ok(method({ priceGross: 2600, version: 5 })));
      await second;
      serve(method({ id: "method-2", name: "Paczkomat", version: 2 }));
      id.value = "method-2";
      await flushPromises();

      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;

      expect(onLateSaved).not.toHaveBeenCalled();
      expect(form.form.value.name).toBe("Paczkomat");
      expect(form.view.value?.version).toBe(2);
      expect(form.locked.value).toBe(false);
    });
  });

  describe("B4 (k): moving to another method", () => {
    it("ignores the answers of a save in progress, with no toast, no message, no move and no lock", async () => {
      const { form, id, puts, saving, onSaved, onLateSaved } = await savingFirst();
      await advance(10_000);
      expect(form.safety.unknown.value).not.toBeNull();
      serve(method({ id: "method-2", name: "Paczkomat", version: 2 }));
      id.value = "method-2";
      await flushPromises();

      expect(form.safety.unknown.value).toBeNull();
      expect(form.locked.value).toBe(false);
      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      expect(await saving).toBeNull();

      expect(onSaved).not.toHaveBeenCalled();
      expect(onLateSaved).not.toHaveBeenCalled();
      expect(form.form.value.name).toBe("Paczkomat");
      expect(form.message.value).toBeNull();
    });

    it("ignores the answer of a check that comes after that", async () => {
      const { form, id } = await unknownEdit();
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const checking = form.safety.check();
      serve(method({ id: "method-2", name: "Paczkomat", version: 2 }));
      id.value = "method-2";
      await flushPromises();

      release(ok(method({ priceGross: 2500, version: 4 })));
      await checking;

      expect(form.form.value.name).toBe("Paczkomat");
      expect(form.view.value?.id).toBe("method-2");
      expect(form.locked.value).toBe(false);
    });

    it("ignores the answer of a read that „Odrzuć moje zmiany…” started, with no lock left", async () => {
      const { form, id } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(conflict("about:blank"));
      await form.save();
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const restoring = form.safety.restore();
      serve(method({ id: "method-2", name: "Paczkomat", version: 2 }));
      id.value = "method-2";
      await flushPromises();

      release(ok(method({ priceGross: 900, version: 9 })));
      await restoring;

      expect(form.view.value?.id).toBe("method-2");
      expect(form.locked.value).toBe(false);
    });
  });

  describe("discarding the draft after a 409 or an unknown result", () => {
    it("keeps the way to the server state after a 409 of the version, with the context that the version is another, and nothing unknown", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      await form.save();
      expect(form.safety.restoreOffered.value).toBe(true);

      form.discard();

      expect(form.error.value).toBeNull();
      expect(form.form.value.price).toBe("19,99");
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(form.safety.restoreNote.value).toBe(OTHER_VERSION);
      expect(form.safety.unknown.value).toBeNull();
    });

    it("keeps the unknown result of a save after a 502, without the sentence that the changes stay, and „Sprawdź stan” compares the restored base", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.put.mockResolvedValue(problem(502));
      await form.save();

      form.discard();

      expect(form.form.value.price).toBe("19,99");
      expect(form.safety.unknown.value?.text).toBe("Nie wiemy, czy zapis się powiódł.");
      mocks.get.mockResolvedValue(ok(method({ priceGross: 2500, version: 4 })));
      await form.safety.check();
      expect(form.safety.unknown.value).toMatchObject({ kind: "otherVersion" });
      expect(form.form.value.price).toBe("19,99");
      expect(form.view.value?.version).toBe(3);
    });

    it("keeps what „Sprawdź stan” found, and the answer of that save that comes later is only a toast", async () => {
      const { form, puts, saving, onLateSaved } = await savingFirst();
      await advance(10_000);
      mocks.get.mockResolvedValue(ok(method({ version: 3 })));
      await form.safety.check();
      expect(form.safety.unknown.value?.kind).toBe("missing");

      form.discard();

      expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
      puts[0]!.resolve(ok(method({ priceGross: 2500, version: 4 })));
      await saving;
      expect(onLateSaved).toHaveBeenCalledTimes(1);
      expect(form.form.value.price).toBe("19,99");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(false);
    });

    it("does nothing while a read replaces the form", async () => {
      const { form } = await open();
      form.form.value.price = "25,00";
      mocks.get.mockReturnValue(new Promise(() => {}));
      void form.safety.restore();

      form.discard();

      expect(form.form.value.price).toBe("25,00");
    });
  });

  describe("B4 (l): a new method", () => {
    async function startNew() {
      const result = await start({ id: null });
      wrapper = result.wrapper;
      Object.assign(result.form.form.value, { name: "Paczkomat", price: "12,99", position: "20" });
      return result;
    }

    async function fillNew() {
      const result = await startNew();
      const posts = holdCalls(mocks.post);
      const saving = result.form.save();
      return { ...result, posts, saving };
    }

    it("says after 10 s that the result is unknown and unlocks with „Odblokuj formularz”, then a changed field is kept", async () => {
      const { form, posts, saving } = await fillNew();
      await advance(10_000);
      expect(form.safety.unknown.value).toMatchObject({ pending: true, created: true, text: UNKNOWN });
      expect(form.locked.value).toBe(true);

      form.safety.unblock();

      expect(form.locked.value).toBe(false);
      expect(form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
      form.form.value.name = "Inna";
      posts[0]!.resolve(ok(method({ id: "created", name: "Paczkomat" }), 201));
      await saving;
      expect(form.form.value.name).toBe("Inna");
    });

    it("says the same after an error or a 5xx, with the draft unlocked", async () => {
      const result = await startNew();
      mocks.post.mockResolvedValue(problem(502));

      await result.form.save();

      expect(result.form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
      expect(result.form.locked.value).toBe(false);
      expect(result.form.form.value.name).toBe("Paczkomat");
    });

    it("shows a toast for the late success and does not change the fields or the route", async () => {
      const { form, posts, saving, onSaved, onLateSaved } = await fillNew();
      await advance(10_000);
      form.safety.unblock();
      form.form.value.name = "Inna";

      posts[0]!.resolve(ok(method({ id: "created", name: "Paczkomat" }), 201));
      await saving;

      expect(onLateSaved).toHaveBeenCalledWith(LATE);
      expect(onSaved).not.toHaveBeenCalled();
      expect(form.view.value).toBeNull();
      expect(form.form.value.name).toBe("Inna");
    });

    it("adds that the earlier save may have created it to a 409 on the name that follows", async () => {
      const { form } = await fillNew();
      await advance(10_000);
      form.safety.unblock();
      mocks.post.mockResolvedValue(conflict("delivery-method-name-taken", TAKEN));

      await form.save();

      expect(form.error.value).toBe(`${TAKEN} Możliwe, że utworzył ją Twój wcześniejszy zapis.`);
    });

    it("adds the same note after an earlier save that ended with an error, not only after „Odblokuj formularz”", async () => {
      const result = await startNew();
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));
      await result.form.save();
      mocks.post.mockResolvedValue(conflict("delivery-method-name-taken", TAKEN));

      await result.form.save();

      expect(result.form.error.value).toBe(`${TAKEN} Możliwe, że utworzył ją Twój wcześniejszy zapis.`);
    });

    it("adds no such note when no earlier save ended unknown", async () => {
      const result = await startNew();
      mocks.post.mockResolvedValue(conflict("delivery-method-name-taken", TAKEN));

      await result.form.save();

      expect(result.form.error.value).toBe(TAKEN);
    });

    it("adds no note to a 409 on the limit of active methods", async () => {
      const result = await startNew();
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));
      await result.form.save();
      mocks.post.mockResolvedValue(conflict("delivery-method-limit-reached", "Za dużo aktywnych."));

      await result.form.save();

      expect(result.form.error.value).toBe("Za dużo aktywnych.");
    });
  });
});
