import { generatedImages } from "./images.generated";

export type PhotoAttributes = { src: string; srcSet: string; width: number; height: number };

/**
 * The attributes of an `<img>` or a `<source>` for a crop of a marketing photo: `srcset` of all its widths (the
 * addresses the bundler gave the files), and the widest file as `src` with its size, which the browser uses to reserve
 * the space.
 */
export function photoAttributes(id: string, crop: string): PhotoAttributes {
  const files = generatedImages[id]?.[crop];
  const widest = files?.at(-1);
  if (files === undefined || widest === undefined) {
    throw new Error(`No generated marketing image "${id}" with the crop "${crop}": run scripts/marketing-images.mjs.`);
  }
  return {
    src: widest.image.src,
    srcSet: files.map((file) => `${file.image.src} ${file.width}w`).join(", "),
    width: widest.width,
    height: widest.height,
  };
}
