import { describe, expect, it } from "vitest";
import { instantToWarsaw, warsawToInstant } from "@/promotions/time";

describe("warsawToInstant", () => {
  it("reads a summer time as UTC+2", () => {
    expect(warsawToInstant("2026-07-15T12:30")).toBe("2026-07-15T10:30:00Z");
  });

  it("reads a winter time as UTC+1", () => {
    expect(warsawToInstant("2026-11-11T00:00")).toBe("2026-11-10T23:00:00Z");
  });

  it("keeps the seconds when they are given", () => {
    expect(warsawToInstant("2026-11-11T08:15:42")).toBe("2026-11-11T07:15:42Z");
  });

  it("does not depend on the time zone of the browser", () => {
    // The tests run in the zone of the container; the Warsaw offset must come from the zone name, not from it.
    expect(warsawToInstant("2026-01-01T00:00")).toBe("2025-12-31T23:00:00Z");
    expect(warsawToInstant("2026-06-01T00:00")).toBe("2026-05-31T22:00:00Z");
  });

  it("takes the earlier of the two moments of an hour that happens twice (the end of summer time)", () => {
    expect(warsawToInstant("2026-10-25T02:30")).toBe("2026-10-25T00:30:00Z");
  });

  it("rejects an hour that does not exist (the start of summer time)", () => {
    expect(warsawToInstant("2026-03-29T02:30")).toBeNull();
  });

  it("accepts the hours around the start of summer time", () => {
    expect(warsawToInstant("2026-03-29T01:59")).toBe("2026-03-29T00:59:00Z");
    expect(warsawToInstant("2026-03-29T03:00")).toBe("2026-03-29T01:00:00Z");
  });

  it.each(["", "2026-11-11", "2026-11-11 10:00", "jutro", "2026-02-31T10:00", "2026-13-01T10:00", "2026-11-11T24:00"])(
    "rejects %j",
    (text) => {
      expect(warsawToInstant(text)).toBeNull();
    },
  );
});

describe("instantToWarsaw", () => {
  it("shows a summer instant in UTC+2", () => {
    expect(instantToWarsaw("2026-07-15T10:30:00+00:00")).toBe("2026-07-15T12:30");
  });

  it("shows a winter instant in UTC+1", () => {
    expect(instantToWarsaw("2026-11-10T23:00:00Z")).toBe("2026-11-11T00:00");
  });

  it("keeps non-zero seconds", () => {
    expect(instantToWarsaw("2026-11-11T07:15:42Z")).toBe("2026-11-11T08:15:42");
  });

  it("accepts an offset other than UTC", () => {
    expect(instantToWarsaw("2026-11-11T00:00:00+01:00")).toBe("2026-11-11T00:00");
  });

  it("returns an empty text for a value that is not a moment", () => {
    expect(instantToWarsaw("nope")).toBe("");
  });

  it.each(["2026-03-29T00:30:00Z", "2026-03-29T01:30:00Z", "2026-10-25T00:30:00Z", "2026-12-31T23:59:59Z"])(
    "round-trips %s",
    (instant) => {
      expect(warsawToInstant(instantToWarsaw(instant))).toBe(instant);
    },
  );
});

describe("the repeated hour", () => {
  it("shows both moments as the same text, so the second one cannot be told apart in a form", () => {
    expect(instantToWarsaw("2026-10-25T00:30:00Z")).toBe("2026-10-25T02:30");
    expect(instantToWarsaw("2026-10-25T01:30:00Z")).toBe("2026-10-25T02:30");
  });
});
