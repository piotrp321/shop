import { flushPromises, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { StaffMe } from "@/stores/auth";
import DashboardView from "@/views/DashboardView.vue";
import { callsTo, dashboard, describeFreshness, mocks, mountScreen, ORDERS, order, serve, system } from "./dashboardSupport";

vi.mock("@/api/client", async () => {
  const { mocks: shared } = await import("./dashboardMocks");
  return { getApiClient: () => ({ GET: shared.get }) };
});

type Role = StaffMe["roles"][number];

const mountDashboard = (roles: StaffMe["roles"] | null = ["admin"]) => mountScreen(DashboardView, roles);

/** The links of the card „Do obsługi” as [label, href]. */
function todo(wrapper: VueWrapper) {
  return wrapper.findAll("[data-testid=todo-item]").map((item) => [item.get("[data-testid=todo-label]").text(), item.attributes("href")]);
}

const PAID = ["Opłacone do wysłania", "/orders?status=paid"];
const NEW = ["Czekają na płatność", "/orders?status=new"];
const REFUNDS = ["Zwroty do wykonania", "/orders?refund=pending"];
const INVENTORY = ["Stany magazynowe", "/inventory"];
const PROMOTIONS = ["Aktywne promocje", "/promotions?status=active"];
const CODES = ["Kody rabatowe", "/discount-codes?status=active"];

describe("DashboardView", () => {
  let wrapper: VueWrapper | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  describe("message of a denied page", () => {
    afterEach(() => window.history.replaceState(null, ""));

    it("shows „Nie masz dostępu do tej strony.” as an alert when the route guard sent the staff member here", async () => {
      window.history.replaceState({ denied: true }, "");
      serve(dashboard());
      wrapper = await mountDashboard();

      const alert = wrapper.get("[data-testid=access-denied]");
      expect(alert.attributes("role")).toBe("alert");
      expect(alert.text()).toBe("Nie masz dostępu do tej strony.");
    });

    it("does not show it again after a reload, which keeps history.state", async () => {
      window.history.replaceState({ denied: true }, "");
      serve(dashboard());
      wrapper = await mountDashboard();
      wrapper.unmount();

      wrapper = await mountDashboard();

      expect(wrapper.find("[data-testid=access-denied]").exists()).toBe(false);
    });

    it("does not show it on a dashboard opened directly", async () => {
      serve(dashboard());
      wrapper = await mountDashboard();

      expect(wrapper.find("[data-testid=access-denied]").exists()).toBe(false);
      expect(wrapper.text()).not.toContain("Nie masz dostępu do tej strony.");
    });
  });

  it("has „Pulpit” as the only H1 and no crumbs", async () => {
    serve(dashboard());
    wrapper = await mountDashboard();

    expect(wrapper.findAll("h1").map((heading) => heading.text())).toEqual(["Pulpit"]);
    expect(wrapper.find("nav[aria-label=Okruszki]").exists()).toBe(false);
  });

  describe("tiles of the catalog", () => {
    it("shows the numbers from the API", async () => {
      serve(dashboard());
      wrapper = await mountDashboard();

      expect(callsTo("/api/v1/admin/dashboard")).toHaveLength(1);
      expect(wrapper.get("[data-testid=product-count]").text()).toBe("412");
      expect(wrapper.get("[data-testid=category-count]").text()).toBe("36");
      expect(wrapper.get("[data-testid=products-without-images]").text()).toBe("0");
    });

    it("formats a number of the API for Polish readers", async () => {
      serve(dashboard({ catalog: { productCount: 12345, categoryCount: 36, productsWithoutImages: 0 } }));
      wrapper = await mountDashboard();

      expect(wrapper.get("[data-testid=product-count]").text().replace(/\s/g, " ")).toBe("12 345");
    });

    it("links the tile „Produkty” to the products, and the tiles „Kategorie” and „Bez zdjęć” to nothing", async () => {
      serve(dashboard());
      wrapper = await mountDashboard();

      const tiles = wrapper.findAll("[data-testid=kpi-tile]");
      expect(tiles.map((tile) => tile.get("[data-testid=kpi-title]").text())).toEqual(["Produkty", "Kategorie", "Bez zdjęć"]);
      expect(tiles[0]?.findAll("a").map((link) => link.attributes("href"))).toEqual(["/products"]);
      expect(tiles[1]?.find("a").exists()).toBe(false);
      expect(tiles[2]?.find("a").exists()).toBe(false);
    });

    it("shows the products without images in the footer of „Produkty”", async () => {
      serve(dashboard({ catalog: { productCount: 412, categoryCount: 36, productsWithoutImages: 8 } }));
      wrapper = await mountDashboard();

      const footer = wrapper.findAll("[data-testid=kpi-tile]")[0]?.get("[data-testid=kpi-footer]");
      expect(footer?.text()).toContain("Bez zdjęć");
      expect(footer?.text()).toContain("8");
    });

    it("asks to add the images when some products have none", async () => {
      serve(dashboard({ catalog: { productCount: 412, categoryCount: 36, productsWithoutImages: 8 } }));
      wrapper = await mountDashboard();

      expect(wrapper.get("[data-testid=products-without-images]").text()).toBe("8");
      expect(wrapper.get("[data-testid=images-warning]").text()).toBe("Uzupełnij zdjęcia w formularzu produktu");
    });

    it("shows no such request when every product has an image", async () => {
      serve(dashboard());
      wrapper = await mountDashboard();

      expect(wrapper.find("[data-testid=images-warning]").exists()).toBe(false);
    });

    it("does not show the system status, which has its own screen, even to an administrator", async () => {
      serve(dashboard({ system: system() }));
      wrapper = await mountDashboard(["admin"]);

      expect(wrapper.find("[data-testid=service]").exists()).toBe(false);
      expect(wrapper.text()).not.toContain("Stan systemu");
    });
  });

  describe("„Do obsługi” by role", () => {
    it.each([
      ["admin", [PAID, NEW, REFUNDS, INVENTORY, PROMOTIONS, CODES]],
      ["support", [PAID, NEW, REFUNDS]],
      ["warehouse", [PAID, INVENTORY]],
      ["marketing", [PROMOTIONS, CODES]],
    ] as [Role, string[][]][])("shows %s the rows of their role, in order", async (role, rows) => {
      serve(dashboard({ system: null }));
      wrapper = await mountDashboard([role]);

      expect(todo(wrapper)).toEqual(rows);
      expect(rows.length, "every role sees at least one row").toBeGreaterThan(0);
    });

    it("shows a member with several roles the rows of all of them once, in order", async () => {
      serve(dashboard({ system: null }));
      wrapper = await mountDashboard(["warehouse", "marketing"]);

      expect(todo(wrapper)).toEqual([PAID, INVENTORY, PROMOTIONS, CODES]);
    });

    it("has a label, a description and an arrow in every row, and no counts", async () => {
      serve(dashboard({ system: null }));
      wrapper = await mountDashboard(["admin"]);

      for (const item of wrapper.findAll("[data-testid=todo-item]")) {
        expect(item.find("[data-testid=todo-description]").text().length).toBeGreaterThan(0);
        expect(item.findAll("svg[aria-hidden=true]").length).toBeGreaterThanOrEqual(2);
      }
      expect(wrapper.get("[data-testid=todo]").text()).not.toMatch(/\d/);
    });

    it("has a heading „Do obsługi” for the card", async () => {
      serve(dashboard());
      wrapper = await mountDashboard(["support"]);

      expect(wrapper.findAll("h2").map((heading) => heading.text())).toContain("Do obsługi");
    });

    it("shows no rows until the staff member is known", async () => {
      serve(dashboard());
      wrapper = await mountDashboard(null);

      expect(todo(wrapper)).toEqual([]);
    });
  });

  describe("„Ostatnie zamówienia”", () => {
    const five = ["R-5", "R-4", "R-3", "R-2", "R-1"].map((number, index) => order(number, { itemCount: index + 1 }));

    it.each(["admin", "support", "warehouse"] as Role[])("asks for the five newest orders for %s", async (role) => {
      serve(dashboard(), { items: five, nextCursor: null });
      wrapper = await mountDashboard([role]);

      expect(callsTo(ORDERS)).toEqual([[ORDERS, { params: { query: { perPage: 5 } } }]]);
      expect(wrapper.findAll("[data-testid=recent-order]")).toHaveLength(5);
    });

    it("does not ask for the orders of marketing, and has no card", async () => {
      serve(dashboard());
      wrapper = await mountDashboard(["marketing"]);

      expect(callsTo(ORDERS)).toHaveLength(0);
      expect(wrapper.find("[data-testid=recent-orders]").exists()).toBe(false);
      expect(wrapper.text()).not.toContain("Ostatnie zamówienia");
    });

    it("asks for the orders once the staff member turns out to have access to them", async () => {
      serve(dashboard(), { items: five, nextCursor: null });
      wrapper = await mountDashboard(null);
      expect(callsTo(ORDERS)).toHaveLength(0);

      const { useAuthStore } = await import("@/stores/auth");
      useAuthStore().staff = { id: "u1", username: "anna", roles: ["support"] };
      await flushPromises();

      expect(callsTo(ORDERS)).toHaveLength(1);
      expect(wrapper.findAll("[data-testid=recent-order]")).toHaveLength(5);
    });

    it("shows the number as a link, the customer, the date, the units, the amount and the status of each order", async () => {
      serve(dashboard(), { items: [order("R-100001", { itemCount: 3, status: "shipped" })], nextCursor: "next" });
      wrapper = await mountDashboard(["admin"]);

      const row = wrapper.get("[data-testid=recent-order]");
      const number = row.get("a");
      expect(number.text()).toBe("R-100001");
      expect(number.attributes("href")).toBe("/orders/id-R-100001");
      expect(row.get("[data-testid=order-customer]").text()).toBe("Anna Nowak");
      expect(row.get("[data-testid=order-date]").text()).toMatch(/^05\.11\.2026.*13:30:00$/);
      expect(row.get("[data-testid=order-items]").text()).toBe("3");
      expect(row.get("[data-testid=order-total]").text().replace(/\s/g, " ")).toBe("399,98 zł");
      expect(row.get("[data-testid=order-status]").text()).toBe("Wysłane");
    });

    it.each([
      ["new", "text-status-warning"],
      ["paid", "text-status-info"],
      ["shipped", "text-status-success"],
      ["delivered", "text-status-info"],
      ["cancelled", "text-status-danger"],
      ["returned", "text-status-neutral"],
    ] as const)("colours the status %s with %s", async (status, colour) => {
      serve(dashboard(), { items: [order("R-1", { status })], nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      expect(wrapper.get("[data-testid=order-status]").classes()).toContain(colour);
    });

    it("heads the columns Numer, Klient, Data, Pozycje, Kwota and Status", async () => {
      serve(dashboard(), { items: five, nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      expect(wrapper.get("[data-testid=recent-orders]").findAll("th").map((cell) => cell.text())).toEqual(["Numer", "Klient", "Data", "Pozycje", "Kwota", "Status"]);
    });

    it("links „Wszystkie zamówienia →” to the list of the orders", async () => {
      serve(dashboard(), { items: five, nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      const link = wrapper.get("[data-testid=all-orders]");
      expect(link.text()).toBe("Wszystkie zamówienia →");
      expect(link.attributes("href")).toBe("/orders");
    });

    it("scrolls a table wider than the card in a named, focusable region", async () => {
      serve(dashboard(), { items: five, nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      const region = wrapper.get("[data-testid=recent-orders] [role=region]");
      expect(region.attributes("aria-label")).toBe("Ostatnie zamówienia");
      expect(region.attributes("tabindex")).toBe("0");
      expect(region.classes()).toContain("overflow-x-auto");
    });

    it("says there are no orders yet", async () => {
      serve(dashboard(), { items: [], nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      expect(wrapper.get("[data-testid=recent-orders]").text()).toContain("Nie ma jeszcze zamówień.");
      expect(wrapper.find("[data-testid=recent-order]").exists()).toBe(false);
    });

    it("shows a skeleton in the card while the orders load", async () => {
      mocks.get.mockImplementation(async (path: string) => (path === ORDERS ? new Promise(() => {}) : { data: dashboard(), response: { status: 200 } }));
      wrapper = await mountDashboard(["admin"]);

      expect(wrapper.get("[data-testid=recent-orders]").find("[data-testid=orders-loading]").exists()).toBe(true);
    });

    describe("when the orders cannot be read", () => {
      function failOrders(response: unknown) {
        mocks.get.mockImplementation(async (path: string, ...rest: unknown[]) => {
          if (path === ORDERS) {
            if (response instanceof Error) {
              throw response;
            }
            return response;
          }
          return { data: dashboard(), response: { status: 200 }, rest };
        });
      }

      it("shows the error in the card, with „Spróbuj ponownie”, and leaves the rest of the dashboard alone", async () => {
        failOrders({ error: { status: 500 }, response: { status: 500 } });
        wrapper = await mountDashboard(["admin"]);

        const card = wrapper.get("[data-testid=recent-orders]");
        const message = card.get("[data-testid=orders-error]");
        expect(message.attributes("role")).toBe("alert");
        expect(message.text()).toContain("Nie udało się pobrać zamówień (HTTP 500).");
        expect(card.get("[data-testid=orders-retry]").text()).toBe("Spróbuj ponownie");
        expect(wrapper.find("[data-testid=dashboard-error]").exists()).toBe(false);
        expect(wrapper.get("[data-testid=product-count]").text()).toBe("412");
        expect(todo(wrapper)).toHaveLength(6);
      });

      it("explains a request that did not reach the API", async () => {
        failOrders(new TypeError("Failed to fetch"));
        wrapper = await mountDashboard(["admin"]);

        const text = wrapper.get("[data-testid=orders-error]").text();
        expect(text).toContain("Nie udało się pobrać zamówień. Sprawdź połączenie z API.");
        expect(text).not.toContain("Failed to fetch");
      });

      it("gives no retry for a 403, which a retry would not change", async () => {
        failOrders({ error: { status: 403 }, response: { status: 403 } });
        wrapper = await mountDashboard(["admin"]);

        expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Nie masz dostępu do zamówień.");
        expect(wrapper.find("[data-testid=orders-retry]").exists()).toBe(false);
      });

      it("loads the orders again on „Spróbuj ponownie” and clears the error", async () => {
        failOrders({ error: { status: 500 }, response: { status: 500 } });
        wrapper = await mountDashboard(["admin"]);
        serve(dashboard(), { items: [order("R-1")], nextCursor: null });

        await wrapper.get("[data-testid=orders-retry]").trigger("click");
        await flushPromises();

        expect(wrapper.find("[data-testid=orders-error]").exists()).toBe(false);
        expect(wrapper.findAll("[data-testid=recent-order]")).toHaveLength(1);
      });

      it("keeps the orders on screen when a refresh of them fails", async () => {
        serve(dashboard(), { items: [order("R-1")], nextCursor: null });
        wrapper = await mountDashboard(["admin"]);

        failOrders({ error: { status: 500 }, response: { status: 500 } });
        await wrapper.get("[data-testid=refresh]").trigger("click");
        await flushPromises();

        expect(wrapper.find("[data-testid=orders-error]").exists()).toBe(true);
        expect(wrapper.findAll("[data-testid=recent-order]")).toHaveLength(1);
      });

      it("names the time of the orders shown when their refresh fails, as the dashboard does for its data", async () => {
        vi.useFakeTimers({ toFake: ["Date"] });
        try {
          vi.setSystemTime(new Date(Date.UTC(2026, 10, 1, 13, 3, 12)));
          serve(dashboard(), { items: [order("R-1")], nextCursor: null });
          wrapper = await mountDashboard(["admin"]);

          vi.setSystemTime(new Date(Date.UTC(2026, 10, 1, 13, 3, 42)));
          failOrders({ error: { status: 500 }, response: { status: 500 } });
          await wrapper.get("[data-testid=refresh]").trigger("click");
          await flushPromises();

          expect(wrapper.get("[data-testid=orders-error]").text()).toMatch(
            /^Nie udało się pobrać zamówień \(HTTP 500\)\. Pokazane dane z 01\.11\.2026.*14:03:12 mogą być nieaktualne\./,
          );
        } finally {
          vi.useRealTimers();
        }
      });

      it("names the time of the orders also when the request fails to leave", async () => {
        vi.useFakeTimers({ toFake: ["Date"] });
        try {
          vi.setSystemTime(new Date(Date.UTC(2026, 10, 1, 13, 3, 12)));
          serve(dashboard(), { items: [order("R-1")], nextCursor: null });
          wrapper = await mountDashboard(["admin"]);

          failOrders(new TypeError("Failed to fetch"));
          await wrapper.get("[data-testid=refresh]").trigger("click");
          await flushPromises();

          expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Sprawdź połączenie z API. Pokazane dane z 01.11.2026");
        } finally {
          vi.useRealTimers();
        }
      });

      it("adds no time to the error of the first load, which has no orders to name", async () => {
        failOrders({ error: { status: 500 }, response: { status: 500 } });
        wrapper = await mountDashboard(["admin"]);

        expect(wrapper.get("[data-testid=orders-error]").text()).not.toContain("Pokazane dane");
      });

      it("moves the focus to the message when „Spróbuj ponownie” disappears with the message", async () => {
        failOrders({ error: { status: 500 }, response: { status: 500 } });
        wrapper = await mountDashboard(["admin"]);
        serve(dashboard(), { items: [order("R-1")], nextCursor: null });

        const retry = wrapper.get("[data-testid=orders-retry]");
        (retry.element as HTMLElement).focus();
        await retry.trigger("click");
        await flushPromises();

        expect(document.activeElement, "the focus does not fall to the body").not.toBe(document.body);
        expect(wrapper.get("[data-testid=recent-orders]").element.contains(document.activeElement)).toBe(true);
      });
    });

    it("loads the orders again on „Odśwież” and on the 30 s interval", async () => {
      vi.useFakeTimers();
      try {
        serve(dashboard(), { items: five, nextCursor: null });
        wrapper = await mountDashboard(["admin"]);
        expect(callsTo(ORDERS)).toHaveLength(1);

        await wrapper.get("[data-testid=refresh]").trigger("click");
        await flushPromises();
        expect(callsTo(ORDERS)).toHaveLength(2);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(callsTo(ORDERS)).toHaveLength(3);
      } finally {
        vi.useRealTimers();
      }
    });

    it("shows a refreshed order list", async () => {
      serve(dashboard(), { items: [order("R-1")], nextCursor: null });
      wrapper = await mountDashboard(["admin"]);

      serve(dashboard(), { items: [order("R-2")], nextCursor: null });
      await wrapper.get("[data-testid=refresh]").trigger("click");
      await flushPromises();

      expect(wrapper.findAll("[data-testid=recent-order] a").map((link) => link.text())).toEqual(["R-2"]);
    });
  });
});

describeFreshness("DashboardView", {
  mount: () => mountDashboard(["admin"]),
  withNumber: (value) => dashboard({ catalog: { productCount: value, categoryCount: 1, productsWithoutImages: 0 } }),
  readNumber: (view) => view.get("[data-testid=product-count]").text(),
  shown: "Produkty",
});
