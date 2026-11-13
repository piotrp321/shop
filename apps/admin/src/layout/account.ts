import type { StaffMe } from "@/stores/auth";

type Role = StaffMe["roles"][number];

const ROLE_LABELS: Record<Role, string> = {
  admin: "Administrator",
  support: "Obsługa klienta",
  warehouse: "Magazyn",
  marketing: "Marketing",
};

/** The Polish name of a role of the staff (the API sends the code). */
export function roleLabel(role: Role): string {
  return ROLE_LABELS[role] ?? role;
}

/**
 * Initials for the avatar: the first letters of the first two parts of the login (separated by `.`, `-`, `_` or a
 * space), or the first two letters of a single part. Code points, so a letter outside the BMP is not cut in half.
 */
export function initialsOf(username: string): string {
  const parts = username.split(/[.\-_\s]+/u).filter((part) => part !== "");
  const letters =
    parts.length >= 2 ? [...(parts[0] ?? "")].slice(0, 1).concat([...(parts[1] ?? "")].slice(0, 1)) : [...(parts[0] ?? "")].slice(0, 2);
  return letters.length === 0 ? "?" : letters.join("").toLocaleUpperCase("pl");
}
