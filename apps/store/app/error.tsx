"use client";

import { Button } from "@/components/ui/button";

export default function Error({ retry }: { error: unknown; retry: () => void }) {
  return (
    <main className="mx-auto flex max-w-3xl flex-col items-start gap-4 p-8">
      <h1 className="text-2xl font-semibold">Nie udało się wczytać katalogu</h1>
      <p className="text-muted-foreground">Sklep jest chwilowo niedostępny. Spróbuj ponownie za chwilę.</p>
      <Button onClick={() => retry()}>Spróbuj ponownie</Button>
    </main>
  );
}
