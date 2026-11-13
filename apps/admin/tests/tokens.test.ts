// @vitest-environment node
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const css = readFileSync(new URL("../src/styles/tokens.css", import.meta.url), "utf8");

/** Every `--name: value;` declaration of the file, comments removed. A later declaration wins. */
function readDeclarations(source: string): Map<string, string> {
  const declarations = new Map<string, string>();
  for (const [, name, value] of source.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/(--[\w-]+)\s*:\s*([^;{}]+);/g)) {
    declarations.set(name!, value!.trim());
  }
  return declarations;
}

/** Follows `var(--other)` references down to a hex colour. */
function resolveColor(declarations: Map<string, string>, name: string, seen: string[] = []): string {
  if (seen.includes(name)) throw new Error(`Circular token reference: ${[...seen, name].join(" -> ")}`);
  const value = declarations.get(name);
  if (value === undefined) throw new Error(`Token ${name} is not declared in tokens.css`);
  const reference = /^var\((--[\w-]+)\)$/.exec(value);
  if (reference) return resolveColor(declarations, reference[1]!, [...seen, name]);
  if (!/^#[0-9a-fA-F]{6}$/.test(value)) throw new Error(`Token ${name} is not a six-digit hex colour: ${value}`);
  return value.toLowerCase();
}

function luminance(hex: string): number {
  const channel = (offset: number) => {
    const value = parseInt(hex.slice(offset, offset + 2), 16) / 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

/** WCAG 2.x contrast ratio. */
export function contrastRatio(a: string, b: string): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];
  return (light + 0.05) / (dark + 0.05);
}

const TEXT = 4.5;
const GRAPHIC = 3;

type Pair = { foreground: string; background: string; minimum: number };

const pair = (foreground: string, background: string, minimum: number): Pair => ({ foreground, background, minimum });

/** Every pair of the token table in docs/features/admin-design/spec.md (names without the leading dashes). */
const PAIRS: Pair[] = [
  // Text on the surfaces.
  pair("foreground", "background", TEXT),
  pair("foreground", "card", TEXT),
  pair("heading", "card", TEXT),
  pair("heading", "table-head", TEXT),
  pair("muted-foreground", "background", TEXT),
  pair("muted-foreground", "card", TEXT),
  pair("muted-foreground", "table-head", TEXT),
  // Interface blue: links, the selected page, the action button.
  pair("interface", "card", TEXT),
  pair("interface", "background", TEXT),
  pair("interface-foreground", "interface", TEXT),
  pair("interface-hover", "card", TEXT),
  pair("interface-foreground", "interface-hover", TEXT),
  pair("interface", "interface-soft", TEXT),
  pair("interface-hover", "interface-soft", TEXT),
  // The main button and the destructive one.
  pair("primary-foreground", "primary", TEXT),
  pair("primary-foreground", "primary-hover", TEXT),
  pair("destructive-foreground", "destructive", TEXT),
  pair("destructive", "card", TEXT),
  pair("destructive", "background", TEXT),
  // The sidebar.
  pair("sidebar-foreground", "sidebar", TEXT),
  pair("sidebar-foreground", "sidebar-accent", TEXT),
  pair("sidebar-section", "sidebar", TEXT),
  pair("sidebar-active", "sidebar", TEXT),
  pair("sidebar-active", "sidebar-accent", TEXT),
  // Status labels.
  pair("status-success", "status-success-soft", TEXT),
  pair("status-warning", "status-warning-soft", TEXT),
  pair("status-danger", "status-danger-soft", TEXT),
  pair("status-info", "status-info-soft", TEXT),
  pair("status-neutral", "status-neutral-soft", TEXT),
  // Borders of fields, selects and checkboxes (WCAG 1.4.11) and the focus rings.
  pair("input-border", "card", GRAPHIC),
  pair("focus-ring", "card", GRAPHIC),
  pair("focus-ring", "background", GRAPHIC),
  pair("focus-ring", "table-head", GRAPHIC),
  pair("focus-ring-dark", "sidebar", GRAPHIC),
  pair("focus-ring-dark", "sidebar-accent", GRAPHIC),
  // The icons of the KPI tiles and the dots of their footers.
  ...["blue", "violet", "green", "teal", "amber"].flatMap((name) => [
    pair(`kpi-${name}`, `kpi-${name}-soft`, GRAPHIC),
    pair(`kpi-${name}`, "card", GRAPHIC),
  ]),
];

/** Returns a description of every pair below its minimum. */
export function failingPairs(declarations: Map<string, string>): string[] {
  const failures: string[] = [];
  for (const { foreground, background, minimum } of PAIRS) {
    const ratio = contrastRatio(
      resolveColor(declarations, `--${foreground}`),
      resolveColor(declarations, `--${background}`),
    );
    if (ratio < minimum) {
      failures.push(`${foreground} on ${background}: ${ratio.toFixed(2)}:1, needs ${minimum}:1`);
    }
  }
  return failures;
}

const tokens = readDeclarations(css);

describe("contrast of the design tokens", () => {
  it("computes the WCAG 2.x ratio", () => {
    expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
    expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
    expect(contrastRatio("#6b7280", "#f4f5f9")).toBeCloseTo(4.44, 1);
  });

  it("every pair of the token table meets its minimum", () => {
    expect(failingPairs(tokens)).toEqual([]);
  });

  it.each(PAIRS.map((p) => [`${p.foreground} on ${p.background}`, p] as const))("%s is declared", (_name, p) => {
    expect(() => resolveColor(tokens, `--${p.foreground}`)).not.toThrow();
    expect(() => resolveColor(tokens, `--${p.background}`)).not.toThrow();
  });

  it("fails for the muted text of the mockup (#6b7280 on the background is 4.44:1)", () => {
    const changed = new Map(tokens).set("--muted-foreground", "#6b7280");
    expect(failingPairs(changed).join("\n")).toContain("muted-foreground on background");
  });

  it("fails for the field border of the mockup (#dfe2e8 on white is 1.3:1)", () => {
    const changed = new Map(tokens).set("--input-border", "#dfe2e8");
    expect(failingPairs(changed).join("\n")).toContain("input-border on card");
  });
});
