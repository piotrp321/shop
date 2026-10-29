import type { components } from "@/api/schema";
import { cn } from "@/lib/utils";
import { buildImageProps } from "./image-props";

type ProductImage = components["schemas"]["ProductImage"];

/** Neutral stand-in for a product without a usable photo. */
export function PhotoPlaceholder({ className }: { className?: string }) {
  return (
    <div
      role="img"
      aria-label="Brak zdjęcia"
      className={cn("flex aspect-square w-full items-center justify-center bg-muted text-sm text-muted-foreground", className)}
    >
      Brak zdjęcia
    </div>
  );
}

/**
 * Plain `<img srcset>` from the signed WebP variants the API returned. The shop signs nothing and picks no sizes
 * of its own, so `next/image` is not used.
 */
export function ProductPhoto({
  image,
  sizes,
  priority = false,
  className,
}: {
  image: ProductImage | null;
  sizes: string;
  priority?: boolean;
  className?: string;
}) {
  if (!image || !image.variants.webp?.length) {
    return <PhotoPlaceholder className={className} />;
  }
  const props = buildImageProps(image, sizes);
  return (
    <img
      {...props}
      alt={props.alt}
      loading={priority ? "eager" : "lazy"}
      fetchPriority={priority ? "high" : undefined}
      decoding="async"
      className={cn("aspect-square w-full object-cover", className)}
    />
  );
}
