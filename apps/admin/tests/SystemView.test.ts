import type { VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import SystemView from "@/views/SystemView.vue";
import { dashboard, describeFreshness, mocks, mountScreen, serve, serviceNames, system } from "./dashboardSupport";

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

    const region = wrapper.get("[role=region]");
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
  });

  it("warns about a discrepancy with both numbers", async () => {
    serve(dashboard({ system: system({ searchIndex: { indexedProducts: 400, catalogProducts: 412, inSync: false } }) }));
    wrapper = await mountSystem();

    const message = wrapper.get("[data-testid=out-of-sync]");
    expect(message.attributes("role")).toBe("status");
    expect(message.text()).toContain("400");
    expect(message.text()).toContain("412");
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
