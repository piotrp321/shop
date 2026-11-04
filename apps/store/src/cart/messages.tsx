export const SAVE_FAILED = "Nie udało się zapisać zmian. Spróbuj ponownie.";
export const PRODUCT_GONE = "Produkt nie jest już dostępny";

/** The session ended while the page was open; the login brings the customer back to `returnTo`. */
export function SessionExpired({ returnTo }: { returnTo: string }) {
  return (
    <>
      Twoja sesja wygasła. Zaloguj się ponownie.{" "}
      <a href={`/auth/login?returnTo=${encodeURIComponent(returnTo)}`} className="underline underline-offset-4">
        Zaloguj się
      </a>
    </>
  );
}
