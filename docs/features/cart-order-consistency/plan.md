# CART-ORDER-CONSISTENCY – plan zadań

Specyfikacja: `docs/features/cart-order-consistency/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi
subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie
realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi
epiku i wypycha ją. Zadania nie mają własnych PR. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | CART-ORDER-CONSISTENCY-1 | zmergowane, 60664561 i poprawki po przeglądzie d87c0206 (FULL: PASS, DELTA: PASS) |
| 2 | CART-ORDER-CONSISTENCY-2, CART-ORDER-CONSISTENCY-3 | zadanie 2 zmergowane (f862efb2; FULL: PASS); zadanie 3 zmergowane (752dafd9; FULL: CHANGES_REQUIRED, DELTA 1: CHANGES_REQUIRED, DELTA 2: PASS) |
| 3 | CART-ORDER-CONSISTENCY-4 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Przed startem

Sesja prowadząca sprawdza na aktualnym `main` i zapisuje wynik w opisie PR epiku:

- „Zależności i równoległość”: stan API-INPUT-CONTRACT (`OrderRequestViolationCases`, `InputContract/OrderingCases`)
  i ADMIN-UX-FIXES etap 2 (czy jest na `main`, `paths` jego planu),
  `git diff HEAD..origin/main -- e2e .github make apps/api/fixtures apps/api/openapi.json`, kolejność merge'u.
- Przebiegi R1, R2 i R4 specyfikacji nadal dają opisane wyniki (skrypt odtworzenia jest częścią testu E2E zadań 3 i 4:
  pierwsze uruchomienie przed zmianą to ich RED).
- Gałąź epiku jest wypychana po fali 1 dopiero razem z zadaniem 2 (test dymny k6 w CI wymaga nowego pola).

## Przegląd całej zmiany

`architecture-reviewer` (zmiana kontraktu złożenia zamówienia i odcisku żądania), `qa-adversarial` (współbieżność
złożenia ze zmianami koszyka, próby obejścia porównania: duplikaty, wielkość liter, lista innego koszyka, powtórzenie
klucza; znacznik kupującego: obcy znacznik, znacznik innego konta, brak sesji z polem `email`), `ux-reviewer` w trybie `integration` (komunikaty kasy przy 1280, 375 i 320 px, klawiatura, ogłaszanie).

## Zadania

```json
{
  "schema_version": 1,
  "epic": "CART-ORDER-CONSISTENCY",
  "spec": "docs/features/cart-order-consistency/spec.md",
  "tasks": [
    {
      "id": "CART-ORDER-CONSISTENCY-1",
      "title": "API i kasa: zamówienie tylko z koszyka zgodnego z listą pokazaną w kasie",
      "scope": "Spec, Z1 i pierwsze dwa punkty Z2 (decyzje D1, D2, D3, D8). API: pole expectedLines w obu żądaniach złożenia, value object ExpectedLines w Ordering, krok porównania w PlaceOrderHandler, wyjątek CartContentChanged (409 cart-changed), odcisk żądania z listą, eksport OpenAPI i wygenerowany klient sklepu. Sklep, tylko tyle, żeby zamówienia działały: kasa wysyła expectedLines z pokazanego podsumowania, BFF przekazuje pole (pickFields), problems.ts ma przyczynę cart_changed obsłużoną dzisiejszą gałęzią konfliktu (komunikat detail z API i ponowny odczyt koszyka). Poza zadaniem: lista zmian w komunikacie i kolejność odczytu przed komunikatem (zadanie 3), buyer (zadanie 4), k6 i test dymny (zadanie 2), blokada placeOrder z zapisami koszyka (D4), Cart i jego widok (bez zmian), openapi-admin.json.",
      "acceptance_criteria": [
        "Kontrakt: expectedLines wymagane w PlaceGuestOrderRequest i PlaceCustomerOrderRequest; każde naruszenie z tabeli Z1 specyfikacji daje 422 z podanym propertyPath i tytułem po polsku, nigdy 500 (brak klucza, null, nie lista, lista pusta, 51 elementów, element nie obiekt, zły variantId, quantity: tekst, ułamek, true, 0, -1, 100, duplikat wariantu także różniący się wielkością liter). Wiersze są w OrderRequestViolationCases (oba endpointy); zestaw InputContract nie obejmuje treści żądań.",
        "PlaceOrder: linie zablokowanego koszyka inne niż expectedLines (inna ilość, brak linii, dodatkowa linia) dają CartContentChanged (409, type cart-changed, detail ze specyfikacji) po CartEmpty i przed limitem zamówienia gościa, limitem nieopłaconych zamówień i wyceną. Po odrzuceniu: koszyk bez zmian, brak zamówienia, rezerwacji, użycia kodu, roszczenia limitu i wiersza outboxa, numer z sekwencji niepobrany. Lista zgodna bez względu na kolejność i wielkość liter UUID składa zamówienie jak dziś.",
        "Test integracyjny przebiegu R1: koszyk A × 1 i B × 2 w tej samej cenie, żądanie z A × 2 i B × 1 i z kwotą równą sumie koszyka daje CartContentChanged.",
        "Idempotencja: ten sam klucz i ta sama treść po złożeniu zwraca to samo zamówienie także przy opróżnionym koszyku (kroki 0 i 2 przed porównaniem); ten sam klucz z inną listą daje IdempotencyKeyReused; RequestFingerprint obejmuje listę w postaci kanonicznej.",
        "Współbieżność (PlaceOrderConcurrencyTest, osobne procesy, 5 rund): O8–O12 z tabeli specyfikacji oraz O5 naprzemiennie z listą sprzed scalenia i po scaleniu; po każdej rundzie kontrole O1–O7 i dodatkowo linie każdego zamówienia równe expectedLines żądania, które je złożyło. Żadna runda nie kończy się zakleszczeniem ani zamówieniem o liniach innych niż lista.",
        "Sklep: treść POST /api/checkout ma expectedLines równe liniom pokazanego podsumowania (variantId, quantity), także po zastosowaniu i usunięciu kodu rabatowego i po ponownym odczycie po 409; BFF przekazuje pole do API bez zmian dla gościa i klienta; 409 cart-changed nie składa zamówienia, pokazuje detail z API i odświeża podsumowanie (Vitest: CheckoutPage.test.tsx, app/api/checkout/route.test.ts, client.test.ts).",
        "apps/api/openapi.json i apps/store/src/api/schema.d.ts są wygenerowane (make -C apps/api openapi, make api-client) w tym samym commicie co zmiana API; apps/api/openapi-admin.json i apps/admin bez różnic.",
        "Istniejące testy złożenia zamówienia (jednostkowe, integracyjne, funkcjonalne, współbieżności, kodu rabatowego, dostawy) dostają tylko nowe pole w żądaniu, bez zmiany asercji; istniejące E2E zamówień przechodzą bez zmiany asercji i helperów e2e/support.",
        "Dokumentacja w tym samym commicie: docs/features/checkout/spec.md (komenda, krok porównania, odcisk, tabela API, scenariusze O8–O11, zdanie „żądanie nie niesie pozycji” zastąpione zasadą „tylko do porównania”), apps/store/CLAUDE.md (lista pól przekazywanych do API), apps/api/CLAUDE.md tylko jeśli zmienia się zasada transakcji albo kolejność blokad (nie powinna).",
        "TDD: raport zawiera komendy i rzeczywiste wyniki testów przed implementacją (RED: test jednostkowy ExpectedLines i handlera, test integracyjny R1, O8–O11, wiersze kontraktu wejścia, test treści żądania sklepu nie przechodzą) i po niej (GREEN); make -C apps/api check, make -C apps/api test, make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/tests/",
        "apps/api/config/packages/nelmio_api_doc.yaml",
        "apps/api/openapi.json",
        "apps/store/src/api/",
        "apps/store/src/checkout/",
        "apps/store/app/api/checkout/",
        "apps/store/CLAUDE.md",
        "docs/features/checkout/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "CART-ORDER-CONSISTENCY-2",
      "title": "k6 i test dymny wdrożenia wysyłają listę pozycji",
      "scope": "Spec, Z5. load/lib/flows.js (przepływ zamówienia k6) i infra/k8s/smoke/checks.py z testem infra/k8s/tests/test_smoke_messaging.py wysyłają expectedLines z odczytanego koszyka. Bez zmian w aplikacjach, chartcie i workflowach. Raporty w docs/reports są niezmienne i zostają.",
      "acceptance_criteria": [
        "Przepływ zamówienia k6 i test dymny biorą expectedLines z odpowiedzi koszyka, którą odczytały przed złożeniem (variantId, quantity), nie z danych wejściowych skryptu.",
        "make load-smoke na lokalnym stosie przechodzi; make -C infra/k8s check przechodzi.",
        "Ścieżka błędu: odpowiedź 422 albo 409 cart-changed na złożenie kończy skrypt k6 i test dymny błędem, który podaje status i type odpowiedzi (wynik obu ścieżek w raporcie; test_smoke_messaging.py ma przypadek odrzucenia).",
        "load/README.md opisuje pole tylko wtedy, gdy opisuje treść żądania zamówienia.",
        "Konfiguracja i narzędzia: bez TDD, weryfikacja komendami powyżej; raport mówi to wprost."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "CART-ORDER-CONSISTENCY-1"
      ],
      "paths": [
        "load/",
        "infra/k8s/smoke/",
        "infra/k8s/tests/test_smoke_messaging.py"
      ],
      "parallel_with": [
        "CART-ORDER-CONSISTENCY-3",
        "CART-ORDER-CONSISTENCY-4"
      ]
    },
    {
      "id": "CART-ORDER-CONSISTENCY-3",
      "title": "Kasa: komunikat rozbieżności mówi, co się zmieniło",
      "scope": "Spec, Z3 (decyzja D5; #852) i „Granica ochrony zamówienia”. Sklep: po 409 cart-changed i order-total-changed kasa najpierw czyta koszyk (formularz zablokowany, region „Składamy zamówienie…”), potem w jednym renderze pokazuje komunikat z listą zmian, link „Przejdź do koszyka” i podsumowanie z serwera; podsumowanie nieaktualne po nieudanym odczycie. Czysta funkcja różnic dwóch CartView w osobnym module src/checkout. Bez zmian API i BFF. Poza zadaniem: buyer (zadanie 4), odświeżanie po powrocie do karty (D7), limit czasu żądań (#865), komunikat strony koszyka po spóźnionym zapisie. E2E: pomocniki dwóch kart i wstrzymywania żądań lokalne w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "Funkcja różnic (Vitest): każdy wiersz tabeli zmian specyfikacji daje podany tekst; kolejność podsumowania; brak różnic daje pustą listę; pozycja bez produktu (wariant nieznany Catalogowi) nie wywołuje błędu; kwoty przez formatPrice; funkcja niczego nie sumuje.",
        "CheckoutPage (Vitest, wstrzymane obietnice): po 409 cart-changed albo order-total-changed i przed odpowiedzią odczytu nie ma komunikatu, region statusu mówi „Składamy zamówienie…”, pola, wybór dostawy i płatności oraz kod mają aria-disabled, a zmiana pola i wyboru w tym czasie nic nie zmienia; po zwolnieniu odczytu komunikat z listą zmian i nowe podsumowanie pojawiają się w tym samym renderze, formularz jest odblokowany z zachowanymi danymi, następne wysłanie ma nowy Idempotency-Key i expectedLines nowego podsumowania.",
        "Fokus według F2: po odczycie idzie na komunikat tylko wtedy, gdy został na elemencie wysłania albo zginął; przeniesiony przez klienta w trakcie zostaje.",
        "Stany po odczycie: pusty koszyk i pozycje nie do kupienia (dzisiejszy widok zamiast formularza), 401 (widok wygasłej sesji), kod rabatowy odrzucony po zmianie koszyka (komunikat przy kodzie jak dziś, wiersz „Rabat” w liście zmian), wybrana metoda dostawy niedostępna (wiersz listy, wybór zdjęty), brak różnic (komunikat bez listy).",
        "Odczyt nieudany: komunikat ze specyfikacji, podsumowanie oznaczone jako nieaktualne; kolejne kliknięcie „Zamawiam z obowiązkiem zapłaty” czyta koszyk i nie wysyła POST /api/checkout (liczba żądań w teście); po udanym odczycie pokazuje komunikat z listą i dopiero następne kliknięcie zamawia.",
        "E2E (e2e/tests/cart-order-consistency.spec.ts, dwie karty jednego kontekstu, 1280 px): R1 – zamówienie nie powstaje, komunikat wymienia obie pozycje, podsumowanie ma nowe ilości, drugie kliknięcie składa zamówienie, a e-mail zamówienia ma ilości z nowego podsumowania. R2 – w chwili pojawienia się komunikatu (odpowiedź odczytu koszyka wstrzymana przez page.route, potem zwolniona) podsumowanie pokazuje 3 szt. i nową kwotę, komunikat ma „było 1 szt., jest 3 szt.”, wiersz „Do zapłaty” i link do koszyka. R3 – zamówienie z drugiej karty jest zgodne z kasą, spóźniony zapis ilości go nie zmienia.",
        "E2E 375 px z dotykiem i 320 px: komunikat R1 nie powoduje przewijania poziomego, link „Przejdź do koszyka” jest osiągalny klawiaturą i dotykiem.",
        "Komentarz w apps/api/fixtures/stock.yaml (lista testów E2E, które zamawiają produkt) wymienia nowe testy; to jedyna zmiana w apps/api.",
        "Istniejące testy kasy przechodzą; zmieniają się tylko asercje tekstu komunikatu po order-total-changed i kolejność „komunikat, potem odczyt” tam, gdzie test ją utrwala (wymienione w raporcie).",
        "Dokumentacja w tym samym commicie: docs/features/checkout/spec.md (tabela UI odrzuceń, testy), docs/features/cart/spec.md i docs/features/store-ux-fixes/spec.md („Granica ochrony zamówienia” według tej specyfikacji), apps/store/CLAUDE.md tylko jeśli zmienia się zasada fokusu po odrzuceniu.",
        "TDD: raport zawiera komendy i rzeczywiste wyniki przed zmianą (RED: testy funkcji różnic, testy CheckoutPage kolejności i podsumowania nieaktualnego, E2E R1 w części komunikatu i R2 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "CART-ORDER-CONSISTENCY-1"
      ],
      "paths": [
        "apps/store/src/checkout/",
        "apps/api/fixtures/stock.yaml",
        "e2e/tests/cart-order-consistency.spec.ts",
        "docs/features/checkout/spec.md",
        "docs/features/cart/spec.md",
        "docs/features/store-ux-fixes/spec.md"
      ],
      "parallel_with": [
        "CART-ORDER-CONSISTENCY-2"
      ]
    },
    {
      "id": "CART-ORDER-CONSISTENCY-4",
      "title": "Kasa: zamówienie tylko dla kupującego pokazanego w kasie (gość, konto A, konto B)",
      "scope": "Spec, Z4 (decyzja D6; przebiegi R4, R5, R6). Sklep: GET /auth/session zwraca znacznik kupującego (HMAC z SessionData.sub), kasa odsyła go w polu buyer (albo guest), BFF porównuje go z sub sesji z chwili wysłania przed scaleniem koszyka i przed API, własny typ 409 checkout-buyer-changed, kasa czyta sesję i koszyk od nowa i pokazuje komunikat. Konto zamówienia nadal wynika wyłącznie z sesji; pole nie trafia do API. Bez zmian API i bez zmiany SessionData.",
      "acceptance_criteria": [
        "GET /auth/session (Vitest): klient dostaje buyer = HMAC-SHA256 z „checkout-buyer:<sub>” kluczem STORE_SESSION_SECRET w base64url; ten sam sub w dwóch sesjach i po odświeżeniu tokenu daje ten sam znacznik, inny sub inny; odpowiedź nie zawiera sub, identyfikatora sesji ani tokenów; gość dostaje null; 503 i wygasła sesja jak dziś.",
        "BFF (Vitest, dubler API i sesji): każdy wiersz tabeli Z4 specyfikacji z liczbą wywołań API i scalenia koszyka gościa (zero przy 409, 401, 503 i 422) i ze stanem ciasteczek; porównanie znacznika w stałym czasie; buyer nie jest w treści żądania do API; 409 ma tylko type i detail.",
        "Zmiana konta: kasa konta A wysłana przy sesji konta B z identycznymi pozycjami i kwotą daje 409 checkout-buyer-changed, bez zamówienia i bez scalenia. Niedostępność sesji: kasa konta A bez ciasteczka sesji, ze złym podpisem, z sesją usuniętą z Redisa albo z tokenem nie do odświeżenia daje 401, a przy błędzie Redisa albo Keycloaka 503; w żadnym z tych przypadków nie powstaje zamówienie gościa, także gdy żądanie niesie email, a przeglądarka ma ciasteczko koszyka gościa. Odświeżenie tokenu w trakcie żądania nie daje konfliktu.",
        "Kasa (Vitest, wstrzymane obietnice): po 409 checkout-buyer-changed formularz jest zablokowany do końca ponownego odczytu sesji i koszyka, a zmiana pola w tym czasie nic nie zmienia; potem w jednym renderze komunikat ze specyfikacji z adresem konta (albo tekst o wylogowaniu), kupujący i podsumowanie z odczytu, zachowane pola i wybory; następne wysłanie niesie znacznik z ostatniego odczytu, nowy Idempotency-Key i expectedLines nowego podsumowania. Kolejna zmiana konta między odczytem a wysłaniem daje drugie 409 i drugi odczyt. Odczyt nieudany: komunikat ze specyfikacji, kasa nieaktualna, kolejne kliknięcie czyta sesję i koszyk i nie wysyła POST /api/checkout.",
        "E2E (e2e/tests/cart-order-consistency.spec.ts, dwie karty): R4 (gość, logowanie Jana), R5 (konto A, w drugiej karcie wylogowanie i logowanie na konto B z tymi samymi pozycjami i kwotą: brak zamówienia na obu kontach, koszyk B bez zmian, komunikat z adresem B, drugie kliknięcie składa zamówienie B) i R6 (konto A, wylogowanie w drugiej karcie: widok wygasłej sesji, żadnego zamówienia gościa). Konta z e2e/support/accounts.ts, bez nowych kont w realmie.",
        "Typ checkout-buyer-changed jest własnym typem BFF w src/checkout/problems.ts, poza mapą typów ze schematu API; przyczynę wyznacza type, nigdy tekst.",
        "Dokumentacja w tym samym commicie: docs/features/checkout/spec.md (BFF, tabela UI), apps/store/CLAUDE.md („Kasa”: pole buyer i jego sprawdzenie; „Logowanie”: znacznik w /auth/session i czego odpowiedź nie zawiera).",
        "TDD: raport zawiera komendy i rzeczywiste wyniki przed zmianą (RED: testy znacznika, testy BFF tabeli Z4, test kasy po 409 oraz E2E R4, R5 i R6 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "CART-ORDER-CONSISTENCY-3"
      ],
      "paths": [
        "apps/store/src/checkout/",
        "apps/store/src/auth/",
        "apps/store/src/api/client.ts",
        "apps/store/app/api/checkout/",
        "apps/store/app/auth/session/",
        "apps/store/CLAUDE.md",
        "e2e/tests/cart-order-consistency.spec.ts",
        "docs/features/checkout/spec.md"
      ],
      "parallel_with": [
        "CART-ORDER-CONSISTENCY-2"
      ]
    }
  ]
}
```

## Zakończenie

- `make check` i testy `apps/api` i `apps/store`, `STORE_MODE=production make e2e`, `make load-smoke`.
- Po merge'u: zamknąć #853 i #852; granice z sekcji „Poza zakresem” specyfikacji, których właściciel nie przyjął jako
  ostatecznych, zgłosić jako osobne issues (komunikat koszyka po spóźnionym zapisie).
