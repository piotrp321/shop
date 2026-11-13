import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { h } from "vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";

interface Row {
  id: string;
  name: string;
  category: string;
  changed: string;
}

const rows: Row[] = [
  { id: "1", name: "Buty", category: "Namioty", changed: "12.11.2026, 10:34:05" },
  { id: "2", name: "Plecak", category: "Plecaki", changed: "13.11.2026, 09:00:00" },
];

const columns: ListColumn<Row>[] = [
  { key: "name", header: "Produkt", main: true },
  { key: "category", header: "Kategoria", hideBelow: "lg", text: (row) => row.category },
  { key: "changed", header: "Ostatnia zmiana", hideBelow: "md", text: (row) => row.changed },
  { key: "actions", header: "Akcje", align: "right" },
];

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

function mountTable(props: Record<string, unknown> = {}) {
  wrapper = mount(DataTable<Row>, {
    props: {
      label: "Lista produktów",
      columns,
      rows,
      rowKey: (row: Row) => row.id,
      rowTestid: "product-row",
      loading: false,
      loaded: true,
      error: null,
      forbidden: false,
      retry: async () => {},
      ...props,
    },
    slots: {
      lead: ({ row }: { row: Row }) => h("img", { "data-testid": "lead", alt: row.name }),
      "cell-name": ({ row }: { row: Row }) => h("strong", { "data-testid": "name" }, row.name),
      "cell-category": ({ row }: { row: Row }) => h("span", row.category),
      "cell-changed": ({ row }: { row: Row }) => h("span", row.changed),
      "cell-actions": ({ row }: { row: Row }) => h("button", { type: "button" }, `Edytuj ${row.name}`),
      empty: () => h("p", { "data-testid": "empty-slot" }, "Pusto"),
    },
    attachTo: document.body,
  });
  return wrapper;
}

describe("DataTable", () => {
  it("shows a header cell per column and a row per item, with the cells of the slots", () => {
    const table = mountTable();

    expect(table.findAll("th").map((cell) => cell.text())).toEqual(["Produkt", "Kategoria", "Ostatnia zmiana", "Akcje"]);
    const body = table.findAll("[data-testid=product-row]");
    expect(body).toHaveLength(2);
    expect(body[0]!.get("[data-testid=name]").text()).toBe("Buty");
    expect(body[1]!.findAll("td")).toHaveLength(4);
  });

  it("scrolls in its own region, named by the list and reachable with the keyboard (WCAG 1.4.10)", () => {
    const table = mountTable();

    const region = table.get("[role=region]");
    expect(region.attributes("aria-label")).toBe("Lista produktów");
    expect(region.attributes("tabindex")).toBe("0");
    expect(region.classes()).toContain("overflow-x-auto");
    expect(region.find("table").exists()).toBe(true);
  });

  it("hides the columns of lower priority below their width", () => {
    const table = mountTable();
    const heads = table.findAll("th");

    expect(heads[0]!.classes()).not.toContain("hidden");
    expect(heads[1]!.classes()).toEqual(expect.arrayContaining(["hidden", "lg:table-cell"]));
    expect(heads[2]!.classes()).toEqual(expect.arrayContaining(["hidden", "min-[900px]:table-cell"]));
    const cells = table.get("[data-testid=product-row]").findAll("td");
    expect(cells[1]!.classes()).toEqual(expect.arrayContaining(["hidden", "lg:table-cell"]));
    expect(cells[2]!.classes()).toEqual(expect.arrayContaining(["hidden", "min-[900px]:table-cell"]));
    expect(cells[3]!.classes()).not.toContain("hidden");
  });

  it("repeats the value of a hidden column, with its label, as a line of the main cell", () => {
    const table = mountTable();
    const main = table.get("[data-testid=product-row]").findAll("td")[0]!;

    const category = main.get("[data-testid=line-category]");
    expect(category.text()).toBe("Kategoria: Namioty");
    expect(category.classes()).toContain("lg:hidden");
    const changed = main.get("[data-testid=line-changed]");
    expect(changed.text()).toBe("Ostatnia zmiana: 12.11.2026, 10:34:05");
    expect(changed.classes()).toContain("min-[900px]:hidden");
  });

  it("shows the lead of the main cell before its text, and the lines of the hidden columns under the text, not under the lead", () => {
    const table = mountTable();
    const main = table.get("[data-testid=product-row]").findAll("td")[0]!;

    const lead = main.get("[data-testid=lead]").element;
    const text = main.get("[data-testid=name]").element;
    const line = main.get("[data-testid=line-category]").element;
    expect(lead.compareDocumentPosition(text) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(text.parentElement).toBe(line.parentElement!.parentElement);
    expect(lead.parentElement).not.toBe(text.parentElement);
    expect(table.get("[data-testid=product-row]").findAll("[data-testid=lead]")).toHaveLength(1);
  });

  it("has no line for a column that is never hidden", () => {
    const table = mountTable();
    const main = table.get("[data-testid=product-row]").findAll("td")[0]!;

    expect(main.findAll("[data-testid^=line-]")).toHaveLength(2);
  });

  it("aligns a numeric column to the right", () => {
    const table = mountTable();

    expect(table.findAll("th")[3]!.classes()).toContain("text-right");
    expect(table.get("[data-testid=product-row]").findAll("td")[3]!.classes()).toContain("text-right");
  });

  it("shows a skeleton of rows on the first load, and no table", () => {
    const table = mountTable({ loaded: false, loading: true, rows: [] });

    expect(table.find("[data-testid=list-loading]").exists()).toBe(true);
    expect(table.find("table").exists()).toBe(false);
  });

  it("keeps the table, marked busy, while a further request is loading", () => {
    const table = mountTable({ loading: true });

    expect(table.find("[data-testid=list-loading]").exists()).toBe(false);
    expect(table.get("[role=region]").attributes("aria-busy")).toBe("true");
  });

  it("is not busy when nothing is loading", () => {
    const table = mountTable();

    expect(table.get("[role=region]").attributes("aria-busy")).toBe("false");
  });

  it("shows the empty slot instead of the table for a loaded list without rows", () => {
    const table = mountTable({ rows: [] });

    expect(table.find("[data-testid=empty-slot]").exists()).toBe(true);
    expect(table.find("table").exists()).toBe(false);
  });

  describe("an error", () => {
    it("shows no table and no skeleton, only the alert with a retry, when the list never loaded (#251 F10)", async () => {
      const retry = vi.fn(async () => {});
      const table = mountTable({ loaded: false, rows: [], error: "Nie udało się pobrać produktów (HTTP 500).", retry });

      const alert = table.get("[role=alert]");
      expect(alert.text()).toContain("Nie udało się pobrać produktów (HTTP 500).");
      expect(table.find("table").exists()).toBe(false);
      expect(table.find("[data-testid=list-loading]").exists()).toBe(false);

      await table.get("[data-testid=retry]").trigger("click");
      expect(retry).toHaveBeenCalledTimes(1);
    });

    it("keeps the page that is shown under the alert when only a neighbouring page failed", () => {
      const table = mountTable({ error: "Nie udało się pobrać produktów (HTTP 500)." });

      expect(table.find("[role=alert]").exists()).toBe(true);
      expect(table.findAll("[data-testid=product-row]")).toHaveLength(2);
    });

    it("has no retry for a 403", () => {
      const table = mountTable({ loaded: false, rows: [], error: "Nie masz dostępu.", forbidden: true });

      expect(table.get("[role=alert]").text()).toContain("Nie masz dostępu.");
      expect(table.find("[data-testid=retry]").exists()).toBe(false);
    });

    it("keeps the focus on „Spróbuj ponownie” when the retry fails again", async () => {
      const table = mountTable({ loaded: false, rows: [], error: "Błąd." });
      const retry = table.get<HTMLButtonElement>("[data-testid=retry]").element;
      retry.focus();

      await table.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(retry);
    });

    it("moves the focus to the alert when the retry ends in a 403 and the button goes (#337)", async () => {
      const table = mountTable({ loaded: false, rows: [], error: "Błąd.", retry: async () => {} });
      await table.setProps({
        retry: async () => {
          await table.setProps({ error: "Nie masz dostępu.", forbidden: true });
        },
      });
      table.get<HTMLButtonElement>("[data-testid=retry]").element.focus();

      await table.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(table.find("[data-testid=retry]").exists()).toBe(false);
      expect(document.activeElement).toBe(table.get("[role=alert]").element);
      expect(table.get("[role=alert]").attributes("tabindex")).toBe("-1");
    });

    it("moves the focus to the table when the retry succeeds and the alert goes", async () => {
      const table = mountTable({ loaded: false, rows: [], error: "Błąd." });
      await table.setProps({
        retry: async () => {
          await table.setProps({ error: null, loaded: true, rows });
        },
      });
      table.get<HTMLButtonElement>("[data-testid=retry]").element.focus();

      await table.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(table.get("[role=region]").element);
    });

    it("moves the focus to the empty state when the retry succeeds with no rows", async () => {
      const table = mountTable({ loaded: false, rows: [], error: "Błąd." });
      await table.setProps({
        retry: async () => {
          await table.setProps({ error: null, loaded: true, rows: [] });
        },
      });
      table.get<HTMLButtonElement>("[data-testid=retry]").element.focus();

      await table.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(table.get("[data-testid=empty-region]").element);
    });
  });
});
