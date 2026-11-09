import { statSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { generatedImages } from "./images.generated";
import { MARKETING_IMAGES, marketingFileName, marketingHeight } from "./marketing-images";

const IMAGES_DIR = new URL("./images/", import.meta.url).pathname;

describe("images.generated.ts", () => {
  it("has exactly the images, crops and widths of the list, with the file names and heights the list gives", () => {
    const expected = Object.fromEntries(
      MARKETING_IMAGES.map((image) => [
        image.id,
        Object.fromEntries(
          Object.entries(image.crops).map(([cropName, crop]) => [
            cropName,
            crop.widths.map((width) => ({ file: marketingFileName(image.id, cropName, width), width, height: marketingHeight(crop, width) })),
          ]),
        ),
      ]),
    );
    const actual = Object.fromEntries(
      Object.entries(generatedImages).map(([id, crops]) => [
        id,
        Object.fromEntries(Object.entries(crops).map(([cropName, files]) => [cropName, files.map(({ file, width, height }) => ({ file, width, height }))])),
      ]),
    );
    expect(actual).toEqual(expected);
  });

  it("imports the file of every entry (the address of the bundler ends with the file name)", () => {
    for (const crops of Object.values(generatedImages)) {
      for (const files of Object.values(crops)) {
        for (const { file, image } of files) expect(image.src).toBe(`/_next/static/media/${file}`);
      }
    }
  });

  it("has every file of the list in src/home/images within its budget", () => {
    for (const image of MARKETING_IMAGES) {
      for (const [cropName, crop] of Object.entries(image.crops)) {
        for (const width of crop.widths) {
          const size = statSync(`${IMAGES_DIR}${marketingFileName(image.id, cropName, width)}`).size;
          expect(size, `${image.id} ${cropName} ${width}`).toBeLessThanOrEqual(crop.budgetKb[width]! * 1024);
        }
      }
    }
  });

  it("keeps the budgets the spec names: hero 1600 250 KB, hero phone 828 120 KB, tile 768 80 KB", () => {
    const hero = MARKETING_IMAGES.find((image) => image.id === "hero")!;
    expect(hero.crops.wide!.budgetKb[1600]).toBe(250);
    expect(hero.crops.phone!.budgetKb[828]).toBe(120);
    for (const image of MARKETING_IMAGES.filter((candidate) => candidate.id !== "hero")) expect(image.crops.tile!.budgetKb[768]).toBe(80);
  });

  it("has the phone crop of the hero in the widths 480, 640, 828 and 4:5, the wide one in 1280, 1600", () => {
    const hero = MARKETING_IMAGES.find((image) => image.id === "hero")!;
    expect(hero.crops.phone).toMatchObject({ aspect: [4, 5], widths: [480, 640, 828] });
    expect(hero.crops.wide).toMatchObject({ aspect: [16, 9], widths: [1280, 1600] });
  });
});
