# ADMIN-UX-FIXES etap 2 – plan zadań

Specyfikacja: `docs/features/admin-ux-fixes/spec.md`, sekcja „Etap 2: bezpieczeństwo formularzy”. Epik prowadzi główna
sesja Claude Code z natywnymi subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku
jako szkic. Każde zadanie realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe
gałęzie zadań do gałęzi epiku i wypycha ją. Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with`
mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ADMIN-UX-2-1 | zmergowane do gałęzi epiku, commit 238373aa (czoło zadania 7be9a92e) |
| 2 | ADMIN-UX-2-2, ADMIN-UX-2-3, ADMIN-UX-2-4 | po kolei (decyzja właściciela 2026-11-23); ADMIN-UX-2-2 w toku |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ADMIN-UX-2",
  "spec": "docs/features/admin-ux-fixes/spec.md",
  "tasks": [
    {
      "id": "ADMIN-UX-2-1",
      "title": "Wspólny kod limitu i nieznanego wyniku oraz formularz promocji (B3, B4)",
      "scope": "apps/admin: wspólny kod w `src/composables` (limit żądania, klasyfikacja wyniku zapisu, spóźniona informacja, porównanie żądań dla zgodności) i jego pierwsze użycie w formularzu promocji: B3 dla „Odśwież” (#866 punkty 1 i 2) oraz B4 z E7 dla zapisu promocji. Spec: „Zasady wspólne”, „B3”, „B4”, „Zadania dokumentacji”. Nie obejmuje akcji „Zakończ teraz” i „Usuń” ani innych formularzy.",
      "acceptance_criteria": [
        "Wspólny kod ma własne testy: limit 10 s, klasyfikacja (2xx sukces, 4xx odmowa, brak odpowiedzi w limicie, błąd sieci i 5xx jako nieznany wynik), pomijanie odpowiedzi nieaktualnego żądania także w `finally`, porównanie żądań (daty jako momenty, listy w kolejności, wiersz bez identyfikatora po wartościach).",
        "B3 w promocji („Kryteria etapu 2” punkt 2): „Odśwież” bez odpowiedzi odblokowuje pola po 10 s z komunikatem i ponowieniem, zwolniona potem odpowiedź niczego nie zmienia, „Odświeżamy…” jest w `role=\"status\"`; nieudane „Odśwież” zostawia formularz zamiast komunikatu wczytania, a testy tego zachowania zmieniają się razem z nim.",
        "B4 dla formularza promocji (spec „B4”, „Kryteria etapu 2” punkt 3 (a)–(l); w (d) „Od teraz” nigdy nie daje zgodności): każdy podpunkt ma test Vitest ze wstrzymaną obietnicą i fałszywym zegarem; szkic, stan bazowy i wersja zmieniają się tylko po potwierdzonym sukcesie, zgodności i udanym odczycie po „Odrzuć moje zmiany i pokaż stan z serwera”.",
        "409 zapisu promocji inny niż `promotion-overlap` nie wywołuje odczytu: komunikat, szkic zostaje, przycisk „Odrzuć moje zmiany i pokaż stan z serwera”; testy dotychczasowego odświeżenia po 409 zmieniają się razem z zachowaniem. „Zakończ teraz” i „Usuń” działają jak dotąd.",
        "Zachowanie etapu 1 (blokada zapisu, oczekiwanie na początek „Od teraz”, limit 10 s oczekiwania) zostaje; jego testy przechodzą bez zmiany asercji poza nazwanymi wyżej.",
        "Dokumentacja: w tej specyfikacji D7, K2 i „Poza zakresem” etapu 1; `docs/features/promotions/spec.md` („inne 409”); `apps/admin/CLAUDE.md` (zasada szkicu i nieznanego wyniku ze wskazaniem wspólnego kodu).",
        "Każde kryterium ma test napisany przed zmianą; raport podaje komendy i wyniki RED i GREEN.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą; `git diff` względem `main` nie zawiera `apps/api`, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/admin/src/composables/",
        "apps/admin/src/components/FormSafetyNotices.vue",
        "apps/admin/src/promotions/",
        "apps/admin/src/views/PromotionFormView.vue",
        "apps/admin/tests/",
        "apps/admin/CLAUDE.md",
        "docs/features/admin-ux-fixes/spec.md",
        "docs/features/promotions/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "ADMIN-UX-2-2",
      "title": "Formularz kodu rabatowego: blokada pól i nieznany wynik (B1, B4)",
      "scope": "apps/admin: `src/discount-codes`, `DiscountCodeFormView.vue`, ich testy i E2E kodu. Spec: „B1”, „B4”, „Kryteria etapu 2” punkty 1, 3 i 4 (a), (d). Używa wspólnego kodu z ADMIN-UX-2-1 bez jego zmiany.",
      "acceptance_criteria": [
        "B1 („Kryteria etapu 2” punkt 1): przy wstrzymanym zapisie wszystkie pola są wyłączone, drugie „Zapisz” nie wysyła żądania, po 422 pola mają wysłane wartości; odpowiedź zapisu po przejściu do innego kodu albo formularza nowego jest pomijana.",
        "B4 dla formularza kodu rabatowego (spec „B4”, „Kryteria etapu 2” punkt 3 (a)–(l)): każdy podpunkt ma test Vitest ze wstrzymaną obietnicą i fałszywym zegarem; szkic, stan bazowy i wersja zmieniają się tylko po potwierdzonym sukcesie, zgodności i udanym odczycie po „Odrzuć moje zmiany i pokaż stan z serwera”.",
        "409 inny niż `discount-code-taken` nie wywołuje odczytu (E7); testy dotychczasowego `reloadQuietly` po 409 zmieniają się razem z zachowaniem; 409 `discount-code-taken` po nieznanym wyniku utworzenia ma dopisane zdanie.",
        "E2E `admin-screens-discount-codes.spec.ts`: punkty 4 (a) i 4 (d) specyfikacji; oba nie przechodzą przed zmianą; `make e2e` przechodzi.",
        "Dokumentacja: `docs/features/discount-codes/spec.md` („inne 409: `detail` i odświeżenie”).",
        "Każde kryterium ma test napisany przed zmianą; raport podaje komendy i wyniki RED i GREEN.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą; `git diff` względem `main` nie zawiera `apps/api`, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-UX-2-1"
      ],
      "paths": [
        "apps/admin/src/discount-codes/",
        "apps/admin/src/views/DiscountCodeFormView.vue",
        "apps/admin/tests/useDiscountCodeForm.test.ts",
        "apps/admin/tests/DiscountCodeFormView.test.ts",
        "e2e/tests/admin-screens-discount-codes.spec.ts",
        "docs/features/discount-codes/spec.md"
      ],
      "parallel_with": [
        "ADMIN-UX-2-3",
        "ADMIN-UX-2-4"
      ]
    },
    {
      "id": "ADMIN-UX-2-3",
      "title": "Formularz produktu: blokada pól, limit „Odśwież” i nieznany wynik (B2, B3, B4)",
      "scope": "apps/admin: `src/products` (formularz, edytor wariantów, zdjęcia), `ProductFormView.vue`, ich testy i E2E produktu. Spec: „B2”, „B3”, „B4”, „Kryteria etapu 2” punkty 1–3 i 4 (b), (c). Nie obejmuje karty „Ceny i stany” ani wysyłania zdjęć poza blokadą dodawania plików. Używa wspólnego kodu z ADMIN-UX-2-1 bez jego zmiany.",
      "acceptance_criteria": [
        "B2 („Kryteria etapu 2” punkt 1): przy wstrzymanym zapisie, publikacji, wycofaniu i „Odśwież” wyłączone są pola, wybór kategorii, edytor wariantów i zdjęcia (opis, kolejność, usuwanie, dodawanie plików); „Zapisz”, „Opublikuj” i „Wycofaj” są nieaktywne także w trakcie „Odśwież”; karta „Ceny i stany” nie jest blokowana; odpowiedź zapisu po przejściu do innego produktu jest pomijana.",
        "B3 („Kryteria etapu 2” punkt 2) dla „Odśwież” produktu.",
        "B4 dla formularza produktu (spec „B4”, „Kryteria etapu 2” punkt 3 (a)–(l), także dla publikacji i wycofania; w (d) pusty slug nigdy nie daje zgodności, a publikacja ze zgodnymi polami i statusem `draft` jej nie daje): każdy podpunkt ma test Vitest ze wstrzymaną obietnicą i fałszywym zegarem; szkic, stan bazowy i wersja zmieniają się tylko po potwierdzonym sukcesie, zgodności i udanym odczycie po „Odrzuć moje zmiany i pokaż stan z serwera”.",
        "409 `product-modified` zostaje bez automatycznego odczytu; jego przycisk działa według „Odrzuć moje zmiany i pokaż stan z serwera” (szkic zastępowany dopiero po odpowiedzi 200).",
        "E2E `catalog-admin.spec.ts`: punkty 4 (b) i 4 (c) specyfikacji; oba nie przechodzą przed zmianą; `make e2e` przechodzi.",
        "Dokumentacja: `docs/features/admin-design/spec.md`, „Po zapisie”: wyjątek #250 F16 nie dotyczy zapisu żadnego z formularzy; test tego wyjątku w `ProductFormView.test.ts` zmienia się razem z zachowaniem.",
        "Każde kryterium ma test napisany przed zmianą; raport podaje komendy i wyniki RED i GREEN.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą; `git diff` względem `main` nie zawiera `apps/api`, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-UX-2-1"
      ],
      "paths": [
        "apps/admin/src/products/",
        "apps/admin/src/views/ProductFormView.vue",
        "apps/admin/tests/useProductForm.test.ts",
        "apps/admin/tests/ProductFormView.test.ts",
        "e2e/tests/catalog-admin.spec.ts",
        "docs/features/admin-design/spec.md"
      ],
      "parallel_with": [
        "ADMIN-UX-2-2",
        "ADMIN-UX-2-4"
      ]
    },
    {
      "id": "ADMIN-UX-2-4",
      "title": "Formularz metody dostawy: nieznany wynik (B4)",
      "scope": "apps/admin: `src/delivery/useDeliveryMethodForm.ts`, `DeliveryMethodFormView.vue` i ich test. Spec: „B4”, „Kryteria etapu 2” punkt 3. Blokada pól zapisu już istnieje. Używa wspólnego kodu z ADMIN-UX-2-1 bez jego zmiany.",
      "acceptance_criteria": [
        "B4 dla formularza metody dostawy (spec „B4”, „Kryteria etapu 2” punkt 3 (a)–(l)): każdy podpunkt ma test Vitest ze wstrzymaną obietnicą i fałszywym zegarem; szkic, stan bazowy i wersja zmieniają się tylko po potwierdzonym sukcesie, zgodności i udanym odczycie po „Odrzuć moje zmiany i pokaż stan z serwera”.",
        "409 wersji nie wywołuje odczytu (E7): komunikat, szkic zostaje, przycisk „Odrzuć moje zmiany i pokaż stan z serwera”; testy dotychczasowego `reloadQuietly` po 409 zmieniają się razem z zachowaniem; 409 `delivery-method-name-taken` po nieznanym wyniku utworzenia ma dopisane zdanie.",
        "Dokumentacja: `docs/features/delivery/spec.md` (409 wersji w formularzu metody).",
        "Każde kryterium ma test napisany przed zmianą; raport podaje komendy i wyniki RED i GREEN.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą; `git diff` względem `main` nie zawiera `apps/api`, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-UX-2-1"
      ],
      "paths": [
        "apps/admin/src/delivery/useDeliveryMethodForm.ts",
        "apps/admin/src/views/DeliveryMethodFormView.vue",
        "apps/admin/tests/DeliveryMethodFormView.test.ts",
        "docs/features/delivery/spec.md"
      ],
      "parallel_with": [
        "ADMIN-UX-2-2",
        "ADMIN-UX-2-3"
      ]
    }
  ]
}
```
