// jsdom lacks several browser APIs that Reka UI components (dialogs, menus, comboboxes, selects, tooltips) use for
// positioning and pointer handling. These stubs only make them callable; tests that need real layout do not belong in jsdom.
if (typeof window !== "undefined") {
  class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  globalThis.ResizeObserver ??= ResizeObserverStub;

  window.Element.prototype.scrollIntoView ??= function scrollIntoView() {};
  window.Element.prototype.hasPointerCapture ??= () => false;
  window.Element.prototype.setPointerCapture ??= () => {};
  window.Element.prototype.releasePointerCapture ??= () => {};

  window.matchMedia ??= (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  });
}
