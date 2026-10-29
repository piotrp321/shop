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
