// The marketing photos of the home page: the one place that says which source file becomes which crops and widths.
// Plain data without imports of files (`scripts/marketing-images.mjs` reads it with Node, which strips the types):
// the script turns it into the WebP files in `src/home/images/` and `images.generated.ts`.

import type { StaticImageData } from "next/image";

/** A crop of the source photo that the script resizes to every width of `widths`. */
export type MarketingCrop = {
  /** Width : height of the crop. The crop is the largest rectangle of this shape that fits in the source. */
  aspect: readonly [number, number];
  /**
   * Where the crop sits in the source and which part of the file stays visible when CSS `object-fit: cover` crops it
   * further, in percent like `object-position` (horizontal, vertical).
   */
  position: readonly [number, number];
  /** Output widths in pixels, ascending. A width wider than the crop would enlarge the source, so the script refuses it. */
  widths: readonly number[];
  /** WebP quality (0-100) when the photo needs less than `WEBP_QUALITY` to fit the budgets (a detailed hero). */
  quality?: number;
  /** The largest size of the file of each width in kilobytes (1 KB = 1024 bytes); the script refuses a larger file. */
  budgetKb: Readonly<Record<number, number>>;
};

export type MarketingImage = {
  /** Names the files (`<id>-<crop>-<width>.webp`) and the entry of `images.generated.ts`. */
  id: string;
  /** The file in `apps/api/fixtures/images/`. */
  source: string;
  /** The text alternative; empty when the photo is decoration next to a text (the tile of a department). */
  alt: string;
  /** By the name of the crop; the name is part of the file names. */
  crops: Readonly<Record<string, MarketingCrop>>;
};

/** The quality of the WebP files (0-100) unless the crop says otherwise. */
export const WEBP_QUALITY = 75;

const TILE: Omit<MarketingCrop, "position"> = {
  aspect: [3, 4],
  widths: [320, 480, 768],
  budgetKb: { 320: 25, 480: 50, 768: 80 },
};

export const MARKETING_IMAGES: readonly MarketingImage[] = [
  {
    id: "hero",
    source: "hikers-trail-toward-peaks-chile.jpg",
    alt: "Turyści z plecakami idący szlakiem w stronę szczytów",
    crops: {
      wide: { aspect: [16, 9], position: [68, 42], quality: 66, widths: [1280, 1600], budgetKb: { 1280: 170, 1600: 250 } },
      phone: { aspect: [4, 5], position: [72, 40], quality: 55, widths: [480, 640, 828], budgetKb: { 480: 55, 640: 85, 828: 120 } },
    },
  },
  { id: "dept-bieganie", source: "runner-road-fitz-roy.jpg", alt: "", crops: { tile: { ...TILE, position: [48, 50] } } },
  { id: "dept-rower", source: "cyclist-mountain-road-romania.jpg", alt: "", crops: { tile: { ...TILE, position: [85, 50] } } },
  { id: "dept-turystyka", source: "hiker-yellow-backpack-meadow.jpg", alt: "", crops: { tile: { ...TILE, position: [50, 50] } } },
  { id: "dept-sporty-zimowe", source: "skier-val-disere.jpg", alt: "", crops: { tile: { ...TILE, position: [62, 50] } } },
  { id: "dept-fitness", source: "yoga-rock-mountains.jpg", alt: "", crops: { tile: { ...TILE, position: [50, 62] } } },
  { id: "dept-sporty-wodne", source: "kayak-lake-mountains.jpg", alt: "", crops: { tile: { ...TILE, position: [30, 50] } } },
  { id: "dept-sporty-druzynowe", source: "soccer-ball-field-blue.jpg", alt: "", crops: { tile: { ...TILE, position: [50, 50] } } },
];

/** The file of one width of a crop. */
export const marketingFileName = (id: string, crop: string, width: number): string => `${id}-${crop}-${width}.webp`;

/** The height of the crop at `width`, as the script and the page both compute it. */
export const marketingHeight = (crop: Pick<MarketingCrop, "aspect">, width: number): number => Math.round((width * crop.aspect[1]) / crop.aspect[0]);

/** One generated file as `images.generated.ts` lists it: the import of the bundler (a hashed URL under /_next/static/media). */
export type GeneratedFile = { file: string; width: number; height: number; image: StaticImageData };

/** The shape of `images.generated.ts`: by image id, then by crop name, files in the order of `widths`. */
export type GeneratedImages = Record<string, Record<string, readonly GeneratedFile[]>>;
