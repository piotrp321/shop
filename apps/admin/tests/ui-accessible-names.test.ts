import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

// The accessible names of the controls inside the opened content: the text and the labels of buttons.
function buttonNames() {
  return [...document.body.querySelectorAll("button")].map((button) =>
    (button.getAttribute("aria-label") ?? button.textContent ?? "").trim(),
  );
}

const ENGLISH_NAMES = /\b(close|cancel|continue|open|toggle|previous|next|more)\b/i;

describe("Dialog", () => {
  const Host = defineComponent({
    setup: () => () =>
      h(Dialog, { open: true }, () =>
        h(DialogContent, null, () => [
          h(DialogHeader, null, () => [h(DialogTitle, null, () => "Zmień cenę"), h(DialogDescription, null, () => "Opis")]),
          h(DialogFooter, { showCloseButton: true }),
        ]),
      ),
  });

  it("names its close buttons „Zamknij” and has no English accessible name", async () => {
    wrapper = mount(Host, { attachTo: document.body });
    await flushPromises();

    const names = buttonNames();
    expect(names).toEqual(["Zamknij", "Zamknij"]);
    expect(document.body.textContent).not.toMatch(ENGLISH_NAMES);
  });
});

describe("AlertDialog", () => {
  const Host = defineComponent({
    setup: () => () =>
      h(AlertDialog, { open: true }, () =>
        h(AlertDialogContent, null, () =>
          h(AlertDialogHeader, null, () => [
            h(AlertDialogTitle, null, () => "Usunąć wariant?"),
            h(AlertDialogDescription, null, () => "Opis"),
          ]),
        ),
      ),
  });

  it("names its close button „Zamknij” and has no English accessible name", async () => {
    wrapper = mount(Host, { attachTo: document.body });
    await flushPromises();

    expect(buttonNames()).toEqual(["Zamknij"]);
    expect(document.body.textContent).not.toMatch(ENGLISH_NAMES);
  });
});

describe("Sheet", () => {
  it("names its close button „Zamknij”", async () => {
    const Host = defineComponent({
      setup: () => () =>
        h(Sheet, { open: true }, () =>
          h(SheetContent, { side: "left" }, () =>
            h(SheetHeader, null, () => [h(SheetTitle, null, () => "Menu"), h(SheetDescription, null, () => "Nawigacja")]),
          ),
        ),
    });
    wrapper = mount(Host, { attachTo: document.body });
    await flushPromises();

    expect(buttonNames()).toEqual(["Zamknij"]);
  });
});

// The sources of src/components/ui: every text that only a screen reader reads (sr-only, aria-label, title) is Polish.
const sources = import.meta.glob<string>("../src/components/ui/**/*.vue", { query: "?raw", import: "default", eager: true });

describe("src/components/ui", () => {
  it("has no English accessible names in its sources", () => {
    const names: string[] = [];
    for (const [file, source] of Object.entries(sources)) {
      for (const match of source.matchAll(/sr-only[^>]*>([^<]+)</g)) names.push(`${file}: ${match[1]!.trim()}`);
      for (const match of source.matchAll(/\b(?:aria-label|title)="([^"]+)"/g)) names.push(`${file}: ${match[1]}`);
    }

    expect(names.length).toBeGreaterThan(0);
    expect(names.filter((name) => ENGLISH_NAMES.test(name))).toEqual([]);
  });
});
