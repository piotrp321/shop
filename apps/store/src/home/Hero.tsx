import Link from "next/link";
import type { CSSProperties } from "react";
import { Button } from "@/components/ui/button";
import { HERO } from "./home-content";
import { photoAttributes } from "./home-images";
import { MARKETING_IMAGES } from "./marketing-images";

const heroImage = MARKETING_IMAGES.find((image) => image.id === HERO.imageId)!;
const position = (crop: string) => `${heroImage.crops[crop]!.position[0]}% ${heroImage.crops[crop]!.position[1]}%`;

/**
 * The hero of the home page: the photo over the whole width with the heading, a short text and a link to a department
 * on it, under an overlay that keeps the text readable. A `<picture>` (plain, without `next/image`): a phone (up to 767 px)
 * gets the tall crop in three widths, a wider screen the wide crop in two. The photo is the one image of the page that
 * loads with priority. `href` is the address of the department of the link, or `null` when the tree has none (or could
 * not be read): the hero is then without a link.
 */
export function Hero({ href }: { href: string | null }) {
  const phone = photoAttributes(HERO.imageId, "phone");
  const wide = photoAttributes(HERO.imageId, "wide");
  return (
    <section className="relative isolate flex min-h-150 items-end text-inverse-foreground lg:min-h-170">
      <picture>
        <source media="(max-width: 767px)" srcSet={phone.srcSet} sizes="100vw" width={phone.width} height={phone.height} />
        <img
          src={wide.src}
          srcSet={wide.srcSet}
          sizes="100vw"
          width={wide.width}
          height={wide.height}
          alt={heroImage.alt}
          fetchPriority="high"
          decoding="async"
          style={{ "--hero-pos-phone": position("phone"), "--hero-pos-wide": position("wide") } as CSSProperties}
          className="absolute inset-0 -z-20 h-full w-full object-cover object-(--hero-pos-phone) md:object-(--hero-pos-wide)"
        />
      </picture>
      <div aria-hidden="true" className="absolute inset-0 -z-10 bg-linear-to-t from-hero-overlay from-64% to-hero-overlay-clear lg:bg-linear-to-r lg:from-46% lg:to-82%" />
      <div className="mx-auto w-full max-w-content px-gutter pt-8 pb-10 lg:pt-12 lg:pb-18">
        <h1 className="max-w-[11ch] text-5xl font-semibold tracking-hero lg:max-w-[9ch] lg:text-7xl">{HERO.heading}</h1>
        <p className="mt-4 max-w-[34ch] text-lg lg:max-w-[30ch] lg:text-xl">{HERO.text}</p>
        {href !== null && (
          <Button asChild size="lg" className="mt-6 px-7 focus-visible:outline-inverse-foreground">
            <Link href={href}>{HERO.link.label}</Link>
          </Button>
        )}
      </div>
    </section>
  );
}
