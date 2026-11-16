import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useCustomerDetail } from "@/customers/useCustomerDetail";

type Customer = components["schemas"]["BackofficeCustomerResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const CUSTOMER = "/api/v1/admin/customers/{customerId}";

function customer(id = "c-1"): Customer {
  return {
    customerId: id,
    contact: { email: "anna.nowak@example.com", fullName: "Anna Nowak", phone: "+48600100200" },
    summary: { orderCount: 3, paidCount: 2, paidGross: 24600, currency: "PLN", firstOrderAt: "2026-10-01T10:00:00+00:00", lastOrderAt: "2026-11-05T12:30:00+00:00" },
    addresses: [{ street: "Długa 1", postalCode: "00-950", city: "Warszawa", country: "PL" }],
  };
}

function mountDetail(id = ref("c-1")) {
  let detail!: ReturnType<typeof useCustomerDetail>;
  const wrapper = mount(
    defineComponent({
      setup() {
        detail = useCustomerDetail(() => id.value);
        return () => null;
      },
    }),
  );
  return { wrapper, detail, id };
}

describe("useCustomerDetail", () => {
  let wrapper: ReturnType<typeof mountDetail>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("loads the customer by its id", async () => {
    mocks.get.mockResolvedValue({ data: customer(), response: { status: 200 } });
    const mounted = mountDetail();
    wrapper = mounted.wrapper;
    expect(mounted.detail.loading.value).toBe(true);
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(CUSTOMER, { params: { path: { customerId: "c-1" } } });
    expect(mounted.detail.customer.value?.contact.fullName).toBe("Anna Nowak");
    expect(mounted.detail.loading.value).toBe(false);
    expect(mounted.detail.error.value).toBeNull();
  });

  it("says the customer was not found on a 404, without an error to retry", async () => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 404 } });
    const mounted = mountDetail();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mounted.detail.notFound.value).toBe(true);
    expect(mounted.detail.notFoundMessage).toBe("Nie znaleźliśmy klienta.");
    expect(mounted.detail.customer.value).toBeNull();
    expect(mounted.detail.error.value).toBeNull();
  });

  it.each([
    [403, "Nie masz dostępu do klientów.", true],
    [500, "Nie udało się pobrać klienta (HTTP 500).", false],
  ])("reports a %s in Polish", async (status, message, forbidden) => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status } });
    const mounted = mountDetail();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mounted.detail.error.value).toBe(message);
    expect(mounted.detail.forbidden.value).toBe(forbidden);
    expect(mounted.detail.notFound.value).toBe(false);
  });

  it("reports a failed request and recovers on retry", async () => {
    mocks.get.mockRejectedValueOnce(new Error("network"));
    const mounted = mountDetail();
    wrapper = mounted.wrapper;
    await flushPromises();
    expect(mounted.detail.error.value).toBe("Nie udało się pobrać klienta. Sprawdź połączenie z API.");

    mocks.get.mockResolvedValue({ data: customer(), response: { status: 200 } });
    await mounted.detail.retry();

    expect(mounted.detail.error.value).toBeNull();
    expect(mounted.detail.customer.value?.customerId).toBe("c-1");
  });

  it("drops the customer and loads the next one when the id changes, ignoring a late answer of the old one", async () => {
    let release!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    const mounted = mountDetail();
    wrapper = mounted.wrapper;

    mocks.get.mockResolvedValue({ data: customer("c-2"), response: { status: 200 } });
    mounted.id.value = "c-2";
    await flushPromises();
    release({ data: customer("c-1"), response: { status: 200 } });
    await flushPromises();

    expect(mounted.detail.customer.value?.customerId).toBe("c-2");
  });
});
