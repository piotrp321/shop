import type { StaffMe } from "@/stores/auth";

/**
 * Whether the staff member may open „Stan systemu” (the administrators, the roles from `/me`). It only hides the menu
 * item and sends the others away from the route; the API gives `system: null` to everyone else.
 */
export function canViewSystem(staff: StaffMe | null): boolean {
  return staff?.roles.includes("admin") ?? false;
}
