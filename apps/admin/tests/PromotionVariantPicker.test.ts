import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import PromotionVariantPicker from "@/promotions/PromotionVariantPicker.vue";

type PriceListItem = components["schemas"]["PriceListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const price = { gross: 12000, net: 9756, vat: 2244, vatRate: 2300, currency: "PLN", version: 1, updatedAt: "2026-11-01T12:30:00+00:00", vatRateOutdated: false };
const tent: PriceListItem = { productId: "prod-a", name: "Namiot Tarvik", taxClass: "standard", applicableVatRate: 2300, variants: [{ variantId: "a-1", label: "Zielony", price }] };
const bag: PriceListItem = { productId: "prod-b", name: "Śpiwór", taxClass: "standard", applicableVatRate: 2300, variants: [{ variantId: "b-1", label: "", price }] };

const ok = (items: PriceListItem[], next: string | null) => ({ data: { items, next }, response: { status: 200 } });
const failure = { data: undefined, response: { status: 500 } };

/** Two pages: the tent with a next page, then the bag as the last one. */
function servePages() {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    options?.params?.query?.after === "c1" ? ok([bag], null) : ok([tent], "c1"),
  );
}

describe("PromotionVariantPicker focus", () => {
  let wrapper: ReturnType<typeof mountPicker>;

  function mountPicker() {
    return mount(PromotionVariantPicker, { attachTo: document.body, props: { chosen: [] } });
  }

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = "";
  });

  async function open() {
    wrapper = mountPicker();
    await flushPromises();
  }

  const button = (testid: string) => wrapper.get<HTMLButtonElement>(`[data-testid=${testid}]`).element;
  async function press(testid: string) {
    button(testid).focus();
    await wrapper.get(`[data-testid=${testid}]`).trigger("click");
    await flushPromises();
  }

  it("moves the focus to „Poprzednia” when „Następna” brings the last page and goes off", async () => {
    servePages();
    await open();

    await press("picker-next");

    expect(button("picker-next").disabled).toBe(true);
    expect(document.activeElement).toBe(button("picker-previous"));
  });

  it("moves the focus to „Następna” when „Poprzednia” brings the first page and goes off", async () => {
    servePages();
    await open();
    await press("picker-next");

    await press("picker-previous");

    expect(button("picker-previous").disabled).toBe(true);
    expect(document.activeElement).toBe(button("picker-next"));
  });

  it("keeps the button that has the focus enabled while the next page is requested", async () => {
    servePages();
    await open();
    let answer: (value: ReturnType<typeof ok>) => void = () => {};
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));

    button("picker-next").focus();
    await wrapper.get("[data-testid=picker-next]").trigger("click");

    expect(button("picker-next").disabled).toBe(false);
    answer(ok([bag], null));
    await flushPromises();
    expect(document.activeElement).toBe(button("picker-previous"));
  });

  it("keeps the focus off the body when a retry brings the list and the alert goes", async () => {
    mocks.get.mockResolvedValue(failure);
    await open();
    servePages();

    await press("picker-retry");

    expect(wrapper.find("[data-testid=picker-error]").exists()).toBe(false);
    expect(document.activeElement).not.toBe(document.body);
    expect(document.activeElement).toBe(wrapper.get("[role=region]").element);
  });

  it("puts the focus in the search when a retry brings an empty list", async () => {
    mocks.get.mockResolvedValue(failure);
    await open();
    mocks.get.mockResolvedValue(ok([], null));

    await press("picker-retry");

    expect(wrapper.find("[data-testid=picker-error]").exists()).toBe(false);
    expect(document.activeElement).toBe(wrapper.get("[data-testid=picker-search]").element);
  });

  it("keeps the focus on „Spróbuj ponownie”, enabled while it runs, when the retry fails again", async () => {
    mocks.get.mockResolvedValue(failure);
    await open();
    let answer: (value: typeof failure) => void = () => {};
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));

    button("picker-retry").focus();
    await wrapper.get("[data-testid=picker-retry]").trigger("click");
    expect(button("picker-retry").disabled).toBe(false);
    answer(failure);
    await flushPromises();

    expect(wrapper.find("[data-testid=picker-error]").exists()).toBe(true);
    expect(document.activeElement).toBe(button("picker-retry"));
  });
});
