// @vitest-environment node
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import notices from "../THIRD_PARTY_NOTICES.md?raw";

const tokens = readFileSync(new URL("../src/styles/tokens.css", import.meta.url), "utf8");

// `?inline` gives the bytes of a file as a base64 data URL.
const fonts = import.meta.glob<string>("../src/assets/fonts/*.woff2", { query: "?inline", import: "default", eager: true });

const FILES = ["nunito-latin-wght.woff2", "nunito-latin-ext-wght.woff2"];

async function sha256(dataUrl: string): Promise<string> {
  const bytes = Uint8Array.from(atob(dataUrl.slice(dataUrl.indexOf(",") + 1)), (char) => char.charCodeAt(0));
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

describe("Nunito files of the panel", () => {
  it("the panel has exactly the two subsets, latin and latin-ext", () => {
    expect(Object.keys(fonts).map((path) => path.split("/").at(-1)).sort()).toEqual([...FILES].sort());
  });

  it.each(FILES)("%s is loaded by tokens.css", (file) => {
    expect(tokens).toContain(`../assets/fonts/${file}`);
  });

  it.each(FILES)("THIRD_PARTY_NOTICES.md lists the SHA-256 of %s", async (file) => {
    const dataUrl = fonts[`../src/assets/fonts/${file}`];
    expect(dataUrl).toBeDefined();
    expect(notices).toContain(file);
    expect(notices).toContain(await sha256(dataUrl!));
  });

  it("THIRD_PARTY_NOTICES.md names the licence, the source address and the version of the font", () => {
    expect(notices).toContain("SIL Open Font License");
    expect(notices).toContain("https://fonts.googleapis.com/css2?family=Nunito:wght@400..800");
    expect(notices).toMatch(/fonts\.gstatic\.com\/s\/nunito\/v\d+\//);
  });

  it("declares font-display: swap and the weights 400-800, and requests nothing from an external host", () => {
    expect(tokens).toMatch(/font-display:\s*swap/);
    expect(tokens).toMatch(/font-weight:\s*400 800/);
    expect(tokens).not.toMatch(/url\(\s*["']?https?:/);
  });
});
