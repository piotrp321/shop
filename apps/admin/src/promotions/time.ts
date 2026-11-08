// Promotion times are typed and shown in Polish time. The API takes and sends RFC 3339 moments, so the form converts in
// both directions. This is presentation only: the zone rules come from `Intl`, no zone data is kept here.
const ZONE = "Europe/Warsaw";
const LOCAL_PATTERN = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/;
const DAY_MS = 86_400_000;

const partsFormatter = new Intl.DateTimeFormat("en-US", {
  timeZone: ZONE,
  year: "numeric",
  month: "numeric",
  day: "numeric",
  hour: "numeric",
  minute: "numeric",
  second: "numeric",
  hourCycle: "h23",
});

/** The wall clock of Warsaw at a moment, as the same numbers read as if they were UTC. */
function wallClock(ms: number): number {
  const parts: Record<string, number> = {};
  for (const part of partsFormatter.formatToParts(new Date(ms))) {
    if (part.type !== "literal") {
      parts[part.type] = Number(part.value);
    }
  }
  return Date.UTC(parts.year!, parts.month! - 1, parts.day!, parts.hour!, parts.minute!, parts.second!);
}

/** The offset of Warsaw from UTC at a moment, in milliseconds (whole seconds: `ms` is cut to them). */
function offsetAt(ms: number): number {
  const whole = Math.floor(ms / 1000) * 1000;
  return wallClock(whole) - whole;
}

function pad(value: number, length = 2): string {
  return String(value).padStart(length, "0");
}

/**
 * Turns a time typed in Polish time (`YYYY-MM-DDTHH:mm`, seconds optional, the value of a `datetime-local` input) into
 * an RFC 3339 moment in UTC. Returns null for text that is not a real time in Warsaw: not in that shape, a date that
 * does not exist, or an hour skipped when summer time starts. An hour that happens twice (summer time ends) is read
 * as its first occurrence.
 */
export function warsawToInstant(text: string): string | null {
  const match = LOCAL_PATTERN.exec(text);
  if (match === null) {
    return null;
  }
  const [year, month, day, hour, minute, second] = match.slice(1).map((value) => (value === undefined ? 0 : Number(value))) as [
    number,
    number,
    number,
    number,
    number,
    number,
  ];
  const wall = Date.UTC(year, month - 1, day, hour, minute, second);
  // The wall clock must name itself: a date like February 31 or 24:00 rolls over and fails this check.
  if (new Date(wall).toISOString().slice(0, 19) !== `${pad(year, 4)}-${pad(month)}-${pad(day)}T${pad(hour)}:${pad(minute)}:${pad(second)}`) {
    return null;
  }
  // The offset a day before and a day after bracket the change of the offset, if the time is next to one.
  const candidates = [...new Set([offsetAt(wall - DAY_MS), offsetAt(wall + DAY_MS)])]
    .map((offset) => wall - offset)
    .filter((moment) => wallClock(moment) === wall)
    .sort((a, b) => a - b);
  const moment = candidates[0];
  return moment === undefined ? null : new Date(moment).toISOString().replace(".000Z", "Z");
}

/**
 * Shows an RFC 3339 moment in Polish time in the shape of a `datetime-local` input (`YYYY-MM-DDTHH:mm`, with seconds
 * only when they are not zero, so that nothing the API stored is lost). A value that is not a moment gives "".
 */
export function instantToWarsaw(instant: string): string {
  const date = new Date(instant);
  if (Number.isNaN(date.getTime())) {
    return "";
  }
  const local = new Date(wallClock(date.getTime()));
  const base = `${pad(local.getUTCFullYear(), 4)}-${pad(local.getUTCMonth() + 1)}-${pad(local.getUTCDate())}T${pad(local.getUTCHours())}:${pad(local.getUTCMinutes())}`;
  return local.getUTCSeconds() === 0 ? base : `${base}:${pad(local.getUTCSeconds())}`;
}
