import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type TaxClass = components["schemas"]["TaxClassResponse"];

export type TaxClassesResult = { taxClasses: TaxClass[] } | { error: string };

/** The tax classes of the catalog (`GET /api/v1/admin/tax-classes`), or the message of the failure: the form of a product and the price list read them here. */
export async function fetchTaxClasses(): Promise<TaxClassesResult> {
  try {
    const result = await getApiClient().GET("/api/v1/admin/tax-classes");
    if (result.data) {
      return { taxClasses: result.data };
    }
    return { error: `Nie udało się pobrać klas podatkowych (HTTP ${result.response.status}).` };
  } catch {
    return { error: "Nie udało się pobrać klas podatkowych. Sprawdź połączenie z API." };
  }
}

/** The Polish name of a tax class; the code itself when the list does not have it (also when it could not be loaded). */
export function taxClassName(taxClasses: TaxClass[], code: string): string {
  return taxClasses.find((taxClass) => taxClass.code === code)?.name ?? code;
}
