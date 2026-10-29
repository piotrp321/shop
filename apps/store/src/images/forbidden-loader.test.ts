import { describe, expect, it } from "vitest";
import forbiddenLoader from "./forbidden-loader";

describe("forbidden image loader", () => {
  it("throws when next/image asks for a URL", () => {
    expect(() => forbiddenLoader({ src: "/a.jpg", width: 256, quality: 75 })).toThrow(/ProductPhoto/);
  });
});
