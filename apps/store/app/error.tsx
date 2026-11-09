"use client";

import { Button } from "@/components/ui/button";

/** The error page of a failed read of the catalog, in the frame of the 404 page (`app/not-found.tsx`). */
export default function Error({ retry }: { error: unknown; retry: () => void }) {
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
