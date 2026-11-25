import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { MAX_PARAMETERS, useProductForm } from "@/products/useProductForm";

/**
 * The brand, the description and the parameters of the product form (docs/features/product-data/spec.md, „Panel”): what
 * the draft sends (as it is, never trimmed), the operations on the list of parameters, the messages of the API at their
 * fields, and the comparison of „Sprawdź stan” after an unknown result of a save, which for these fields goes through the
 * normalization of the API (T1–T3) without changing the fields, the request or the changes of the draft.
 */

type ProductEditView = components["schemas"]["ProductEditViewResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PRODUCT = "/api/v1/admin/products/{id}";
const TAX_CLASSES = "/api/v1/admin/tax-classes";
const MATCHES = "Stan na serwerze odpowiada formularzowi.";

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
    brand: "Hallen",
    description: "Pierwszy akapit.\n\nDrugi akapit.",
    parameters: [
      { name: "Waga", value: "2,4 kg" },
      { name: "Liczba osób", value: "4" },
    ],
    mainCategoryId: "c-main",
    additionalCategoryIds: ["c-extra"],
    taxClass: "standard",
    images: [{ key: "originals/a.jpg", alt: "Przód", preview }],
    variantAxes: ["Kolor"],
    variants: [
      { variantId: "v-1", values: ["czarne"] },
      { variantId: "v-2", values: ["białe"] },
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

async function start(options: { id?: string | null; canManage?: boolean } = {}) {
  const id = ref<string | null>(options.id === undefined ? "p-1" : options.id);
  let form!: ReturnType<typeof useProductForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useProductForm({ productId: () => id.value, canManage: () => options.canManage ?? true, onCreated: vi.fn(), onLateSaved: vi.fn() });
        return () => null;
      },
    }),
  );
  wrappers.push(wrapper);
  await flushPromises();
  return form;
}

type Form = Awaited<ReturnType<typeof start>>;

describe("the brand, the description and the parameters of the product form", () => {
  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    serveReads();
  });

  afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
  });

  describe("the request of a save", () => {
    it("carries them as they are in the draft: with the edges and every character, never trimmed", async () => {
      const form = await start();
      form.form.value.brand = " Hallen\t";
      form.form.value.description = " Opis\r\n\u{2028}";
      form.form.value.parameters = [
        { name: " Waga​", value: " 2 kg\u{2028}" },
        { name: "\t", value: " " },
      ];
      mocks.put.mockResolvedValue(problem(422, { violations: [] }));

      await form.save();

      const body = mocks.put.mock.calls[0]![1].body;
      expect(body.brand).toBe(" Hallen\t");
      expect(body.description).toBe(" Opis\r\n\u{2028}");
      expect(body.parameters).toEqual([
        { name: " Waga​", value: " 2 kg\u{2028}" },
        { name: "\t", value: " " },
      ]);
      expect(form.form.value.brand).toBe(" Hallen\t");
    });

    it("carries the keys of a new product that has no content: an empty text and an empty list", async () => {
      const form = await start({ id: null });
      form.form.value.name = "Nowy";
      mocks.post.mockResolvedValue(ok(view({ brand: "", description: "", parameters: [] }), 201));

      await form.save();

      const body = mocks.post.mock.calls[0]![1].body;
      expect(body).toMatchObject({ brand: "", description: "", parameters: [] });
    });

    it("shows the product of the API after a save, with the texts as the API trimmed them", async () => {
      const form = await start();
      form.form.value.brand = "  Inna ";
      form.form.value.parameters = [{ name: " Waga ", value: " 1 kg " }];
      mocks.put.mockResolvedValue(ok(view({ version: 4, brand: "Inna", parameters: [{ name: "Waga", value: "1 kg" }] })));

      await form.save();

      expect(form.form.value.brand).toBe("Inna");
      expect(form.form.value.parameters).toEqual([{ name: "Waga", value: "1 kg" }]);
      expect(form.dirty.value).toBe(false);
    });
  });

  describe("the draft", () => {
    it("counts a change of the brand, the description and the parameters, and a restored value is no change", async () => {
      const form = await start();

      form.form.value.brand = "Inna";
      expect(form.dirty.value).toBe(true);
      form.form.value.brand = "Hallen";
      form.form.value.description = "Inny.";
      expect(form.dirty.value).toBe(true);
      form.form.value.description = "Pierwszy akapit.\n\nDrugi akapit.";
      form.setParameter(0, "value", "3 kg");
      expect(form.dirty.value).toBe(true);
      form.setParameter(0, "value", "2,4 kg");
      expect(form.dirty.value).toBe(false);
      form.moveParameter(0, 1);
      expect(form.dirty.value).toBe(true);
      form.moveParameter(1, -1);
      expect(form.dirty.value).toBe(false);
    });

    it("counts a trailing space as a change and keeps it in the field: the form does not normalize what was typed", async () => {
      const form = await start();

      form.form.value.brand = "Hallen ";

      expect(form.dirty.value).toBe(true);
      expect(form.form.value.brand).toBe("Hallen ");
    });

    it("restores them from the API with „Odrzuć”", async () => {
      const form = await start();
      form.form.value.brand = "Inna";
      form.addParameter();
      form.removeParameter(0);

      form.discard();

      expect(form.form.value.brand).toBe("Hallen");
      expect(form.form.value.parameters).toEqual([
        { name: "Waga", value: "2,4 kg" },
        { name: "Liczba osób", value: "4" },
      ]);
      expect(form.dirty.value).toBe(false);
    });
  });

  describe("the list of parameters", () => {
    const names = (form: Form) => form.form.value.parameters.map((parameter) => parameter.name);

    it("adds a parameter with an empty name and an empty value at the end", async () => {
      const form = await start();

      form.addParameter();

      expect(form.form.value.parameters).toHaveLength(3);
      expect(form.form.value.parameters[2]).toEqual({ name: "", value: "" });
      expect(form.dirty.value).toBe(true);
    });

    it("adds up to 30 parameters and no more", async () => {
      const form = await start({ id: null });

      for (let count = 0; count < 35; count++) {
        form.addParameter();
      }

      expect(MAX_PARAMETERS).toBe(30);
      expect(form.form.value.parameters).toHaveLength(30);
      expect(form.canAddParameter.value).toBe(false);
    });

    it("tells that a parameter can be added below the limit", async () => {
      const form = await start();

      expect(form.canAddParameter.value).toBe(true);
    });

    it("edits the name and the value of the parameter at the position", async () => {
      const form = await start();

      form.setParameter(1, "name", "Miejsc");
      form.setParameter(1, "value", "5");

      expect(form.form.value.parameters[1]).toEqual({ name: "Miejsc", value: "5" });
      expect(form.form.value.parameters[0]).toEqual({ name: "Waga", value: "2,4 kg" });
    });

    it("removes the parameter at the position", async () => {
      const form = await start();

      form.removeParameter(0);

      expect(names(form)).toEqual(["Liczba osób"]);
      form.removeParameter(0);
      expect(names(form)).toEqual([]);
    });

    it("moves a parameter up and down and ignores moves outside the list", async () => {
      const form = await start();

      form.moveParameter(0, -1);
      form.moveParameter(1, 1);
      expect(names(form)).toEqual(["Waga", "Liczba osób"]);
      form.moveParameter(1, -1);
      expect(names(form)).toEqual(["Liczba osób", "Waga"]);
      form.moveParameter(0, 1);
      expect(names(form)).toEqual(["Waga", "Liczba osób"]);
    });

    it("ignores a position outside the list", async () => {
      const form = await start();

      form.removeParameter(5);
      form.removeParameter(-1);
      form.setParameter(7, "name", "x");

      expect(form.form.value.parameters).toHaveLength(2);
      expect(form.dirty.value).toBe(false);
    });

    it("changes nothing without the permission to manage products", async () => {
      const form = await start({ canManage: false });

      form.addParameter();
      form.setParameter(0, "name", "x");
      form.moveParameter(0, 1);
      form.removeParameter(1);

      expect(names(form)).toEqual(["Waga", "Liczba osób"]);
      expect(form.canAddParameter.value).toBe(false);
    });

    it("is not edited while a request runs: the operations change nothing until the answer", async () => {
      const form = await start();
      let answer!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (answer = resolve)));
      const saving = form.save();
      const draft = JSON.stringify(form.form.value);

      form.addParameter();
      form.setParameter(0, "name", "x");
      form.moveParameter(0, 1);
      form.removeParameter(0);

      expect(JSON.stringify(form.form.value)).toBe(draft);
      expect(form.canAddParameter.value).toBe(false);
      answer(ok(view({ version: 4 })));
      await saving;
      expect(form.canAddParameter.value).toBe(true);
    });

    it("forgets the messages at the parameters when the rows move, because the paths count them by position", async () => {
      const form = await start();
      form.form.value.name = "Zmiana";
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "parameters[1].name", title: "Parametr o tej nazwie już jest na liście." },
            { propertyPath: "name", title: "Nazwa jest zła." },
          ],
        }),
      );
      await form.save();
      expect(form.errorsAt("parameters[1]")).toEqual(["Parametr o tej nazwie już jest na liście."]);

      form.moveParameter(1, -1);

      expect(form.errorsAt("parameters")).toEqual([]);
      expect(form.errorsAt("name")).toEqual(["Nazwa jest zła."]);
    });
  });

  describe("the violations of the API", () => {
    it("are put at the brand, the description, the list, a row, its name and its value", async () => {
      const form = await start();
      form.form.value.brand = "x";
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "brand", title: "Marka zawiera niedozwolone znaki." },
            { propertyPath: "description", title: "Opis może mieć najwyżej 5000 znaków." },
            { propertyPath: "parameters", title: "Produkt może mieć najwyżej 30 parametrów." },
            { propertyPath: "parameters[0]", title: "Parametr musi mieć nazwę i wartość." },
            { propertyPath: "parameters[1].name", title: "Nazwa parametru zawiera niedozwolone znaki." },
            { propertyPath: "parameters[1].value", title: "Wartość parametru musi mieć od 1 do 200 znaków." },
          ],
        }),
      );

      await form.save();

      expect(form.errorsAt("brand")).toEqual(["Marka zawiera niedozwolone znaki."]);
      expect(form.errorsAt("description")).toEqual(["Opis może mieć najwyżej 5000 znaków."]);
      expect(form.errorsExactly("parameters")).toEqual(["Produkt może mieć najwyżej 30 parametrów."]);
      expect(form.errorsExactly("parameters[0]")).toEqual(["Parametr musi mieć nazwę i wartość."]);
      expect(form.errorsAt("parameters[1]")).toEqual(["Nazwa parametru zawiera niedozwolone znaki.", "Wartość parametru musi mieć od 1 do 200 znaków."]);
      expect(form.errorsExactly("parameters[1].name")).toEqual(["Nazwa parametru zawiera niedozwolone znaki."]);
      expect(form.errorsExactly("parameters[1].value")).toEqual(["Wartość parametru musi mieć od 1 do 200 znaków."]);
      expect(form.error.value).toBeNull();
      expect(form.dirty.value).toBe(true);
    });

    it("show no message about the list as a whole for a row, and the other way round", async () => {
      const form = await start();
      form.form.value.brand = "x";
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "parameters[0].value", title: "Zła wartość." }] }));

      await form.save();

      expect(form.errorsExactly("parameters")).toEqual([]);
      expect(form.errorsAt("parameters[0]")).toEqual(["Zła wartość."]);
    });
  });

  describe("„Sprawdź stan” after an unknown result of a save (criterion 9: the normalization of the API)", () => {
    /** The save of the changes `edit` makes ended unknown; the read answers `answer`. */
    async function checkSaved(edit: (form: Form) => void, answer: ProductEditView) {
      const form = await start();
      form.form.value.name = "Pierwsza";
      edit(form);
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      mocks.get.mockResolvedValue(ok(answer));
      await form.safety.check();
      return form;
    }
    const served = (overrides: Partial<ProductEditView> = {}) => view({ name: "Pierwsza", version: 4, ...overrides });
    const setBrand = (value: string) => (form: Form) => (form.form.value.brand = value);
    const setDescription = (value: string) => (form: Form) => (form.form.value.description = value);
    const setParameters = (...rows: [string, string][]) => (form: Form) => (form.form.value.parameters = rows.map(([name, value]) => ({ name, value })));

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
      ["U+00A0", " "],
      ["U+2003", " "],
      ["U+202F", " "],
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

    it.each(EDGES)("is the same when the brand and a parameter have %s at the edges and the server has them trimmed (T2)", async (_name, edge) => {
      const form = await checkSaved(
        (current) => {
          setBrand(`${edge}Inna marka${edge}`)(current);
          setParameters([`${edge}Materiał${edge}`, `${edge}tkanina${edge}`])(current);
        },
        served({ brand: "Inna marka", parameters: [{ name: "Materiał", value: "tkanina" }] }),
      );

      matched(form);
      expect(form.form.value.brand).toBe("Inna marka");
    });

    it.each([...EDGES, ["U+000A", "\n"]] as [string, string][])("is the same when the description has %s at the edges and the server has it trimmed", async (_name, edge) => {
      matched(await checkSaved(setDescription(`${edge}Pierwszy.\n\nDrugi.${edge}`), served({ description: "Pierwszy.\n\nDrugi." })));
    });

    it.each([
      ["only white space", "  　 "],
      ["only U+200D", "‍"],
      ["line feeds and spaces", " \n \n"],
      ["only zero width characters", "​⁠﻿­"],
    ])("is the same when the description is %s and the server has no description (T3)", async (_name, text) => {
      matched(await checkSaved(setDescription(text), served({ description: "" })));
    });

    it("is the same when the brand is only white space and the server has no brand", async () => {
      matched(await checkSaved(setBrand("​  "), served({ brand: "" })));
    });

    it("keeps what is inside a text: U+200B between letters, U+200D in an emoji and the tags of a flag at the end", async () => {
      const england = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
      const kept = `Ha​llen \u{1F469}‍\u{1F4BB} ${england}`;

      matched(await checkSaved(setBrand(` ${kept} `), served({ brand: kept })));
      notMatched(await checkSaved(setBrand(` ${kept} `), served({ brand: kept.replace("​", "") })));
      notMatched(await checkSaved(setBrand(` ${kept} `), served({ brand: kept.replace(england, "") })));
    });

    it("is not the same when the server has more or less than the draft", async () => {
      notMatched(await checkSaved(setBrand("Inna marka"), served({ brand: "Inna marka2" })));
      notMatched(await checkSaved(setParameters(["Materiał", "tkanina"]), served({ parameters: [{ name: "Materiał", value: "tkanina " }] })));
      notMatched(await checkSaved(setDescription("Opis"), served({ description: "Opis " })));
    });

    it("is not the same when the order or the number of the parameters differs", async () => {
      const two: [string, string][] = [
        ["Waga", "1 kg"],
        ["Kolor", "czarny"],
      ];
      const rows = two.map(([name, value]) => ({ name, value }));

      matched(await checkSaved(setParameters(...two), served({ parameters: rows })));
      notMatched(await checkSaved(setParameters(...two), served({ parameters: [rows[1]!, rows[0]!] })));
      notMatched(await checkSaved(setParameters(...two), served({ parameters: [rows[0]!] })));
      notMatched(await checkSaved(setParameters(...two), served({ parameters: [...rows, { name: "Inny", value: "x" }] })));
    });

    it.each([
      ["U+0009 at the end of the brand", setBrand("Hallen\t")],
      ["U+000D at the start of the brand", setBrand("\rHallen")],
      ["U+0085 inside the brand", setBrand("Hal\u0085len")],
      ["U+2028 at the end of the brand", setBrand("Hallen\u{2028}")],
      ["a line feed in the brand", setBrand("Hal\nlen")],
      ["U+000D in the description", setDescription("Opis\r")],
      ["U+2028 at the edge of the description", setDescription("\u{2028}Opis")],
      ["U+2028 at the edge of a value", setParameters(["Waga", "2 kg\u{2028}"])],
      ["U+0009 in a name", setParameters(["Wa\tga", "2 kg"])],
    ])("is never the same when the draft has a character T1 forbids (%s), though the server has the text without it, and nothing in the form changes", async (_name, edit) => {
      const form = await checkSaved(edit, served({ brand: "Hallen", description: "Opis", parameters: [{ name: "Waga", value: "2 kg" }] }));

      notMatched(form);
      expect(form.dirty.value).toBe(true);
    });

    it("does not change the fields, the request or the changes of the draft: a draft with edges stays as typed after a check that does not match", async () => {
      const form = await start();
      form.form.value.brand = " Inna  ";
      form.form.value.description = "\n Opis \n";
      form.form.value.parameters = [{ name: " Waga ", value: " 1 kg " }];
      mocks.put.mockRejectedValueOnce(new TypeError("network down"));
      await form.save();
      mocks.get.mockResolvedValue(ok(view({ version: 6 })));
      await form.safety.check();

      expect(form.form.value.brand).toBe(" Inna  ");
      expect(form.form.value.description).toBe("\n Opis \n");
      expect(form.form.value.parameters).toEqual([{ name: " Waga ", value: " 1 kg " }]);
      expect(form.dirty.value).toBe(true);

      mocks.put.mockResolvedValue(ok(view({ version: 7 })));
      await form.save();
      const body = mocks.put.mock.calls[1]![1].body;
      expect(body.brand).toBe(" Inna  ");
      expect(body.description).toBe("\n Opis \n");
      expect(body.parameters).toEqual([{ name: " Waga ", value: " 1 kg " }]);
    });

    it("takes the state of the server after a match, so the next save has the new version and the trimmed texts", async () => {
      const form = await checkSaved(setBrand(" Inna "), served({ brand: "Inna" }));
      mocks.put.mockResolvedValue(ok(view({ version: 5, brand: "Inna" })));

      form.form.value.name = "Zmiana";
      await form.save();

      expect(mocks.put.mock.calls[1]![1].body).toMatchObject({ version: 4, brand: "Inna" });
      expect(form.view.value?.version).toBe(5);
    });

    it("keeps the strict comparison of the old fields: a trimmed space in the name does not match, whatever the new fields are", async () => {
      notMatched(await checkSaved(setBrand(" Inna "), served({ brand: "Inna", name: "Pierwsza " })));
      notMatched(await checkSaved(setBrand(" Inna "), served({ brand: "Inna", shortDescription: "Lekkie buty. " })));
    });

    it("does not compare a draft with a forbidden character in the new fields with a state the server could not have produced", async () => {
      // The tab in the brand: no state of the server equals this draft, not even the one with the brand cut at the tab.
      const form = await checkSaved(setBrand("Hallen\t"), served({ brand: "Hallen" }));

      notMatched(form);
      expect(form.safety.status.value).toBe("");
    });
  });
});
