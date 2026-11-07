import type { ImageVariants } from "./useImageUpload";

export interface ThumbnailSources {
  src: string;
  srcset: string;
}

/**
 * Picks the URLs of an `<img>` from the signed variants the API gave: WebP when there is one, else the first format.
 * `src` is the smallest width and `srcset` lists all of them, so the browser takes the sharper one on dense screens.
 */
export function thumbnailSources(variants: ImageVariants): ThumbnailSources | null {
  const entries = variants.webp?.length ? variants.webp : Object.values(variants).find((candidates) => candidates.length > 0);
  if (entries === undefined) {
    return null;
  }
  return { src: entries[0]!.url, srcset: entries.map((entry) => `${entry.url} ${entry.width}w`).join(", ") };
}
