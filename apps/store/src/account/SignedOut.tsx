import { loginHref } from "./order-format";

/** The customer has no session (any more) and is on the way to the login, which brings them back to `returnTo`. */
export function SignedOut({ returnTo }: { returnTo: string }) {
  return (
    <p role="status">
      Przekierowujemy do logowania…{" "}
      <a href={loginHref(returnTo)} className="underline underline-offset-4">
        Zaloguj się
      </a>
    </p>
  );
}
