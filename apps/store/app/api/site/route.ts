/**
 * The switches of this deployment for the browser: `{ demoNotice, customerAccounts }` from the server variables
 * `STORE_DEMO_NOTICE` (default `false`) and `STORE_CUSTOMER_ACCOUNTS` (default `true`). A value is `true` or `false`
 * (any case, spaces around ignored); anything else, also an empty value, gives the default. The pages never read these
 * variables, so their static and ISR HTML is the same for every deployment; the browser asks here instead.
 */

// Reads the environment at request time, never at build time.
export const dynamic = "force-dynamic";

function flag(value: string | undefined, fallback: boolean): boolean {
  switch (value?.trim().toLowerCase()) {
    case "true":
      return true;
    case "false":
      return false;
    default:
      return fallback;
  }
}

export function GET(): Response {
  return Response.json(
    {
      demoNotice: flag(process.env.STORE_DEMO_NOTICE, false),
      customerAccounts: flag(process.env.STORE_CUSTOMER_ACCOUNTS, true),
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
