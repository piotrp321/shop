import { describe, expect, it } from "vitest";
import { parseGross } from "@/prices/parseGross";

describe("parseGross", () => {
  it.each([
    ["12,34", 1234],
    ["12.34", 1234],
    ["12", 1200],
    ["12,5", 1250],
    ["12.5", 1250],
    ["0,01", 1],
    ["0,10", 10],
    ["007,05", 705],
    ["  54,90 ", 5490],
    ["1 234,56", 123456],
    ["1 234,56", 123456],
    ["1 234,56", 123456],
    ["999 999,99", 99_999_999],
    ["12 345 678", 1_234_567_800],
    ["12\u00a0345\u202f678,9", 1_234_567_890],
    [" \u00a01 234 \t", 123_400],
    ["0", 0],
    ["12345678901", 1_234_567_890_100],
  ])("turns %j into %d grosze", (text, grosze) => {
    expect(parseGross(text)).toEqual({ ok: true, grosze });
  });

  it.each([
    ["", "Podaj kwotę brutto."],
    ["   ", "Podaj kwotę brutto."],
    ["12,345", "Kwota może mieć najwyżej 2 miejsca po przecinku."],
    ["0.001", "Kwota może mieć najwyżej 2 miejsca po przecinku."],
    ["-12,34", "Kwota nie może być ujemna."],
    ["- 5", "Kwota nie może być ujemna."],
    ["12zł", "Wpisz kwotę w złotych, np. 54,90."],
    ["abc", "Wpisz kwotę w złotych, np. 54,90."],
    ["12,3a", "Wpisz kwotę w złotych, np. 54,90."],
    ["1e3", "Wpisz kwotę w złotych, np. 54,90."],
    ["+12", "Wpisz kwotę w złotych, np. 54,90."],
    ["12,", "Wpisz kwotę w złotych, np. 54,90."],
    [",50", "Wpisz kwotę w złotych, np. 54,90."],
    ["1.234,56", "Wpisz kwotę w złotych, np. 54,90."],
    ["1,2,3", "Wpisz kwotę w złotych, np. 54,90."],
    ["0x10", "Wpisz kwotę w złotych, np. 54,90."],
    ["١٢", "Wpisz kwotę w złotych, np. 54,90."],
    ["99999999999999999", "Kwota jest za duża."],
    ["5 99", "Wpisz kwotę w złotych, np. 54,90."],
    ["12 34", "Wpisz kwotę w złotych, np. 54,90."],
    ["1  234", "Wpisz kwotę w złotych, np. 54,90."],
    ["1 2345", "Wpisz kwotę w złotych, np. 54,90."],
    ["1234 567", "Wpisz kwotę w złotych, np. 54,90."],
    ["1 234 ,56", "Wpisz kwotę w złotych, np. 54,90."],
    ["1 234,5 6", "Wpisz kwotę w złotych, np. 54,90."],
    ["1 234,567", "Kwota może mieć najwyżej 2 miejsca po przecinku."],
  ])("rejects %j", (text, message) => {
    expect(parseGross(text)).toEqual({ ok: false, message });
  });
});
