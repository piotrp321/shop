// Generates the marketing photos of the home page from the list in `src/home/marketing-images.ts`: for every image and
// crop the WebP files of every width in `src/home/images/` and the module `src/home/images.generated.ts` with their
// static imports. Run it in the `store` container (`make -C apps/store shell`, then `node scripts/marketing-images.mjs`);
// the sources are the demo photos mounted read-only at /fixtures/images (infra/docker-compose.yml). Uses `sharp`, which
// Next.js installs. Everything is checked and encoded in memory first; only when nothing is wrong are the files written,
// so a failed run changes nothing.
// Exit codes: 0 done; 1 the list or the result is wrong (a width wider than the crop would enlarge the photo, a file
// over its budget); 2 the environment is wrong (no `sharp`, no source directory or file).
// Variables for the tests: MARKETING_IMAGES_LIST (the list), MARKETING_IMAGES_SOURCE (the photos, default
// /fixtures/images), MARKETING_IMAGES_OUT (where `images/` and `images.generated.ts` go, default src/home) and
// MARKETING_IMAGES_SHARP (the module that stands for `sharp`).
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readdirSync, statSync, unlinkSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const listPath = resolve(process.env.MARKETING_IMAGES_LIST ?? join(here, "../src/home/marketing-images.ts"));
const sourceDir = resolve(process.env.MARKETING_IMAGES_SOURCE ?? "/fixtures/images");
const outDir = resolve(process.env.MARKETING_IMAGES_OUT ?? join(here, "../src/home"));
const sharpModule = process.env.MARKETING_IMAGES_SHARP ?? "sharp";

const ENVIRONMENT = 2;
const RESULT = 1;

function fail(code, problems) {
  console.error(`marketing-images: ${problems.length === 1 ? "problem" : `${problems.length} problems`}, nothing was written:`);
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(code);
}

let sharp;
try {
  sharp = createRequire(import.meta.url)(sharpModule);
} catch {
  fail(ENVIRONMENT, [`the module "${sharpModule}" (sharp) cannot be loaded: run the script in the store container, where Next.js installs it (npm ci).`]);
}

let list;
try {
  list = await import(pathToFileURL(listPath).href);
} catch (error) {
  fail(RESULT, [`the list ${listPath} cannot be read: ${error instanceof Error ? error.message : String(error)}`]);
}

if (!existsSync(sourceDir) || !statSync(sourceDir).isDirectory()) {
  fail(ENVIRONMENT, [
    `the source directory ${sourceDir} is missing: the store container needs the mount ./apps/api/fixtures/images:/fixtures/images:ro (infra/docker-compose.yml); recreate it with "docker compose up -d store".`,
  ]);
}

const { MARKETING_IMAGES, WEBP_QUALITY, marketingFileName, marketingHeight } = list;

const problems = [];
const note = (code, message) => problems.push({ code, message });
const files = []; // { image, cropName, crop, width, height, name, data }

for (const image of MARKETING_IMAGES) {
  const sourcePath = join(sourceDir, image.source);
  if (!existsSync(sourcePath)) {
    note(ENVIRONMENT, `${image.id}: the source file ${image.source} is not in ${sourceDir}.`);
    continue;
  }
  const meta = await sharp(sourcePath).metadata();
  // EXIF orientation 5-8 turns the photo by a quarter, so the sides swap.
  const turned = (meta.orientation ?? 1) >= 5;
  const sourceWidth = turned ? meta.height : meta.width;
  const sourceHeight = turned ? meta.width : meta.height;

  for (const [cropName, crop] of Object.entries(image.crops)) {
    const [aw, ah] = crop.aspect;
    // The largest rectangle of the aspect that fits in the source.
    const cropWidth = sourceWidth * ah <= sourceHeight * aw ? sourceWidth : Math.floor((sourceHeight * aw) / ah);
    const cropHeight = Math.floor((cropWidth * ah) / aw);
    const left = Math.round(((sourceWidth - cropWidth) * crop.position[0]) / 100);
    const top = Math.round(((sourceHeight - cropHeight) * crop.position[1]) / 100);
    for (const width of crop.widths) {
      const name = marketingFileName(image.id, cropName, width);
      if (width > cropWidth) {
        note(RESULT, `${name}: the width ${width} is wider than the crop of ${image.source} (${cropWidth} px): the photo would be enlarged.`);
        continue;
      }
      const height = marketingHeight(crop, width);
      const data = await sharp(sourcePath)
        .rotate()
        .extract({ left, top, width: cropWidth, height: cropHeight })
        .resize({ width, height, fit: "fill" })
        .webp({ quality: crop.quality ?? WEBP_QUALITY, effort: 6 })
        .toBuffer();
      const budgetKb = crop.budgetKb[width];
      if (budgetKb === undefined) {
        note(RESULT, `${name}: the list has no budget for the width ${width}.`);
        continue;
      }
      if (data.length > budgetKb * 1024) {
        note(RESULT, `${name}: ${(data.length / 1024).toFixed(1)} KB is over the budget of ${budgetKb} KB.`);
        continue;
      }
      files.push({ image, cropName, crop, width, height, name, data });
    }
  }
}

if (problems.length > 0) {
  fail(
    Math.max(...problems.map((problem) => problem.code)),
    problems.map((problem) => problem.message),
  );
}

// Nothing is wrong: write. Files of the earlier lists that are no longer in this one go.
const imagesDir = join(outDir, "images");
mkdirSync(imagesDir, { recursive: true });
const wanted = new Set(files.map((file) => file.name));
for (const stale of readdirSync(imagesDir)) {
  if (stale.endsWith(".webp") && !wanted.has(stale)) unlinkSync(join(imagesDir, stale));
}
for (const file of files) {
  writeFileSync(join(imagesDir, file.name), file.data);
  console.log(`${file.name}  ${file.width}x${file.height}  ${(file.data.length / 1024).toFixed(1)} KB`);
}

const identifier = (name) => `i_${name.replace(/[^A-Za-z0-9]/g, "_")}`;
const lines = [
  "// Generated by scripts/marketing-images.mjs from marketing-images.ts. Do not edit: change the list and run the script.",
  'import type { GeneratedImages } from "./marketing-images";',
  ...files.map((file) => `import ${identifier(file.name)} from "./images/${file.name}";`),
  "",
  "export const generatedImages: GeneratedImages = {",
];
for (const image of MARKETING_IMAGES) {
  lines.push(`  ${JSON.stringify(image.id)}: {`);
  for (const cropName of Object.keys(image.crops)) {
    lines.push(`    ${JSON.stringify(cropName)}: [`);
    for (const file of files.filter((candidate) => candidate.image === image && candidate.cropName === cropName)) {
      lines.push(`      { file: ${JSON.stringify(file.name)}, width: ${file.width}, height: ${file.height}, image: ${identifier(file.name)} },`);
    }
    lines.push("    ],");
  }
  lines.push("  },");
}
lines.push("};", "");
writeFileSync(join(outDir, "images.generated.ts"), lines.join("\n"));
console.log(`marketing-images: ${files.length} files written to ${imagesDir}`);
