import localFont from "next/font/local";

// Outfit (SIL OFL, see THIRD_PARTY_NOTICES.md): one variable font in two files from the repository, the `latin` and
// the `latin-ext` subset (Polish letters). Both declare the family `Outfit`, and `unicode-range` makes the browser
// fetch only the file that holds a character the page uses. Nothing is requested from a font host.
// The loader wants literals, so the options are written out in both calls. The CSS variable `--font-outfit` names the
// family after the first export (`Outfit`), so that export is the one with the variable and the same family name is
// declared for the second file.
export const Outfit = localFont({
  src: "./fonts/outfit-latin-wght.woff2",
  weight: "100 900",
  display: "swap",
  variable: "--font-outfit",
  declarations: [
    { prop: "font-family", value: "Outfit" },
    {
      prop: "unicode-range",
      value:
        "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD",
    },
  ],
});

export const OutfitLatinExt = localFont({
  src: "./fonts/outfit-latin-ext-wght.woff2",
  weight: "100 900",
  display: "swap",
  variable: "--font-outfit-ext",
  declarations: [
    { prop: "font-family", value: "Outfit" },
    {
      prop: "unicode-range",
      value:
        "U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF",
    },
  ],
});

/** Class names that put the font variables on `<html>`; the tokens reference `--font-outfit`. */
export const fontVariables = `${Outfit.variable} ${OutfitLatinExt.variable}`;
