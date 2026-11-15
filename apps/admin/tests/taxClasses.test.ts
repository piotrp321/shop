import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchTaxClasses, taxClassName } from "@/products/taxClasses";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

describe("fetchTaxClasses", () => {
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it("returns the classes of the API", async () => {
    mocks.get.mockResolvedValue({ data: [{ code: "standard", name: "Standardowa" }], response: { status: 200 } });

    expect(await fetchTaxClasses()).toEqual({ taxClasses: [{ code: "standard", name: "Standardowa" }] });
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/tax-classes");
  });

  it("returns the message of a rejected request, with its status", async () => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 503 } });

    expect(await fetchTaxClasses()).toEqual({ error: "Nie udało się pobrać klas podatkowych (HTTP 503)." });
  });

  it("returns a message about the connection when the request itself fails", async () => {
    mocks.get.mockRejectedValue(new TypeError("network down"));

    expect(await fetchTaxClasses()).toEqual({ error: "Nie udało się pobrać klas podatkowych. Sprawdź połączenie z API." });
  });
});

describe("taxClassName", () => {
  const classes = [{ code: "printed_book", name: "Książki drukowane" }];

  it("gives the Polish name of a known code", () => {
    expect(taxClassName(classes, "printed_book")).toBe("Książki drukowane");
  });

  it("gives the code itself when the list does not have it, or when the list is empty (a failed request)", () => {
    expect(taxClassName(classes, "energy_gel")).toBe("energy_gel");
    expect(taxClassName([], "printed_book")).toBe("printed_book");
  });
});
