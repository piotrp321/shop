import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may open „Dostawa” (the administrators, the roles from `/me`). It only hides the menu item and
 * sends the others away from the routes; the API refuses the delivery methods to every other role.
 */
export function canManageDelivery(staff: StaffMe | null): boolean {
  return staff?.roles.includes("admin") ?? false;
}
