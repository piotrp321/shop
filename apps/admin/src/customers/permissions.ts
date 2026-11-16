import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may open the customers (admin or support, the roles from `/me`). It only hides the menu item
 * and the link „Karta klienta” and sends the others away from the route; the API checks the permission on every request.
 */
export function canViewCustomers(staff: StaffMe | null): boolean {
  return staff?.roles.some((role) => role === "admin" || role === "support") ?? false;
}
