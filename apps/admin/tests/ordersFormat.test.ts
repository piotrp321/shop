import { describe, expect, it } from "vitest";
import type { components } from "@/api/schema";
import { actorLabel, cancellationReasonLabel, noteRequired, STATUS_OPTIONS, statusLabel, transitionLabel } from "@/orders/format";

type StatusChange = components["schemas"]["BackofficeOrderStatusChange"];

function change(overrides: Partial<StatusChange>): StatusChange {
  return { from: "paid", to: "shipped", occurredAt: "2026-11-05T12:40:00+00:00", actorType: "staff", staffId: "s1", staffUsername: "anna", note: null, ...overrides };
}

describe("statusLabel", () => {
  it.each([
    ["new", "Czeka na płatność"],
    ["paid", "Opłacone"],
    ["shipped", "Wysłane"],
    ["delivered", "Dostarczone"],
    ["cancelled", "Anulowane"],
    ["returned", "Zwrócone"],
  ] as const)("names %s as %s", (status, label) => {
    expect(statusLabel(status).label).toBe(label);
  });

  it("offers every status as a filter option, in the order of the lifecycle", () => {
    expect(STATUS_OPTIONS.map((option) => option.value)).toEqual(["new", "paid", "shipped", "delivered", "cancelled", "returned"]);
    expect(STATUS_OPTIONS.map((option) => option.label)).toEqual(["Czeka na płatność", "Opłacone", "Wysłane", "Dostarczone", "Anulowane", "Zwrócone"]);
  });
});

describe("actorLabel", () => {
  it("names the staff member by user name", () => {
    expect(actorLabel(change({ actorType: "staff", staffUsername: "anna" }))).toBe("anna");
  });

  it("falls back to a generic name for a staff entry without a user name", () => {
    expect(actorLabel(change({ actorType: "staff", staffUsername: null }))).toBe("Pracownik");
  });

  it("names the customer and the system", () => {
    expect(actorLabel(change({ actorType: "customer", staffUsername: null }))).toBe("Klient");
    expect(actorLabel(change({ actorType: "system", staffUsername: null }))).toBe("System");
  });
});

describe("transitions", () => {
  it.each([
    ["ship", "Oznacz jako wysłane"],
    ["deliver", "Oznacz jako dostarczone"],
    ["cancel", "Anuluj zamówienie"],
    ["return", "Przyjmij zwrot"],
  ] as const)("labels %s as %s", (transition, label) => {
    expect(transitionLabel(transition)).toBe(label);
  });

  it("requires a note only for cancel and return", () => {
    expect(["ship", "deliver", "cancel", "return"].map((transition) => noteRequired(transition as never))).toEqual([false, false, true, true]);
  });
});

describe("cancellationReasonLabel", () => {
  it("names the reasons the API gives", () => {
    expect(cancellationReasonLabel("payment_timeout")).toBe("Brak płatności w terminie");
    expect(cancellationReasonLabel("staff")).toBe("Anulowane przez pracownika");
    expect(cancellationReasonLabel(null)).toBeNull();
  });
});
