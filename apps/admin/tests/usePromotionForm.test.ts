import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { formatDateTime } from "@/prices/format";
import { usePromotionForm } from "@/promotions/usePromotionForm";

type Promotion = components["schemas"]["PromotionResponse"];
type PromotionVariant = components["schemas"]["PromotionVariantResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock("@/api/client", () => ({
  getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put, DELETE: mocks.delete }),
}));

const PROMOTION = "/api/v1/admin/promotions/{id}";
const PROMOTIONS = "/api/v1/admin/promotions";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

function variant(id: string, overrides: Partial<PromotionVariant> = {}): PromotionVariant {
  return {
    variantId: id,
    productId: `p-${id}`,
    productName: `Produkt ${id}`,
    label: "",
    regularGross: 10000,
    promotionalGross: 8000,
    ...overrides,
  };
}

function promotion(overrides: Partial<Promotion> = {}): Promotion {
  return {
    id: "promo-1",
    name: "Jesień w górach",
    percentOff: 20,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: "2026-11-20T23:00:00+00:00",
    status: "planned",
    variantCount: 2,
    version: 4,
    updatedAt: "2026-11-07T12:30:00+00:00",
    updatedByName: "ola.marketing",
    variants: [variant("v-1"), variant("v-2")],
    ...overrides,
  };
}

function ok(data: unknown, status = 200) {
  return { data, response: { status } };
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

function conflict(kind: string, detail = "Szczegóły z API.", extra: Record<string, unknown> = {}) {
  return problem(409, { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail, ...extra });
}

interface Setup {
  id?: string | null;
  canManage?: boolean;
}

async function start(options: Setup = {}) {
  const id = ref<string | null>(options.id === undefined ? "promo-1" : options.id);
  const canManage = ref(options.canManage ?? true);
  const onCreated = vi.fn();
  const onDeleted = vi.fn();
  const onEnded = vi.fn();
  const onLateSaved = vi.fn();
  let form!: ReturnType<typeof usePromotionForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = usePromotionForm({ promotionId: () => id.value, canManage: () => canManage.value, onCreated, onDeleted, onEnded, onLateSaved });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, wrapper, id, canManage, onCreated, onDeleted, onEnded, onLateSaved };
}

function servePromotion(data: Promotion | null = promotion()) {
  mocks.get.mockImplementation(async () => (data ? ok(data) : problem(404)));
}

describe("usePromotionForm", () => {
  let wrapper: Awaited<ReturnType<typeof start>>["wrapper"] | undefined;

  beforeEach(() => {
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    servePromotion();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function open(promo: Promotion = promotion(), options: Setup = {}) {
    servePromotion(promo);
    const started = await start(options);
    wrapper = started.wrapper;
    return started;
  }

  describe("loading", () => {
    it("starts an empty form for a new promotion without asking the API", async () => {
      const started = await start({ id: null });
      wrapper = started.wrapper;

      expect(mocks.get).not.toHaveBeenCalled();
      expect(started.form.loaded.value).toBe(true);
      expect(started.form.form.value).toEqual({ name: "", percentOff: "", startNow: false, startsAt: "", endsAt: "", variants: [] });
      expect(started.form.dirty.value).toBe(false);
      expect(started.form.status.value).toBeNull();
    });

    it("loads a promotion and shows its times in Polish time", async () => {
      const { form } = await open();

      expect(mocks.get).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" } } });
      expect(form.form.value).toMatchObject({
        name: "Jesień w górach",
        percentOff: "20",
        startNow: false,
        startsAt: "2026-11-11T00:00",
        endsAt: "2026-11-21T00:00",
      });
      expect(form.form.value.variants.map((item) => item.variantId)).toEqual(["v-1", "v-2"]);
      expect(form.status.value).toBe("planned");
      expect(form.dirty.value).toBe(false);
    });

    it.each([
      [404, "Ta promocja nie istnieje."],
      [500, "Nie udało się pobrać promocji (HTTP 500)."],
    ])("says so when the load answers %s", async (status, text) => {
      mocks.get.mockResolvedValue(problem(status));
      const started = await start();
      wrapper = started.wrapper;

      expect(started.form.loadError.value).toBe(text);
      expect(started.form.loaded.value).toBe(false);
    });

    it("says so when the load request fails to leave", async () => {
      mocks.get.mockRejectedValue(new TypeError("network down"));
      const started = await start();
      wrapper = started.wrapper;

      expect(started.form.loadError.value).toContain("połączenie");
    });

    it("loads the other promotion when the route moves to it", async () => {
      const { id, form } = await open();
      servePromotion(promotion({ id: "promo-2", name: "Wiosna" }));

      id.value = "promo-2";
      await flushPromises();

      expect(form.form.value.name).toBe("Wiosna");
    });
  });

  describe("which fields can change", () => {
    it.each([
      ["planned", true, false, false],
      ["active", true, true, false],
      ["ended", false, false, true],
    ] as const)("%s: editable %s, schedule locked %s, read-only %s", async (status, canSave, scheduleLocked, readOnly) => {
      const { form } = await open(promotion({ status }));

      expect(form.scheduleLocked.value).toBe(scheduleLocked);
      expect(form.readOnly.value).toBe(readOnly);
      form.form.value.name = "Inna nazwa";
      expect(form.canSave.value).toBe(canSave);
    });

    it("is read-only for a staff member who may not change promotions", async () => {
      const { form } = await open(promotion(), { canManage: false });

      expect(form.readOnly.value).toBe(true);
      form.form.value.name = "Inna nazwa";
      expect(form.canSave.value).toBe(false);
      expect(form.canDelete.value).toBe(false);
      expect(await form.save()).toBeNull();
      expect(await form.remove()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(mocks.delete).not.toHaveBeenCalled();
    });

    it("offers ending only for an active promotion, and deleting only for a planned one", async () => {
      expect((await open(promotion({ status: "active" }))).form.canEndNow.value).toBe(true);
      wrapper?.unmount();
      const planned = await open(promotion({ status: "planned" }));
      expect(planned.form.canEndNow.value).toBe(false);
      expect(planned.form.canDelete.value).toBe(true);
      wrapper?.unmount();
      const ended = await open(promotion({ status: "ended" }));
      expect(ended.form.canEndNow.value).toBe(false);
      expect(ended.form.canDelete.value).toBe(false);
    });

    it("does not offer ending or deleting while there are unsaved changes", async () => {
      const { form } = await open(promotion({ status: "active" }));
      form.form.value.name = "Zmieniona";

      expect(form.canEndNow.value).toBe(false);
    });
  });

  describe("creating", () => {
    async function fillNew() {
      const started = await start({ id: null });
      wrapper = started.wrapper;
      started.form.form.value.name = "  Jesień w górach ";
      started.form.form.value.percentOff = "20";
      started.form.form.value.startsAt = "2026-11-11T00:00";
      started.form.form.value.endsAt = "2026-11-21T00:00";
      started.form.addVariants([variant("v-1"), variant("v-2")]);
      return started;
    }

    it("posts the promotion with the times converted to UTC and moves to the created one", async () => {
      const { form, onCreated } = await fillNew();
      mocks.post.mockResolvedValue(ok(promotion(), 201));

      expect(await form.save()).toBe("success");

      expect(mocks.post).toHaveBeenCalledWith(PROMOTIONS, {
        body: {
          name: "  Jesień w górach ",
          percentOff: 20,
          startsAt: "2026-11-10T23:00:00Z",
          endsAt: "2026-11-20T23:00:00Z",
          variantIds: ["v-1", "v-2"],
        },
      });
      expect(onCreated).toHaveBeenCalledWith("promo-1");
      expect(form.message.value).toBe("Promocja zapisana.");
      expect(form.view.value?.version).toBe(4);
      expect(form.dirty.value).toBe(false);
    });

    it("sends a null start for „od teraz” whatever is typed as the start", async () => {
      const { form } = await fillNew();
      form.form.value.startNow = true;
      mocks.post.mockResolvedValue(ok(promotion({ status: "active" }), 201));

      await form.save();

      expect(mocks.post.mock.calls[0]![1].body.startsAt).toBeNull();
    });

    it("does not load the promotion it has just created", async () => {
      const { form, id } = await fillNew();
      mocks.post.mockResolvedValue(ok(promotion(), 201));
      await form.save();

      id.value = "promo-1";
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("converts a summer time with the summer offset", async () => {
      const { form } = await fillNew();
      form.form.value.startsAt = "2026-07-01T10:00";
      form.form.value.endsAt = "2026-07-15T10:00";
      mocks.post.mockResolvedValue(ok(promotion(), 201));

      await form.save();

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ startsAt: "2026-07-01T08:00:00Z", endsAt: "2026-07-15T08:00:00Z" });
    });

    it.each([
      ["a percent that is not a whole number", { percentOff: "20,5" }, "percentOff"],
      ["an empty percent", { percentOff: "" }, "percentOff"],
      ["an empty end", { endsAt: "" }, "endsAt"],
      ["an end in the hour that does not exist", { endsAt: "2026-03-29T02:30" }, "endsAt"],
      ["an empty start without „od teraz”", { startsAt: "" }, "startsAt"],
    ])("does not send %s", async (_name, change, path) => {
      const { form } = await fillNew();
      Object.assign(form.form.value, change);

      expect(await form.save()).toBe("failure");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(form.errorsAt(path)).toHaveLength(1);
    });

    it("needs no start when it starts now", async () => {
      const { form } = await fillNew();
      form.form.value.startNow = true;
      form.form.value.startsAt = "";
      mocks.post.mockResolvedValue(ok(promotion(), 201));

      expect(await form.save()).toBe("success");
    });
  });

  describe("revising", () => {
    it("puts the whole promotion with the version of the view", async () => {
      const { form } = await open();
      form.form.value.name = "Zimowa wyprzedaż";
      form.form.value.percentOff = "30";
      form.removeVariant("v-2");
      mocks.put.mockResolvedValue(ok(promotion({ name: "Zimowa wyprzedaż", percentOff: 30, version: 5, variants: [variant("v-1")] })));

      expect(await form.save()).toBe("success");

      expect(mocks.put).toHaveBeenCalledWith(PROMOTION, {
        params: { path: { id: "promo-1" } },
        body: {
          name: "Zimowa wyprzedaż",
          percentOff: 30,
          startsAt: "2026-11-10T23:00:00+00:00",
          endsAt: "2026-11-20T23:00:00+00:00",
          variantIds: ["v-1"],
          version: 4,
        },
      });
      expect(form.view.value?.version).toBe(5);
      expect(form.form.value.name).toBe("Zimowa wyprzedaż");
      expect(form.dirty.value).toBe(false);
    });

    it("sends the stored moments untouched when the times were not edited, so no seconds are lost", async () => {
      const { form } = await open(promotion({ startsAt: "2026-11-10T23:00:41+00:00" }));
      form.form.value.name = "Inna";
      mocks.put.mockResolvedValue(ok(promotion()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body.startsAt).toBe("2026-11-10T23:00:41+00:00");
    });

    it("converts a time that was edited", async () => {
      const { form } = await open();
      form.form.value.endsAt = "2026-11-22T08:30";
      mocks.put.mockResolvedValue(ok(promotion()));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-11-22T07:30:00Z",
      });
    });

    it("starts a planned promotion now when „od teraz” is chosen", async () => {
      const { form } = await open();
      form.form.value.startNow = true;
      expect(form.dirty.value).toBe(true);
      mocks.put.mockResolvedValue(ok(promotion({ status: "active" })));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body.startsAt).toBeNull();
    });

    it("sends the stored percent, start and variants of an active promotion and only its new name and end", async () => {
      const { form } = await open(promotion({ status: "active" }));
      form.form.value.name = "Nowa nazwa";
      form.form.value.endsAt = "2026-11-25T12:00";
      // The view disables these; the form must not send them even if something changed them.
      form.form.value.percentOff = "50";
      form.form.value.startNow = true;
      form.form.value.startsAt = "2026-12-01T00:00";
      form.form.value.variants = [variant("v-9")];
      mocks.put.mockResolvedValue(ok(promotion({ status: "active" })));

      await form.save();

      expect(mocks.put.mock.calls[0]![1].body).toEqual({
        name: "Nowa nazwa",
        percentOff: 20,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-11-25T11:00:00Z",
        variantIds: ["v-1", "v-2"],
        version: 4,
      });
    });

    it("does not send anything when nothing changed", async () => {
      const { form } = await open();

      expect(form.canSave.value).toBe(false);
      expect(await form.save()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("sends one request at a time", async () => {
      const { form } = await open();
      form.form.value.name = "Inna";
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const first = form.save();
      expect(form.busy.value).toBe(true);
      expect(await form.save()).toBeNull();
      release(ok(promotion()));
      await first;

      expect(mocks.put).toHaveBeenCalledTimes(1);
      expect(form.busy.value).toBe(false);
    });
  });

  describe("ending now", () => {
    it("puts the stored promotion with the end set to the time of the browser", async () => {
      vi.useFakeTimers({ toFake: ["Date"] });
      vi.setSystemTime(new Date("2026-11-15T10:20:30.456Z"));
      const { form } = await open(promotion({ status: "active" }));
      mocks.put.mockResolvedValue(ok(promotion({ status: "ended", endsAt: "2026-11-15T10:20:31+00:00" })));

      expect(await form.endNow()).toBe("success");

      expect(mocks.put).toHaveBeenCalledWith(PROMOTION, {
        params: { path: { id: "promo-1" } },
        body: {
          name: "Jesień w górach",
          percentOff: 20,
          startsAt: "2026-11-10T23:00:00+00:00",
          endsAt: "2026-11-15T10:20:30.456Z",
          variantIds: ["v-1", "v-2"],
          version: 4,
        },
      });
      expect(form.status.value).toBe("ended");
      expect(form.message.value).toBe("Promocja zakończona.");
    });

    describe("when the API leaves the promotion active (#304 F7)", () => {
      // The API sets the end a moment ahead, so the answer of the PUT is still „active”: the form waits for that
      // moment and reads the promotion again.
      const NOW = "2026-11-15T10:20:30.000Z";
      const setEnd = (endsAt: string) => mocks.put.mockResolvedValue(ok(promotion({ status: "active", endsAt })));

      async function openActive() {
        vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
        vi.setSystemTime(new Date(NOW));
        return open(promotion({ status: "active" }));
      }

      /** Answers the next reads with the promotion as `status` with the end `endsAt`. */
      function serveRead(status: "active" | "ended", endsAt: string) {
        mocks.get.mockImplementation(async () => ok(promotion({ status, endsAt })));
      }

      it("disables saving and ending, with nothing said yet, and reads again once the end has passed and one second more", async () => {
        const { form, onEnded } = await openActive();
        setEnd("2026-11-15T10:20:33+00:00");
        mocks.get.mockClear();

        expect(await form.endNow()).toBe("success");

        expect(form.ending.value).toBe(true);
        expect(form.canEndNow.value).toBe(false);
        expect(form.canSave.value).toBe(false);
        expect(form.message.value).toBeNull();
        expect(form.status.value).toBe("active");
        serveRead("ended", "2026-11-15T10:20:33+00:00");

        await vi.advanceTimersByTimeAsync(3999);
        expect(mocks.get).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        await flushPromises();

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" } } });
        expect(form.ending.value).toBe(false);
        expect(form.status.value).toBe("ended");
        expect(form.readOnly.value).toBe(true);
        expect(form.message.value).toBe("Promocja zakończona.");
        expect(onEnded).toHaveBeenCalledWith("Promocja zakończona.");
      });

      it("waits one second only for an end that has passed already", async () => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:29+00:00");
        mocks.get.mockClear();
        await form.endNow();
        serveRead("ended", "2026-11-15T10:20:29+00:00");

        await vi.advanceTimersByTimeAsync(999);
        expect(mocks.get).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        await flushPromises();

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(form.status.value).toBe("ended");
      });

      it("reads again every second, at most 5 times, and then says when the end is set, from the last answer", async () => {
        const { form, onEnded } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        mocks.get.mockClear();
        await form.endNow();
        // The clock of the browser differs from the server's: the promotion is still active.
        serveRead("active", "2026-11-15T10:20:45+00:00");

        await vi.advanceTimersByTimeAsync(1000);
        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(form.ending.value).toBe(true);
        for (let retry = 1; retry <= 5; retry++) {
          await vi.advanceTimersByTimeAsync(999);
          expect(mocks.get).toHaveBeenCalledTimes(retry);
          await vi.advanceTimersByTimeAsync(1);
          expect(mocks.get).toHaveBeenCalledTimes(retry + 1);
        }
        await flushPromises();

        expect(mocks.get).toHaveBeenCalledTimes(6);
        expect(form.ending.value).toBe(false);
        expect(form.status.value).toBe("active");
        expect(form.endNotice.value).toBe(`Koniec promocji ustawiony na ${formatDateTime("2026-11-15T10:20:45+00:00")}.`);
        expect(form.message.value).toBeNull();
        expect(onEnded).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(mocks.get).toHaveBeenCalledTimes(6);
      });

      it("shows the state of the last answer when the retries are used up and nothing is unsaved: the form, the view and the version", async () => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        await form.endNow();
        const last = promotion({ status: "active", endsAt: "2026-11-15T10:25:45+00:00", version: 9, updatedByName: "ktoś.inny" });
        mocks.get.mockImplementation(async () => ok(last));

        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();

        expect(form.ending.value).toBe(false);
        expect(form.endNotice.value).toBe(`Koniec promocji ustawiony na ${formatDateTime("2026-11-15T10:25:45+00:00")}.`);
        expect(form.view.value?.version).toBe(9);
        expect(form.view.value?.endsAt).toBe("2026-11-15T10:25:45+00:00");
        expect(form.form.value.endsAt).toBe("2026-11-15T11:25:45");
        expect(form.dirty.value).toBe(false);
      });

      it("shows the state of the last answer when the retries are used up, also over unsaved changes", async () => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        await form.endNow();
        form.form.value.name = "Inna nazwa";
        mocks.get.mockImplementation(async () => ok(promotion({ status: "active", endsAt: "2026-11-15T10:25:45+00:00", version: 9 })));

        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();

        expect(form.endNotice.value).toBe(`Koniec promocji ustawiony na ${formatDateTime("2026-11-15T10:25:45+00:00")}.`);
        expect(form.form.value.name).toBe(promotion().name);
        expect(form.view.value?.version).toBe(9);
        expect(form.dirty.value).toBe(false);
      });

      it("stops at the first read that says ended, without the remaining retries", async () => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        mocks.get.mockClear();
        await form.endNow();
        serveRead("active", "2026-11-15T10:20:30+00:00");
        await vi.advanceTimersByTimeAsync(2000);
        expect(mocks.get).toHaveBeenCalledTimes(2);
        serveRead("ended", "2026-11-15T10:20:30+00:00");

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(mocks.get).toHaveBeenCalledTimes(3);
        expect(form.status.value).toBe("ended");
        expect(form.message.value).toBe("Promocja zakończona.");
        expect(form.endNotice.value).toBeNull();
      });

      it.each([
        ["a failed read", () => mocks.get.mockResolvedValue(problem(500))],
        ["a read that does not reach the API", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
      ])("ends the wait with an error for %s, and „Odśwież” (refresh) clears it", async (_name, fail) => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        await form.endNow();
        mocks.get.mockClear();
        fail();

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(form.ending.value).toBe(false);
        expect(form.endError.value).toContain("Nie udało się odczytać promocji");
        expect(form.endNotice.value).toBeNull();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(mocks.get).toHaveBeenCalledTimes(1);

        serveRead("ended", "2026-11-15T10:20:30+00:00");
        await form.refresh();
        expect(form.endError.value).toBeNull();
        expect(form.status.value).toBe("ended");
      });

      it("keeps what the staff member typed meanwhile while the promotion is still active", async () => {
        const { form } = await openActive();
        setEnd("2026-11-15T10:20:30+00:00");
        await form.endNow();
        form.form.value.name = "Inna nazwa";
        serveRead("active", "2026-11-15T10:20:30+00:00");

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();

        expect(form.form.value.name).toBe("Inna nazwa");
        expect(form.dirty.value).toBe(true);
        expect(form.ending.value).toBe(true);
        expect(form.canSave.value).toBe(false);
      });

      it("makes no request after the form is left", async () => {
        const { form, wrapper: mountedWrapper, onEnded } = await openActive();
        wrapper = undefined;
        setEnd("2026-11-15T10:20:33+00:00");
        await form.endNow();
        mocks.get.mockClear();
        serveRead("ended", "2026-11-15T10:20:33+00:00");

        mountedWrapper.unmount();
        await vi.advanceTimersByTimeAsync(30_000);
        await flushPromises();

        expect(mocks.get).not.toHaveBeenCalled();
        expect(onEnded).not.toHaveBeenCalled();
      });

      it("ignores the answer of a read that comes after the form was left", async () => {
        const { form, wrapper: mountedWrapper, onEnded } = await openActive();
        wrapper = undefined;
        setEnd("2026-11-15T10:20:30+00:00");
        await form.endNow();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        await vi.advanceTimersByTimeAsync(1000);

        mountedWrapper.unmount();
        release(ok(promotion({ status: "ended" })));
        await flushPromises();

        expect(onEnded).not.toHaveBeenCalled();
        expect(form.status.value).toBe("active");
      });

      it("does not wait for a promotion the PUT already ended", async () => {
        const { form, onEnded } = await openActive();
        mocks.put.mockResolvedValue(ok(promotion({ status: "ended", endsAt: NOW })));
        mocks.get.mockClear();

        await form.endNow();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(form.ending.value).toBe(false);
        expect(mocks.get).not.toHaveBeenCalled();
        expect(form.message.value).toBe("Promocja zakończona.");
        expect(onEnded, "the view says it from the result of the call").not.toHaveBeenCalled();
      });
    });

    it("does nothing for a promotion that is not active", async () => {
      const { form } = await open(promotion({ status: "planned" }));

      expect(await form.endNow()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("does nothing for a staff member who may not change promotions", async () => {
      const { form } = await open(promotion({ status: "active" }), { canManage: false });

      expect(await form.endNow()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
    });
  });

  describe("the lock while a save runs (#580)", () => {
    it("locks the fields from the save to its answer, and a second save sends nothing", async () => {
      const { form } = await open();
      form.form.value.name = "Inna";
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      expect(form.locked.value).toBe(false);

      const saving = form.save();

      expect(form.locked.value).toBe(true);
      expect(await form.save()).toBeNull();
      release(ok(promotion({ name: "Inna", version: 5 })));
      await saving;
      expect(form.locked.value).toBe(false);
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it.each([
      ["a 422", () => mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Za długa nazwa." }] }))],
      ["a request that does not reach the API", () => mocks.put.mockRejectedValue(new TypeError("network down"))],
    ])("unlocks the fields with the values that were sent after %s", async (_name, fail) => {
      const { form } = await open();
      form.form.value.name = "Wysłana";
      form.form.value.percentOff = "35";
      fail();

      expect(await form.save()).toBe("failure");

      expect(form.locked.value).toBe(false);
      expect(form.form.value.name).toBe("Wysłana");
      expect(form.form.value.percentOff).toBe("35");
    });

    it("does not lock the fields while „Zakończ teraz” or „Usuń” runs", async () => {
      const ended = await open(promotion({ status: "active" }));
      mocks.put.mockReturnValue(new Promise(() => {}));
      void ended.form.endNow();
      expect(ended.form.busy.value).toBe(true);
      expect(ended.form.locked.value).toBe(false);
      wrapper?.unmount();

      const deleted = await open(promotion({ status: "planned" }));
      mocks.delete.mockReturnValue(new Promise(() => {}));
      void deleted.form.remove();
      expect(deleted.form.busy.value).toBe(true);
      expect(deleted.form.locked.value).toBe(false);
    });

    it("ignores the answer of a save that comes after another promotion was opened: no change, no message, no move and no wait", async () => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
      vi.setSystemTime(new Date("2026-11-15T10:20:30.000Z"));
      const { form, id, onCreated } = await open();
      form.form.value.startNow = true;
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const saving = form.save();

      servePromotion(promotion({ id: "promo-2", name: "Druga", version: 7 }));
      id.value = "promo-2";
      await flushPromises();
      // The save of the previous promotion does not hold this one.
      expect(form.locked.value).toBe(false);
      expect(form.busy.value).toBe(false);
      mocks.get.mockClear();
      release(ok(promotion({ status: "planned", startsAt: "2026-11-15T10:20:31+00:00", version: 5 })));

      expect(await saving).toBeNull();
      expect(form.view.value?.id).toBe("promo-2");
      expect(form.form.value.name).toBe("Druga");
      expect(form.view.value?.version).toBe(7);
      expect(form.message.value).toBeNull();
      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(onCreated).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(15_000);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("ignores the answer of a deletion that comes after another promotion was opened, and it does not hold that one", async () => {
      const { form, id, onDeleted } = await open();
      let release!: (value: unknown) => void;
      mocks.delete.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const deleting = form.remove();

      servePromotion(promotion({ id: "promo-2", name: "Druga", version: 7 }));
      id.value = "promo-2";
      await flushPromises();
      expect(form.busy.value).toBe(false);
      expect(form.canDelete.value).toBe(true);
      release(problem(404));

      expect(await deleting).toBeNull();
      expect(onDeleted).not.toHaveBeenCalled();
      expect(form.error.value).toBeNull();
      expect(form.view.value?.name).toBe("Druga");
    });

    it("ignores the answer of a create that comes after the form of a new promotion was opened again", async () => {
      const { form, id, onCreated } = await open(promotion(), { id: null });
      Object.assign(form.form.value, { name: "N", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
      form.addVariants([variant("v-1")]);
      let release!: (value: unknown) => void;
      mocks.post.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const saving = form.save();

      // To an existing promotion and back to the form of a new one, while the create runs.
      id.value = "promo-2";
      await flushPromises();
      id.value = null;
      await flushPromises();
      release(ok(promotion(), 201));

      expect(await saving).toBeNull();
      expect(form.view.value).toBeNull();
      expect(form.form.value.name).toBe("");
      expect(form.message.value).toBeNull();
      expect(onCreated).not.toHaveBeenCalled();
    });
  });

  describe("after a save „od teraz” that answers planned (#580)", () => {
    // The start „od teraz” is the next whole second after the save, so the answer of the save is still „planned”: the
    // form waits for the start and reads the promotion again. The clock of the browser only decides when it reads.
    const NOW = "2026-11-15T10:20:30.000Z";
    const STARTED_MESSAGE = "Promocja jest aktywna.";
    const READ_FAILED = "Nie udało się odczytać statusu promocji. Odśwież, żeby zobaczyć jej stan.";

    async function openPlanned() {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
      vi.setSystemTime(new Date(NOW));
      const started = await open(promotion({ status: "planned", startsAt: "2026-11-16T23:00:00+00:00" }));
      mocks.get.mockClear();
      return started;
    }

    /** Saves „od teraz” and answers with the promotion planned to start at `startsAt`. */
    async function saveNow(form: ReturnType<typeof usePromotionForm>, startsAt: string) {
      form.form.value.startNow = true;
      mocks.put.mockResolvedValue(ok(promotion({ status: "planned", startsAt, version: 5 })));
      expect(await form.save()).toBe("success");
    }

    function serveRead(status: Promotion["status"], overrides: Partial<Promotion> = {}) {
      mocks.get.mockImplementation(async () => ok(promotion({ status, startsAt: "2026-11-15T10:20:31+00:00", ...overrides })));
    }

    it("locks the form, reads once exactly 2 s after the answer for a start 1 s ahead, and an active answer unlocks it", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");

      expect(form.starting.value).toBe(true);
      expect(form.locked.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(form.canDelete.value).toBe(false);
      expect(form.message.value).toBe("Promocja zapisana.");
      serveRead("active", { version: 6 });

      await vi.advanceTimersByTimeAsync(1999);
      expect(mocks.get).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(mocks.get).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" } } });
      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.status.value).toBe("active");
      expect(form.view.value?.version).toBe(6);
      expect(form.canEndNow.value).toBe(true);
      expect(form.startNotice.value).toBe(STARTED_MESSAGE);
      expect(form.startError.value).toBeNull();
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("reads one second after a start that the clock of the browser has already passed: 0.7 s after the answer for a start 0.3 s before it", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:29.700+00:00");
      serveRead("active");

      await vi.advanceTimersByTimeAsync(699);
      expect(mocks.get).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.status.value).toBe("active");
    });

    it("reads at once when the start and one second more have already passed by the clock of the browser", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:28.500+00:00");
      serveRead("active");

      await vi.advanceTimersByTimeAsync(0);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.status.value).toBe("active");
    });

    it("reads first 3 s after the answer at the latest, when the start is far ahead of the clock of the browser", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:21:30+00:00");
      serveRead("active");

      await vi.advanceTimersByTimeAsync(2999);
      expect(mocks.get).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.status.value).toBe("active");
    });

    it("counts from the answer of the save, however long the save took", async () => {
      const { form } = await openPlanned();
      form.form.value.startNow = true;
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      const saving = form.save();
      await vi.advanceTimersByTimeAsync(15_000);
      expect(form.locked.value).toBe(true);
      release(ok(promotion({ status: "planned", startsAt: "2026-11-15T10:20:46+00:00" })));
      expect(await saving).toBe("success");
      serveRead("active");

      await vi.advanceTimersByTimeAsync(1999);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.startError.value).toBeNull();
      await vi.advanceTimersByTimeAsync(1);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.status.value).toBe("active");
    });

    it("reads again every second, at most 5 times, and then unlocks the form, still planned, and says when it starts", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");
      serveRead("planned", { startsAt: "2026-11-15T10:20:41+00:00", version: 8 });

      await vi.advanceTimersByTimeAsync(2000);
      expect(mocks.get).toHaveBeenCalledTimes(1);
      for (let retry = 1; retry <= 5; retry++) {
        expect(form.starting.value).toBe(true);
        await vi.advanceTimersByTimeAsync(999);
        expect(mocks.get).toHaveBeenCalledTimes(retry);
        await vi.advanceTimersByTimeAsync(1);
        expect(mocks.get).toHaveBeenCalledTimes(retry + 1);
      }
      await flushPromises();

      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.status.value).toBe("planned");
      expect(form.view.value?.version).toBe(8);
      expect(form.startNotice.value).toBe(`Promocja jeszcze się nie rozpoczęła. Początek: ${formatDateTime("2026-11-15T10:20:41+00:00")}.`);
      expect(form.startError.value).toBeNull();
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).toHaveBeenCalledTimes(6);
    });

    it.each([
      ["a read that fails", () => mocks.get.mockResolvedValue(problem(500))],
      ["a read of a promotion that is gone", () => mocks.get.mockResolvedValue(problem(404))],
      ["a read that does not reach the API", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
    ])("ends the wait with an error for %s, leaves the form as after the save, and „Odśwież” (refresh) shows the promotion", async (_name, fail) => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");
      fail();

      await vi.advanceTimersByTimeAsync(2000);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.startError.value).toBe(READ_FAILED);
      expect(form.startNotice.value).toBeNull();
      expect(form.status.value).toBe("planned");
      expect(form.view.value?.version).toBe(5);
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).toHaveBeenCalledTimes(1);

      serveRead("active", { version: 6 });
      await form.refresh();

      expect(form.startError.value).toBeNull();
      expect(form.status.value).toBe("active");
      expect(form.view.value?.version).toBe(6);
    });

    it("gives up 10 s after the answer of the save while a read has no answer, and the late answer changes nothing", async () => {
      const { form } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      await vi.advanceTimersByTimeAsync(9999);
      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.starting.value).toBe(true);
      expect(form.locked.value).toBe(true);
      await vi.advanceTimersByTimeAsync(1);

      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.startError.value).toBe(READ_FAILED);
      expect(form.canSave.value).toBe(false);
      form.form.value.name = "Zmieniona po limicie";
      expect(form.canSave.value).toBe(true);

      release(ok(promotion({ status: "active", startsAt: "2026-11-15T10:20:31+00:00", version: 9 })));
      await flushPromises();

      expect(form.form.value.name).toBe("Zmieniona po limicie");
      expect(form.status.value).toBe("planned");
      expect(form.view.value?.version).toBe(5);
      expect(form.startError.value).toBe(READ_FAILED);
      expect(form.startNotice.value).toBeNull();
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("gives up 10 s after the answer of the save also while the reads still say planned", async () => {
      const { form } = await openPlanned();
      // Every read takes 1.5 s: the sixth would come after the limit.
      await saveNow(form, "2026-11-15T10:20:31+00:00");
      mocks.get.mockImplementation(
        () => new Promise((resolve) => setTimeout(() => resolve(ok(promotion({ status: "planned", startsAt: "2026-11-15T10:20:31+00:00" }))), 1500)),
      );

      await vi.advanceTimersByTimeAsync(9999);
      expect(form.starting.value).toBe(true);
      await vi.advanceTimersByTimeAsync(1);

      expect(form.starting.value).toBe(false);
      expect(form.startError.value).toBe(READ_FAILED);
      const reads = mocks.get.mock.calls.length;
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).toHaveBeenCalledTimes(reads);
      expect(form.startError.value).toBe(READ_FAILED);
      expect(form.startNotice.value).toBeNull();
    });

    it("waits on after the form of a new promotion moved to the page of the promotion it created", async () => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
      vi.setSystemTime(new Date(NOW));
      const { form, id, onCreated } = await open(promotion(), { id: null });
      Object.assign(form.form.value, { name: "N", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
      form.addVariants([variant("v-1")]);
      mocks.post.mockResolvedValue(ok(promotion({ status: "planned", startsAt: "2026-11-15T10:20:31+00:00" }), 201));

      expect(await form.save()).toBe("success");
      expect(onCreated).toHaveBeenCalledWith("promo-1");
      id.value = "promo-1";
      await flushPromises();
      expect(form.starting.value).toBe(true);
      serveRead("active");

      await vi.advanceTimersByTimeAsync(2000);
      await flushPromises();

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.status.value).toBe("active");
      expect(form.startNotice.value).toBe(STARTED_MESSAGE);
    });

    it("makes no read after the form is left, and ignores the answer of a read that comes after that", async () => {
      const first = await openPlanned();
      wrapper = undefined;
      await saveNow(first.form, "2026-11-15T10:20:31+00:00");
      first.wrapper.unmount();
      await vi.advanceTimersByTimeAsync(20_000);
      expect(mocks.get).not.toHaveBeenCalled();

      const second = await openPlanned();
      wrapper = undefined;
      await saveNow(second.form, "2026-11-15T10:20:31+00:00");
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      await vi.advanceTimersByTimeAsync(2000);
      expect(mocks.get).toHaveBeenCalledTimes(1);
      second.wrapper.unmount();
      release(ok(promotion({ status: "active", version: 9 })));
      await flushPromises();
      await vi.advanceTimersByTimeAsync(20_000);

      expect(second.form.status.value).toBe("planned");
      expect(second.form.startNotice.value).toBeNull();
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("stops waiting when another promotion is opened: no more reads, no limit, and a late answer changes nothing", async () => {
      const { form, id } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");
      let release!: (value: unknown) => void;
      mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      await vi.advanceTimersByTimeAsync(2000);
      expect(mocks.get).toHaveBeenCalledTimes(1);
      const pendingRead = release;

      servePromotion(promotion({ id: "promo-2", name: "Druga", status: "planned", version: 7 }));
      id.value = "promo-2";
      await flushPromises();
      pendingRead(ok(promotion({ status: "active", version: 9 })));
      await flushPromises();
      await vi.advanceTimersByTimeAsync(20_000);

      expect(mocks.get).toHaveBeenCalledTimes(2);
      expect(mocks.get.mock.calls[1]).toEqual([PROMOTION, { params: { path: { id: "promo-2" } } }]);
      expect(form.view.value?.id).toBe("promo-2");
      expect(form.status.value).toBe("planned");
      expect(form.view.value?.version).toBe(7);
      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.startNotice.value).toBeNull();
      expect(form.startError.value).toBeNull();
    });

    it("stops waiting when the form of a new promotion is opened", async () => {
      const { form, id } = await openPlanned();
      await saveNow(form, "2026-11-15T10:20:31+00:00");

      id.value = null;
      await flushPromises();
      await vi.advanceTimersByTimeAsync(20_000);

      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.view.value).toBeNull();
      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(form.startError.value).toBeNull();
    });

    it("does not wait after a save with a start in the future", async () => {
      const { form } = await openPlanned();
      form.form.value.startsAt = "2026-11-18T10:00";
      mocks.put.mockResolvedValue(ok(promotion({ status: "planned", startsAt: "2026-11-18T09:00:00+00:00", version: 5 })));

      expect(await form.save()).toBe("success");
      await vi.advanceTimersByTimeAsync(20_000);

      expect(form.starting.value).toBe(false);
      expect(form.locked.value).toBe(false);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does not wait when the answer of a save „od teraz” is already active", async () => {
      const { form } = await openPlanned();
      form.form.value.startNow = true;
      mocks.put.mockResolvedValue(ok(promotion({ status: "active", startsAt: NOW })));

      expect(await form.save()).toBe("success");
      await vi.advanceTimersByTimeAsync(20_000);

      expect(form.starting.value).toBe(false);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.status.value).toBe("active");
    });
  });

  describe("deleting", () => {
    it("deletes a planned promotion with the version of the view and leaves the form", async () => {
      const { form, onDeleted } = await open();
      mocks.delete.mockResolvedValue({ data: undefined, response: { status: 204 } });

      expect(await form.remove()).toBe("success");

      expect(mocks.delete).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" }, query: { version: 4 } } });
      expect(onDeleted).toHaveBeenCalledTimes(1);
    });

    it("does not delete an active or an ended promotion", async () => {
      const active = await open(promotion({ status: "active" }));
      expect(await active.form.remove()).toBeNull();
      wrapper?.unmount();
      const ended = await open(promotion({ status: "ended" }));
      expect(await ended.form.remove()).toBeNull();
      expect(mocks.delete).not.toHaveBeenCalled();
    });

    it("keeps the form and shows an error when the promotion to delete is already gone", async () => {
      const { form, onDeleted } = await open();
      mocks.delete.mockResolvedValue(problem(404));

      await form.remove();

      expect(onDeleted).not.toHaveBeenCalled();
      expect(form.error.value).toBe("Ta promocja już nie istnieje.");
    });
  });

  describe("the variants", () => {
    it("adds variants once and keeps the order", async () => {
      const started = await start({ id: null });
      wrapper = started.wrapper;

      started.form.addVariants([variant("v-1"), variant("v-2")]);
      started.form.addVariants([variant("v-2"), variant("v-3")]);

      expect(started.form.form.value.variants.map((item) => item.variantId)).toEqual(["v-1", "v-2", "v-3"]);
      expect(started.form.dirty.value).toBe(true);
    });

    it("removes a variant", async () => {
      const { form } = await open();

      form.removeVariant("v-1");

      expect(form.form.value.variants.map((item) => item.variantId)).toEqual(["v-2"]);
      expect(form.dirty.value).toBe(true);
    });

    it("does not change the variants of an active promotion or of one that is read-only", async () => {
      const active = await open(promotion({ status: "active" }));
      active.form.addVariants([variant("v-9")]);
      active.form.removeVariant("v-1");
      expect(active.form.form.value.variants.map((item) => item.variantId)).toEqual(["v-1", "v-2"]);
      wrapper?.unmount();

      const readOnly = await open(promotion(), { canManage: false });
      readOnly.form.removeVariant("v-1");
      expect(readOnly.form.form.value.variants).toHaveLength(2);
    });
  });

  describe("conflicts and errors", () => {
    async function openChanged() {
      const started = await open();
      started.form.form.value.name = "Inna";
      return started;
    }

    it("marks the variants of a 409 promotion-overlap and keeps what was typed", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(
        conflict("promotion-overlap", "Część wariantów jest już w innej promocji w tym okresie.", { conflictingVariantIds: ["v-2"] }),
      );
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");

      expect(form.isConflicting("v-2")).toBe(true);
      expect(form.isConflicting("v-1")).toBe(false);
      expect(form.error.value).toBe("Część wariantów jest już w innej promocji w tym okresie.");
      expect(form.form.value.name).toBe("Inna");
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("marks the variants of an overlap on creating too", async () => {
      const started = await start({ id: null });
      wrapper = started.wrapper;
      Object.assign(started.form.form.value, { name: "N", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
      started.form.addVariants([variant("v-1")]);
      mocks.post.mockResolvedValue(conflict("promotion-overlap", "Kolizja.", { conflictingVariantIds: ["v-1"] }));

      await started.form.save();

      expect(started.form.isConflicting("v-1")).toBe(true);
    });

    it("forgets the mark of a variant that is removed and the marks when the next save starts", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("promotion-overlap", "Kolizja.", { conflictingVariantIds: ["v-1", "v-2"] }));
      await form.save();

      form.removeVariant("v-1");
      expect(form.isConflicting("v-1")).toBe(false);
      expect(form.isConflicting("v-2")).toBe(true);

      mocks.put.mockResolvedValue(ok(promotion()));
      await form.save();
      expect(form.isConflicting("v-2")).toBe(false);
    });

    it("keeps the draft, the base and the version after a 409 of the version, says so, and does not read the promotion (E7)", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      servePromotion(promotion({ name: "Zmieniona przez kogoś", version: 7 }));
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");

      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.form.value.name).toBe("Inna");
      expect(form.view.value?.version).toBe(4);
      expect(form.error.value).toBe("Promocja została w międzyczasie zmieniona. Twoje zmiany zostają w formularzu.");
      expect(form.dirty.value).toBe(true);
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(form.locked.value).toBe(false);
    });

    it("shows the detail of another 409 and keeps the draft without reading the promotion", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      servePromotion(promotion({ status: "active", version: 6 }));
      mocks.get.mockClear();

      await form.save();

      expect(form.error.value).toBe("Rozpoczętej promocji nie można zmienić.");
      expect(form.status.value).toBe("planned");
      expect(form.form.value.name).toBe("Inna");
      expect(form.safety.restoreOffered.value).toBe(true);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does not offer the server state after a 409 of overlapping promotions, which keeps what was typed", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("promotion-overlap", "Kolizja.", { conflictingVariantIds: ["v-2"] }));

      await form.save();

      expect(form.safety.restoreOffered.value).toBe(false);
    });

    it("shows the detail of a 409 on deleting and reloads", async () => {
      const { form, onDeleted } = await open();
      mocks.delete.mockResolvedValue(conflict("promotion-not-editable", "Tylko zaplanowaną promocję można usunąć."));
      servePromotion(promotion({ status: "active" }));

      await form.remove();

      expect(form.error.value).toBe("Tylko zaplanowaną promocję można usunąć.");
      expect(form.status.value).toBe("active");
      expect(onDeleted).not.toHaveBeenCalled();
    });

    it("reloads after a 409 on ending now", async () => {
      const { form } = await open(promotion({ status: "active" }));
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      servePromotion(promotion({ status: "active", version: 9 }));

      await form.endNow();

      expect(form.view.value?.version).toBe(9);
      expect(form.error.value).toContain("w międzyczasie zmieniona");
    });

    it("puts the violations of a 422 at their fields", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(
        problem(422, {
          status: 422,
          violations: [
            { propertyPath: "name", title: "Nazwa jest za długa." },
            { propertyPath: "percentOff", title: "Procent musi być od 1 do 90." },
            { propertyPath: "variantIds[1]", title: "Wariant bez ceny nie może być w promocji." },
          ],
        }),
      );

      expect(await form.save()).toBe("failure");

      expect(form.errorsAt("name")).toEqual(["Nazwa jest za długa."]);
      expect(form.errorsAt("percentOff")).toEqual(["Procent musi być od 1 do 90."]);
      expect(form.errorsAt("variantIds")).toEqual(["Wariant bez ceny nie może być w promocji."]);
      expect(form.error.value).toBeNull();
    });

    it("shows a violation outside the form and a 422 without violations as a message", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(422, { status: 422, violations: [{ propertyPath: "other", title: "Coś innego." }] }));
      await form.save();
      expect(form.error.value).toBe("Coś innego.");

      mocks.put.mockResolvedValue(problem(422, { status: 422, detail: "Promocja kończy się w przeszłości." }));
      await form.save();
      expect(form.error.value).toBe("Promocja kończy się w przeszłości.");
    });

    it.each([
      [403, "Nie masz uprawnień do zmiany promocji."],
      [404, "Ta promocja już nie istnieje."],
      [418, "Nie udało się wykonać operacji (HTTP 418)."],
    ])("says what a %s means and keeps the form", async (status, text) => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(status));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBe(text);
      expect(form.form.value.name).toBe("Inna");
      expect(form.busy.value).toBe(false);
    });

    it("says the result is unknown, not that the save failed, when the request fails to leave", async () => {
      const { form } = await openChanged();
      mocks.put.mockRejectedValue(new TypeError("network down"));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBeNull();
      expect(form.safety.unknown.value?.text).toBe("Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.");
      expect(form.form.value.name).toBe("Inna");
      expect(form.busy.value).toBe(false);
    });

    it("ignores the answer that arrives after the form was left", async () => {
      const { form, wrapper: mounted, onCreated } = await start({ id: null });
      wrapper = mounted;
      Object.assign(form.form.value, { name: "N", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
      form.addVariants([variant("v-1")]);
      let release!: (value: unknown) => void;
      mocks.post.mockImplementation(() => new Promise((resolve) => (release = resolve)));

      const saving = form.save();
      mounted.unmount();
      release(ok(promotion(), 201));

      expect(await saving).toBeNull();
      expect(onCreated).not.toHaveBeenCalled();
    });
  });

  describe("discarding", () => {
    it("goes back to the promotion as the API last sent it, without a request, and clears the messages", async () => {
      const { form } = await open();
      form.form.value.name = "Zmieniona";
      form.removeVariant("v-1");
      form.error.value = "Coś.";
      mocks.get.mockClear();

      form.discard();

      expect(form.form.value.name).toBe("Jesień w górach");
      expect(form.form.value.variants.map((variant) => variant.variantId)).toEqual(["v-1", "v-2"]);
      expect(form.error.value).toBeNull();
      expect(form.dirty.value).toBe(false);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does nothing for a new promotion, which has nothing to go back to", async () => {
      const { form } = await open(promotion(), { id: null });
      form.form.value.name = "Nowa";

      form.discard();

      expect(form.form.value.name).toBe("Nowa");
    });

    it("does nothing while a request runs", async () => {
      const { form } = await open();
      form.form.value.name = "Zmieniona";
      mocks.put.mockReturnValue(new Promise(() => {}));
      void form.save();

      form.discard();

      expect(form.form.value.name).toBe("Zmieniona");
    });
  });

  describe("refreshing", () => {
    it("discards the changes and shows the promotion as the API has it", async () => {
      const { form } = await open();
      form.form.value.name = "Zmieniona";
      form.error.value = "Coś.";

      await form.refresh();

      expect(form.form.value.name).toBe("Jesień w górach");
      expect(form.error.value).toBeNull();
      expect(form.dirty.value).toBe(false);
    });

    describe("while the read of „Odśwież” has no answer", () => {
      /** Holds the next read of the promotion; `release` answers it. */
      function holdRead() {
        const held: { release: (value: unknown) => void } = { release: () => {} };
        mocks.get.mockImplementation(() => new Promise((resolve) => (held.release = resolve)));
        return held;
      }

      it("locks the fields and the controls of the variants and turns the actions off, and the answer unlocks the form and is shown", async () => {
        const { form } = await open();
        form.form.value.name = "Wpisana przed odświeżeniem";
        const held = holdRead();

        const refreshing = form.refresh();

        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(form.canDelete.value).toBe(false);
        form.addVariants([variant("v-3")]);
        form.removeVariant("v-1");
        form.discard();
        expect(form.form.value.variants.map((item) => item.variantId)).toEqual(["v-1", "v-2"]);
        expect(form.form.value.name).toBe("Wpisana przed odświeżeniem");

        held.release(ok(promotion({ name: "Z odczytu", version: 6 })));
        await refreshing;

        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("Z odczytu");
        expect(form.view.value?.version).toBe(6);
        expect(form.dirty.value).toBe(false);
        expect(form.canDelete.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        form.form.value.name = "Po odświeżeniu";
        expect(form.canSave.value).toBe(true);
      });

      it("turns „Zakończ teraz” off for an active promotion", async () => {
        const { form } = await open(promotion({ status: "active" }));
        expect(form.canEndNow.value).toBe(true);
        const held = holdRead();

        const refreshing = form.refresh();

        expect(form.locked.value).toBe(true);
        expect(form.canEndNow.value).toBe(false);
        held.release(ok(promotion({ status: "active" })));
        await refreshing;
        expect(form.canEndNow.value).toBe(true);
      });

      it.each([
        ["a read that does not reach the API", () => Promise.reject(new TypeError("network down"))],
        ["a read that fails", () => Promise.resolve(problem(500))],
      ])("unlocks the form again after %s, keeps it as it was and offers to try again instead of replacing it with the message of the load", async (_name, answer) => {
        const { form } = await open();
        let settle!: () => void;
        mocks.get.mockImplementation(
          () =>
            new Promise((resolve, reject) => {
              settle = () => answer().then(resolve, reject);
            }),
        );

        form.form.value.name = "Wpisana przed odświeżeniem";
        const refreshing = form.refresh();
        expect(form.locked.value).toBe(true);
        settle();
        await refreshing;

        expect(form.locked.value).toBe(false);
        expect(form.loadError.value).toBeNull();
        expect(form.loaded.value).toBe(true);
        expect(form.safety.readFailed.value).toBe(true);
        expect(form.form.value.name).toBe("Wpisana przed odświeżeniem");
        expect(form.view.value?.version).toBe(4);
      });

      it("ignores the answer after another promotion was opened, and the other promotion is not locked", async () => {
        const { form, id } = await open();
        const held = holdRead();
        const refreshing = form.refresh();
        expect(form.locked.value).toBe(true);

        servePromotion(promotion({ id: "promo-2", name: "Druga promocja", version: 7 }));
        id.value = "promo-2";
        await flushPromises();

        expect(form.form.value.name).toBe("Druga promocja");
        expect(form.locked.value).toBe(false);
        held.release(ok(promotion({ name: "Spóźniona", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Druga promocja");
        expect(form.view.value?.id).toBe("promo-2");
        expect(form.locked.value).toBe(false);
        expect(form.canDelete.value).toBe(true);
      });

      it("ignores the answer after the form was left, and leaves no lock", async () => {
        const { form, wrapper: mounted } = await open();
        const held = holdRead();
        const refreshing = form.refresh();
        expect(form.locked.value).toBe(true);

        mounted.unmount();
        held.release(ok(promotion({ name: "Spóźniona", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Jesień w górach");
        expect(form.view.value?.version).toBe(4);
        expect(form.locked.value).toBe(false);
      });
    });
  });
  describe("the draft and the unknown result of a save (stage 2, B3 and B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const NOT_ON_SERVER = "Zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
    const OTHER_VERSION = "Na serwerze jest inna wersja niż ta, którą edytujesz.";
    const LATE = "Wcześniejszy zapis został wykonany.";

    beforeEach(() => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
      vi.setSystemTime(new Date("2026-11-15T10:20:30.000Z"));
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

    /** An edit with the name typed, and the first save of it started and held. */
    async function savingFirst(name = "Pierwsza") {
      const started = await open();
      started.form.form.value.name = name;
      const puts = holdCalls(mocks.put);
      const saving = started.form.save();
      return { ...started, puts, saving };
    }

    /** The first save ended with an error that does not reach the API: the result is unknown. */
    async function unknownEdit(name = "Pierwsza") {
      const started = await open();
      started.form.form.value.name = name;
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      expect(await started.form.save()).toBe("failure");
      mocks.put.mockReset();
      return started;
    }

    describe("B3: „Odśwież”", () => {
      it("says „Odświeżamy…” while the read runs", async () => {
        const { form } = await open();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

        const refreshing = form.refresh();

        expect(form.safety.status.value).toBe("Odświeżamy…");
        release(ok(promotion()));
        await refreshing;
        expect(form.safety.status.value).toBe("");
      });

      it("unlocks the fields 10 s after „Odśwież” with a message to try again, keeps the form and ignores the late answer, also after a field was changed", async () => {
        const { form } = await open();
        form.form.value.name = "Wpisana";
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const refreshing = form.refresh();

        await advance(9_999);
        expect(form.locked.value).toBe(true);
        await advance(1);

        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
        expect(form.form.value.name).toBe("Wpisana");
        form.form.value.name = "Zmieniona po limicie";
        release(ok(promotion({ name: "Spóźniona", status: "active", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Zmieniona po limicie");
        expect(form.view.value?.version).toBe(4);
        expect(form.status.value).toBe("planned");
        expect(form.locked.value).toBe(false);
      });

      it("tries again with the retry of the message, and a good answer shows the promotion", async () => {
        const { form } = await open();
        form.form.value.name = "Wpisana";
        mocks.get.mockRejectedValueOnce(new TypeError("network down"));
        await form.refresh();
        expect(form.safety.readFailed.value).toBe(true);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Z serwera", version: 6 })));

        await form.safety.retryRead();

        expect(form.safety.readFailed.value).toBe(false);
        expect(form.form.value.name).toBe("Z serwera");
        expect(form.view.value?.version).toBe(6);
      });
    });

    describe("a 409 of a save (E7)", () => {
      it("reads nothing, whatever the 409 is, and the draft is replaced only by „Odrzuć moje zmiany i pokaż stan z serwera” after the answer 200", async () => {
        const { form } = await open();
        form.form.value.name = "Moja";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        mocks.get.mockClear();
        await form.save();
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
        expect(form.safety.restoreOffered.value).toBe(true);

        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const restoring = form.safety.restore();
        expect(form.form.value.name).toBe("Moja");
        expect(form.locked.value).toBe(true);
        release(ok(promotion({ name: "Z serwera", version: 7 })));
        await restoring;

        expect(form.form.value.name).toBe("Z serwera");
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
        form.form.value.name = "Moja";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        await form.save();
        fail();

        const restoring = form.safety.restore();
        await advance(10_000);
        await restoring;

        expect(form.form.value.name).toBe("Moja");
        expect(form.view.value?.version).toBe(4);
        expect(form.error.value).toContain("w międzyczasie zmieniona");
        expect(form.locked.value).toBe(false);
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.readFailed.value).toBe(true);
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
        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));

        expect(await saving).toBe("success");
        expect(form.view.value?.version).toBe(5);
        expect(form.message.value).toBe("Promocja zapisana.");
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.dirty.value).toBe(false);
      });

      it("shows the errors of a 422 that comes after the limit at their fields, without the message of an unknown result", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);

        puts[0]!.resolve(problem(422, { violations: [{ propertyPath: "name", title: "Za długa nazwa." }] }));

        expect(await saving).toBe("failure");
        expect(form.errorsAt("name")).toEqual(["Za długa nazwa."]);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("Pierwsza");
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
        form.form.value.name = "Moja";
        form.form.value.percentOff = "35";
        answer();

        expect(await form.save()).toBe("failure");

        expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN });
        expect(form.error.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("Moja");
        expect(form.form.value.percentOff).toBe("35");
        expect(form.view.value?.version).toBe(4);
        expect(form.dirty.value).toBe(true);
      });

      it("puts the errors of a 422 at the fields without that message", async () => {
        const { form } = await open();
        form.form.value.name = "Moja";
        mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Za długa." }] }));

        await form.save();

        expect(form.errorsAt("name")).toEqual(["Za długa."]);
        expect(form.safety.unknown.value).toBeNull();
      });
    });

    describe("B4 (c): „Sprawdź stan”", () => {
      it("takes the promotion from the server when it equals the draft, and sends no write", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(promotion({ name: "Pierwsza", version: 5 })));

        await form.safety.check();

        expect(form.view.value?.version).toBe(5);
        expect(form.dirty.value).toBe(false);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.status.value).toBe("Stan na serwerze odpowiada formularzowi.");
        expect(form.locked.value).toBe(false);
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it("leaves the draft, the base and the version when the server has other values with the same version, and says so", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));

        await form.safety.check();

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.view.value?.version).toBe(4);
        expect(form.dirty.value).toBe(true);
        expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
        expect(form.locked.value).toBe(false);
        expect(mocks.put).not.toHaveBeenCalled();
      });

      it("leaves them too and offers the server state when the version differs", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(promotion({ name: "Cudza", version: 6 })));

        await form.safety.check();

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.view.value?.version).toBe(4);
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

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.view.value?.version).toBe(4);
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
        release(ok(promotion({ name: "Pierwsza", version: 5 })));
        await checking;
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (d): what counts as the same request", () => {
      /** The first save of the name „Pierwsza” ended unknown; `change` edits the draft; the read answers `served`. */
      async function checkWith(change: (form: ReturnType<typeof usePromotionForm>) => void, served: Promotion) {
        const { form } = await open();
        form.form.value.name = "Pierwsza";
        mocks.put.mockRejectedValueOnce(new TypeError("network down"));
        await form.save();
        change(form);
        mocks.get.mockResolvedValue(ok(served));
        await form.safety.check();
        return form;
      }
      const PLAIN = promotion({ name: "Pierwsza", version: 5 });

      it("is not the same when one field differs", async () => {
        const form = await checkWith((current) => (current.form.value.percentOff = "21"), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when the order of the variants differs", async () => {
        const form = await checkWith((current) => (current.form.value.variants = [...current.form.value.variants].reverse()), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when a space was trimmed", async () => {
        const form = await checkWith((current) => (current.form.value.name = "Pierwsza "), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.form.value.name).toBe("Pierwsza ");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("never is „Od teraz”: the start is set by the server", async () => {
        const form = await checkWith((current) => (current.form.value.startNow = true), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.form.value.startNow).toBe(true);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is the same when the server writes the moments in another form", async () => {
        const form = await checkWith(() => {}, promotion({ name: "Pierwsza", version: 5, startsAt: "2026-11-11T00:00:00+01:00", endsAt: "2026-11-20T23:00:00.000Z" }));
        expect(form.view.value?.version).toBe(5);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("is not the same when an end that was typed is another moment than the one on the server", async () => {
        const form = await checkWith((current) => (current.form.value.endsAt = "2026-11-22T00:00"), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when no request can be built from the draft, and the check puts no error at the field", async () => {
        const form = await checkWith((current) => (current.form.value.percentOff = "abc"), PLAIN);
        expect(form.view.value?.version).toBe(4);
        expect(form.errorsAt("percentOff")).toEqual([]);
      });
    });

    describe("B4 (e) and (f): the draft changed after the unlock", () => {
      it("(e) keeps what was typed after the unlock, whatever „Sprawdź stan” finds, without the message of a match and without a write", async () => {
        const { form } = await unknownEdit("Pierwsza");
        form.form.value.name = "Druga";
        mocks.get.mockResolvedValue(ok(promotion({ name: "Pierwsza", version: 5 })));

        await form.safety.check();

        expect(form.form.value.name).toBe("Druga");
        expect(form.dirty.value).toBe(true);
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.status.value).toBe("");
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it("(f) keeps the changed field after the earlier save is done and the next one gets a 409, and reads nothing by itself", async () => {
        const { form, puts, saving, onLateSaved } = await savingFirst("Pierwsza");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");
        form.form.value.name = "Druga";
        const second = form.save();
        mocks.get.mockClear();

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;
        puts[1]!.resolve(conflict("about:blank", "Ktoś zmienił."));
        await second;

        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(4);
        expect(form.error.value).toContain("w międzyczasie zmieniona");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
      });
    });

    describe("B4 (g): „Odrzuć moje zmiany i pokaż stan z serwera” after a check", () => {
      it("replaces the draft only with the answer 200", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(promotion({ name: "Cudza", version: 6 })));
        await form.safety.check();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));

        const restoring = form.safety.restore();
        expect(form.form.value.name).toBe("Pierwsza");
        release(ok(promotion({ name: "Cudza", version: 6 })));
        await restoring;

        expect(form.form.value.name).toBe("Cudza");
        expect(form.view.value?.version).toBe(6);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.restoreOffered.value).toBe(false);
      });

      it("keeps the draft and the messages after an error", async () => {
        const { form } = await unknownEdit();
        mocks.get.mockResolvedValue(ok(promotion({ name: "Cudza", version: 6 })));
        await form.safety.check();
        mocks.get.mockRejectedValue(new TypeError("network down"));

        await form.safety.restore();

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (h) and (i): the earlier save answers during or after the check", () => {
      it("(h) keeps the draft and leaves nothing locked when it answers before the answer of the read", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const checking = form.safety.check();

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;
        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.view.value?.version).toBe(4);
        release(ok(promotion({ name: "Stara", version: 4 })));
        await checking;

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.locked.value).toBe(false);
        expect(form.safety.writing.value).toBe(false);
      });

      it("(h) keeps the draft and leaves nothing locked when it answers after the answer of the read", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));
        await form.safety.check();

        puts[0]!.reject(new TypeError("network down"));
        await saving;

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.kind).toBe("missing");
      });

      it("(i) does not take the lock of the newer save and shows no message of the earlier one when its error and end come after the newer save started", async () => {
        const { form, puts, saving } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));
        await form.safety.check();
        form.form.value.name = "Druga";
        const second = form.save();
        expect(form.locked.value).toBe(true);

        puts[0]!.reject(new TypeError("network down"));
        await saving;

        expect(form.locked.value).toBe(true);
        expect(form.busy.value).toBe(true);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.error.value).toBeNull();
        puts[1]!.resolve(ok(promotion({ name: "Druga", version: 5 })));
        expect(await second).toBe("success");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (j): the late success of the earlier save", () => {
      async function afterCheck() {
        const started = await savingFirst("Pierwsza");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));
        await started.form.safety.check();
        started.form.form.value.name = "Druga";
        const second = started.form.save();
        return { ...started, second };
      }

      it("only says so in a toast after the success of the newer save, and changes no field, version or dirty flag", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(ok(promotion({ name: "Druga", version: 6 })));
        await second;

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(6);
        expect(form.dirty.value).toBe(false);
      });

      it("keeps the errors of the newer save after its 422", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(problem(422, { violations: [{ propertyPath: "name", title: "Za długa." }] }));
        await second;

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.errorsAt("name")).toEqual(["Za długa."]);
        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(4);
        expect(form.dirty.value).toBe(true);
      });

      it("keeps the lock of the newer save while it runs", async () => {
        const { form, puts, saving, second, onLateSaved } = await afterCheck();

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.locked.value).toBe(true);
        expect(form.form.value.name).toBe("Druga");
        puts[1]!.resolve(ok(promotion({ name: "Druga", version: 6 })));
        await second;
      });

      it("says nothing when the staff member moved to another promotion, and changes nothing there", async () => {
        const { form, id, puts, saving, second, onLateSaved } = await afterCheck();
        puts[1]!.resolve(ok(promotion({ name: "Druga", version: 6 })));
        await second;
        servePromotion(promotion({ id: "promo-2", name: "Inna promocja", version: 2 }));
        id.value = "promo-2";
        await flushPromises();

        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.name).toBe("Inna promocja");
        expect(form.view.value?.version).toBe(2);
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (k): moving to another promotion", () => {
      it("ignores the answers of a save in progress, with no toast, no message, no move and no lock", async () => {
        const { form, id, puts, saving, onCreated, onLateSaved } = await savingFirst();
        await advance(10_000);
        expect(form.safety.unknown.value).not.toBeNull();
        servePromotion(promotion({ id: "promo-2", name: "Inna promocja", version: 2 }));
        id.value = "promo-2";
        await flushPromises();

        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.name).toBe("Inna promocja");
        expect(form.message.value).toBeNull();
      });

      it("ignores the answer of a check that comes after that", async () => {
        const { form, id } = await unknownEdit();
        let release!: (value: unknown) => void;
        mocks.get.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const checking = form.safety.check();
        servePromotion(promotion({ id: "promo-2", name: "Inna promocja", version: 2 }));
        id.value = "promo-2";
        await flushPromises();

        release(ok(promotion({ name: "Pierwsza", version: 5 })));
        await checking;

        expect(form.form.value.name).toBe("Inna promocja");
        expect(form.view.value?.id).toBe("promo-2");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("discarding the draft after a 409 or an unknown result", () => {
      it("takes the message and the button of the server state away with the draft", async () => {
        const { form } = await open();
        form.form.value.name = "Moja";
        mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
        await form.save();
        expect(form.safety.restoreOffered.value).toBe(true);

        form.discard();

        expect(form.error.value).toBeNull();
        expect(form.safety.restoreOffered.value).toBe(false);
        expect(form.form.value.name).toBe("Jesień w górach");
      });

      it("takes the unknown result away, and the answer of that save that comes later is only a toast", async () => {
        const { form, puts, saving, onLateSaved } = await savingFirst();
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(promotion({ name: "Stara", version: 4 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");

        form.discard();

        expect(form.safety.unknown.value).toBeNull();
        puts[0]!.resolve(ok(promotion({ name: "Pierwsza", version: 5 })));
        await saving;
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.form.value.name).toBe("Jesień w górach");
        expect(form.view.value?.version).toBe(4);
        expect(form.dirty.value).toBe(false);
      });
    });

    describe("B4 (l): a new promotion", () => {
      const UNKNOWN_NEW =
        "Nie wiemy, czy promocja została utworzona. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.";

      async function fillNew() {
        const started = await start({ id: null });
        wrapper = started.wrapper;
        Object.assign(started.form.form.value, { name: "Nowa", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
        started.form.addVariants([variant("v-1")]);
        const posts = holdCalls(mocks.post);
        const saving = started.form.save();
        return { ...started, posts, saving };
      }

      it("unlocks with „Odblokuj formularz”, says the promotion may still be created, and a changed field is kept", async () => {
        const { form, posts, saving } = await fillNew();
        await advance(10_000);
        expect(form.safety.unknown.value).toMatchObject({ pending: true, created: true, text: UNKNOWN });

        form.safety.unblock();

        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        form.form.value.name = "Inna nazwa";
        posts[0]!.resolve(ok(promotion({ id: "created" }), 201));
        await saving;
        expect(form.form.value.name).toBe("Inna nazwa");
      });

      it("says the same after an error or a 5xx, with the draft unlocked", async () => {
        const started = await start({ id: null });
        wrapper = started.wrapper;
        Object.assign(started.form.form.value, { name: "Nowa", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
        started.form.addVariants([variant("v-1")]);
        mocks.post.mockResolvedValue(problem(502));

        await started.form.save();

        expect(started.form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        expect(started.form.locked.value).toBe(false);
        expect(started.form.form.value.name).toBe("Nowa");
      });

      it("shows a toast for the late success and does not change the fields or the route", async () => {
        const { form, posts, saving, onCreated, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.name = "Zmieniona nazwa";

        posts[0]!.resolve(ok(promotion({ id: "created", name: "Nowa" }), 201));
        await saving;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(onCreated).not.toHaveBeenCalled();
        expect(form.view.value).toBeNull();
        expect(form.form.value.name).toBe("Zmieniona nazwa");
      });

      it("adds that the earlier save may have created it to a 409 of overlapping promotions that follows", async () => {
        const { form } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        mocks.post.mockResolvedValue(conflict("promotion-overlap", "Kolizja.", { conflictingVariantIds: ["v-1"] }));

        await form.save();

        expect(form.error.value).toBe("Kolizja. Możliwe, że utworzył ją Twój wcześniejszy zapis.");
        expect(form.isConflicting("v-1")).toBe(true);
      });

      it("says nothing when the earlier creation answers after a later one made the promotion and the route moved to it", async () => {
        const { form, id, posts, saving, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.name = "Druga";
        const second = form.save();
        posts[1]!.resolve(ok(promotion({ id: "promo-b", name: "Druga" }), 201));
        await second;
        servePromotion(promotion({ id: "promo-b", name: "Druga" }));
        id.value = "promo-b";
        await flushPromises();

        posts[0]!.resolve(ok(promotion({ id: "promo-a", name: "Nowa" }), 201));
        await saving;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.view.value?.id).toBe("promo-b");
        expect(form.form.value.name).toBe("Druga");
      });

      it("adds no such note to a 409 when no earlier save ended unknown", async () => {
        const started = await start({ id: null });
        wrapper = started.wrapper;
        Object.assign(started.form.form.value, { name: "Nowa", percentOff: "10", startNow: true, endsAt: "2026-11-21T00:00" });
        started.form.addVariants([variant("v-1")]);
        mocks.post.mockResolvedValue(conflict("promotion-overlap", "Kolizja.", { conflictingVariantIds: ["v-1"] }));

        await started.form.save();

        expect(started.form.error.value).toBe("Kolizja.");
      });
    });
  });
});
