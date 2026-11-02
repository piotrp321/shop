import { describe, expect, it } from "vitest";
import { formatDelta, movementReasonLabel, movementSourceLabel, movementTypeLabel } from "@/inventory/format";

describe("movementTypeLabel", () => {
  it.each([
    ["receipt", "Przyjęcie"],
    ["correction", "Korekta"],
    ["reservation", "Rezerwacja"],
    ["release", "Zwolnienie"],
    ["commit", "Zatwierdzenie"],
  ] as const)("names the type %s as %s", (type, label) => {
    expect(movementTypeLabel(type)).toBe(label);
  });

  it("shows a type it does not know as it came, rather than hiding the entry", () => {
    expect(movementTypeLabel("transfer" as never)).toBe("transfer");
  });
});

describe("movementReasonLabel", () => {
  it.each([
    ["inventory_count", "Inwentaryzacja"],
    ["damage", "Uszkodzenie"],
    ["loss", "Zaginięcie"],
    ["other", "Inne"],
  ] as const)("names the reason %s as %s", (reason, label) => {
    expect(movementReasonLabel(reason)).toBe(label);
  });

  it("shows a dash when the entry has no reason and an unknown reason as it came", () => {
    expect(movementReasonLabel(null)).toBe("—");
    expect(movementReasonLabel("theft" as never)).toBe("theft");
  });
});

describe("movementSourceLabel", () => {
  it.each([
    ["staff", "Pracownik"],
    ["seed", "Dane demo"],
    ["system", "System"],
  ] as const)("names the source %s as %s", (source, label) => {
    expect(movementSourceLabel(source)).toBe(label);
  });

  it("shows a source it does not know as it came", () => {
    expect(movementSourceLabel("import" as never)).toBe("import");
  });
});

describe("formatDelta", () => {
  it("marks an increase with a plus, a decrease with a minus sign and leaves zero plain", () => {
    expect(formatDelta(12)).toBe("+12");
    expect(formatDelta(-3)).toBe("−3");
    expect(formatDelta(0)).toBe("0");
  });
});
