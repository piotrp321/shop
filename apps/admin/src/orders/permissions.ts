import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may open the orders (admin, support or warehouse, the roles from `/me`). It only hides the
 * menu item and sends the others away from the route; the API checks the permission on every request.
 */
export function canViewOrders(staff: StaffMe | null): boolean {
  return staff?.roles.some((role) => role === "admin" || role === "support" || role === "warehouse") ?? false;
}
