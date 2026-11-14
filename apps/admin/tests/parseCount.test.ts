import { describe, expect, it } from "vitest";
import { parseCount } from "@/inventory/parseCount";

describe("parseCount", () => {
  it.each([
    ["5", 5],
    ["0", 0],
    ["007", 7],
    ["  12 ", 12],
    ["  15  ", 15],
    ["100000", 100_000],
    ["-3", -3],
  ])("reads %j as the whole number %d", (text, value) => {
    expect(parseCount(text, "Brakuje liczby.")).toEqual({ ok: true, value });
  });

  it.each([[""], ["   "], [" "], [" "]])("says the number is missing for %j, with the message it is given", (text) => {
    expect(parseCount(text, "Brakuje liczby.")).toEqual({ ok: false, message: "Brakuje liczby." });
  });

  it.each([["abc"], ["1,5"], ["1.5"], ["1e3"], ["+5"], ["--5"], ["12 3"], ["١٢"], ["１２"], ["5\u0000"]])("asks for a whole number instead of %j", (text) => {
    expect(parseCount(text, "Brakuje liczby.")).toEqual({ ok: false, message: "Podaj liczbę całkowitą." });
  });

  it("does not take a number that is not a safe integer", () => {
    expect(parseCount("9007199254740993", "Brakuje liczby.")).toEqual({ ok: false, message: "Liczba jest za duża." });
  });
});
