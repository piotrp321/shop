import type { VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import SystemView from "@/views/SystemView.vue";
import { dashboard, describeFreshness, messages, mocks, mountScreen, serve, serviceNames, system, transport, transportNames } from "./dashboardSupport";

vi.mock("@/api/client", async () => {
  const { mocks: shared } = await import("./dashboardMocks");
  return { getApiClient: () => ({ GET: shared.get }) };
});

const mountSystem = () => mountScreen(SystemView, ["admin"], "/system");

describe("SystemView", () => {
  let wrapper: VueWrapper | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("has „Stan systemu” as the only H1 and the crumbs Pulpit › Stan systemu", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    expect(wrapper.findAll("h1").map((heading) => heading.text())).toEqual(["Stan systemu"]);
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.findAll("li:not([role=presentation])").map((item) => item.text())).toEqual(["Pulpit", "Stan systemu"]);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Stan systemu");
  });

  it("shows the services in the API order, named in Polish", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    const services = wrapper.findAll("[data-testid=service]");
    expect(services.map((service) => service.get("[data-testid=service-name]").text())).toEqual([
      "Baza danych (PostgreSQL)",
      "Pamięć podręczna (Redis)",
      "Wyszukiwarka (Elasticsearch)",
      "Kolejka wiadomości (RabbitMQ)",
      "Logowanie (Keycloak)",
      "Magazyn plików (Garage)",
      "Obrazy (imgproxy)",
      "Poczta (Mailpit)",
    ]);
    expect(services).toHaveLength(serviceNames.length);
  });

  it("says „Działa” in the success colour with the latency of a service that is up", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    const first = wrapper.findAll("[data-testid=service]")[0];
    const status = first?.get("[data-testid=service-tag]");
    expect(status?.text()).toBe("Działa");
    expect(status?.classes()).toContain("text-status-success");
    expect(first?.text()).toContain("1 ms");
    expect(wrapper.findAll("[data-testid=service-tag]").map((tag) => tag.text())).toEqual(Array(8).fill("Działa"));
  });

  it("says „Nie działa” in the danger colour and shows no latency for a service that is down", async () => {
    const services = system().services.map((service) => (service.name === "redis" ? { ...service, status: "down" as const, latencyMs: null } : service));
    serve(dashboard({ system: system({ services }) }));
    wrapper = await mountSystem();

    const redis = wrapper.findAll("[data-testid=service]")[1];
    const status = redis?.get("[data-testid=service-tag]");
    expect(status?.text()).toBe("Nie działa");
    expect(status?.classes()).toContain("text-status-danger");
    expect(redis?.text()).not.toContain("ms");
  });

  it("shows the events waiting to be sent, not an „outbox”", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    expect(wrapper.text()).toContain("Zdarzenia czekające na wysłanie");
    expect(wrapper.text()).not.toMatch(/outbox/i);
    expect(wrapper.get("[data-testid=outbox-pending]").text()).toBe("3");
  });

  it("shows the queues in a named, focusable region that scrolls", async () => {
    serve(dashboard({ system: system({ queues: [{ name: "events", messages: 7, consumers: 1 }, { name: "failed", messages: 0, consumers: 0 }] }) }));
    wrapper = await mountSystem();

    const region = wrapper.get("[role=region][aria-label=Kolejki]");
    expect(region.attributes("aria-label")).toBe("Kolejki");
    expect(region.attributes("tabindex")).toBe("0");
    expect(region.classes()).toContain("overflow-x-auto");
    const table = region.get("[data-testid=queues]");
    expect(table.findAll("th").map((cell) => cell.text())).toEqual(["Nazwa", "Wiadomości", "Konsumenci"]);
    expect(table.findAll("tbody tr").map((row) => row.findAll("td").map((cell) => cell.text()))).toEqual([
      ["events", "7", "1"],
      ["failed", "0", "0"],
    ]);
  });

  it("says there are no queues when the list is empty", async () => {
    serve(dashboard({ system: system({ queues: [] }) }));
    wrapper = await mountSystem();

    expect(wrapper.get("[data-testid=queues]").text()).toContain("Brak kolejek.");
  });

  it("says „Brak danych o kolejkach” when queues is null", async () => {
    serve(dashboard({ system: system({ queues: null }) }));
    wrapper = await mountSystem();

    expect(wrapper.text()).toContain("Brak danych o kolejkach");
    expect(wrapper.find("[data-testid=queues]").exists()).toBe(false);
  });

  it("shows the search index next to the catalogue without a warning when they are in sync", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    expect(wrapper.get("[data-testid=search-index]").text()).toContain("412");
    expect(wrapper.find("[data-testid=out-of-sync]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=not-comparable]").exists()).toBe(false);
  });

  it("warns about a discrepancy with both numbers", async () => {
    serve(dashboard({ system: system({ searchIndex: { indexedProducts: 400, catalogProducts: 412, inSync: false } }) }));
    wrapper = await mountSystem();

    const message = wrapper.get("[data-testid=out-of-sync]");
    expect(message.attributes("role")).toBe("status");
    expect(message.text()).toContain("400");
    expect(message.text()).toContain("412");
  });

  it("says that the index cannot be compared without an estimate of the products, and warns of nothing", async () => {
    serve(dashboard({ system: system({ searchIndex: { indexedProducts: 412, catalogProducts: null, inSync: null } }) }));
    wrapper = await mountSystem();

    const index = wrapper.get("[data-testid=search-index]");
    expect(index.text()).toContain("412");
    expect(wrapper.get("[data-testid=not-comparable]").text()).toBe("Nie można porównać: brak statystyk bazy.");
    expect(wrapper.get("[data-testid=not-comparable]").attributes("role")).toBe("status");
    expect(wrapper.find("[data-testid=out-of-sync]").exists()).toBe(false);
  });

  it("warns about a discrepancy with an unknown index count when indexedProducts is null", async () => {
    serve(dashboard({ system: system({ searchIndex: { indexedProducts: null, catalogProducts: 412, inSync: false } }) }));
    wrapper = await mountSystem();

    expect(wrapper.find("[data-testid=out-of-sync]").exists()).toBe(true);
    expect(wrapper.get("[data-testid=search-index]").text()).toContain("brak danych");
  });

  it("says there is no system data when the API sent none", async () => {
    serve(dashboard({ system: null }));
    wrapper = await mountSystem();

    expect(wrapper.get("[data-testid=system-unavailable]").text()).toContain("Brak danych o stanie systemu");
    expect(wrapper.findAll("[data-testid=service]")).toHaveLength(0);
  });

  describe("messages", () => {
    const mountMessages = async (overrides: Parameters<typeof messages>[0] = {}) => {
      serve(dashboard({ system: system({ messages: messages(overrides) }) }));
      wrapper = await mountSystem();
      return wrapper;
    };

    it("has a „Wiadomości” section with a heading", async () => {
      const view = await mountMessages();

      const section = view.get("[data-testid=messages]");
      expect(section.get("h2").text()).toBe("Wiadomości");
      expect(section.attributes("aria-labelledby")).toBe(section.get("h2").attributes("id"));
    });

    it("says „Brak” for no failed messages, in no danger tone", async () => {
      const view = await mountMessages();

      const count = view.get("[data-testid=failed-count]");
      expect(count.text()).toBe("Brak");
      expect(count.classes()).not.toContain("text-status-danger");
      expect(view.find("[data-testid=failed-oldest]").exists()).toBe(false);
    });

    it("shows the number of failed messages in the danger tone, with the date of the oldest in the time zone of the browser", async () => {
      const oldestAt = "2026-11-18T09:05:07+00:00";
      const view = await mountMessages({ healthy: false, failed: { count: 12, capped: false, oldestAt } });

      const count = view.get("[data-testid=failed-count]");
      expect(count.text()).toBe("12");
      expect(count.classes()).toContain("text-status-danger");
      const date = new Date(oldestAt);
      const two = (value: number) => String(value).padStart(2, "0");
      expect(view.get("[data-testid=failed-oldest]").text()).toBe(
        `Najstarsza: ${two(date.getDate())}.${two(date.getMonth() + 1)}.${date.getFullYear()}, ${two(date.getHours())}:${two(date.getMinutes())}:${two(date.getSeconds())}`,
      );
    });

    it("shows „1000+” when the failed messages are capped", async () => {
      const view = await mountMessages({ healthy: false, failed: { count: 1000, capped: true, oldestAt: "2026-11-18T09:05:07+00:00" } });

      const count = view.get("[data-testid=failed-count]");
      expect(count.text()).toBe("1000+");
      expect(count.classes()).toContain("text-status-danger");
    });

    it("labels the failed number so that it reads as „Nieudane wiadomości”", async () => {
      const view = await mountMessages();

      expect(view.get("[data-testid=messages]").text()).toContain("Nieudane wiadomości");
    });

    it("has a row for every queue of the database in the API order, named in Polish", async () => {
      const view = await mountMessages();

      const rows = view.get("[data-testid=transports]").findAll("tbody tr");
      expect(rows.map((row) => row.get("[data-testid=transport-name]").text())).toEqual([
        "Zdarzenia do wysłania",
        "Komendy opóźnione",
        "Webhooki symulatora płatności",
        "Granice promocji",
        "Zadania w tle",
      ]);
      expect(rows).toHaveLength(transportNames.length);
    });

    it("shows a queue the panel does not know under the name the API sent", async () => {
      const view = await mountMessages({ transports: [transport("reports")] });

      expect(view.get("[data-testid=transport-name]").text()).toBe("reports");
    });

    it("puts the queues in a named, focusable region that scrolls, apart from the queues of RabbitMQ", async () => {
      const view = await mountMessages();

      const region = view.get("[role=region][aria-label='Kolejki w bazie danych']");
      expect(region.attributes("tabindex")).toBe("0");
      expect(region.classes()).toContain("overflow-x-auto");
      expect(region.find("[data-testid=transports]").exists()).toBe(true);
      expect(region.find("[data-testid=queues]").exists()).toBe(false);
      expect(region.findAll("th").map((cell) => cell.text())).toEqual(["Kolejka", "Gotowe", "Najstarsza gotowa", "Stan"]);
    });

    it("shows the ready messages, „1000+” when capped, and the age of the oldest", async () => {
      const view = await mountMessages({
        transports: [
          transport("outbox"),
          transport("scheduled", { ready: 5, oldestReadyAgeSeconds: 42 }),
          transport("jobs", { ready: 1000, capped: true, oldestReadyAgeSeconds: 7260 }),
        ],
      });

      const rows = view.get("[data-testid=transports]").findAll("tbody tr");
      expect(rows.map((row) => [row.get("[data-testid=transport-ready]").text(), row.get("[data-testid=transport-age]").text()])).toEqual([
        ["0", "—"],
        ["5", "42 s"],
        ["1000+", "2 h"],
      ]);
    });

    it("says „W normie” in the success tone and „Zator” in the danger tone as the API says with stalled", async () => {
      const view = await mountMessages({
        healthy: false,
        transports: [transport("outbox"), transport("jobs", { ready: 3, oldestReadyAgeSeconds: 900, stalled: true })],
      });

      const states = view.findAll("[data-testid=transport-state]");
      expect(states.map((state) => state.text())).toEqual(["W normie", "Zator"]);
      expect(states[0]?.classes()).toContain("text-status-success");
      expect(states[1]?.classes()).toContain("text-status-danger");
    });

    it("does not judge a queue by its age: a long wait without stalled is „W normie”", async () => {
      const view = await mountMessages({ transports: [transport("outbox", { ready: 1, oldestReadyAgeSeconds: 100000 })] });

      expect(view.get("[data-testid=transport-state]").text()).toBe("W normie");
    });

    it("says there are no queues when the list is empty", async () => {
      const view = await mountMessages({ transports: [] });

      expect(view.get("[data-testid=transports]").text()).toContain("Brak kolejek.");
    });

    it("says „Kolejki działają” when healthy, in a status the screen reader announces", async () => {
      const view = await mountMessages();

      const summary = view.get("[data-testid=messages-summary]");
      expect(summary.text()).toBe("Kolejki działają");
      expect(summary.attributes("role")).toBe("status");
      expect(summary.classes()).toContain("text-status-success");
    });

    it("says „Wymaga uwagi” when not healthy, even with no queue stalled", async () => {
      const view = await mountMessages({ healthy: false, failed: { count: 1, capped: false, oldestAt: "2026-11-18T09:05:07+00:00" } });

      const summary = view.get("[data-testid=messages-summary]");
      expect(summary.text()).toBe("Wymaga uwagi");
      expect(summary.classes()).toContain("text-status-warning");
    });
  });

  it("does not ask for the orders", async () => {
    serve(dashboard());
    wrapper = await mountSystem();

    expect(mocks.get.mock.calls.map(([path]) => path)).toEqual(["/api/v1/admin/dashboard"]);
  });
});

describeFreshness("SystemView", {
  mount: () => mountSystem(),
  withNumber: (value) => dashboard({ system: system({ outbox: { pending: value } }) }),
  readNumber: (view) => view.get("[data-testid=outbox-pending]").text(),
  shown: "Zdarzenia czekające na wysłanie",
});
