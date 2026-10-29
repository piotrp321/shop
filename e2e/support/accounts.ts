// Demo accounts imported with the Keycloak realms (see README, "Konta demo"). The values are public demo data.

export const customerPassword = "Klient-Demo-1";
export const staffPassword = "Pracownik-Demo-1";

export const customers = {
  jan: { email: "jan.kowalski@example.com", password: customerPassword },
  anna: { email: "anna.nowak@example.com", password: customerPassword },
} as const;

// `totpSecret` is the raw (UTF-8) secret; `totp()` turns it into the current code.
export const staff = {
  admin: { username: "admin", password: staffPassword, totpSecret: "demo-totp-admin-0001" },
  support: { username: "support", password: staffPassword, totpSecret: "demo-totp-support-01" },
  warehouse: { username: "warehouse", password: staffPassword, totpSecret: "demo-totp-warehouse1" },
  marketing: { username: "marketing", password: staffPassword, totpSecret: "demo-totp-marketing1" },
} as const;
