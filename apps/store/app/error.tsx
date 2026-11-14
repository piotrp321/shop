"use client";

import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { reportBrowserError } from "@/observability/lazy";

/**
 * The error page of a failed read of the catalog, in the frame of the 404 page (`app/not-found.tsx`). It reports an
 * error of the browser to Sentry once its SDK is started; an error with `digest` came from the server, which reported
 * it already (`@/observability/lazy`).
 */
export default function Error({ error, retry }: { error: unknown; retry: () => void }) {
  useEffect(() => {
    reportBrowserError(error);
  }, [error]);

  return (
    <main className="mx-auto flex max-w-content flex-col items-start gap-6 px-gutter py-16 lg:py-28">
      <h1 className="text-4xl font-semibold tracking-tight lg:text-6xl">Nie udało się wczytać katalogu</h1>
      <p className="text-lg text-muted-foreground">Sklep jest chwilowo niedostępny. Spróbuj ponownie za chwilę.</p>
      <Button size="lg" onClick={() => retry()}>
        Spróbuj ponownie
      </Button>
    </main>
  );
}
