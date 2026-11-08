import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may change promotions (admin or marketing, the roles from `/me`). It only hides the actions;
 * the API checks the permission on every request. Every staff member may read promotions.
 */
export function canManagePromotions(staff: StaffMe | null): boolean {
  return staff?.roles.some((role) => role === "admin" || role === "marketing") ?? false;
}
