"use client";

import { ChevronLeftIcon, ChevronRightIcon, SearchIcon, XIcon } from "lucide-react";
import { useLayoutEffect, useRef, useState, useSyncExternalStore, type KeyboardEvent } from "react";
import type { components } from "@/api/schema";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { buildZoomImageProps, smallestVariant } from "./image-props";
import { PhotoPlaceholder, ProductPhoto } from "./ProductPhoto";
import { usePhotoStrip } from "./use-photo-strip";

type ProductImage = components["schemas"]["ProductImage"];
type ZoomImage = components["schemas"]["ZoomImage"];
type Photo = { image: ProductImage; zoom: ZoomImage | undefined };

// The stage is the whole width of the page below `lg`; from `lg` the page is two columns (7:5, a gap of 72 px, the
// thumbnails 84 px and a gap of 16 px on the left of the stage) in a content of at most 90rem with a margin of 3rem.
const STAGE_SIZES = "(min-width: 1440px) 642px, (min-width: 1024px) calc((100vw - 10.5rem) * 7 / 12 - 6.25rem), calc(100vw - 2rem)";

// Every photo of the enlargement is as wide as the window, so the browser picks the file for the screen: a phone never
// asks for the 2560 px one.
const ZOOM_SIZES = "100vw";

const noopSubscribe = () => () => {};

/** False in the HTML of the server and in the first render of the browser, true after hydration: what only JavaScript can do appears then. */
function useHydrated(): boolean {
  return useSyncExternalStore(noopSubscribe, () => true, () => false);
}

const arrowButton = "absolute top-1/2 -translate-y-1/2 rounded-full border-input bg-card text-foreground hover:bg-muted";

function Arrow({ direction, onClick, className }: { direction: "previous" | "next"; onClick: () => void; className?: string }) {
  const Icon = direction === "previous" ? ChevronLeftIcon : ChevronRightIcon;
  return (
    <Button type="button" variant="outline" size="icon" className={cn(arrowButton, className)} aria-label={direction === "previous" ? "Poprzednie zdjęcie" : "Następne zdjęcie"} onClick={onClick}>
      <Icon aria-hidden />
    </Button>
  );
}

const stripClasses = "flex overflow-x-auto overscroll-x-contain snap-x snap-mandatory [scrollbar-width:none] [&::-webkit-scrollbar]:hidden";

/**
 * The gallery of the product page. The HTML of the server holds all of it and works without JavaScript: a strip of the
 * photos (CSS scroll-snap, so a finger swipes it), a counter and thumbnails that are links to the photos in the strip.
 * After hydration JavaScript adds what only it can do: the current thumbnail (`aria-current`), the counter following the
 * strip, the previous and next buttons (absolutely placed, so the layout does not move), smooth scrolling from the
 * thumbnails without a change of the address, the arrow keys, and the enlargement of a photo in a dialog with the photos
 * of the `zoom` size. Nothing here depends on the visitor, so the server can keep the HTML in the ISR cache.
 */
export function ProductGallery({ name, images, zoomImages }: { name: string; images: ProductImage[]; zoomImages: ZoomImage[] }) {
  const hydrated = useHydrated();
  // The photos with a usable variant, each with its twin of the `zoom` size (the API gives them in the same order).
  const photos: Photo[] = images.map((image, position) => ({ image, zoom: zoomImages[position] })).filter(({ image }) => image.variants.webp?.length);
  const count = photos.length;
  const { ref: stripRef, index, goTo, onScroll, neighbour, arrowTarget } = usePhotoStrip(count);
  const [zoomStart, setZoomStart] = useState<number | null>(null);
  const opener = useRef<HTMLElement | null>(null);

  if (count === 0) {
    return <PhotoPlaceholder />;
  }

  const zoomable = photos.every(({ zoom }) => zoom?.variants.webp?.length);
  const several = count > 1;

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const slide = (event.target as HTMLElement).closest<HTMLElement>("[data-photo]");
    const target = slide === null ? null : arrowTarget(event, Number(slide.dataset.photo));
    if (target === null) return;
    event.preventDefault();
    goTo(target);
    document.getElementById(`zdjecie-${target + 1}`)?.querySelector("button")?.focus({ preventScroll: true });
  }

  return (
    <div className="flex flex-col gap-3 [reading-flow:flex-visual] lg:flex-row lg:gap-4">
      <div className="relative min-w-0 lg:order-2 lg:flex-1">
        {/* The arrow keys of the photo buttons inside bubble up to the strip. */}
        <div ref={stripRef} role="group" aria-label="Zdjęcia produktu" className={stripClasses} onScroll={onScroll} onKeyDown={onKeyDown}>
          {photos.map(({ image }, position) => {
            const photo = <ProductPhoto image={image} sizes={STAGE_SIZES} priority={position === 0} className="h-full rounded-md" />;
            return (
              <div key={position} id={`zdjecie-${position + 1}`} data-photo={position} className="aspect-square w-full shrink-0 snap-center">
                {zoomable ? (
                  <button
                    type="button"
                    aria-haspopup="dialog"
                    aria-label={`Powiększ zdjęcie ${position + 1} z ${count}: ${image.alt}`}
                    className="relative block size-full cursor-zoom-in focus-visible:-outline-offset-3"
                    onClick={(event) => {
                      opener.current = event.currentTarget;
                      setZoomStart(position);
                    }}
                  >
                    {photo}
                    <span
                      aria-hidden="true"
                      className="absolute right-2.5 bottom-2.5 inline-flex min-h-11 items-center gap-2 rounded-md border border-input bg-card px-3.5 text-sm font-semibold text-foreground"
                    >
                      <SearchIcon className="size-4.5" />
                      Powiększ
                    </span>
                  </button>
                ) : (
                  photo
                )}
              </div>
            );
          })}
        </div>
        {several && (
          <p aria-live="polite" className="pointer-events-none absolute bottom-2.5 left-2.5 rounded-md bg-inverse px-3 py-2 text-xs leading-none font-semibold text-inverse-foreground">
            {`${index + 1} / ${count}`}
          </p>
        )}
        {several && hydrated && (
          <>
            <Arrow direction="previous" className="left-2.5" onClick={() => goTo(neighbour(index, -1))} />
            <Arrow direction="next" className="right-2.5" onClick={() => goTo(neighbour(index, 1))} />
          </>
        )}
      </div>
      {several && (
        <ul aria-label="Miniatury zdjęć" className="flex gap-2.5 overflow-x-auto p-1 lg:order-1 lg:flex-col lg:overflow-visible">
          {photos.map(({ image }, position) => {
            const thumb = smallestVariant(image)!;
            return (
              <li key={position} className="shrink-0">
                <a
                  href={`#zdjecie-${position + 1}`}
                  aria-label={`Pokaż zdjęcie ${position + 1} z ${count}`}
                  aria-current={hydrated && position === index ? "true" : undefined}
                  className="block size-18 overflow-hidden rounded-md border border-input focus-visible:outline-3 focus-visible:outline-offset-3 aria-[current=true]:outline-2 aria-[current=true]:outline-offset-2 aria-[current=true]:outline-foreground lg:size-21"
                  onClick={(event) => {
                    event.preventDefault();
                    goTo(position);
                  }}
                >
                  <img src={thumb.url} alt="" width={thumb.width} height={thumb.height} loading="lazy" decoding="async" className="size-full object-cover" />
                </a>
              </li>
            );
          })}
        </ul>
      )}
      {zoomable && zoomStart !== null && (
        <Dialog open onOpenChange={(open) => !open && setZoomStart(null)}>
          <ZoomView
            name={name}
            zooms={photos.map(({ zoom }) => zoom!)}
            start={zoomStart}
            onCloseAutoFocus={(event) => {
              // Safari does not focus a button on a click, so the dialog cannot rely on remembering the focused element.
              event.preventDefault();
              opener.current?.focus();
            }}
          />
        </Dialog>
      )}
    </div>
  );
}

/** The inside of the enlargement: the counter and „Zamknij” on top, the whole photos (`object-fit: contain`) in a strip, the alt text of the photo under it. */
function ZoomView({ name, zooms, start, onCloseAutoFocus }: { name: string; zooms: ZoomImage[]; start: number; onCloseAutoFocus: (event: Event) => void }) {
  const count = zooms.length;
  const several = count > 1;
  const { ref, index, goTo, onScroll, neighbour, arrowTarget } = usePhotoStrip(count, start);

  // The strip opens on the photo that was visible, without an animation.
  useLayoutEffect(() => {
    if (ref.current) ref.current.scrollLeft = start * ref.current.clientWidth;
  }, [ref, start]);

  return (
    <DialogContent
      showCloseButton={false}
      aria-describedby={undefined}
      data-surface="inverse"
      className="top-0 left-0 h-dvh w-screen max-w-none translate-x-0 translate-y-0 grid-rows-[auto_minmax(0,1fr)_auto] gap-0 rounded-none border-0 bg-inverse p-0 text-inverse-foreground sm:max-w-none"
      onCloseAutoFocus={onCloseAutoFocus}
      onKeyDown={(event) => {
        // The arrow keys work wherever the focus is in the window.
        const target = arrowTarget(event, index);
        if (target === null) return;
        event.preventDefault();
        goTo(target);
      }}
    >
      <DialogTitle className="sr-only">{name}</DialogTitle>
      <div className="flex items-center justify-between gap-4 px-3.5 py-2.5">
        {several ? (
          <p aria-live="polite" className="text-sm font-semibold">{`${index + 1} / ${count}`}</p>
        ) : (
          <span />
        )}
        <DialogClose asChild>
          <Button type="button" variant="outline" className="border-inverse-foreground text-inverse-foreground hover:bg-inverse-border">
            <XIcon aria-hidden />
            Zamknij
          </Button>
        </DialogClose>
      </div>
      <div className="relative min-h-0">
        <div ref={ref} role="group" aria-label="Powiększone zdjęcia" className={cn(stripClasses, "h-full")} onScroll={onScroll}>
          {zooms.map((zoom, position) => {
            const props = buildZoomImageProps(zoom, ZOOM_SIZES)!;
            return (
              <div key={position} className="size-full shrink-0 snap-center px-3.5">
                <img {...props} alt={props.alt} loading={position === start ? "eager" : "lazy"} decoding="async" className="size-full object-contain" />
              </div>
            );
          })}
        </div>
        {several && (
          <>
            <Arrow direction="previous" className="left-3.5" onClick={() => goTo(neighbour(index, -1))} />
            <Arrow direction="next" className="right-3.5" onClick={() => goTo(neighbour(index, 1))} />
          </>
        )}
      </div>
      <p aria-hidden="true" className="px-3.5 pt-2.5 pb-3.5 text-center text-sm text-inverse-muted">
        {zooms[index]?.alt}
      </p>
    </DialogContent>
  );
}
