const warsaw = new Intl.DateTimeFormat("pl-PL", { timeZone: "Europe/Warsaw", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });

/**
 * The time of day of a moment from the API (RFC 3339, usually UTC) in Warsaw, e.g. "14:35". The zone is fixed, so the
 * server and the browser of a customer abroad show the same text.
 */
export function formatDeadlineTime(iso: string): string {
  const moment = new Date(iso);
  return Number.isNaN(moment.getTime()) ? "" : warsaw.format(moment);
}
