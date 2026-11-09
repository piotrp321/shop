// The text of the hero and the photo of every department of the home page. There is no CMS, so this is code: the text is
// the one of the mockup (docs/features/visual-design/mockups), the photos are the images of `marketing-images.ts`.

export const HERO = {
  /** The id of the photo in `marketing-images.ts`. */
  imageId: "hero",
  heading: "Sprzęt na każdy szlak",
  text: "Bieganie, rower, góry i woda. Wszystko, czego potrzebujesz poza miastem, w jednym sklepie.",
  /** The link to a department: the slug of the root category it leads to. Left out when the tree has no such category. */
  link: { slug: "turystyka", label: "Zobacz Turystykę" },
} as const;

/** The photo of a department by the slug of its root category. A root category that is not here has a tile without a photo. */
export const DEPARTMENT_IMAGE_IDS: Readonly<Record<string, string>> = {
  bieganie: "dept-bieganie",
  rower: "dept-rower",
  turystyka: "dept-turystyka",
  "sporty-zimowe": "dept-sporty-zimowe",
  fitness: "dept-fitness",
  "sporty-wodne": "dept-sporty-wodne",
  "sporty-druzynowe": "dept-sporty-druzynowe",
};
