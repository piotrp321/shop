import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useProductForm } from "@/products/useProductForm";

/**
 * The SKU and the EAN of the variants in the product form (docs/features/product-data/spec.md, „Panel”, „Kody przy zmianie
 * osi i wierszy”, „Nieznany wynik zapisu”): what the request carries (the codes as typed, never trimmed), the capitals of
 * the SKU while it is typed, the codes of the rows that stay through every operation of the variant editor, the messages of
 * the API at their row, and the comparison of „Sprawdź stan” after an unknown result of a save, which for the codes goes
 * through the normalization of the API (T1, T2, T3 and the capitals of the SKU) without changing the fields, the request or
 * the changes of the draft.
 */

type ProductEditView = components["schemas"]["ProductEditViewResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const TAX_CLASSES = "/api/v1/admin/tax-classes";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const MATCHES = "Stan na serwerze odpowiada formularzowi.";
const TAKEN = "Kod SKU jest już używany przez inny produkt.";
const NOTE = "Możliwe, że utworzył go Twój wcześniejszy zapis.";

const preview = { alt: "Zdjęcie", size: "thumbnail", aspectRatio: "1:1", variants: { webp: [{ width: 96, url: "https://img.test/a" }] } };

function view(overrides: Partial<ProductEditView> = {}): ProductEditView {
  return {
    id: "p-1",
    version: 3,
    status: "draft",
    firstPublishedAt: null,
    name: "Buty biegowe",
    slug: "buty-biegowe",
    slugLocked: false,
    shortDescription: "Lekkie buty.",
    brand: "",
    description: "",
    parameters: [],
    mainCategoryId: "c-main",
    additionalCategoryIds: [],
    taxClass: "standard",
    images: [{ key: "originals/a.jpg", alt: "Przód", preview }],
    variantAxes: ["Kolor", "Rozmiar"],
    variants: [
      { variantId: "v-1", values: ["czarne", "M"], sku: "BUT-CM", ean: "5901234123457" },
      { variantId: "v-2", values: ["czarne", "L"], sku: "BUT-CL", ean: "59012344" },
      { variantId: "v-3", values: ["białe", "M"], sku: "", ean: "" },
    ],
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

function ok(data: unknown, status = 200) {
  return { data, response: { status } };
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

function serveReads(product: ProductEditView | null = view()) {
  mocks.get.mockImplementation(async (path: string) => {
    if (path === TAX_CLASSES) {
      return ok([{ code: "standard", name: "Standardowa" }]);
    }
    return product ? ok(product) : problem(404);
  });
}

const wrappers: ReturnType<typeof mount>[] = [];

async function start(options: { id?: string | null } = {}) {
  const id = ref<string | null>(options.id === undefined ? "p-1" : options.id);
  let form!: ReturnType<typeof useProductForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useProductForm({ productId: () => id.value, canManage: () => true, onCreated: vi.fn(), onLateSaved: vi.fn() });
        return () => null;
      },
    }),
  );
  wrappers.push(wrapper);
  await flushPromises();
  return form;
}

type Form = Awaited<ReturnType<typeof start>>;

const codes = (form: Form) => form.form.value.variants.map((row) => [row.variantId, row.sku, row.ean]);

describe("the SKU and the EAN of the variants in the product form", () => {
  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    serveReads();
  });

  afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
  });

  describe("the rows of the form", () => {
    it("hold the codes of the product as the API sent them, empty texts for none", async () => {
      const form = await start();

      expect(codes(form)).toEqual([
        ["v-1", "BUT-CM", "5901234123457"],
        ["v-2", "BUT-CL", "59012344"],
        ["v-3", "", ""],
      ]);
      expect(form.dirty.value).toBe(false);
    });

    it("of a new product hold one row without values and without codes", async () => {
      const form = await start({ id: null });

      expect(form.form.value.variants).toEqual([{ variantId: null, values: [], sku: "", ean: "" }]);
    });
  });

  describe("the request of a save", () => {
    it("carries the codes as they are in the draft: with the edges and every character, never trimmed", async () => {
      const form = await start();
      form.setSku(0, " BUT\t1 ");
      form.setEan(1, "\u{00A0}5901234123457\u{2028}");
      mocks.put.mockResolvedValue(problem(422, { violations: [] }));

      await form.save();

      const rows = mocks.put.mock.calls[0]![1].body.variants;
      expect(rows[0]).toEqual({ variantId: "v-1", values: ["czarne", "M"], sku: " BUT\t1 ", ean: "5901234123457" });
      expect(rows[1]).toEqual({ variantId: "v-2", values: ["czarne", "L"], sku: "BUT-CL", ean: "\u{00A0}5901234123457\u{2028}" });
      expect(rows[2]).toEqual({ variantId: "v-3", values: ["białe", "M"], sku: "", ean: "" });
      expect(codes(form)[0]).toEqual(["v-1", " BUT\t1 ", "5901234123457"]);
    });

    it("carries the empty texts of the codes of a new product", async () => {
      const form = await start({ id: null });
      form.form.value.name = "Nowy";
      mocks.post.mockResolvedValue(ok(view({ variantAxes: [], variants: [{ variantId: "v-9", values: [], sku: "", ean: "" }] }), 201));

      await form.save();

      expect(mocks.post.mock.calls[0]![1].body.variants).toEqual([{ variantId: null, values: [], sku: "", ean: "" }]);
    });

    it("shows the codes of the API after a save, with the SKU in capitals", async () => {
      const form = await start();
      form.setSku(2, "nowy-1");
      form.setEan(2, " 59012344 ");
      mocks.put.mockResolvedValue(
        ok(
          view({
            version: 4,
            variants: [
              { variantId: "v-1", values: ["czarne", "M"], sku: "BUT-CM", ean: "5901234123457" },
              { variantId: "v-2", values: ["czarne", "L"], sku: "BUT-CL", ean: "59012344" },
              { variantId: "v-3", values: ["białe", "M"], sku: "NOWY-1", ean: "59012344" },
            ],
          }),
        ),
      );

      await form.save();

      expect(codes(form)[2]).toEqual(["v-3", "NOWY-1", "59012344"]);
      expect(form.dirty.value).toBe(false);
    });

    it("keeps the fields locked while the request runs and the typed codes after it", async () => {
      const form = await start();
      let answer!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (answer = resolve)));

      const saving = form.save();
      form.setSku(0, "inne");
      form.setEan(0, "59012344");

      expect(form.locked.value).toBe(true);
      expect(codes(form)[0]).toEqual(["v-1", "BUT-CM", "5901234123457"]);
      answer(problem(422, { violations: [] }));
      await saving;
      form.setSku(0, "inne");
      expect(codes(form)[0]![1]).toBe("INNE");
    });
  });

  describe("the SKU while it is typed", () => {
    it("turns the letters of the ASCII into capitals and removes no character", async () => {
      const form = await start();

      form.setSku(0, "but-1/cz_2.x");
      expect(form.form.value.variants[0]!.sku).toBe("BUT-1/CZ_2.X");

      form.setSku(0, "żółw ab\t1 ś");
      // Only the letters of the ASCII change: the "w" of "żółw" does, the Polish letters do not.
      expect(form.form.value.variants[0]!.sku).toBe("żółW AB\t1 ś");

      form.setSku(0, " \u{00A0}ab ");
      expect(form.form.value.variants[0]!.sku).toBe(" \u{00A0}AB ");
      expect(form.dirty.value).toBe(true);
    });

    it("leaves the EAN as typed", async () => {
      const form = await start();

      form.setEan(0, "ab 59012344 ");

      expect(form.form.value.variants[0]!.ean).toBe("ab 59012344 ");
    });

    it("ignores a row that is not there", async () => {
      const form = await start();

      form.setSku(7, "x");
      form.setEan(-1, "x");

      expect(form.dirty.value).toBe(false);
    });
  });

  describe("the operations of the variant editor keep the codes of the rows that stay (criterion 8c)", () => {
    const request = async (form: Form) => {
      mocks.put.mockResolvedValue(problem(422, { violations: [] }));
      await form.save();
      return mocks.put.mock.calls.at(-1)![1].body.variants as { variantId: string | null; values: string[]; sku: string; ean: string }[];
    };

    it("when an axis is removed", async () => {
      const form = await start();

      form.removeAxis(1);

      expect(codes(form)).toEqual([
        ["v-1", "BUT-CM", "5901234123457"],
        ["v-2", "BUT-CL", "59012344"],
        ["v-3", "", ""],
      ]);
      expect((await request(form)).map((row) => [row.variantId, row.values, row.sku, row.ean])).toEqual([
        ["v-1", ["czarne"], "BUT-CM", "5901234123457"],
        ["v-2", ["czarne"], "BUT-CL", "59012344"],
        ["v-3", ["białe"], "", ""],
      ]);
    });

    it("when an axis is added", async () => {
      const form = await start({ id: null });
      form.form.value.variants = [{ variantId: null, values: [], sku: "A-1", ean: "59012344" }];
      form.addAxis();

      expect(codes(form)).toEqual([[null, "A-1", "59012344"]]);
      expect(form.form.value.variants[0]!.values).toEqual([""]);
    });

    it("when the axes are moved", async () => {
      const form = await start();

      form.moveAxis(0, 1);

      expect(codes(form)).toEqual([
        ["v-1", "BUT-CM", "5901234123457"],
        ["v-2", "BUT-CL", "59012344"],
        ["v-3", "", ""],
      ]);
      expect(form.form.value.variants[0]!.values).toEqual(["M", "czarne"]);
      expect((await request(form))[0]).toEqual({ variantId: "v-1", values: ["M", "czarne"], sku: "BUT-CM", ean: "5901234123457" });
    });

    it("when an axis is renamed", async () => {
      const form = await start();

      form.setAxis(0, "Barwa");

      expect(codes(form)[1]).toEqual(["v-2", "BUT-CL", "59012344"]);
      expect((await request(form))[1]).toMatchObject({ variantId: "v-2", sku: "BUT-CL", ean: "59012344" });
    });

    it("when a value is changed", async () => {
      const form = await start();

      form.setValue(1, 1, "XL");

      expect(form.form.value.variants[1]).toEqual({ variantId: "v-2", values: ["czarne", "XL"], sku: "BUT-CL", ean: "59012344" });
      expect((await request(form))[1]).toEqual({ variantId: "v-2", values: ["czarne", "XL"], sku: "BUT-CL", ean: "59012344" });
    });

    it("when a row is removed: the others keep theirs and the removed ones go with the row", async () => {
      const form = await start();

      form.removeVariant(0);

      expect(codes(form)).toEqual([
        ["v-2", "BUT-CL", "59012344"],
        ["v-3", "", ""],
      ]);
    });

    it("when a row is added: the new row has no codes and the others keep theirs", async () => {
      const form = await start();

      form.addVariant();

      expect(form.form.value.variants).toHaveLength(4);
      expect(form.form.value.variants[3]).toEqual({ variantId: null, values: ["", ""], sku: "", ean: "" });
      expect(codes(form).slice(0, 3)).toEqual([
        ["v-1", "BUT-CM", "5901234123457"],
        ["v-2", "BUT-CL", "59012344"],
        ["v-3", "", ""],
      ]);
    });

    it("when the last axis is removed: the first row stays with its identifier and its codes, the others go", async () => {
      serveReads(view({ variantAxes: ["Kolor"], variants: [
        { variantId: "v-1", values: ["czarne"], sku: "BUT-C", ean: "5901234123457" },
        { variantId: "v-2", values: ["białe"], sku: "BUT-B", ean: "59012344" },
      ] }));
      const form = await start();

      form.removeAxis(0);

      expect(form.form.value.variants).toEqual([{ variantId: "v-1", values: [], sku: "BUT-C", ean: "5901234123457" }]);
      expect(await request(form)).toEqual([{ variantId: "v-1", values: [], sku: "BUT-C", ean: "5901234123457" }]);
    });

    it("keeps the typed codes of a row that has no identifier yet", async () => {
      const form = await start();
      form.addVariant();
      form.setSku(3, "nowy");
      form.setEan(3, "59012344");

      form.setAxis(0, "Barwa");
      form.moveAxis(1, -1);
      form.setValue(3, 0, "M");

      expect(form.form.value.variants[3]).toEqual({ variantId: null, values: ["M", ""], sku: "NOWY", ean: "59012344" });
    });
  });

  describe("the violations of the API", () => {
    it("are put at the SKU and the EAN of their row and nowhere else", async () => {
      const form = await start();
      form.setSku(1, "zły kod");
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "variants[1].sku", title: "Kod SKU może mieć od 1 do 40 znaków: litery A–Z, cyfry oraz znaki - _ . / i musi zaczynać się literą albo cyfrą." },
            { propertyPath: "variants[2].ean", title: "Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną." },
            { propertyPath: "variants[0].sku", title: "Ten kod SKU powtarza się w produkcie." },
          ],
        }),
      );

      await form.save();

      expect(form.errorsExactly("variants[1].sku")).toEqual(["Kod SKU może mieć od 1 do 40 znaków: litery A–Z, cyfry oraz znaki - _ . / i musi zaczynać się literą albo cyfrą."]);
      expect(form.errorsExactly("variants[2].ean")).toEqual(["Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną."]);
      expect(form.errorsExactly("variants[0].sku")).toEqual(["Ten kod SKU powtarza się w produkcie."]);
      expect(form.error.value).toBeNull();
      expect(form.variantsErrors.value).toEqual([]);
    });

    it("show a 409 sku-taken as a message of the section of the variants, with the fields and the draft as they were", async () => {
      const form = await start();
      form.setSku(0, "zajety");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}sku-taken`, title: "Conflict", status: 409, detail: "x" }));

      await form.save();

      expect(form.variantsErrors.value).toEqual([TAKEN]);
      expect(form.error.value).toBeNull();
      expect(form.safety.unknown.value).toBeNull();
      expect(codes(form)[0]).toEqual(["v-1", "ZAJETY", "5901234123457"]);
      expect(form.dirty.value).toBe(true);
    });

    it("forget the messages at the rows when a row or an axis goes, because the paths count them by position", async () => {
      const form = await start();
      form.setSku(0, "x");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[2].sku", title: "Zły kod." }] }));
      await form.save();
      expect(form.errorsExactly("variants[2].sku")).toEqual(["Zły kod."]);

      form.removeVariant(0);

      expect(form.errorsExactly("variants[2].sku")).toEqual([]);
    });
  });

  describe("a refusal because the SKU is taken, after a creation whose result was not known (criterion 9g)", () => {
    async function afterUnknownCreation() {
      const form = await start({ id: null });
      form.form.value.name = "Nowy";
      form.form.value.slug = "nowy";
      form.setSku(0, "moj-kod");
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      return form;
    }

    it("says at the SKU of the row that the earlier save may have created the product, for a 422", async () => {
      const form = await afterUnknownCreation();
      mocks.post.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[0].sku", title: TAKEN }] }));

      await form.save();

      expect(form.errorsExactly("variants[0].sku")).toEqual([`${TAKEN} ${NOTE}`]);
    });

    it("says it in the message of the section of the variants, for a 409 sku-taken", async () => {
      const form = await afterUnknownCreation();
      mocks.post.mockResolvedValue(problem(409, { type: `${PREFIX}sku-taken`, title: "Conflict", status: 409, detail: "x" }));

      await form.save();

      expect(form.variantsErrors.value).toEqual([`${TAKEN} ${NOTE}`]);
    });

    it("says nothing of the sort for another violation of the SKU, nor when no earlier creation ended unknown", async () => {
      const form = await afterUnknownCreation();
      mocks.post.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[0].sku", title: "Ten kod SKU powtarza się w produkcie." }] }));
      await form.save();
      expect(form.errorsExactly("variants[0].sku")).toEqual(["Ten kod SKU powtarza się w produkcie."]);

      const fresh = await start({ id: null });
      fresh.form.value.name = "Nowy";
      mocks.post.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[0].sku", title: TAKEN }] }));
      await fresh.save();
      expect(fresh.errorsExactly("variants[0].sku")).toEqual([TAKEN]);
    });
  });

  describe("„Sprawdź stan” after an unknown result of a save (criterion 9: the normalization of the API)", () => {
    /** The save of the changes `edit` makes ended unknown; the read answers `answer`. */
    async function checkSaved(edit: (form: Form) => void, answer: ProductEditView) {
      serveReads();
      const form = await start();
      form.form.value.name = "Pierwsza";
      edit(form);
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      mocks.get.mockResolvedValue(ok(answer));
      await form.safety.check();
      return form;
    }
    const served = (rows: Partial<ProductEditView["variants"][number]>[] = [], overrides: Partial<ProductEditView> = {}) => {
      const base = view().variants;
      return view({ name: "Pierwsza", version: 4, variants: base.map((row, index) => ({ ...row, ...rows[index] })), ...overrides });
    };
    const setCodes = (row: number, sku: string, ean: string) => (form: Form) => {
      form.setSku(row, sku);
      form.setEan(row, ean);
    };

    const matched = (form: Form) => {
      expect(form.view.value?.version).toBe(4);
      expect(form.safety.unknown.value).toBeNull();
      expect(form.safety.status.value).toBe(MATCHES);
      expect(form.dirty.value).toBe(false);
    };
    const notMatched = (form: Form) => {
      expect(form.view.value?.version).toBe(3);
      expect(form.safety.unknown.value?.kind).toBe("otherVersion");
    };

    const EDGES: [string, string][] = [
      ["a space", " "],
      ["U+00A0", " "],
      ["U+2003", " "],
      ["U+202F", " "],
      ["U+3000", "　"],
      ["U+180E", "᠎"],
      ["U+200B", "​"],
      ["U+2060", "⁠"],
      ["U+FEFF", "﻿"],
      ["U+00AD", "­"],
    ];

    it("is the same when nothing differs", async () => {
      matched(await checkSaved(() => {}, served()));
    });

    it("is the same when the SKU was typed in small letters and the server has capitals", async () => {
      const form = await checkSaved(setCodes(2, "nowy-kod/1", ""), served([{}, {}, { sku: "NOWY-KOD/1" }]));

      matched(form);
      expect(form.form.value.variants[2]!.sku).toBe("NOWY-KOD/1");
    });

    it("is the same when the SKU was written in small letters by the staff member and kept in the draft as typed", async () => {
      // A SKU that came into the draft in small letters (a paste that is not typed) is compared after the capitals too.
      const form = await checkSaved((current) => (current.form.value.variants[2]!.sku = "wklejony-1"), served([{}, {}, { sku: "WKLEJONY-1" }]));

      matched(form);
    });

    it.each(EDGES)("is the same when the SKU and the EAN have %s at the edges and the server has them trimmed (T2)", async (_name, edge) => {
      const form = await checkSaved(setCodes(2, `${edge}nowy-1${edge}`, `${edge}5901234123457${edge}`), served([{}, {}, { sku: "NOWY-1", ean: "5901234123457" }]));

      matched(form);
      expect(form.form.value.variants[2]).toMatchObject({ sku: "NOWY-1", ean: "5901234123457" });
    });

    it.each([
      ["only white space", "  　 "],
      ["only zero width characters", "​⁠﻿­"],
      ["only U+200D", "‍"],
    ])("is the same when a code is %s and the server has no code (T3)", async (_name, text) => {
      matched(await checkSaved(setCodes(0, text, text), served([{ sku: "", ean: "" }])));
    });

    it("is not the same when the server has another code, more or less than the draft", async () => {
      notMatched(await checkSaved(setCodes(2, "nowy-1", ""), served([{}, {}, { sku: "NOWY-2" }])));
      notMatched(await checkSaved(setCodes(2, "nowy-1", ""), served([{}, {}, { sku: "NOWY-1 " }])));
      notMatched(await checkSaved(setCodes(2, "", "59012344"), served([{}, {}, { ean: "5901234123457" }])));
      notMatched(await checkSaved(setCodes(2, "nowy-1", ""), served()));
    });

    it("compares the EAN without the capitals: letters are not turned into anything", async () => {
      notMatched(await checkSaved((current) => (current.form.value.variants[2]!.ean = "abc"), served([{}, {}, { ean: "ABC" }])));
    });

    it.each([
      ["a tab at the end of the SKU", setCodes(2, "nowy-1\t", "")],
      ["U+2028 at the start of the SKU", setCodes(2, "\u{2028}nowy-1", "")],
      ["U+000D inside the SKU", setCodes(2, "nowy\r1", "")],
      ["a tab at the end of the EAN", setCodes(2, "", "59012344\t")],
      ["U+2028 at the end of the EAN", setCodes(2, "", "59012344\u{2028}")],
      ["U+0085 at the start of the EAN", setCodes(2, "", "\u{0085}59012344")],
    ])("is never the same when the draft has a character T1 forbids (%s), though the server has the code without it, and nothing in the form changes", async (_name, edit) => {
      const form = await checkSaved(edit, served([{}, {}, { sku: "NOWY-1", ean: "59012344" }]));

      notMatched(form);
      expect(form.dirty.value).toBe(true);
    });

    it("matches a new row without an identifier by its values and compares its codes", async () => {
      const edit = (form: Form) => {
        form.addVariant();
        form.setValue(3, 0, "zielone");
        form.setValue(3, 1, "S");
        form.setSku(3, "buty-zs");
        form.setEan(3, `${"​"}5901234123457 `);
      };
      const rows = [...view().variants, { variantId: "v-new", values: ["zielone", "S"], sku: "BUTY-ZS", ean: "5901234123457" }];

      matched(await checkSaved(edit, view({ name: "Pierwsza", version: 4, variants: rows })));
      notMatched(await checkSaved(edit, view({ name: "Pierwsza", version: 4, variants: [...rows.slice(0, 3), { ...rows[3]!, sku: "" }] })));
      notMatched(await checkSaved(edit, view({ name: "Pierwsza", version: 4, variants: [...rows.slice(0, 3), { ...rows[3]!, ean: "59012344" }] })));
    });

    it("does not change the fields, the request or the changes of the draft", async () => {
      const form = await start();
      form.setSku(2, " nowy ");
      form.setEan(2, " 59012344 ");
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      mocks.get.mockResolvedValue(ok(view({ version: 6 })));
      await form.safety.check();

      expect(codes(form)[2]).toEqual(["v-3", " NOWY ", " 59012344 "]);
      expect(form.dirty.value).toBe(true);

      mocks.put.mockResolvedValue(ok(view({ version: 7 })));
      await form.save();
      expect(mocks.put.mock.calls[1]![1].body.variants[2]).toMatchObject({ sku: " NOWY ", ean: " 59012344 " });
    });

    it("takes the codes of the server after a match, so the next save has the new version and the trimmed codes", async () => {
      const form = await checkSaved(setCodes(2, " nowy ", " 59012344 "), served([{}, {}, { sku: "NOWY", ean: "59012344" }]));
      mocks.put.mockResolvedValue(ok(view({ version: 5 })));

      form.form.value.name = "Zmiana";
      await form.save();

      expect(mocks.put.mock.calls[1]![1].body).toMatchObject({ version: 4 });
      expect(mocks.put.mock.calls[1]![1].body.variants[2]).toMatchObject({ sku: "NOWY", ean: "59012344" });
    });

    it("ends the message that the server matches when the staff member changes a code afterwards, also by what the normalization ignores", async () => {
      const form = await checkSaved(setCodes(2, " nowy ", ""), served([{}, {}, { sku: "NOWY" }]));
      expect(form.safety.status.value).toBe(MATCHES);

      form.setSku(2, "NOWY ");
      await flushPromises();

      expect(form.safety.status.value).toBe("");
      expect(form.dirty.value).toBe(true);
    });
  });
});
