import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { components } from "@/api/schema";
import OrderTimeline from "@/orders/OrderTimeline.vue";

type StatusChange = components["schemas"]["BackofficeOrderStatusChange"];

function change(overrides: Partial<StatusChange>): StatusChange {
  return { from: "paid", to: "shipped", occurredAt: "2026-11-05T12:40:00+00:00", actorType: "staff", staffId: "s1", staffUsername: "anna", note: null, ...overrides };
}

describe("OrderTimeline", () => {
  it("lists the entries in the order of the API, in an ordered list", () => {
    const wrapper = mount(OrderTimeline, {
      props: {
        history: [
          change({ from: null, to: "new", actorType: "customer", staffUsername: null }),
          change({ from: "new", to: "paid", actorType: "system", staffUsername: null }),
          change({ from: "paid", to: "shipped" }),
        ],
      },
    });

    expect(wrapper.find("ol").exists()).toBe(true);
    const rows = wrapper.findAll("li[data-testid=history-row]");
    expect(rows.map((row) => row.get("[data-testid=history-change]").text())).toEqual(["Czeka na płatność", "Czeka na płatność → Opłacone", "Opłacone → Wysłane"]);
  });

  it("shows the time in a <time> element with the machine-readable moment, in Polish time", () => {
    const wrapper = mount(OrderTimeline, { props: { history: [change({})] } });

    const time = wrapper.get("[data-testid=history-at]");
    expect(time.element.tagName).toBe("TIME");
    expect(time.attributes("datetime")).toBe("2026-11-05T12:40:00+00:00");
    expect(time.text()).toBe("05.11.2026, 13:40:00");
  });

  it("shows both statuses of a change as badges in the colours of the status", () => {
    const wrapper = mount(OrderTimeline, { props: { history: [change({ from: "paid", to: "cancelled" })] } });

    const badges = wrapper.get("[data-testid=history-change]").findAll("[data-slot=badge]");
    expect(badges.map((badge) => badge.text())).toEqual(["Opłacone", "Anulowane"]);
    expect(badges[0]!.classes().join(" ")).toContain("text-status-info");
    expect(badges[1]!.classes().join(" ")).toContain("text-status-danger");
  });

  it.each([
    [{ actorType: "staff", staffUsername: "anna" }, "anna"],
    [{ actorType: "staff", staffUsername: null }, "Pracownik"],
    [{ actorType: "customer", staffUsername: null }, "Klient"],
    [{ actorType: "system", staffUsername: null }, "System"],
  ] as const)("names the actor %j as %s", (actor, label) => {
    const wrapper = mount(OrderTimeline, { props: { history: [change(actor)] } });

    expect(wrapper.get("[data-testid=history-actor]").text()).toBe(label);
  });

  it("shows the note, and nothing for an entry without one", () => {
    const wrapper = mount(OrderTimeline, { props: { history: [change({ note: "Klient zrezygnował" }), change({ note: null })] } });

    const rows = wrapper.findAll("[data-testid=history-row]");
    expect(rows[0]!.get("[data-testid=history-note]").text()).toBe("Klient zrezygnował");
    expect(rows[1]!.find("[data-testid=history-note]").exists()).toBe(false);
  });

  it("keeps a long note and a word without a space inside the card", () => {
    const wrapper = mount(OrderTimeline, { props: { history: [change({ note: "x".repeat(500) })] } });

    expect(wrapper.get("[data-testid=history-note]").classes()).toContain("break-words");
  });

  it("renders no entries for an empty history", () => {
    const wrapper = mount(OrderTimeline, { props: { history: [] } });

    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(0);
  });
});
