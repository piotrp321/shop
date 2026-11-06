// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, describe, expect, it } from "vitest";
import { TREE, node } from "./fixtures";
import { MegaMenu } from "./MegaMenu";

afterEach(cleanup);

const department = (name: string) => screen.getByRole("button", { name });
const panelOf = (button: HTMLElement) => document.getElementById(button.getAttribute("aria-controls") ?? "")!;
const hrefs = (html: string) => [...html.matchAll(/href="([^"]*)"/g)].map((match) => match[1]);

describe("MegaMenu", () => {
  it("is a navigation named Działy with a button for each department, all closed at first", () => {
    render(<MegaMenu categories={TREE} />);
    expect(screen.getByRole("navigation", { name: "Działy" })).toBeTruthy();
    for (const name of ["Bieganie", "Rower"]) {
      expect(department(name).getAttribute("aria-expanded")).toBe("false");
      expect(panelOf(department(name)).hidden).toBe(true);
    }
  });

  it("keeps the links of every department and of levels 2 and 3 in the HTML while the menu is closed", () => {
    const html = renderToStaticMarkup(<MegaMenu categories={TREE} />);
    expect(hrefs(html)).toEqual(
      expect.arrayContaining(["/c/bieganie", "/c/bieganie/buty", "/c/bieganie/buty/trailowe", "/c/bieganie/odziez", "/c/rower", "/c/rower/kaski"]),
    );
    expect(html).toContain("Trailowe");
    expect(html).toContain("Kaski");
  });

  it("stops at level 3: deeper categories are reached through the subcategories on the category page", () => {
    const deep = [node("turystyka", "Turystyka", [node("biwak", "Biwak", [node("spanie", "Spanie", [node("maty", "Maty")])])])];
    const html = renderToStaticMarkup(<MegaMenu categories={deep} />);
    expect(hrefs(html)).toContain("/c/turystyka/biwak/spanie");
    expect(hrefs(html)).not.toContain("/c/turystyka/biwak/spanie/maty");
    expect(html).not.toContain("Maty");
  });

  it("uses real buttons, so Enter and Space (the keyboard click of a button) open a department", () => {
    render(<MegaMenu categories={TREE} />);
    expect(department("Bieganie").tagName).toBe("BUTTON");
    expect(department("Bieganie").getAttribute("type")).toBe("button");
  });

  it("opens the panel of a department with a click and closes it with the next click", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.click(department("Bieganie"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    const panel = panelOf(department("Bieganie"));
    expect(panel.hidden).toBe(false);
    const links = within(panel).getAllByRole("link");
    expect(links.map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
      ["Wszystko z działu", "/c/bieganie"],
      ["Buty", "/c/bieganie/buty"],
      ["Trailowe", "/c/bieganie/buty/trailowe"],
      ["Odzież", "/c/bieganie/odziez"],
    ]);
    fireEvent.click(department("Bieganie"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    expect(panel.hidden).toBe(true);
  });

  it("opens a department on hover and moves to another one without keeping the first open", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.pointerEnter(department("Bieganie"), { pointerType: "mouse" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    fireEvent.pointerEnter(department("Rower"), { pointerType: "mouse" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    expect(department("Rower").getAttribute("aria-expanded")).toBe("true");
    expect(panelOf(department("Bieganie")).hidden).toBe(true);
    expect(panelOf(department("Rower")).hidden).toBe(false);
  });

  it("keeps a department opened by hover open for the click that follows, and closes it with the next click", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.pointerEnter(department("Bieganie"), { pointerType: "mouse" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(department("Bieganie"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(department("Bieganie"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
  });

  it("does not open on a touch or pen pointer entering, so the tap opens it once", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.pointerEnter(department("Bieganie"), { pointerType: "touch" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    fireEvent.click(department("Bieganie"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
  });

  it("switches to another department with a click on it", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.click(department("Bieganie"));
    fireEvent.click(department("Rower"));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    expect(department("Rower").getAttribute("aria-expanded")).toBe("true");
  });

  it("closes with Escape and returns the focus to the button of the department", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.click(department("Bieganie"));
    const link = within(panelOf(department("Bieganie"))).getByRole("link", { name: "Buty" });
    act(() => link.focus());
    fireEvent.keyDown(link, { key: "Escape" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    expect(document.activeElement).toBe(department("Bieganie"));
  });

  it("closes with Escape pressed outside the menu (a panel opened by hover, the focus on the body)", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.pointerEnter(department("Bieganie"), { pointerType: "mouse" });
    expect(document.activeElement).toBe(document.body);
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
    expect(panelOf(department("Bieganie")).hidden).toBe(true);
    expect(document.activeElement).toBe(department("Bieganie"));
  });

  it("ignores Escape while nothing is open and does not take the focus", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(document.activeElement).toBe(document.body);
  });

  it("ignores other keys", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.click(department("Bieganie"));
    fireEvent.keyDown(department("Bieganie"), { key: "ArrowDown" });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
  });

  it("closes with a click outside and stays open for a click inside", () => {
    render(
      <>
        <button type="button">Poza menu</button>
        <MegaMenu categories={TREE} />
      </>,
    );
    fireEvent.click(department("Bieganie"));
    fireEvent.mouseDown(panelOf(department("Bieganie")));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    fireEvent.mouseDown(screen.getByRole("button", { name: "Poza menu" }));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
  });

  it("closes when the focus leaves the menu, but not when it moves inside", () => {
    render(
      <>
        <button type="button">Poza menu</button>
        <MegaMenu categories={TREE} />
      </>,
    );
    fireEvent.click(department("Bieganie"));
    const link = within(panelOf(department("Bieganie"))).getByRole("link", { name: "Buty" });
    fireEvent.blur(department("Bieganie"), { relatedTarget: link });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("true");
    fireEvent.blur(link, { relatedTarget: screen.getByRole("button", { name: "Poza menu" }) });
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
  });

  it("closes after a click on a link of the panel (the menu stays mounted across navigation)", () => {
    render(<MegaMenu categories={TREE} />);
    fireEvent.click(department("Bieganie"));
    fireEvent.click(within(panelOf(department("Bieganie"))).getByRole("link", { name: "Wszystko z działu" }));
    expect(department("Bieganie").getAttribute("aria-expanded")).toBe("false");
  });

  describe("narrow screen", () => {
    const menuButton = () => screen.getByRole("button", { name: "Menu" });
    const list = () => document.getElementById(menuButton().getAttribute("aria-controls") ?? "")!;

    it("has a Menu button that shows the list of the departments and their levels 2 and 3", () => {
      render(<MegaMenu categories={TREE} />);
      expect(menuButton().getAttribute("aria-expanded")).toBe("false");
      expect(list().hidden).toBe(true);
      fireEvent.click(menuButton());
      expect(menuButton().getAttribute("aria-expanded")).toBe("true");
      expect(list().hidden).toBe(false);
      expect(within(list()).getAllByRole("link").map((link) => link.getAttribute("href"))).toEqual([
        "/c/bieganie",
        "/c/bieganie/buty",
        "/c/bieganie/buty/trailowe",
        "/c/bieganie/odziez",
        "/c/rower",
        "/c/rower/kaski",
      ]);
    });

    it("closes with Escape and returns the focus to the Menu button", () => {
      render(<MegaMenu categories={TREE} />);
      fireEvent.click(menuButton());
      const link = within(list()).getByRole("link", { name: "Kaski" });
      act(() => link.focus());
      fireEvent.keyDown(link, { key: "Escape" });
      expect(menuButton().getAttribute("aria-expanded")).toBe("false");
      expect(document.activeElement).toBe(menuButton());
    });

    it("closes with Escape pressed outside the menu (the button without the focus, as after a click in Safari)", () => {
      render(<MegaMenu categories={TREE} />);
      fireEvent.click(menuButton());
      expect(document.activeElement).toBe(document.body);
      fireEvent.keyDown(document.body, { key: "Escape" });
      expect(menuButton().getAttribute("aria-expanded")).toBe("false");
      expect(document.activeElement).toBe(menuButton());
    });

    it("closes after a click on a link", () => {
      render(<MegaMenu categories={TREE} />);
      fireEvent.click(menuButton());
      fireEvent.click(within(list()).getByRole("link", { name: "Kaski" }));
      expect(menuButton().getAttribute("aria-expanded")).toBe("false");
    });
  });
});
