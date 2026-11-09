import Link from "next/link";
import type { DepartmentTile } from "./departments";
import { photoAttributes } from "./home-images";

// The columns of the grid below: two on a phone (16 px margins, a 14 px gap), seven from `lg` (48 px margins, a 20 px
// gap, the content at most 1440 px wide, so a column is 175 px from 1440 px up).
const TILE_SIZES = "(min-width: 1440px) 175px, (min-width: 1024px) calc((100vw - 216px) / 7), calc((100vw - 46px) / 2)";

/**
 * One row of tiles, a tile per root category: the photo (a decoration, so `alt=""`; the name is the text of the link)
 * and the name as an h2 that links to the category page. A category without a photo has the tile without one.
 */
export function DepartmentTiles({ tiles }: { tiles: DepartmentTile[] }) {
  return (
    <section aria-label="Działy sklepu" className="mx-auto max-w-content px-gutter pt-10 pb-2 lg:pt-18 lg:pb-6">
      <ul className="grid grid-cols-2 gap-x-3.5 gap-y-5 lg:grid-cols-7 lg:gap-5">
        {tiles.map((tile) => {
          const photo = tile.imageId === undefined ? null : photoAttributes(tile.imageId, "tile");
          return (
            <li key={tile.id}>
              <h2>
                <Link href={tile.href} className="block">
                  {photo === null ? (
                    <span className="block aspect-3/4 w-full rounded-md bg-muted" />
                  ) : (
                    <img
                      src={photo.src}
                      srcSet={photo.srcSet}
                      sizes={TILE_SIZES}
                      width={photo.width}
                      height={photo.height}
                      alt=""
                      loading="lazy"
                      decoding="async"
                      className="aspect-3/4 w-full rounded-md object-cover"
                    />
                  )}
                  <span className="mt-2.5 block text-base font-semibold tracking-tight lg:mt-3">{tile.name}</span>
                </Link>
              </h2>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
