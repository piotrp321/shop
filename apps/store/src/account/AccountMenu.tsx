"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { type AccountSessionResult, loadAccountSession } from "@/api/client";
import { Button } from "@/components/ui/button";

/**
 * Account box in the header. Loads the session in the browser from `/auth/session`, so the catalog HTML stays the
 * same for everybody. Shows nothing while loading or when the session cannot be checked.
 */
export function AccountMenu() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [result, setResult] = useState<AccountSessionResult | null>(null);

  useEffect(() => {
    let active = true;
    void loadAccountSession().then((loaded) => {
      if (active) setResult(loaded);
    });
    return () => {
      active = false;
    };
  }, []);

  if (result?.status !== "ok") {
    return null;
  }

  if (result.session === null) {
    const query = searchParams.toString();
    const returnTo = query ? `${pathname}?${query}` : pathname;
    return (
      <a href={`/auth/login?returnTo=${encodeURIComponent(returnTo)}`} className="text-sm underline underline-offset-4">
        Zaloguj się
      </a>
    );
  }

  return (
    <div className="flex items-center gap-3">
      <span className="text-sm text-muted-foreground">Zalogowany jako {result.session.name}</span>
      <form method="post" action="/auth/logout">
        <Button type="submit" variant="outline" size="sm">
          Wyloguj się
        </Button>
      </form>
    </div>
  );
}
