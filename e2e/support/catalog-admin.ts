import { expect, type Page } from "@playwright/test";
import { openMenuItem } from "./panel-menu";

/** A product the tests create in the panel; the name and slug are unique per run, so a rerun never meets an earlier product. */
export type NewProduct = {
  name: string;
  slug: string;
  description: string;
  /** A leaf of the category tree of the demo data: the name as the tree shows it. */
  category: string;
  axis: string;
  variantValue: string;
};

const runSuffix = (): string => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

/**
 * The data of one new product. The name starts with "Zz" so it sorts after the demo products wherever the store or the
 * panel sort by name; no test relies on the number of products around it.
 */
export function newProduct(label: string): NewProduct {
  const suffix = runSuffix();
  return {
    name: `Zz E2E ${label} ${suffix}`,
    slug: `zz-e2e-${label.toLowerCase().replace(/[^a-z0-9]+/g, "-")}-${suffix}`,
    description: "Produkt utworzony przez test E2E panelu.",
    category: "Trening",
    axis: "Kolor",
    variantValue: "czarny",
  };
}

function crc32(bytes: Uint8Array): number {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function adler32(bytes: Uint8Array): number {
  let a = 1;
  let b = 0;
  for (const byte of bytes) {
    a = (a + byte) % 65521;
    b = (b + a) % 65521;
  }
  return ((b << 16) | a) >>> 0;
}

const be32 = (value: number): number[] => [(value >>> 24) & 255, (value >>> 16) & 255, (value >>> 8) & 255, value & 255];

function chunk(type: string, data: Uint8Array): Uint8Array {
  const typed = new Uint8Array(4 + data.length);
  typed.set([...type].map((letter) => letter.charCodeAt(0)));
  typed.set(data, 4);
  return new Uint8Array([...be32(data.length), ...typed, ...be32(crc32(typed))]);
}

/**
 * A valid PNG of a single colour, 96 x 96 px, built by hand (the e2e package has no Node typings and no image library).
 * The scanlines are stored uncompressed in one deflate block, which keeps the file below 28 kB.
 */
export function solidPng(red: number, green: number, blue: number): Uint8Array {
  const size = 96;
  const raw = new Uint8Array(size * (1 + size * 3));
  for (let row = 0; row < size; row++) {
    const start = row * (1 + size * 3);
    for (let column = 0; column < size; column++) {
      raw.set([red, green, blue], start + 1 + column * 3);
    }
  }
  const deflate = new Uint8Array([0x78, 0x01, 0x01, raw.length & 255, raw.length >> 8, ~raw.length & 255, (~raw.length >> 8) & 255, ...raw, ...be32(adler32(raw))]);
  const header = new Uint8Array([...be32(size), ...be32(size), 8, 2, 0, 0, 0]);
  return new Uint8Array([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
    ...chunk("IHDR", header),
    ...chunk("IDAT", deflate),
    ...chunk("IEND", new Uint8Array()),
  ]);
}

/** Opens the combobox of the category field, narrows it to the category by typing and picks it (the option is named by its path). */
async function chooseCategory(page: Page, category: string): Promise<void> {
  const combobox = page.getByRole("combobox", { name: "Kategoria główna" });
  await combobox.click();
  await combobox.fill(category);
  await page.getByRole("option", { name: new RegExp(`(^| › )${category}$`) }).click();
}

/**
 * Fills the form of a new product (data, one photo, one axis with one variant) and saves it as a draft. Resolves when the
 * panel shows the saved draft, whose address carries the product id (returned).
 */
export async function createDraftInPanel(page: Page, product: NewProduct): Promise<string> {
  await openMenuItem(page, "Lista produktów", "Katalog", "Produkty");
  await page.getByTestId("add-product").click();
  await expect(page.getByRole("heading", { level: 1, name: "Nowy produkt" })).toBeVisible();

  await page.getByLabel("Nazwa", { exact: true }).fill(product.name);
  await page.getByLabel("Slug", { exact: true }).fill(product.slug);
  await page.getByLabel("Krótki opis").fill(product.description);
  await chooseCategory(page, product.category);
  await page.getByRole("combobox", { name: "Klasa podatkowa" }).click();
  await page.getByRole("option").first().click();

  await page.getByTestId("image-input").setInputFiles({ name: "e2e-photo.png", mimeType: "image/png", buffer: Buffer.from(solidPng(30, 120, 200)) });
  await expect(page.getByTestId("image-row"), "the photo was not uploaded").toHaveCount(1);

  await page.getByTestId("add-axis").click();
  await page.getByTestId("axis-name").fill(product.axis);
  await page.getByTestId("variant-value").fill(product.variantValue);

  await page.getByTestId("save").click();
  await expect(page.getByTestId("product-status")).toHaveText("Szkic");
  await expect(page).toHaveURL(/\/products\/[0-9a-f-]{36}$/);
  await expect(page.getByTestId("form-error"), "saving the draft was rejected").toHaveCount(0);
  return new URL(page.url()).pathname.split("/").pop() ?? "";
}

/** Publishes the draft open in the form; resolves when the panel shows it as published. */
export async function publishInPanel(page: Page): Promise<void> {
  await page.getByTestId("publish").click();
  await expect(page.getByTestId("product-status")).toHaveText("Opublikowany");
  await expect(page.getByTestId("form-error"), "the publication was rejected").toHaveCount(0);
}

/** Withdraws the published product open in the form; resolves when the panel shows it as a draft. */
export async function unpublishInPanel(page: Page): Promise<void> {
  await page.getByTestId("unpublish").click();
  await expect(page.getByTestId("product-status")).toHaveText("Szkic");
  await expect(page.getByTestId("form-error"), "the withdrawal was rejected").toHaveCount(0);
}

/**
 * Receives stock for one variant of a product in the stock screen of the panel (the staff member is logged in already).
 * Resolves when the panel shows the received quantity as the physical stock.
 */
export async function receiveStockInPanel(page: Page, productName: string, variantLabel: string, quantity: number): Promise<void> {
  await openMenuItem(page, "Stany magazynowe", "Katalog");
  await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill(productName);
  // A name shows on the first row of each product: the product as the only name means the (debounced) search is applied.
  await expect(page.getByTestId("name"), "the search narrows the list to the product").toHaveText([productName]);
  const row = page.getByTestId("stock-row").filter({ has: page.getByTestId("variant-label").getByText(variantLabel, { exact: true }) });
  await expect(row).toHaveCount(1);
  await row.getByTestId("receive-stock").click();
  const dialog = page.getByTestId("receive-dialog");
  await dialog.getByLabel("Przyjęta ilość (szt.)").fill(String(quantity));
  await dialog.getByTestId("receive-submit").click();
  await expect(dialog, "the receipt was rejected (validation, permission or API error)").toBeHidden();
  await expect(row.getByTestId("physical")).toHaveText(String(quantity));
}
