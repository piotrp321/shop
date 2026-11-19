import { describe, expect, it } from "vitest";
import nextConfig from "../next.config";

// Spec: docs/features/product-page/spec.md, "Cache ISR". The incremental cache (pages, 404 pages included, and the fetch
// cache) lives only in the memory of the process, bounded by a constant, and is never written to the disk of the pod.
describe("ISR cache of the store", () => {
  it("is never flushed to disk", () => {
    expect(nextConfig.experimental?.isrFlushToDisk).toBe(false);
  });

  it("is bounded to 128 MB of memory", () => {
    expect(nextConfig.cacheMaxMemorySize).toBe(128 * 1024 * 1024);
  });
});
