import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/catalog/load-catalog", () => ({ loadCatalog: vi.fn() }));

import { loadCatalog } from "@/catalog/load-catalog";
import Home from "./page";

const props = { searchParams: Promise.resolve({ category: "00000000-0000-4000-8000-0000000000ff" }) } as unknown as Parameters<typeof Home>[0];

beforeEach(() => {
  vi.mocked(loadCatalog).mockReset();
});

describe("Home page", () => {
  it("renders the Next.js 404 for an unknown category", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
    const failure = await Home(props).catch((error: unknown) => error);
    expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
  });

  it("does not turn an invalid filter into a 404", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    await expect(Home(props)).resolves.toBeTruthy();
  });
});
