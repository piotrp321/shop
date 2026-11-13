import { describe, expect, it } from "vitest";
import { serviceLabel, serviceStatusLabel } from "@/dashboard/labels";

describe("serviceLabel", () => {
  it.each([
    ["postgres", "Baza danych (PostgreSQL)"],
    ["redis", "Pamięć podręczna (Redis)"],
    ["elasticsearch", "Wyszukiwarka (Elasticsearch)"],
    ["rabbitmq", "Kolejka wiadomości (RabbitMQ)"],
    ["keycloak", "Logowanie (Keycloak)"],
    ["garage", "Magazyn plików (Garage)"],
    ["imgproxy", "Obrazy (imgproxy)"],
    ["mailpit", "Poczta (Mailpit)"],
  ])("names %s as %s", (name, label) => {
    expect(serviceLabel(name)).toBe(label);
  });

  it("shows a service the panel does not know under the name the API sent", () => {
    expect(serviceLabel("clamav")).toBe("clamav");
  });
});

describe("serviceStatusLabel", () => {
  it("says a service is up or down in Polish", () => {
    expect(serviceStatusLabel("up")).toBe("Działa");
    expect(serviceStatusLabel("down")).toBe("Nie działa");
  });
});
