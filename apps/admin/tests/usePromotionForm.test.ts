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
  let form!: ReturnType<typeof usePromotionForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = usePromotionForm({ promotionId: () => id.value, canManage: () => canManage.value, onCreated, onDeleted });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, wrapper, id, canManage, onCreated, onDeleted };
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

    it("says that the end was set, not that the promotion ended, when the API leaves it active", async () => {
      // The clock of the browser is ahead of the server: the API only shortens the promotion.
      const { form } = await open(promotion({ status: "active" }));
      mocks.put.mockResolvedValue(ok(promotion({ status: "active", endsAt: "2026-11-15T10:20:31+00:00" })));

      expect(await form.endNow()).toBe("success");

      expect(form.status.value).toBe("active");
      expect(form.message.value).toBe(`Koniec promocji ustawiony na ${formatDateTime("2026-11-15T10:20:31+00:00")}.`);
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

    it("reloads the promotion when the version changed meanwhile and says so", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      servePromotion(promotion({ name: "Zmieniona przez kogoś", version: 7 }));
      mocks.get.mockClear();

      expect(await form.save()).toBe("failure");

      expect(mocks.get).toHaveBeenCalledTimes(1);
      expect(form.form.value.name).toBe("Zmieniona przez kogoś");
      expect(form.view.value?.version).toBe(7);
      expect(form.error.value).toBe("Promocja została w międzyczasie zmieniona – pokazujemy jej aktualny stan.");
      expect(form.dirty.value).toBe(false);
    });

    it("shows the detail of another 409 and reloads the promotion", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      servePromotion(promotion({ status: "active", version: 6 }));
      mocks.get.mockClear();

      await form.save();

      expect(form.error.value).toBe("Rozpoczętej promocji nie można zmienić.");
      expect(form.status.value).toBe("active");
      expect(mocks.get).toHaveBeenCalledTimes(1);
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

    it("keeps the message of a 409 when the reload fails", async () => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      mocks.get.mockRejectedValue(new TypeError("network down"));

      await form.save();

      expect(form.error.value).toBe("Rozpoczętej promocji nie można zmienić.");
      expect(form.form.value.name).toBe("Inna");
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
      [503, "Usługa jest chwilowo niedostępna. Spróbuj ponownie."],
      [500, "Nie udało się wykonać operacji (HTTP 500)."],
    ])("says what a %s means and keeps the form", async (status, text) => {
      const { form } = await openChanged();
      mocks.put.mockResolvedValue(problem(status));

      expect(await form.save()).toBe("failure");

      expect(form.error.value).toBe(text);
      expect(form.form.value.name).toBe("Inna");
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
  });
});
