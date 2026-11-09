// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { hydrateRoot } from "react-dom/client";
import { renderToStaticMarkup, renderToString } from "react-dom/server";
import { use } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const nav = vi.hoisted(() => ({ pathname: "/", params: new URLSearchParams(), pending: null as Promise<void> | null }));
vi.mock("next/navigation", () => ({
  usePathname: () => nav.pathname,
  // While `pending` is set the hook suspends, like it does on a page rendered without the address (ISR).
  useSearchParams: () => {
    if (nav.pending) use(nav.pending);
    return nav.params;
  },
}));

import { SearchBox } from "./SearchBox";

beforeEach(() => {
  nav.pathname = "/";
  nav.params = new URLSearchParams();
  nav.pending = null;
});
afterEach(() => {
  cleanup();
  document.body.innerHTML = "";
});

const field = () => screen.getByRole("searchbox", { name: "Szukaj produktów" }) as HTMLInputElement;

describe("SearchBox", () => {
  it("is a GET search form to /szukaj with the phrase in `q`", () => {
    render(<SearchBox />);
    const form = screen.getByRole("search") as HTMLFormElement;
    expect(form.tagName).toBe("FORM");
    expect(form.method).toBe("get"); // the default, so the attribute may be absent
    expect(form.getAttribute("action")).toBe("/szukaj");
    expect(field().getAttribute("name")).toBe("q");
    expect(field().type).toBe("search");
    expect(screen.getByRole("button", { name: "Szukaj" }).getAttribute("type")).toBe("submit");
  });

  it("is empty on other pages, even when the address carries a `q`", () => {
    nav.params = new URLSearchParams("q=kurtka");
    render(<SearchBox />);
    expect(field().value).toBe("");
  });

  it("shows the current phrase on /szukaj", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka+zimowa&o=Kolor%3Aczarne");
    render(<SearchBox />);
    expect(field().value).toBe("kurtka zimowa");
  });

  it("is in the HTML for a browser without JavaScript", () => {
    const html = renderToStaticMarkup(<SearchBox />);
    expect(html).toContain('action="/szukaj"');
    expect(html).toContain('name="q"');
  });

  it("has the phrase it is given in the HTML, so the field is filled without JavaScript", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtki");
    expect(renderToStaticMarkup(<SearchBox phrase="kurtki" />)).toMatch(/<input[^>]*id="header-search"[^>]*value="kurtki"/);
    render(<SearchBox phrase="kurtki" />);
    expect(field().value).toBe("kurtki");
  });

  it("hydrates the field of the page HTML in place, so what was typed before the hydration stays", async () => {
    // The server does not have the address (a product page is ISR), so the header is the fallback in the HTML.
    nav.pending = new Promise<void>(() => {});
    const container = document.createElement("div");
    document.body.append(container);
    container.innerHTML = renderToString(<SearchBox />);
    const typed = container.querySelector("input") as HTMLInputElement;
    typed.value = "spiwor";
    nav.pending = null;
    await act(async () => {
      hydrateRoot(container, <SearchBox />, { onRecoverableError: () => {} });
    });
    expect(container.querySelector("input")).toBe(typed);
    expect(typed.value).toBe("spiwor");
  });

  it("puts the phrase of /szukaj into the field without replacing it", async () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka");
    const { rerender } = render(<SearchBox />);
    const before = field();
    expect(before.value).toBe("kurtka");
    nav.params = new URLSearchParams("q=plecak");
    rerender(<SearchBox />);
    expect(field()).toBe(before);
    expect(field().value).toBe("plecak");
  });

  it("leaves what is typed alone while the phrase of the address does not change", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka&sort=nazwa");
    const { rerender } = render(<SearchBox />);
    fireEvent.change(field(), { target: { value: "kurtka zimowa" } });
    nav.params = new URLSearchParams("q=kurtka&sort=cena-rosnaco");
    rerender(<SearchBox />);
    expect(field().value).toBe("kurtka zimowa");
  });

  it("empties the field when leaving /szukaj", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka");
    const { rerender } = render(<SearchBox />);
    nav.pathname = "/c/buty";
    nav.params = new URLSearchParams();
    rerender(<SearchBox />);
    expect(field().value).toBe("");
  });
});

describe("SearchBox where the row of the header has no room for the field (1024 to 1279 px)", () => {
  const toggle = () => screen.getByRole("button", { name: "Wyszukiwarka" });
  const form = () => screen.getByRole("search") as HTMLFormElement;

  it("has one form and a closed button that controls it; the button is the one that comes first in the document", () => {
    render(<SearchBox />);
    expect(screen.getAllByRole("search")).toHaveLength(1);
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
    expect(toggle().getAttribute("aria-controls")).toBe(form().id);
    expect(form().getAttribute("data-state")).toBe("closed");
    expect(toggle().compareDocumentPosition(form()) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("opens the form with the button and moves the focus to the field", () => {
    render(<SearchBox />);
    fireEvent.click(toggle());
    expect(toggle().getAttribute("aria-expanded")).toBe("true");
    expect(form().getAttribute("data-state")).toBe("open");
    expect(document.activeElement).toBe(field());
    fireEvent.click(toggle());
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
  });

  it("closes with Escape from the field and gives the focus back to the button", () => {
    render(<SearchBox />);
    fireEvent.click(toggle());
    fireEvent.keyDown(field(), { key: "Escape" });
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
    expect(form().getAttribute("data-state")).toBe("closed");
    expect(document.activeElement).toBe(toggle());
  });

  it("leaves Escape alone while it is closed", () => {
    render(<SearchBox />);
    toggle().focus();
    fireEvent.keyDown(toggle(), { key: "Escape" });
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
  });

  it("closes when the form is sent, when the focus leaves for the page, and when the page is clicked; not when the focus moves inside", () => {
    render(
      <>
        <SearchBox />
        <a href="/inne">Inny link</a>
      </>,
    );
    fireEvent.click(toggle());
    fireEvent.blur(field(), { relatedTarget: screen.getByRole("button", { name: "Szukaj" }) });
    expect(toggle().getAttribute("aria-expanded")).toBe("true");
    fireEvent.blur(field(), { relatedTarget: screen.getByRole("link", { name: "Inny link" }) });
    expect(toggle().getAttribute("aria-expanded")).toBe("false");

    fireEvent.click(toggle());
    fireEvent.mouseDown(document.body);
    expect(toggle().getAttribute("aria-expanded")).toBe("false");

    fireEvent.click(toggle());
    fireEvent.submit(form());
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
  });

  it("gives the focus to the button when the form is sent from the open panel, so it does not fall to the body", () => {
    render(<SearchBox />);
    fireEvent.click(toggle());
    expect(document.activeElement).toBe(field());
    fireEvent.submit(form());
    expect(toggle().getAttribute("aria-expanded")).toBe("false");
    expect(document.activeElement).toBe(toggle());
  });

  it("does not take the focus when it is sent while closed (the form is a row of the header)", () => {
    render(<SearchBox />);
    field().focus();
    fireEvent.submit(form());
    expect(document.activeElement).toBe(field());
  });

  it("has no background of its own without JavaScript, so the row under the header keeps its background", () => {
    expect(renderToStaticMarkup(<SearchBox />)).toMatch(/<noscript><style>[^<]*#header-search-form \{[^}]*background: transparent !important/);
  });

  it("works without JavaScript: the form is in the HTML and a noscript style shows it as a row of the header, the button hidden", () => {
    const html = renderToStaticMarkup(<SearchBox />);
    expect(html).toContain('action="/szukaj"');
    expect(html).toMatch(/<noscript><style>[^<]*#header-search-form[^<]*display: flex !important[^<]*#header-search-toggle[^<]*display: none !important/);
  });
});

