import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({
  usePathname: () => "/szukaj",
  useSearchParams: () => new URLSearchParams(),
}));

import DefaultSearch from "../default";
import SearchSlot from "./page";

const props = (query: Record<string, string | string[] | undefined>) =>
  ({ params: Promise.resolve({}), searchParams: Promise.resolve(query) }) as unknown as PageProps<"/szukaj">;

const fieldOf = (html: string) => html.match(/<input[^>]*id="header-search"[^>]*>/)?.[0];

describe("the search slot of the header", () => {
  it("puts the phrase of /szukaj into the field of the server HTML, the first one of a repeated q", async () => {
    expect(fieldOf(renderToStaticMarkup(await SearchSlot(props({ q: "kurtki" }))))).toContain('value="kurtki"');
    expect(fieldOf(renderToStaticMarkup(await SearchSlot(props({ q: ["kurtki", "plecak"] }))))).toContain('value="kurtki"');
  });

  it("leaves the field empty without a phrase", async () => {
    expect(fieldOf(renderToStaticMarkup(await SearchSlot(props({}))))).toContain('value=""');
  });

  it("is the empty field on every other page", () => {
    expect(fieldOf(renderToStaticMarkup(<DefaultSearch />))).toContain('value=""');
  });
});
