import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may open the orders (admin, support or warehouse, the roles from `/me`). It only hides the
 * menu item and sends the others away from the route; the API checks the permission on every request.
 */
export function canViewOrders(staff: StaffMe | null): boolean {
  return staff?.roles.some((role) => role === "admin" || role === "support" || role === "warehouse") ?? false;
}

/** Whether the staff member may complete a refund (admin or support): the menu item „Zwroty do wykonania” is theirs. */
export function canCompleteRefunds(staff: StaffMe | null): boolean {
  return staff?.roles.some((role) => role === "admin" || role === "support") ?? false;
}
