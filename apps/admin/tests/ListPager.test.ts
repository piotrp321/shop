import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import ListPager from "@/components/list/ListPager.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

function mountPager(props: Record<string, unknown> = {}) {
  wrapper = mount(ListPager, {
    props: { hasPrevious: false, hasNext: true, pageNumber: 1, range: { from: 1, to: 25 }, ...props },
    attachTo: document.body,
  });
  return wrapper;
}

const previous = () => document.body.querySelector<HTMLButtonElement>("[data-testid=previous-page]")!;
const next = () => document.body.querySelector<HTMLButtonElement>("[data-testid=next-page]")!;

describe("ListPager", () => {
  it("names the buttons and shows the number of the page", () => {
    mountPager({ hasPrevious: true, pageNumber: 2 });

    expect(previous().getAttribute("aria-label")).toBe("Poprzednia strona");
    expect(next().getAttribute("aria-label")).toBe("Następna strona");
    expect(document.body.querySelector("[data-testid=page-number]")!.textContent).toBe("Strona 2");
  });

  it("shows the rows of the page in a status region", () => {
    mountPager({ hasPrevious: true, pageNumber: 2, range: { from: 26, to: 50 } });

    const status = document.body.querySelector("[role=status]")!;
    expect(status.textContent).toBe("Pokazano 26–50");
  });

  it("says which things it counts when the list is of something else than its rows", () => {
    mountPager({ noun: "produkty" });

    expect(document.body.querySelector("[role=status]")!.textContent).toBe("Pokazano produkty 1–25");
  });

  it("keeps the status region, empty, when there are no rows to number", () => {
    mountPager({ range: null });

    expect(document.body.querySelector("[role=status]")!.textContent).toBe("");
  });

  it("disables the button that has nowhere to go", () => {
    mountPager({ hasPrevious: false, hasNext: true });
    expect(previous().disabled).toBe(true);
    expect(next().disabled).toBe(false);
  });

  it("asks for the other page when a button is pressed", () => {
    const pager = mountPager({ hasPrevious: true });

    next().click();
    previous().click();

    expect(pager.emitted("next")).toHaveLength(1);
    expect(pager.emitted("previous")).toHaveLength(1);
  });

  it("leaves the focus on the pressed button while it can still be pressed", async () => {
    const pager = mountPager();
    next().focus();
    next().click();

    await pager.setProps({ hasPrevious: true, hasNext: true, pageNumber: 2 });

    expect(document.activeElement).toBe(next());
  });

  it("moves the focus to „Poprzednia strona” when „Następna strona” is disabled by the last page", async () => {
    const pager = mountPager({ hasPrevious: true, pageNumber: 2 });
    next().focus();
    next().click();

    await pager.setProps({ hasNext: false, pageNumber: 3 });

    expect(next().disabled).toBe(true);
    expect(document.activeElement).toBe(previous());
  });

  it("moves the focus to „Następna strona” when „Poprzednia strona” is disabled by the first page", async () => {
    const pager = mountPager({ hasPrevious: true, hasNext: true, pageNumber: 2 });
    previous().focus();
    previous().click();

    await pager.setProps({ hasPrevious: false, pageNumber: 1 });

    expect(document.activeElement).toBe(next());
  });

  it("does not take the focus from where the staff member moved it", async () => {
    const outside = document.createElement("input");
    document.body.append(outside);
    const pager = mountPager({ hasPrevious: true, pageNumber: 2 });
    next().focus();
    next().click();
    outside.focus();

    await pager.setProps({ hasNext: false, pageNumber: 3 });

    expect(document.activeElement).toBe(outside);
  });
});
