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
