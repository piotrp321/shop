import { describe, expect, it } from "vitest";
import { formatDeadlineTime } from "./format-time";

describe("formatDeadlineTime", () => {
  it.each([
    ["winter time (UTC+1)", "2026-11-05T13:00:00+00:00", "14:00"],
    ["summer time (UTC+2)", "2026-07-05T13:00:00+00:00", "15:00"],
    ["after midnight in Warsaw", "2026-11-05T23:30:00+00:00", "00:30"],
    ["an offset in the string", "2026-11-05T14:35:00+01:00", "14:35"],
    ["a Z suffix", "2026-11-05T13:35:00Z", "14:35"],
  ])("shows %s as Warsaw time", (_name, iso, expected) => {
    expect(formatDeadlineTime(iso)).toBe(expected);
  });

  it("does not depend on the time zone of the machine", () => {
    const previous = process.env.TZ;
    process.env.TZ = "America/Los_Angeles";
    try {
      expect(formatDeadlineTime("2026-11-05T13:00:00+00:00")).toBe("14:00");
    } finally {
      if (previous === undefined) delete process.env.TZ;
      else process.env.TZ = previous;
    }
  });

  it("returns an empty string for a value that is not a date", () => {
    expect(formatDeadlineTime("soon")).toBe("");
  });
});
