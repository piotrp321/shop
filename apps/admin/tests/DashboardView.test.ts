import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import DashboardView from "@/views/DashboardView.vue";

type Dashboard = components["schemas"]["DashboardResponse"];
type System = NonNullable<Dashboard["system"]>;

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const serviceNames = ["postgres", "redis", "elasticsearch", "rabbitmq", "keycloak", "garage", "imgproxy", "mailpit"];

function system(overrides: Partial<System> = {}): System {
  return {
    services: serviceNames.map((name, index) => ({ name, status: "up" as const, latencyMs: index + 1 })),
    outbox: { pending: 3 },
    queues: [{ name: "events", messages: 7, consumers: 1 }],
    searchIndex: { indexedProducts: 412, catalogProducts: 412, inSync: true },
    ...overrides,
  };
}

function dashboard(overrides: Partial<Dashboard> = {}): Dashboard {
  return {
    catalog: { productCount: 412, categoryCount: 36, productsWithoutImages: 0 },
    system: system(),
    ...overrides,
  };
}

function respondWith(data: Dashboard) {
  mocks.get.mockResolvedValue({ data, response: { status: 200 } });
}

async function mountView() {
  const wrapper = mount(DashboardView, { global: { plugins: [[PrimeVue, primeVueOptions]] } });
  await flushPromises();
  return wrapper;
}

describe("DashboardView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  it("shows the catalog numbers from the API", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/dashboard");
    expect(wrapper.text()).toContain("Katalog");
    expect(wrapper.get("[data-testid=product-count]").text()).toBe("412");
    expect(wrapper.get("[data-testid=category-count]").text()).toBe("36");
    expect(wrapper.get("[data-testid=products-without-images]").text()).toBe("0");
  });

  it("hides the 'Stan systemu' heading when system is null", async () => {
    respondWith(dashboard({ system: null }));
    wrapper = await mountView();

    expect(wrapper.text()).toContain("Katalog");
    expect(wrapper.text()).not.toContain("Stan systemu");
    expect(wrapper.findAll("[data-testid=service-tag]")).toHaveLength(0);
  });

  it("shows eight service tags, in the API order, when system is present", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    expect(wrapper.text()).toContain("Stan systemu");
    const services = wrapper.findAll("[data-testid=service]");
    expect(services).toHaveLength(8);
    expect(services.map((service) => service.get("[data-testid=service-name]").text())).toEqual(serviceNames);
    expect(wrapper.findAll("[data-testid=service-tag]")).toHaveLength(8);
    expect(wrapper.findAll("[data-testid=service-tag].p-tag-success")).toHaveLength(8);
    expect(services[0]?.text()).toContain("1 ms");
  });

  it("marks a service that is down and shows no latency for it", async () => {
    const services = system().services.map((service) =>
      service.name === "redis" ? { ...service, status: "down" as const, latencyMs: null } : service,
    );
    respondWith(dashboard({ system: system({ services }) }));
    wrapper = await mountView();

    const redis = wrapper.findAll("[data-testid=service]")[1];
    expect(redis?.get("[data-testid=service-tag]").classes()).toContain("p-tag-danger");
    expect(redis?.text()).not.toContain("ms");
  });

  it("shows the number of events waiting in the outbox and the queues table", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=outbox-pending]").text()).toBe("3");
    const queues = wrapper.get("[data-testid=queues]");
    expect(queues.text()).toContain("events");
    expect(queues.text()).toContain("7");
  });

  it("shows 'Brak danych o kolejkach' when queues is null", async () => {
    respondWith(dashboard({ system: system({ queues: null }) }));
    wrapper = await mountView();

    expect(wrapper.text()).toContain("Brak danych o kolejkach");
    expect(wrapper.find("[data-testid=queues]").exists()).toBe(false);
  });

  it("shows the search index comparison without a warning when in sync", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=search-index]").text()).toContain("412");
    expect(wrapper.find("[data-testid=out-of-sync]").exists()).toBe(false);
  });

  it("shows a discrepancy message when inSync is false", async () => {
    respondWith(
      dashboard({ system: system({ searchIndex: { indexedProducts: 400, catalogProducts: 412, inSync: false } }) }),
    );
    wrapper = await mountView();

    const message = wrapper.get("[data-testid=out-of-sync]");
    expect(message.find(".p-message-warn").exists()).toBe(true);
    expect(message.text()).toContain("400");
    expect(message.text()).toContain("412");
  });

  it("shows the discrepancy message with an unknown index count when indexedProducts is null", async () => {
    respondWith(
      dashboard({ system: system({ searchIndex: { indexedProducts: null, catalogProducts: 412, inSync: false } }) }),
    );
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=out-of-sync]").exists()).toBe(true);
    expect(wrapper.get("[data-testid=search-index]").text()).toContain("brak danych");
  });

  it("warns when there are products without images", async () => {
    respondWith(dashboard({ catalog: { productCount: 412, categoryCount: 36, productsWithoutImages: 8 } }));
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=products-without-images]").text()).toBe("8");
    expect(wrapper.get("[data-testid=images-warning]").classes()).toContain("p-tag-warn");
  });

  it("shows no image warning when every product has an image", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=images-warning]").exists()).toBe(false);
  });

  it("shows a skeleton while loading and no data", async () => {
    mocks.get.mockReturnValue(new Promise(() => {}));
    wrapper = await mountView();

    expect(wrapper.find(".p-skeleton").exists()).toBe(true);
    expect(wrapper.text()).not.toContain("Katalog");
  });

  it("shows an error Message when the API answers with an error", async () => {
    mocks.get.mockResolvedValue({ error: { status: 503 }, response: { status: 503 } });
    wrapper = await mountView();

    const message = wrapper.get("[data-testid=dashboard-error]");
    expect(message.find(".p-message-error").exists()).toBe(true);
    expect(wrapper.find(".p-skeleton").exists()).toBe(false);
  });

  it("shows an error Message when the request fails", async () => {
    mocks.get.mockRejectedValue(new TypeError("Failed to fetch"));
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=dashboard-error]").text()).not.toContain("Failed to fetch");
  });

  it("reloads when 'Odśwież' is clicked and clears the error on success", async () => {
    mocks.get.mockResolvedValueOnce({ error: { status: 503 }, response: { status: 503 } });
    wrapper = await mountView();
    expect(wrapper.find("[data-testid=dashboard-error]").exists()).toBe(true);

    respondWith(dashboard());
    await wrapper.get("[data-testid=refresh]").trigger("click");
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledTimes(2);
    expect(wrapper.find("[data-testid=dashboard-error]").exists()).toBe(false);
    expect(wrapper.text()).toContain("Katalog");
  });

  it("refreshes every 30 seconds and stops after unmount", async () => {
    vi.useFakeTimers();
    respondWith(dashboard());
    wrapper = await mountView();
    expect(mocks.get).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(30_000);
    expect(mocks.get).toHaveBeenCalledTimes(2);

    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(90_000);
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("keeps the last data on screen during a refresh", async () => {
    respondWith(dashboard());
    wrapper = await mountView();

    mocks.get.mockReturnValue(new Promise(() => {}));
    await wrapper.get("[data-testid=refresh]").trigger("click");

    expect(wrapper.find(".p-skeleton").exists()).toBe(false);
    expect(wrapper.text()).toContain("Katalog");
  });
});
