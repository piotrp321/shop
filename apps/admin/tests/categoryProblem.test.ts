import { describe, expect, it } from "vitest";
import { categoryProblem, MODIFIED_MESSAGE } from "@/categories/problem";

const TYPE = "tag:piotrp321.github.io,2026:shop/problems/";
const conflict = (type: string, detail?: string) => ({ type: `${TYPE}${type}`, title: "Conflict", status: 409, ...(detail === undefined ? {} : { detail }) });

describe("categoryProblem: 409 by type", () => {
  it("says a version conflict in the fixed words and asks to reload", () => {
    expect(categoryProblem(409, conflict("category-modified", "Kategoria została w międzyczasie zmieniona. Odśwież drzewo i spróbuj ponownie."))).toEqual({
      kind: "modified",
      message: "Kategoria została w międzyczasie zmieniona – odśwież",
    });
    expect(MODIFIED_MESSAGE).toBe("Kategoria została w międzyczasie zmieniona – odśwież");
  });

  it("puts a taken slug at the field with the text of the API", () => {
    expect(categoryProblem(409, conflict("category-slug-taken", "Kategoria o tym adresie już istnieje na tym poziomie."))).toEqual({
      kind: "slug-taken",
      message: "Kategoria o tym adresie już istnieje na tym poziomie.",
    });
  });

  it.each([
    ["category-too-deep", "Drzewo kategorii może mieć najwyżej 4 poziomy."],
    ["category-cycle", "Nie można przenieść kategorii do niej samej ani do jej podkategorii."],
    ["category-not-empty", "Przenieś produkty do innej kategorii, zanim usuniesz tę."],
  ])("shows the detail of %s in the window", (type, detail) => {
    expect(categoryProblem(409, conflict(type, detail))).toEqual({ kind: "blocked", message: detail });
  });

  it("falls back to a Polish text when the detail is missing", () => {
    expect(categoryProblem(409, conflict("category-too-deep"))).toEqual({ kind: "blocked", message: "Drzewo kategorii może mieć najwyżej 4 poziomy." });
    expect(categoryProblem(409, conflict("category-cycle"))).toEqual({ kind: "blocked", message: "Nie można przenieść kategorii do niej samej ani do jej podkategorii." });
    expect(categoryProblem(409, conflict("category-not-empty"))).toEqual({ kind: "blocked", message: "Przenieś produkty do innej kategorii, zanim usuniesz tę." });
    expect(categoryProblem(409, conflict("category-slug-taken"))).toEqual({ kind: "slug-taken", message: "Ten adres jest już zajęty przez inną kategorię na tym poziomie." });
  });

  it("treats an unknown 409 or a body without a type as a failure, not as a conflict of versions", () => {
    expect(categoryProblem(409, conflict("something-new"))).toMatchObject({ kind: "failed" });
    expect(categoryProblem(409, undefined)).toMatchObject({ kind: "failed" });
  });
});

describe("categoryProblem: the other statuses", () => {
  it("gives the violations of 422 to their fields", () => {
    const body = {
      violations: [
        { propertyPath: "name", title: "Nazwa nie może być pusta." },
        { propertyPath: "slug", title: "Adres może mieć tylko małe litery, cyfry i myślniki." },
        { propertyPath: "slug", title: "Adres jest za długi." },
      ],
    };

    expect(categoryProblem(422, body)).toEqual({
      kind: "invalid",
      fields: { name: ["Nazwa nie może być pusta."], slug: ["Adres może mieć tylko małe litery, cyfry i myślniki.", "Adres jest za długi."] },
      message: null,
    });
  });

  it("keeps a violation of another field as a general message", () => {
    expect(categoryProblem(422, { violations: [{ propertyPath: "parentId", title: "Kategoria nadrzędna nie istnieje." }] })).toEqual({
      kind: "invalid",
      fields: {},
      message: "Kategoria nadrzędna nie istnieje.",
    });
    expect(categoryProblem(422, undefined)).toEqual({ kind: "invalid", fields: {}, message: "API odrzuciło dane kategorii (HTTP 422)." });
  });

  it("says in Polish that the category is gone, that the staff member may not and that the request failed", () => {
    expect(categoryProblem(404, undefined)).toEqual({ kind: "missing", message: "Ta kategoria już nie istnieje." });
    expect(categoryProblem(403, undefined)).toEqual({ kind: "forbidden", message: "Nie masz uprawnień do zmiany kategorii." });
    expect(categoryProblem(500, undefined)).toEqual({ kind: "failed", message: "Nie udało się zapisać zmiany kategorii (HTTP 500)." });
  });
});
