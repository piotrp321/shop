import { Alert, AlertDescription } from "@/components/ui/alert";
import { loginHref } from "./order-format";

/** The customer has no session (any more) and is on the way to the login, which brings them back to `returnTo`. */
export function SignedOut({ returnTo }: { returnTo: string }) {
  return (
    <Alert role="status">
      <AlertDescription>
        Przekierowujemy do logowania…{" "}<a href={loginHref(returnTo)}>Zaloguj się</a>
      </AlertDescription>
    </Alert>
  );
}
