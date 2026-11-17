import { describe, expect, it } from "vitest";
import { canViewCustomers } from "@/customers/permissions";
import type { StaffMe } from "@/stores/auth";

const staff = (roles: StaffMe["roles"]): StaffMe => ({ id: "u1", username: "anna", roles });

describe("canViewCustomers", () => {
  it("is true for admin and support, also among other roles", () => {
    expect(canViewCustomers(staff(["admin"]))).toBe(true);
    expect(canViewCustomers(staff(["support"]))).toBe(true);
    expect(canViewCustomers(staff(["marketing", "support"]))).toBe(true);
  });

  it("is false for the other roles and while the staff member is unknown", () => {
    expect(canViewCustomers(staff(["warehouse"]))).toBe(false);
    expect(canViewCustomers(staff(["marketing"]))).toBe(false);
    expect(canViewCustomers(staff([]))).toBe(false);
    expect(canViewCustomers(null)).toBe(false);
  });
});
