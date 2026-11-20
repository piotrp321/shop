import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, type MaybeRefOrGetter, ref } from "vue";
import type { components } from "@/api/schema";
import { CONFLICT_MESSAGE, useOrderDetail } from "@/orders/useOrderDetail";

type BackofficeOrder = components["schemas"]["BackofficeOrder"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const DETAIL = "/api/v1/admin/orders/{orderId}";
const STATUS_CHANGES = "/api/v1/admin/orders/{orderId}/status-changes";
const REFUND_COMPLETION = "/api/v1/admin/orders/{orderId}/refund-completion";
const TRACKING_NUMBER = "/api/v1/admin/orders/{orderId}/tracking-number";

function order(overrides: Partial<BackofficeOrder> = {}): BackofficeOrder {
  return {
    orderId: "o-1",
    customerId: null,
    number: "R-100001",
    status: "paid",
    version: 2,
    email: "anna.nowak@example.com",
    fullName: "Anna Nowak",
    phone: "+48600100200",
    shippingAddress: { street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa", country: "PL" },
    lines: [],
    discount: null,
    // An order placed before the delivery; the totals are those of its lines.
    delivery: null,
    totals: { net: 32519, vat: 7479, gross: 39998, currency: "PLN" },
    placedAt: "2026-11-05T12:30:00+00:00",
    paymentDeadline: "2026-11-05T13:00:00+00:00",
    paidAt: "2026-11-05T12:40:00+00:00",
    paymentId: "pay-1",
    shippedAt: null,
    deliveredAt: null,
    cancelledAt: null,
    cancellationReason: null,
    returnedAt: null,
    history: [],
    refund: null,
    shipment: null,
    allowedTransitions: ["ship", "cancel"],
    canCompleteRefund: false,
    canChangeTrackingNumber: false,
    ...overrides,
  };
}

function mountDetail(orderId: MaybeRefOrGetter<string> = "o-1") {
  let detail!: ReturnType<typeof useOrderDetail>;
  const wrapper = mount(
    defineComponent({
      setup() {
        detail = useOrderDetail(orderId);
        return () => null;
      },
    }),
  );
  return { wrapper, detail };
}

function posted(call = 0): { path: string; options: { params: { path: { orderId: string } }; body: Record<string, unknown> } } {
  const [path, options] = mocks.post.mock.calls[call] as [string, never];
  return { path, options };
}

describe("useOrderDetail", () => {
  let wrapper: ReturnType<typeof mountDetail>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function start(first: BackofficeOrder | number | Error, id: Parameters<typeof mountDetail>[0] = "o-1") {
    if (first instanceof Error) {
      mocks.get.mockRejectedValue(first);
    } else if (typeof first === "number") {
      mocks.get.mockResolvedValue({ data: undefined, response: { status: first } });
    } else {
      mocks.get.mockResolvedValue({ data: first, response: { status: 200 } });
    }
    const mounted = mountDetail(id);
    wrapper = mounted.wrapper;
    await flushPromises();
    return mounted.detail;
  }

  describe("reading", () => {
    it("loads the order by the id", async () => {
      const detail = await start(order());

      expect(mocks.get).toHaveBeenCalledWith(DETAIL, { params: { path: { orderId: "o-1" } } });
      expect(detail.order.value?.number).toBe("R-100001");
      expect(detail.loaded.value).toBe(true);
      expect(detail.error.value).toBeNull();
      expect(detail.notFound.value).toBe(false);
    });

    it("loads another order when the id changes", async () => {
      const id = ref("o-1");
      const detail = await start(order(), id);

      mocks.get.mockResolvedValue({ data: order({ orderId: "o-2", number: "R-100002" }), response: { status: 200 } });
      id.value = "o-2";
      await flushPromises();

      expect(mocks.get).toHaveBeenLastCalledWith(DETAIL, { params: { path: { orderId: "o-2" } } });
      expect(detail.order.value?.orderId).toBe("o-2");
      expect(detail.order.value?.number).toBe("R-100002");
    });

    it("says the order was not found on a 404, without an error to retry", async () => {
      const detail = await start(404);

      expect(detail.notFound.value).toBe(true);
      expect(detail.notFoundMessage).toBe("Nie znaleźliśmy zamówienia.");
      expect(detail.error.value).toBeNull();
      expect(detail.order.value).toBeNull();
    });

    it("tells a role without access that it has none (403)", async () => {
      const detail = await start(403);

      expect(detail.error.value).toBe("Nie masz dostępu do zamówień.");
      expect(detail.forbidden.value).toBe(true);
    });

    it("shows another error with a retry that loads the order again", async () => {
      const detail = await start(500);

      expect(detail.error.value).toBe("Nie udało się pobrać zamówienia (HTTP 500).");
      expect(detail.forbidden.value).toBe(false);
      expect(detail.notFound.value).toBe(false);

      mocks.get.mockResolvedValue({ data: order(), response: { status: 200 } });
      await detail.retry();

      expect(detail.error.value).toBeNull();
      expect(detail.order.value?.number).toBe("R-100001");
    });

    it("explains a request that did not reach the API", async () => {
      const detail = await start(new TypeError("Failed to fetch"));

      expect(detail.error.value).toBe("Nie udało się pobrać zamówienia. Sprawdź połączenie z API.");
      expect(detail.loading.value).toBe(false);
    });
  });

  describe("actions", () => {
    it.each([
      [["ship", "cancel"], ["Oznacz jako wysłane", "Anuluj zamówienie"]],
      [["deliver", "return"], ["Oznacz jako dostarczone", "Przyjmij zwrot"]],
      [["ship"], ["Oznacz jako wysłane"]],
      [[], []],
    ] as const)("offers only the changes of allowedTransitions %j", async (allowed, labels) => {
      const detail = await start(order({ allowedTransitions: [...allowed] }));

      expect(detail.actions.value.map((action) => action.label)).toEqual(labels);
      expect(detail.actions.value.map((action) => action.transition)).toEqual([...allowed]);
    });

    it("marks the notes the dialog requires: cancel and return", async () => {
      const detail = await start(order({ allowedTransitions: ["ship", "deliver", "cancel", "return"] }));

      expect(detail.actions.value.map((action) => [action.transition, action.noteRequired])).toEqual([
        ["ship", false],
        ["deliver", false],
        ["cancel", true],
        ["return", true],
      ]);
    });

    it("offers no change when the API allows none, whatever the status", async () => {
      const detail = await start(order({ status: "paid", allowedTransitions: [] }));

      expect(detail.actions.value).toEqual([]);
    });

    it("offers the completion of the refund only when the API says it can", async () => {
      expect((await start(order({ canCompleteRefund: true }))).canCompleteRefund.value).toBe(true);
      wrapper?.unmount();
      expect((await start(order({ status: "cancelled", canCompleteRefund: false }))).canCompleteRefund.value).toBe(false);
    });

    it("has no actions before the order is loaded", async () => {
      const detail = await start(500);

      expect(detail.actions.value).toEqual([]);
      expect(detail.canCompleteRefund.value).toBe(false);
    });
  });

  describe("changing the status", () => {
    it("sends the transition with the version of the view and the note, and shows the order the API answers with", async () => {
      const detail = await start(order({ version: 2 }));
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3, allowedTransitions: ["deliver", "return"] }), response: { status: 200 } });

      const result = await detail.changeStatus("ship", "Kurier DPD");

      expect(result).toEqual({ status: "saved" });
      expect(posted()).toEqual({
        path: STATUS_CHANGES,
        options: { params: { path: { orderId: "o-1" } }, body: { transition: "ship", version: 2, note: "Kurier DPD", trackingNumber: null } },
      });
      expect(detail.order.value?.status).toBe("shipped");
      expect(detail.order.value?.version).toBe(3);
      expect(detail.actions.value.map((action) => action.transition)).toEqual(["deliver", "return"]);
      expect(detail.saving.value).toBe(false);
    });

    it("sends the tracking number the staff member typed with a shipment, as it was typed", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });

      await detail.changeStatus("ship", null, " JJD0002-4455 ");

      expect(posted().options.body).toEqual({ transition: "ship", version: 2, note: null, trackingNumber: " JJD0002-4455 " });
    });

    it.each(["", "   "])("sends no number for a blank one (%j)", async (typed) => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });

      await detail.changeStatus("ship", null, typed);

      expect(posted().options.body).toEqual({ transition: "ship", version: 2, note: null, trackingNumber: null });
    });

    it.each(["cancel", "return", "deliver"] as const)("never sends a number with %s, whatever it was given", async (transition) => {
      const detail = await start(order({ allowedTransitions: [transition] }));
      mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 3, allowedTransitions: [] }), response: { status: 200 } });

      await detail.changeStatus(transition, "Powód", "JJD-1");

      expect(posted().options.body).toEqual({ transition, version: 2, note: "Powód", trackingNumber: null });
    });

    it("puts a 422 of the tracking number at its field, next to one of the note", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({
        data: undefined,
        error: {
          violations: [
            { propertyPath: "trackingNumber", title: "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki." },
            { propertyPath: "note", title: "Notatka może mieć najwyżej 500 znaków." },
          ],
        },
        response: { status: 422 },
      });

      const result = await detail.changeStatus("ship", "x", "Żółć");

      expect(result).toEqual({
        status: "invalid",
        fields: { note: "Notatka może mieć najwyżej 500 znaków.", trackingNumber: "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki." },
        message: null,
      });
      expect(detail.order.value?.status).toBe("paid");
    });

    it("sends no note for a blank one where it is optional", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });

      await detail.changeStatus("ship", "   ");

      expect(posted().options.body).toEqual({ transition: "ship", version: 2, note: null, trackingNumber: null });
    });

    it.each(["cancel", "return"] as const)("requires a note to %s and sends nothing without it", async (transition) => {
      const detail = await start(order({ allowedTransitions: [transition] }));

      for (const note of ["", "   "]) {
        const result = await detail.changeStatus(transition, note);
        expect(result).toEqual({ status: "invalid", fields: { note: "Podaj notatkę." }, message: null });
      }

      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("sends the cancellation with the note", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 3, allowedTransitions: [] }), response: { status: 200 } });

      const result = await detail.changeStatus("cancel", "Klient zrezygnował");

      expect(result).toEqual({ status: "saved" });
      expect(posted().options.body).toEqual({ transition: "cancel", version: 2, note: "Klient zrezygnował", trackingNumber: null });
    });

    it("does not send a change that allowedTransitions does not list", async () => {
      const detail = await start(order({ allowedTransitions: ["ship"] }));

      const result = await detail.changeStatus("cancel", "Powód");

      expect(result.status).toBe("failed");
      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("fails without sending when no order is loaded", async () => {
      const detail = await start(500);

      expect((await detail.changeStatus("ship", null)).status).toBe("failed");
      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("sends once when asked again while the first request is still in flight", async () => {
      const detail = await start(order());
      let answer!: () => void;
      mocks.post.mockImplementation(
        () =>
          new Promise((resolve) => {
            answer = () => resolve({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });
          }),
      );

      const first = detail.changeStatus("ship", null);
      expect(detail.saving.value).toBe(true);
      const second = await detail.changeStatus("ship", null);
      answer();
      await first;

      expect(second.status).toBe("failed");
      expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it.each([
      ["a stale version", "about:blank"],
      ["a transition the status does not allow", "tag:piotrp321.github.io,2026:shop/problems/order-transition-not-allowed"],
    ])("reloads the order and reports the conflict on a 409 for %s", async (_name, type) => {
      const detail = await start(order({ version: 2 }));
      mocks.post.mockResolvedValue({ data: undefined, error: { type, status: 409, detail: "tekst" }, response: { status: 409 } });
      mocks.get.mockResolvedValue({ data: order({ status: "shipped", version: 3, allowedTransitions: ["deliver"] }), response: { status: 200 } });

      const result = await detail.changeStatus("ship", null);

      expect(result).toEqual({ status: "conflict", message: "Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan." });
      expect(CONFLICT_MESSAGE).toBe("Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.");
      expect(mocks.get).toHaveBeenCalledTimes(2);
      expect(detail.order.value?.version).toBe(3);
      expect(detail.actions.value.map((action) => action.transition)).toEqual(["deliver"]);
    });

    it("does not bring back the old order when a read that was in flight answers after the change", async () => {
      const detail = await start(order({ version: 2 }));
      let answerRead!: () => void;
      mocks.get.mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            answerRead = () => resolve({ data: order({ version: 2 }), response: { status: 200 } });
          }),
      );
      const reading = detail.retry();
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });

      await detail.changeStatus("ship", null);
      answerRead();
      await reading;

      expect(detail.order.value?.status).toBe("shipped");
      expect(detail.order.value?.version).toBe(3);
      expect(detail.loading.value).toBe(false);
    });

    it("ignores the answer of a change when the id has changed meanwhile, a conflict reload included", async () => {
      const id = ref("o-1");
      const detail = await start(order(), id);
      let answerPost!: (answer: unknown) => void;
      mocks.post.mockImplementation(
        () =>
          new Promise((resolve) => {
            answerPost = resolve;
          }),
      );
      mocks.get.mockResolvedValue({ data: order({ orderId: "o-2", number: "R-100002", version: 7 }), response: { status: 200 } });

      const changing = detail.changeStatus("ship", null);
      id.value = "o-2";
      await flushPromises();
      mocks.get.mockClear();
      answerPost({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      const result = await changing;

      expect(result).toEqual({ status: "ignored" });
      expect(mocks.get).not.toHaveBeenCalled();
      expect(detail.order.value?.orderId).toBe("o-2");
    });

    it("does not show the saved order of another id when the id has changed during the change", async () => {
      const id = ref("o-1");
      const detail = await start(order(), id);
      let answerPost!: (answer: unknown) => void;
      mocks.post.mockImplementation(
        () =>
          new Promise((resolve) => {
            answerPost = resolve;
          }),
      );
      mocks.get.mockResolvedValue({ data: order({ orderId: "o-2", number: "R-100002" }), response: { status: 200 } });

      const changing = detail.changeStatus("ship", null);
      id.value = "o-2";
      await flushPromises();
      answerPost({ data: order({ status: "shipped", version: 3 }), response: { status: 200 } });
      const result = await changing;

      expect(result).toEqual({ status: "ignored" });
      expect(detail.order.value?.orderId).toBe("o-2");
      expect(detail.order.value?.status).toBe("paid");
    });

    it("sends the version of the reloaded order after a conflict", async () => {
      const detail = await start(order({ version: 2 }));
      mocks.post.mockResolvedValueOnce({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      mocks.get.mockResolvedValue({ data: order({ version: 4 }), response: { status: 200 } });
      await detail.changeStatus("ship", null);

      mocks.post.mockResolvedValueOnce({ data: order({ status: "shipped", version: 5 }), response: { status: 200 } });
      await detail.changeStatus("ship", null);

      expect(posted(1).options.body.version).toBe(4);
    });

    it("keeps the order that is shown when the reload after a conflict fails", async () => {
      const detail = await start(order({ version: 2 }));
      mocks.post.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });

      const result = await detail.changeStatus("ship", null);

      expect(result.status).toBe("conflict");
      expect(detail.order.value?.version).toBe(2);
      expect(detail.error.value).toBe("Nie udało się pobrać zamówienia (HTTP 500).");
    });

    it("puts a 422 about the note at the note field", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({
        data: undefined,
        error: { violations: [{ propertyPath: "note", title: "Notatka może mieć najwyżej 500 znaków." }] },
        response: { status: 422 },
      });

      const result = await detail.changeStatus("ship", "x");

      expect(result).toEqual({ status: "invalid", fields: { note: "Notatka może mieć najwyżej 500 znaków." }, message: null });
    });

    it("shows a 422 that points at no field, or has no violations, as a message", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValueOnce({
        data: undefined,
        error: { violations: [{ propertyPath: "transition", title: "Nieznane przejście." }] },
        response: { status: 422 },
      });
      expect(await detail.changeStatus("ship", null)).toEqual({ status: "invalid", fields: {}, message: "Nieznane przejście." });

      mocks.post.mockResolvedValueOnce({ data: undefined, error: { detail: "Odrzucone." }, response: { status: 422 } });
      expect(await detail.changeStatus("ship", null)).toEqual({ status: "invalid", fields: {}, message: "Odrzucone." });

      mocks.post.mockResolvedValueOnce({ data: undefined, response: { status: 422 } });
      expect(await detail.changeStatus("ship", null)).toEqual({ status: "invalid", fields: {}, message: "API odrzuciło zmianę statusu (HTTP 422)." });
    });

    it("reports a 403 in the dialog and keeps the order", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: undefined, response: { status: 403 } });

      const result = await detail.changeStatus("ship", null);

      expect(result).toEqual({ status: "failed", message: "Nie masz uprawnień do tej zmiany." });
      expect(detail.order.value?.version).toBe(2);
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("reports a 404 of the change as a missing order", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValue({ data: undefined, response: { status: 404 } });

      expect(await detail.changeStatus("ship", null)).toEqual({ status: "failed", message: "Nie znaleźliśmy zamówienia." });
    });

    it("reports another status and a lost connection", async () => {
      const detail = await start(order());
      mocks.post.mockResolvedValueOnce({ data: undefined, response: { status: 503 } });
      expect(await detail.changeStatus("ship", null)).toEqual({ status: "failed", message: "Nie udało się zmienić statusu zamówienia (HTTP 503)." });

      mocks.post.mockRejectedValueOnce(new TypeError("Failed to fetch"));
      expect(await detail.changeStatus("ship", null)).toEqual({
        status: "failed",
        message: "Nie udało się zmienić statusu zamówienia. Sprawdź połączenie z API.",
      });
      expect(detail.saving.value).toBe(false);
    });
  });

  describe("the tracking number", () => {
    const shipped = (overrides: Partial<BackofficeOrder> = {}) => order({ status: "shipped", version: 3, allowedTransitions: ["deliver", "return"], canChangeTrackingNumber: true, ...overrides });

    it("is offered only when the API says it can be changed", async () => {
      expect((await start(shipped())).canChangeTrackingNumber.value).toBe(true);
      wrapper?.unmount();
      expect((await start(order())).canChangeTrackingNumber.value).toBe(false);
      wrapper?.unmount();
      expect((await start(500)).canChangeTrackingNumber.value).toBe(false);
    });

    it("sends the number with the version of the view and shows the order the API answers with", async () => {
      const detail = await start(shipped());
      mocks.put.mockResolvedValue({ data: shipped({ version: 4, shipment: { carrier: "Kurier standardowy", trackingNumber: "JJD0002-4455", trackingUrl: null } }), response: { status: 200 } });

      const result = await detail.changeTrackingNumber(" JJD0002-4455 ");

      expect(result).toEqual({ status: "saved" });
      expect(mocks.put).toHaveBeenCalledWith(TRACKING_NUMBER, { params: { path: { orderId: "o-1" } }, body: { trackingNumber: " JJD0002-4455 ", version: 3 } });
      expect(detail.order.value?.version).toBe(4);
      expect(detail.order.value?.shipment?.trackingNumber).toBe("JJD0002-4455");
      expect(detail.saving.value).toBe(false);
    });

    it.each(["", "   "])("removes the number with a blank one (%j) by sending null", async (typed) => {
      const detail = await start(shipped({ shipment: { carrier: null, trackingNumber: "AB-1", trackingUrl: null } }));
      mocks.put.mockResolvedValue({ data: shipped({ version: 4 }), response: { status: 200 } });

      const result = await detail.changeTrackingNumber(typed);

      expect(result).toEqual({ status: "saved" });
      expect(mocks.put).toHaveBeenCalledWith(TRACKING_NUMBER, { params: { path: { orderId: "o-1" } }, body: { trackingNumber: null, version: 3 } });
      expect(detail.order.value?.shipment).toBeNull();
    });

    it("does not send a change the API does not allow now", async () => {
      const detail = await start(order());

      const result = await detail.changeTrackingNumber("AB-1");

      expect(result.status).toBe("failed");
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("fails without sending when no order is loaded", async () => {
      const detail = await start(500);

      expect((await detail.changeTrackingNumber("AB-1")).status).toBe("failed");
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("puts a 422 of the number at its field and keeps the order as it was", async () => {
      const detail = await start(shipped());
      mocks.put.mockResolvedValue({
        data: undefined,
        error: { violations: [{ propertyPath: "trackingNumber", title: "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki." }] },
        response: { status: 422 },
      });

      const result = await detail.changeTrackingNumber("Żółć");

      expect(result).toEqual({ status: "invalid", fields: { trackingNumber: "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki." }, message: null });
      expect(detail.order.value?.version).toBe(3);
    });

    it("says the API refused a 422 that points at no field, with its detail", async () => {
      const detail = await start(shipped());
      mocks.put.mockResolvedValue({ data: undefined, error: { detail: "Coś nie tak." }, response: { status: 422 } });

      expect(await detail.changeTrackingNumber("AB-1")).toEqual({ status: "invalid", fields: {}, message: "Coś nie tak." });
      mocks.put.mockResolvedValue({ data: undefined, error: {}, response: { status: 422 } });
      expect(await detail.changeTrackingNumber("AB-1")).toEqual({ status: "invalid", fields: {}, message: "API odrzuciło zmianę numeru przesyłki (HTTP 422)." });
    });

    it.each([
      ["a stale version", "about:blank"],
      ["an order that is not shipped any more", "tag:piotrp321.github.io,2026:shop/problems/tracking-number-not-changeable"],
    ])("reloads the order and reports the conflict on a 409 for %s", async (_name, type) => {
      const detail = await start(shipped());
      mocks.put.mockResolvedValue({ data: undefined, error: { type, status: 409, detail: "tekst" }, response: { status: 409 } });
      mocks.get.mockResolvedValue({ data: order({ status: "returned", version: 5, allowedTransitions: [], canChangeTrackingNumber: false }), response: { status: 200 } });

      const result = await detail.changeTrackingNumber("AB-1");

      expect(result).toEqual({ status: "conflict", message: CONFLICT_MESSAGE });
      expect(detail.order.value?.version).toBe(5);
      expect(detail.canChangeTrackingNumber.value).toBe(false);
    });

    it.each([
      [403, "Nie masz uprawnień do tej zmiany."],
      [404, "Nie znaleźliśmy zamówienia."],
      [500, "Nie udało się zapisać numeru przesyłki (HTTP 500)."],
    ])("reports a %i", async (status, message) => {
      const detail = await start(shipped());
      mocks.put.mockResolvedValue({ data: undefined, response: { status } });

      expect(await detail.changeTrackingNumber("AB-1")).toEqual({ status: "failed", message });
    });

    it("reports a request that did not reach the API", async () => {
      const detail = await start(shipped());
      mocks.put.mockRejectedValue(new TypeError("Failed to fetch"));

      expect(await detail.changeTrackingNumber("AB-1")).toEqual({ status: "failed", message: "Nie udało się zapisać numeru przesyłki. Sprawdź połączenie z API." });
      expect(detail.saving.value).toBe(false);
    });

    it("sends once when asked again while the first request is still in flight", async () => {
      const detail = await start(shipped());
      let answer!: () => void;
      mocks.put.mockImplementation(
        () =>
          new Promise((resolve) => {
            answer = () => resolve({ data: shipped({ version: 4 }), response: { status: 200 } });
          }),
      );

      const first = detail.changeTrackingNumber("AB-1");
      const second = await detail.changeTrackingNumber("AB-2");
      answer();
      await first;

      expect(second.status).toBe("failed");
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("ignores the answer when the id changed meanwhile", async () => {
      const id = ref("o-1");
      const detail = await start(shipped(), id);
      let answer!: () => void;
      mocks.put.mockImplementation(
        () =>
          new Promise((resolve) => {
            answer = () => resolve({ data: shipped({ version: 4 }), response: { status: 200 } });
          }),
      );

      const sending = detail.changeTrackingNumber("AB-1");
      mocks.get.mockResolvedValue({ data: order({ orderId: "o-2", number: "R-100002" }), response: { status: 200 } });
      id.value = "o-2";
      await flushPromises();
      answer();

      expect(await sending).toEqual({ status: "ignored" });
      expect(detail.order.value?.orderId).toBe("o-2");
    });
  });

  describe("completing the refund", () => {
    const refundOrder = () =>
      order({
        status: "cancelled",
        version: 3,
        allowedTransitions: [],
        canCompleteRefund: true,
        refund: { amount: 39998, currency: "PLN", dueAt: "2026-11-19T12:30:00+00:00", overdue: false, completedAt: null, completedByStaffId: null, completedByUsername: null, note: null },
      });

    it("sends the version of the view and the note and shows the order the API answers with", async () => {
      const detail = await start(refundOrder());
      mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 4, canCompleteRefund: false }), response: { status: 200 } });

      const result = await detail.completeRefund("Przelew wysłany");

      expect(result).toEqual({ status: "saved" });
      expect(posted()).toEqual({
        path: REFUND_COMPLETION,
        options: { params: { path: { orderId: "o-1" } }, body: { version: 3, note: "Przelew wysłany" } },
      });
      expect(detail.canCompleteRefund.value).toBe(false);
      expect(detail.order.value?.version).toBe(4);
    });

    it("sends no note for a blank one", async () => {
      const detail = await start(refundOrder());
      mocks.post.mockResolvedValue({ data: order({ version: 4 }), response: { status: 200 } });

      await detail.completeRefund("  ");

      expect(posted().options.body).toEqual({ version: 3, note: null });
    });

    it("does not send when the API does not allow the completion", async () => {
      const detail = await start(order({ canCompleteRefund: false }));

      expect((await detail.completeRefund(null)).status).toBe("failed");
      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("reloads the order and reports the conflict on a 409 (a stale version, or no refund to do)", async () => {
      const detail = await start(refundOrder());
      mocks.post.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      mocks.get.mockResolvedValue({ data: order({ status: "cancelled", version: 4, canCompleteRefund: false }), response: { status: 200 } });

      const result = await detail.completeRefund(null);

      expect(result).toEqual({ status: "conflict", message: CONFLICT_MESSAGE });
      expect(detail.canCompleteRefund.value).toBe(false);
      expect(detail.order.value?.version).toBe(4);
    });

    it("puts a 422 about the note at the note field and reports a 403 in the dialog", async () => {
      const detail = await start(refundOrder());
      mocks.post.mockResolvedValueOnce({
        data: undefined,
        error: { violations: [{ propertyPath: "note", title: "Notatka musi być jedną linią." }] },
        response: { status: 422 },
      });
      expect(await detail.completeRefund("a\nb")).toEqual({ status: "invalid", fields: { note: "Notatka musi być jedną linią." }, message: null });

      mocks.post.mockResolvedValueOnce({ data: undefined, response: { status: 403 } });
      expect(await detail.completeRefund(null)).toEqual({ status: "failed", message: "Nie masz uprawnień do tej zmiany." });
    });
  });
});
