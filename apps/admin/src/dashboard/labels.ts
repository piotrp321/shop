import type { Dashboard } from "./useDashboard";

type Service = NonNullable<Dashboard["system"]>["services"][number];

// The names the API sends are the technical ones; the screen says what each service does, with the product name where
// there is one (PostgreSQL, Redis, RabbitMQ and Elasticsearch stay as they are named).
const serviceLabels: Record<string, string> = {
  postgres: "Baza danych (PostgreSQL)",
  redis: "Pamięć podręczna (Redis)",
  elasticsearch: "Wyszukiwarka (Elasticsearch)",
  rabbitmq: "Kolejka wiadomości (RabbitMQ)",
  keycloak: "Logowanie (Keycloak)",
  garage: "Magazyn plików (Garage)",
  imgproxy: "Obrazy (imgproxy)",
  mailpit: "Poczta (Mailpit)",
};

/** The Polish name of a service of the system; a service the panel does not know is shown under the name the API sent. */
export function serviceLabel(name: string): string {
  return serviceLabels[name] ?? name;
}

/** What a service is doing: it answers (`up`) or it does not (`down`). */
export function serviceStatusLabel(status: Service["status"]): string {
  return status === "up" ? "Działa" : "Nie działa";
}

// The queues of the database as the API names them; the screen says what each one carries.
const transportLabels: Record<string, string> = {
  outbox: "Zdarzenia do wysłania",
  scheduled: "Komendy opóźnione",
  payment_simulator: "Webhooki symulatora płatności",
  promotions: "Granice promocji",
  jobs: "Zadania w tle",
  indexing_finalize: "Finalizacja przebudowy indeksu",
};

/** The Polish name of a queue of the database; a queue the panel does not know is shown under the name the API sent. */
export function transportLabel(name: string): string {
  return transportLabels[name] ?? name;
}

/** How long a message has been ready: „—” without one, then seconds, minutes and hours, each rounded down. */
export function formatAge(seconds: number | null): string {
  if (seconds === null) {
    return "—";
  }
  if (seconds < 60) {
    return `${seconds} s`;
  }
  if (seconds < 3600) {
    return `${Math.floor(seconds / 60)} min`;
  }
  return `${Math.floor(seconds / 3600)} h`;
}

// No time zone is named: the moment is shown in the zone of the browser.
const browserDateTime = new Intl.DateTimeFormat("pl-PL", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hourCycle: "h23",
});

/** A moment from the API (RFC 3339) in the time zone of the browser; a value that is not a moment is shown as it came. */
export function formatBrowserDateTime(moment: string): string {
  const date = new Date(moment);
  return Number.isNaN(date.getTime()) ? moment : browserDateTime.format(date);
}
