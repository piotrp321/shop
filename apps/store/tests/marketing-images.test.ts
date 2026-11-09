import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import sharp from "sharp";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { MARKETING_IMAGES, marketingFileName, marketingHeight } from "../src/home/marketing-images";

const SCRIPT = new URL("../scripts/marketing-images.mjs", import.meta.url).pathname;
const REAL_LIST = new URL("../src/home/marketing-images.ts", import.meta.url).pathname;

let dir: string;
let source: string;
let out: string;

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), "marketing-images-"));
  source = join(dir, "source");
  out = join(dir, "out");
  mkdirSync(source);
});
afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

function run(env: Record<string, string>) {
  const result = spawnSync(process.execPath, [SCRIPT], {
    env: { ...process.env, MARKETING_IMAGES_SOURCE: source, MARKETING_IMAGES_OUT: out, ...env },
    encoding: "utf8",
    timeout: 120_000,
  });
  return { code: result.status, out: result.stdout, err: result.stderr };
}

/** A list file of the test: the entries are written as JSON, the helpers come from the real list. */
function writeList(images: unknown[]): string {
  const path = join(dir, "list.ts");
  writeFileSync(
    path,
    `import { WEBP_QUALITY, marketingFileName, marketingHeight } from ${JSON.stringify(REAL_LIST)};
export { WEBP_QUALITY, marketingFileName, marketingHeight };
export const MARKETING_IMAGES = ${JSON.stringify(images)};
`,
  );
  return path;
}

const entry = (id: string, sourceFile: string, crop: Record<string, unknown>) => ({
  id,
  source: sourceFile,
  alt: "",
  crops: { c: { aspect: [4, 3], position: [50, 50], widths: [400], budgetKb: { 400: 200 }, ...crop } },
});

async function flatPhoto(name: string, width: number, height: number) {
  await sharp({ create: { width, height, channels: 3, background: "#808080" } }).jpeg().toFile(join(source, name));
}

async function noisyPhoto(name: string, width: number, height: number) {
  await sharp({ create: { width, height, channels: 3, background: "#808080", noise: { type: "gaussian", mean: 128, sigma: 60 } } }).jpeg({ quality: 95 }).toFile(join(source, name));
}

describe("marketing-images.mjs on the error path", () => {
  it("exits 2 and says so when sharp cannot be loaded", () => {
    const { code, err } = run({ MARKETING_IMAGES_SHARP: "sharp-that-is-not-installed" });
    expect(code).toBe(2);
    expect(err).toMatch(/sharp/);
    expect(err).toMatch(/cannot be loaded/);
    expect(existsSync(out)).toBe(false);
  });

  it("exits 2 and names the mount when the source directory is missing", () => {
    const { code, err } = run({ MARKETING_IMAGES_SOURCE: join(dir, "no-such-mount") });
    expect(code).toBe(2);
    expect(err).toMatch(/source directory .*no-such-mount.* is missing/);
    expect(err).toContain("/fixtures/images:ro");
    expect(existsSync(out)).toBe(false);
  });

  it("exits 2 and names the file when a source file is missing, writing nothing", () => {
    const list = writeList([entry("a", "absent.jpg", {})]);
    const { code, err } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(2);
    expect(err).toMatch(/source file absent\.jpg is not in/);
    expect(existsSync(out)).toBe(false);
  });

  it("exits 1 when a width is wider than the crop (the photo would be enlarged), writing nothing", async () => {
    await flatPhoto("small.jpg", 800, 600);
    const list = writeList([entry("a", "small.jpg", { widths: [1000], budgetKb: { 1000: 500 } })]);
    const { code, err } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(1);
    expect(err).toMatch(/a-c-1000\.webp: the width 1000 is wider than the crop of small\.jpg \(800 px\)/);
    expect(existsSync(out)).toBe(false);
  });

  it("measures the width against the crop, not the photo: a 4:5 crop of a 1600x1066 photo is 852 px wide", async () => {
    await flatPhoto("wide.jpg", 1600, 1066);
    const list = writeList([entry("a", "wide.jpg", { aspect: [4, 5], widths: [828, 900], budgetKb: { 828: 500, 900: 500 } })]);
    const { code, err } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(1);
    expect(err).toMatch(/a-c-900\.webp: the width 900 is wider than the crop .* \(852 px\)/);
    expect(err).not.toMatch(/a-c-828/);
  });

  it("exits 1 when a file is over its budget, naming the file, writing nothing", async () => {
    await noisyPhoto("noisy.jpg", 1000, 1000);
    const list = writeList([entry("a", "noisy.jpg", { aspect: [1, 1], widths: [800], budgetKb: { 800: 1 } })]);
    const { code, err } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(1);
    expect(err).toMatch(/a-c-800\.webp: [\d.]+ KB is over the budget of 1 KB/);
    expect(existsSync(out)).toBe(false);
  });

  it("writes nothing at all when only the second image of the list is wrong", async () => {
    await flatPhoto("good.jpg", 800, 600);
    await flatPhoto("small.jpg", 300, 200);
    const list = writeList([entry("good", "good.jpg", {}), entry("bad", "small.jpg", {})]);
    const { code } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(1);
    expect(existsSync(out)).toBe(false);
  });

  it("exits 1 for a width without a budget", async () => {
    await flatPhoto("good.jpg", 800, 600);
    const list = writeList([entry("a", "good.jpg", { widths: [400], budgetKb: {} })]);
    const { code, err } = run({ MARKETING_IMAGES_LIST: list });
    expect(code).toBe(1);
    expect(err).toMatch(/no budget for the width 400/);
  });

  it("exits 1 when the list cannot be read", () => {
    const { code, err } = run({ MARKETING_IMAGES_LIST: join(dir, "missing-list.ts") });
    expect(code).toBe(1);
    expect(err).toMatch(/the list .* cannot be read/);
  });
});

describe("marketing-images.mjs on the real list and photos", () => {
  // Needs the mount of the demo photos at /fixtures/images (the `store` container of the stack has it).
  it("writes every file of the list within its budget and gives the same names and dimensions on a second run", async () => {
    const photos = process.env.MARKETING_IMAGES_REAL_SOURCE ?? "/fixtures/images";
    const real = { MARKETING_IMAGES_SOURCE: photos, MARKETING_IMAGES_LIST: REAL_LIST };

    const describeOutput = async () => {
      const names = readdirSync(join(out, "images")).sort();
      const dimensions = await Promise.all(
        names.map(async (name) => {
          const meta = await sharp(join(out, "images", name)).metadata();
          return `${name} ${meta.width}x${meta.height} ${meta.format}`;
        }),
      );
      return dimensions;
    };

    const first = run(real);
    expect(first.err).toBe("");
    expect(first.code).toBe(0);
    const firstResult = await describeOutput();
    const second = run(real);
    expect(second.code).toBe(0);
    expect(await describeOutput()).toEqual(firstResult);

    const expected = MARKETING_IMAGES.flatMap((image) =>
      Object.entries(image.crops).flatMap(([cropName, crop]) =>
        crop.widths.map((width) => `${marketingFileName(image.id, cropName, width)} ${width}x${marketingHeight(crop, width)} webp`),
      ),
    ).sort();
    expect(firstResult).toEqual(expected);
    expect(existsSync(join(out, "images.generated.ts"))).toBe(true);
  }, 240_000);

  it("removes a file of an earlier list that is no longer wanted", async () => {
    await flatPhoto("good.jpg", 800, 600);
    mkdirSync(join(out, "images"), { recursive: true });
    writeFileSync(join(out, "images", "old-crop-100.webp"), "stale");
    writeFileSync(join(out, "images", "keep.txt"), "not an image");
    const list = writeList([entry("a", "good.jpg", {})]);
    expect(run({ MARKETING_IMAGES_LIST: list }).code).toBe(0);
    expect(readdirSync(join(out, "images")).sort()).toEqual(["a-c-400.webp", "keep.txt"]);
  });
});
