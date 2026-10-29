// Public addresses, identical to the ones a browser on the host uses (localhost:<port>); the container forwards them.
// The ports come from the same variables as in Compose.
const port = (name: string, fallback: string): string => process.env[name] || fallback;

export const urls = {
  store: `http://localhost:${port("STORE_PORT", "3000")}`,
  admin: `http://localhost:${port("ADMIN_PORT", "5173")}`,
  keycloak: `http://localhost:${port("KEYCLOAK_PORT", "8080")}`,
  // Mailpit is reached over the Compose network, not through the browser-facing port.
  mailpit: "http://mailpit:8025",
};
