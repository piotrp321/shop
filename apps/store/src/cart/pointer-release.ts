/**
 * An answer of the cart that comes between the press and the release of a pointer must not change the page: a row that
 * goes or a line of the summary that disappears moves the control under the pointer, and the release lands beside it. The
 * cart page waits for `pointerReleased()` before it applies an answer.
 *
 * The wait ends after the release (`pointerup`, `pointercancel`, or the window losing the focus) in a timer, so that it
 * comes after the `click` the release ends, and after `SAFETY_MS` at the latest when the release is lost.
 */
export const SAFETY_MS = 1_000;

let watchers = 0;
let pressed = false;
let waiting: (() => void)[] = [];

function flush() {
  for (const resume of waiting.splice(0)) resume();
}

const press = () => {
  pressed = true;
};

function release() {
  pressed = false;
  setTimeout(() => {
    if (!pressed) flush();
  }, 0);
}

/** Starts to follow the pointer; the returned function stops it and ends every wait. */
export function watchPointer(): () => void {
  if (watchers++ === 0) {
    document.addEventListener("pointerdown", press, true);
    document.addEventListener("pointerup", release, true);
    document.addEventListener("pointercancel", release, true);
    window.addEventListener("blur", release);
  }
  return () => {
    if (--watchers > 0) return;
    document.removeEventListener("pointerdown", press, true);
    document.removeEventListener("pointerup", release, true);
    document.removeEventListener("pointercancel", release, true);
    window.removeEventListener("blur", release);
    pressed = false;
    flush();
  };
}

/** Settles at once when no pointer is pressed; otherwise after its release. The waits settle in the order they began. */
export function pointerReleased(): Promise<void> {
  if (!pressed) return Promise.resolve();
  return new Promise((resolve) => {
    const resume = () => {
      clearTimeout(safety);
      resolve();
    };
    // A release that never came is not waited for again: the next answer is not held for another second.
    const safety = setTimeout(() => {
      pressed = false;
      waiting = waiting.filter((other) => other !== resume);
      resume();
    }, SAFETY_MS);
    waiting.push(resume);
  });
}
