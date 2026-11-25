import { describe, expect, it } from "vitest";
import { normalizeContentText } from "@/products/contentText";

/**
 * The copy of the rule of texts T1–T3 (docs/features/api-input-contract/spec.md, „Etap 2: reguła tekstu”) that only the
 * comparison of „Sprawdź stan” uses (docs/features/product-data/spec.md, „Porównanie „Sprawdź stan” z normalizacją API”).
 * The table is by code points: the value of the draft and the text the API would store, or null when T1 forbids the value
 * (the API stores nothing of it, so no state of the server is the draft's). The test of the real API answers is
 * `e2e/tests/catalog-admin.spec.ts`; this one only holds the table.
 */

const cp = (...codePoints: number[]) => String.fromCodePoint(...codePoints);
const hex = (value: string) => [...value].map((character) => `U+${character.codePointAt(0)!.toString(16).toUpperCase().padStart(4, "0")}`).join(" ");

/** The characters T2 trims from the ends of a text and keeps inside it. */
const EDGES: [string, string][] = [
  ["a space", " "],
  ["U+00A0", cp(0xa0)],
  ["U+1680", cp(0x1680)],
  ["U+2000", cp(0x2000)],
  ["U+2003", cp(0x2003)],
  ["U+200A", cp(0x200a)],
  ["U+202F", cp(0x202f)],
  ["U+205F", cp(0x205f)],
  ["U+3000", cp(0x3000)],
  ["U+180E", cp(0x180e)],
  ["U+200B", cp(0x200b)],
  ["U+2060", cp(0x2060)],
  ["U+FEFF", cp(0xfeff)],
  ["U+00AD", cp(0xad)],
];

/** T1: forbidden anywhere, also at the edge. */
const FORBIDDEN: [string, string][] = [
  ["U+0000", cp(0x00)],
  ["U+0009", cp(0x09)],
  ["U+000B", cp(0x0b)],
  ["U+000C", cp(0x0c)],
  ["U+000D", cp(0x0d)],
  ["U+001F", cp(0x1f)],
  ["U+007F", cp(0x7f)],
  ["U+0085", cp(0x85)],
  ["U+009F", cp(0x9f)],
  ["U+2028", cp(0x2028)],
  ["U+2029", cp(0x2029)],
];

describe("normalizeContentText", () => {
  describe("T2: the edges", () => {
    it.each(EDGES)("trims %s from both edges of a line and keeps it inside", (_name, edge) => {
      expect(normalizeContentText(`${edge}Hallen${edge}`)).toBe("Hallen");
      expect(normalizeContentText(`${edge}${edge}Hal${edge}len${edge}${edge}`)).toBe(`Hal${edge}len`);
    });

    it.each(EDGES)("trims %s from both edges of a description", (_name, edge) => {
      expect(normalizeContentText(`${edge}Opis\n\nDrugi${edge}`, "paragraphs")).toBe("Opis\n\nDrugi");
    });

    it("trims a mixture of edge characters, in any order", () => {
      expect(normalizeContentText(` ${cp(0x200b, 0xa0, 0x3000, 0xfeff, 0x180e)}Hallen${cp(0xad, 0x2060, 0x2003)} `)).toBe("Hallen");
    });

    it("trims the line feeds around a description but not the ones inside", () => {
      expect(normalizeContentText("\n \nPierwszy.\n\nDrugi.\n \n", "paragraphs")).toBe("Pierwszy.\n\nDrugi.");
    });

    it("leaves the text as it is when there is nothing to trim", () => {
      expect(normalizeContentText("Zażółć gęślą jaźń")).toBe("Zażółć gęślą jaźń");
    });
  });

  describe("T1: a forbidden character gives no text, wherever it is", () => {
    it.each(FORBIDDEN)("%s: at the end, at the start, inside and alone, in a line and in a description", (_name, character) => {
      for (const kind of ["line", "paragraphs"] as const) {
        expect(normalizeContentText(`Hallen${character}`, kind)).toBeNull();
        expect(normalizeContentText(`${character}Hallen`, kind)).toBeNull();
        expect(normalizeContentText(`Hal${character}len`, kind)).toBeNull();
        expect(normalizeContentText(character, kind)).toBeNull();
        expect(normalizeContentText(` ${character} `, kind)).toBeNull();
      }
    });

    it("looks at the value as it came, before the trim: a character the trim would drop is still refused", () => {
      expect(normalizeContentText(`Hallen${cp(0x09)}`)).toBeNull();
      expect(normalizeContentText(`Opis${cp(0x0d)}`, "paragraphs")).toBeNull();
      expect(normalizeContentText(`${cp(0x2028)}${cp(0x200b)}`)).toBeNull();
    });

    it("refuses a lone surrogate, which no JSON body can carry", () => {
      expect(normalizeContentText("Hal\ud800len")).toBeNull();
    });
  });

  describe("the line feed", () => {
    it("is forbidden in a line and allowed in a description", () => {
      expect(normalizeContentText("Pierwsza\nDruga")).toBeNull();
      expect(normalizeContentText("Pierwsza\nDruga", "paragraphs")).toBe("Pierwsza\nDruga");
    });

    it("does not make a carriage return allowed in a description", () => {
      expect(normalizeContentText("Pierwsza\r\nDruga", "paragraphs")).toBeNull();
      expect(normalizeContentText("Pierwsza\n\rDruga", "paragraphs")).toBeNull();
    });
  });

  describe("T3: a text without a visible character is empty", () => {
    const INVISIBLE: [string, string][] = [
      ["nothing", ""],
      ["spaces", "   "],
      ["only edge characters", cp(0xa0, 0x2003, 0x3000, 0x202f, 0x180e, 0x200b, 0x2060, 0xfeff, 0xad)],
      ["only U+200D", cp(0x200d)],
      ["joiners and marks of direction", cp(0x200d, 0x200e, 0x202e, 0x200c)],
      ["U+3164", cp(0x3164)],
      ["U+115F and U+FFA0", cp(0x115f, 0xffa0)],
      ["a variation selector between spaces", ` ${cp(0xa0, 0xfe0f, 0x3000)} `],
      ["tag characters without their flag", cp(0xe0067, 0xe0062, 0xe007f)],
      ["U+034F", cp(0x034f)],
    ];

    it.each(INVISIBLE)("%s is empty", (_name, value) => {
      expect(normalizeContentText(value)).toBe("");
      expect(normalizeContentText(value, "paragraphs")).toBe("");
    });

    it("takes a description of line feeds and invisible characters for empty", () => {
      expect(normalizeContentText(`\n ${cp(0x200d)} \n${cp(0x3164)}\n`, "paragraphs")).toBe("");
      expect(normalizeContentText("\n\n\n", "paragraphs")).toBe("");
    });

    it("takes a private use character and a character Unicode does not know for visible", () => {
      expect(normalizeContentText(cp(0xe000))).toBe(cp(0xe000));
      expect(normalizeContentText(cp(0x10fffd))).toBe(cp(0x10fffd));
      expect(normalizeContentText(cp(0x0378))).toBe(cp(0x0378));
    });
  });

  describe("T5: what stays", () => {
    it("keeps U+200B between letters", () => {
      expect(normalizeContentText(`Hal${cp(0x200b)}len`)).toBe(`Hal${cp(0x200b)}len`);
      expect(normalizeContentText(` Hal${cp(0x200b)}len `)).toBe(`Hal${cp(0x200b)}len`);
    });

    it("keeps U+200D in an emoji", () => {
      const technologist = cp(0x1f469, 0x200d, 0x1f4bb);
      expect(normalizeContentText(`Kurier ${technologist}`)).toBe(`Kurier ${technologist}`);
      expect(normalizeContentText(`  ${technologist}  `)).toBe(technologist);
    });

    it("keeps the tags of a flag at the end of the text", () => {
      const england = cp(0x1f3f4, 0xe0067, 0xe0062, 0xe0065, 0xe006e, 0xe0067, 0xe007f);
      expect(normalizeContentText(`Kurier ${england}`)).toBe(`Kurier ${england}`);
      expect(normalizeContentText(`Kurier ${england} `)).toBe(`Kurier ${england}`);
      expect(normalizeContentText(`Opis ${england}`, "paragraphs")).toBe(`Opis ${england}`);
    });

    it("keeps the Polish letters", () => {
      expect(normalizeContentText(" ŻÓŁĆ ")).toBe("ŻÓŁĆ");
    });
  });

  it("is idempotent: the result of a normalization is its own normal form", () => {
    for (const [, edge] of EDGES) {
      const once = normalizeContentText(`${edge}Hal${cp(0x200b)}len${edge}`)!;
      expect(normalizeContentText(once), hex(edge)).toBe(once);
    }
  });
});
