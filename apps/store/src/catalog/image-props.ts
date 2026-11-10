import type { components } from "@/api/schema";

export type ImageProps = { src: string; srcSet: string; sizes: string; alt: string; width: number; height: number };

/** Attributes of an `<img srcset>` built only from the WebP variants the API returned. */
export function buildImageProps(image: components["schemas"]["ProductImage"], sizes: string): ImageProps {
  const variants = [...(image.variants.webp ?? [])].sort((a, b) => a.width - b.width);
  const largest = variants.at(-1);
  if (!largest) {
    throw new Error("The image has no webp variants.");
  }

  const ratio = /^(\d+):(\d+)$/.exec(image.aspectRatio);
  const ratioWidth = Number(ratio?.[1]);
  const ratioHeight = Number(ratio?.[2]);
  if (!ratio || ratioWidth === 0 || ratioHeight === 0) {
    throw new Error(`Invalid image aspect ratio "${image.aspectRatio}".`);
  }

  return {
    src: largest.url,
    srcSet: variants.map((variant) => `${variant.url} ${variant.width}w`).join(", "),
    sizes,
    alt: image.alt,
    width: largest.width,
    height: Math.round((largest.width * ratioHeight) / ratioWidth),
  };
}

/** The widest WebP variant of the image (the one for Open Graph and JSON-LD), or null when the image has none. */
export function largestVariant(image: components["schemas"]["ProductImage"]): { url: string; width: number; height: number } | null {
  if (!image.variants.webp?.length) {
    return null;
  }
  const { src, width, height } = buildImageProps(image, "");
  return { url: src, width, height };
}

type ZoomImage = components["schemas"]["ZoomImage"];

/** Attributes of an `<img srcset>` for the enlargement, from the WebP variants of the `zoom` size (no aspect ratio, so no width and height). */
export function buildZoomImageProps(image: ZoomImage, sizes: string): { src: string; srcSet: string; sizes: string; alt: string } | null {
  const variants = [...(image.variants.webp ?? [])].sort((a, b) => a.width - b.width);
  const largest = variants.at(-1);
  if (!largest) {
    return null;
  }
  return { src: largest.url, srcSet: variants.map((variant) => `${variant.url} ${variant.width}w`).join(", "), sizes, alt: image.alt };
}

/** The narrowest WebP variant of the image (for a thumbnail), or null when the image has none. */
export function smallestVariant(image: components["schemas"]["ProductImage"]): { url: string; width: number; height: number } | null {
  const [narrowest] = [...(image.variants.webp ?? [])].sort((a, b) => a.width - b.width);
  if (!narrowest) {
    return null;
  }
  const { width, height } = buildImageProps(image, "");
  return { url: narrowest.url, width: narrowest.width, height: Math.round((narrowest.width * height) / width) };
}
