import { describe, expect, it } from "vitest";
import { canManageCategories } from "@/categories/permissions";
import type { StaffMe } from "@/stores/auth";

const staff = (roles: StaffMe["roles"]): StaffMe => ({ id: "u1", username: "anna", roles });

describe("canManageCategories", () => {
  it("is true for admin and marketing, also among other roles", () => {
    expect(canManageCategories(staff(["admin"]))).toBe(true);
    expect(canManageCategories(staff(["marketing"]))).toBe(true);
    expect(canManageCategories(staff(["support", "marketing"]))).toBe(true);
  });

  it("is false for the other roles and while the staff member is unknown", () => {
    expect(canManageCategories(staff(["support"]))).toBe(false);
    expect(canManageCategories(staff(["warehouse"]))).toBe(false);
    expect(canManageCategories(staff([]))).toBe(false);
    expect(canManageCategories(null)).toBe(false);
  });
});
