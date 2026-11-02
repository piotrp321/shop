import { describe, expect, it } from "vitest";
import { formatDateTime, sourceLabel } from "@/prices/format";

describe("sourceLabel", () => {
  it.each([
    ["staff", "Pracownik"],
    ["seed", "Dane demo"],
    ["tax_reconciliation", "Uzgodnienie podatkowe"],
  ])("names the source %s as %s", (source, label) => {
    expect(sourceLabel(source)).toBe(label);
  });

  it("shows a source it does not know as it came, rather than hiding the change", () => {
    expect(sourceLabel("import")).toBe("import");
  });
});

describe("formatDateTime", () => {
  it("shows the moment in Polish time with seconds, whatever the time zone of the browser", () => {
    // 12:30:45 UTC on 1 November 2026 is 13:30:45 in Poland (winter time).
    expect(formatDateTime("2026-11-01T12:30:45+00:00")).toMatch(/^01\.11\.2026.*13:30:45$/);
    // 22:30 UTC on 30 June 2026 is 00:30 on 1 July in Poland (summer time).
    expect(formatDateTime("2026-06-30T22:30:00+00:00")).toMatch(/^01\.07\.2026.*00:30:00$/);
  });

  it("shows an unreadable value as it came", () => {
    expect(formatDateTime("yesterday")).toBe("yesterday");
  });
});
