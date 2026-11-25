import { describe, expect, it } from "vitest";
import { formatAge, serviceLabel, serviceStatusLabel, transportLabel } from "@/dashboard/labels";

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

describe("transportLabel", () => {
  it.each([
    ["outbox", "Zdarzenia do wysłania"],
    ["scheduled", "Komendy opóźnione"],
    ["payment_simulator", "Webhooki symulatora płatności"],
    ["promotions", "Granice promocji"],
    ["jobs", "Zadania w tle"],
  ])("names %s as %s", (name, label) => {
    expect(transportLabel(name)).toBe(label);
  });

  it("shows a queue the panel does not know under the name the API sent", () => {
    expect(transportLabel("reports")).toBe("reports");
  });
});

describe("formatAge", () => {
  it("shows a dash when there is no ready message", () => {
    expect(formatAge(null)).toBe("—");
  });

  it.each([
    [0, "0 s"],
    [59, "59 s"],
    [60, "1 min"],
    [119, "1 min"],
    [3599, "59 min"],
    [3600, "1 h"],
    [7199, "1 h"],
    [90000, "25 h"],
  ])("shows %d seconds as %s, rounded down", (seconds, text) => {
    expect(formatAge(seconds)).toBe(text);
  });
});
