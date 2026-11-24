# API-INPUT-CONTRACT etap 2 – plan zadań

Specyfikacja: `docs/features/api-input-contract/spec.md`, sekcja „Etap 2: reguła tekstu”. Epik prowadzi główna sesja
Claude Code z natywnymi subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako
szkic. Każde zadanie realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe
gałęzie zadań do gałęzi epiku i wypycha ją. Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with`
mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | API-INPUT-2-1 | zmergowane do gałęzi epiku, `a5c98b8b` (zadanie `c3bcecc9`) |
| 2 | API-INPUT-2-2, API-INPUT-2-3, API-INPUT-2-4 | API-INPUT-2-2: zmergowane do gałęzi epiku, `9d8c04f3` (zadanie `e16a481c`); API-INPUT-2-3: zmergowane, `2af1af78` (zadanie `fd03886a`); API-INPUT-2-4: zmergowane, `8923c17b` (zadanie `92e7c491`) |
| 3 | API-INPUT-2-5 | zmergowane do gałęzi epiku, `ec5a05ff` (zadanie `ab31c13f`) |

| poprawki | API-INPUT-2-FIX1 (po przeglądzie całej zmiany) | zmergowane do gałęzi epiku, `805bdeff` (zadanie `a4f7fd4c`) |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Praca szeregowa

- API-INPUT-2-2, API-INPUT-2-3: `apps/api/openapi.json`, `apps/api/openapi-admin.json`, oba `schema.d.ts`,
  `apps/api/src/DemoData/`, `docs/features/quality-fixes/spec.md`; oba zmieniają klasy żądań i zapytań, więc oba
  uruchamiają eksport OpenAPI i generowanie klientów, a wynik to wspólne pliki; generator danych demo używa value
  objectów obu modułów.
- API-INPUT-2-2, API-INPUT-2-4: `apps/api/openapi-admin.json`, `apps/admin/src/api/schema.d.ts`,
  `docs/features/admin-features/spec.md`; eksport OpenAPI panelu to jeden plik, a opis list panelu jedna specyfikacja.
- API-INPUT-2-3, API-INPUT-2-4: `apps/api/openapi-admin.json`, `apps/admin/src/api/schema.d.ts`; jak wyżej.

## TDD w zadaniach

Zadanie nie może zostawić gałęzi z czerwonymi testami, więc nie ma osobnego zadania „same testy”. Każde zadanie:

1. dopisuje swoje testy jednostkowe i wiersze tabeli testu kontraktu tekstów, bez zmiany kodu produkcyjnego;
2. uruchamia je i zapisuje w raporcie komendę i wynik RED: które wykonania nie przechodzą i jaką odpowiedzią (status,
   zapisana wartość);
3. wprowadza zmianę i zapisuje komendę i wynik GREEN, potem `make -C apps/api check` i `make -C apps/api test`.

Wykonania klas „zachowany” i „zły typ” oraz część klasy „pusty” przechodzą już przed zmianą: są regresją, a RED
zadania dotyczy klas, które raport próby pokazuje jako przyjęte albo zapisane ze zmianą. Zadanie 1 zaczyna od testu
jednostkowego `Text`, którego klasa jeszcze nie istnieje. Test odczytu zapisanych wierszy jest czerwony przed zmianą
repozytorium (odpowiedź 422 albo 500 zamiast odczytu). Strażnik w zadaniu 5 pokazuje RED próbą obejścia w teście, w tym usunięciem wiersza `POST /orders` `email`.

## Przewidywany rozmiar

Szacunek przed implementacją:

| Zadanie | Kod produkcyjny | Testy nowe | Testy zmienione |
|---|---|---|---|
| 1 | `Text` ok. 60 linii; Delivery ok. 6 plików, ok. 40 linii | ok. 450 linii (test `Text`, wartości, mechanizm wiersza, wiersze i odczyt Delivery) | ok. 20 linii |
| 2 | ok. 12 plików, ok. 90 linii | ok. 200 linii | ok. 40 linii w testach value objectów |
| 3 | ok. 12 plików, ok. 90 linii | ok. 230 linii | ok. 40 linii |
| 4 | ok. 8 plików, ok. 50 linii | ok. 120 linii | ok. 20 linii |
| 5 | brak | ok. 120 linii | brak |

Razem około 330 linii kodu produkcyjnego i 1100 linii testów; dokumentacja około 40 linii.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "API-INPUT-2",
  "spec": "docs/features/api-input-contract/spec.md",
  "tasks": [
    {
      "id": "API-INPUT-2-1",
      "title": "Wspólna reguła tekstu, mechanizm testu kontraktu tekstów i teksty metod dostawy",
      "scope": "apps/api: Shared (Domain) i Delivery. Specyfikacja, „Etap 2: reguła tekstu”: „Reguła” (T1–T5), „Rozwiązanie” punkt 1 oraz punkty 2–4 dla Delivery, „Pola treści JSON” (wiersze name i description metod dostawy), „Odczyt zapisanych wierszy” (Delivery), „Testy” (test jednostkowy reguły, mechanizm testu kontraktu tekstów, odczyt Delivery), „Dokumentacja razem z implementacją” (zdania apps/api/CLAUDE.md o Shared\\Domain\\Text i o odczycie bez walidacji; zdania o tekstach w docs/features/delivery/spec.md). Zadanie zakłada klasę App\\Shared\\Domain\\Text, wspólne wartości klas tekstu w tests/Support/InputConformance/InputValues.php i mechanizm wiersza testu kontraktu tekstów w tests/Functional/Shared/InputContract z plikami wierszy każdego modułu (Catalog, Delivery, Inventory, Ordering, Pricing; poza Delivery puste), tak żeby kolejne zadania zmieniały tylko plik swojego modułu. Nie obejmuje: pól innych modułów, fraz q, strażnika zamkniętej listy, trackingUrlTemplate (lista „Pola tekstowe poza regułą”).",
      "acceptance_criteria": [
        "App\\Shared\\Domain\\Text jest czystym PHP bez stanu i daje: sprawdzenie znaków niedozwolonych z listą znaków dozwolonych pola (T1), normalizację (T2; tekst pusty według T3 wraca jako pusty tekst) i długość po normalizacji (T4); Deptrac przechodzi bez zmiany deptrac.yaml.",
        "Test jednostkowy Text obejmuje pełną listę ze specyfikacji („Testy”, test jednostkowy reguły): każdy znak T1 na początku, w środku i na końcu, znaki dozwolone pól wielowierszowych, niepoprawny UTF-8 (samotny bajt kontynuacji, ucięta sekwencja, same złe bajty), każdy znak T2 na brzegach i w środku, teksty puste T3 pojedyncze i mieszane, teksty T5 (flaga Anglii na końcu, U+FE0F po znaku, emoji z U+200D) i długość po normalizacji.",
        "Mechanizm wiersza testu kontraktu tekstów wykonuje dla wiersza klasy z tabeli wykonań specyfikacji (pusty, niedozwolony, dozwolony w polu, brzegi, zachowany, zły typ), każde wykonanie jako osobny test na stanie zbudowanym od zera; każde 4xx ma dokładnie jedno naruszenie o podanym propertyPath i title, a dla 2xx test porównuje zapisaną wartość. Mechanizm ma też wiersz pola z formatem (lista przypadków: wysłana wartość, status, zapisana wartość albo komunikat) i wykonanie „brzegi” wiersza frazy (fraza czysta, między U+200B i między U+00A0 na rekordzie z fikstury: te same pozycje, kolejność i licznik); wierszy tych rodzajów zadanie nie dodaje.",
        "POST /api/v1/admin/delivery-methods i PUT /api/v1/admin/delivery-methods/{id}: name pusty według T3 (U+00A0, U+200B, U+3164) to 422 przy name „Nazwa jest wymagana.”; name z U+0000, U+009F albo U+2028 w środku to 422 „Nazwa zawiera niedozwolone znaki.”; description pusty według T3 jest zapisany jako pusty opis, a z U+009F to 422 „Opis zawiera niedozwolone znaki.”; liczba albo lista w miejscu tekstu to 422 z komunikatem kolumny „Zły typ”.",
        "Nazwa i opis metody dostawy między U+00A0 albo między U+200B są zapisane bez tych znaków; tekst zakończony flagą Anglii, tekst z emoji z U+200D i tekst z polskimi znakami są zapisane bez zmian.",
        "Metoda dostawy zapisana w bazie z nazwą, której reguła nie przyjmuje (U+200B jako cała nazwa; nazwa z U+009F), jest zwracana przez listę i szczegóły panelu i przez koszyk bez zmiany; zapis z panelu bez poprawienia nazwy to 422 przy name.",
        "Komunikaty, propertyPath i liczba naruszeń pól metod dostawy poza powyższymi nie zmieniają się; testy metod dostawy, które sprawdzały przyjęcie znaku niewidocznego albo U+009F, zmieniają się razem z regułą.",
        "apps/api/CLAUDE.md ma zdanie o Shared\\Domain\\Text jako jedynym miejscu reguły tekstu (value objecty zostają własne dla contextu) i zasadę, że odczyt z bazy nie waliduje tekstów; docs/features/delivery/spec.md nie ma zdań sprzecznych z regułą.",
        "Raport zadania zawiera komendy i wyniki RED i GREEN; make -C apps/api check i make -C apps/api test przechodzą, a pliki OpenAPI i klient TS panelu są aktualne."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Shared/",
        "apps/api/src/Delivery/",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "apps/api/openapi-admin.json",
        "apps/admin/src/api/schema.d.ts",
        "docs/features/delivery/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-2-2",
      "title": "Reguła tekstu w Catalog: kategoria, produkt, frazy list produktów i odczyt zapisanych wierszy",
      "scope": "apps/api: Catalog. Specyfikacja, „Etap 2: reguła tekstu”: „Pola treści JSON” (name kategorii; name, shortDescription, images[n].alt, variantAxes[n], variants[n].values[m] produktu), „Frazy wyszukiwania” (GET /products, GET /admin/products), „Odczyt zapisanych wierszy” (Catalog: DoctrineProductRepository, repozytorium kategorii, handlery odczytu budujące VariantValues z wiersza), „Rozwiązanie” punkty 2–4 dla Catalog (ProductText, CategoryName, CategoryBodyRules, ValidProductWriteValidator, ListProductsQuery, BackofficeProductsQuery), „Dokumentacja razem z implementacją” (catalog-admin, search, admin-features, quality-fixes). Wiersze w pliku Catalog testu kontraktu tekstów. Nie obejmuje: slug, taxClass, images[n].key i parametrów option, path, chunk (lista „Pola tekstowe poza regułą”), fraz innych list, strażnika.",
      "acceptance_criteria": [
        "Każdy wiersz Catalog tabeli „Pola treści JSON” (name kategorii w POST i PUT; pięć pól produktu w POST i PUT) odpowiada na klasy tekstu z tabeli wykonań statusem, polem i komunikatem ze specyfikacji; krótki opis przyjmuje U+000A i zapisuje pusty opis dla tekstu pustego według T3.",
        "Nazwa kategorii równa U+200B, U+FEFF, U+00AD, U+2060, U+200E albo U+202E to 422 przy name „Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.” (#632 F4); nazwa produktu, tekst alternatywny, oś i wartość wariantu z U+009F to 422 z komunikatem znaku niedozwolonego swojego pola.",
        "Teksty między U+00A0 albo między U+200B są zapisane bez tych znaków; tekst zakończony flagą Anglii, tekst z emoji z U+200D i tekst z polskimi znakami są zapisane bez zmian.",
        "GET /api/v1/products i GET /api/v1/admin/products: q puste według T3 (dwa U+00A0, U+200B, same spacje ASCII) to 400 przy q „Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków.”; q z U+0000, U+009F albo U+2028 to 400 przy q „Szukana fraza zawiera niedozwolone znaki.”; długość liczy się po przycięciu T2 (#641). Wiersze etapu 1 dla q (zły typ, 1 znak, 101 znaków, niepoprawny UTF-8) przechodzą bez zmian.",
        "GET /api/v1/products (indeks testowy) i GET /api/v1/admin/products: dla produktu założonego przez fiksturę odpowiedź na frazę czystą zawiera ten produkt, a odpowiedzi na tę samą frazę między U+200B i między U+00A0 mają te same pozycje w tej samej kolejności i ten sam licznik.",
        "Produkt i kategoria zapisane w bazie z tekstem, którego reguła nie przyjmuje (U+200B jako nazwa, tabulator albo U+009F w nazwie, osi, wartości wariantu i tekście alternatywnym), są zwracane przez listę i szczegóły panelu i przez GET /api/v1/products/{slug} bez zmiany; zapis produktu z panelu bez poprawienia pola to 422 przy tym polu.",
        "Komunikaty, propertyPath i liczba naruszeń pól Catalog poza powyższymi nie zmieniają się; testy value objectów i walidatora, które sprawdzały stare zachowanie, zmieniają się razem z regułą; testy generatora danych demo (suity demo-data i demo-data-orders) przechodzą.",
        "Zdania o przycinaniu i znakach tekstów w docs/features/catalog-admin/spec.md, search/spec.md, admin-features/spec.md i quality-fixes/spec.md są zgodne z regułą.",
        "Raport zadania zawiera komendy i wyniki RED i GREEN; make -C apps/api check i make -C apps/api test przechodzą, a pliki OpenAPI i klienci TS są aktualne."
      ],
      "risk": "STANDARD",
      "depends_on": ["API-INPUT-2-1"],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/src/DemoData/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/api/openapi-admin.json",
        "apps/store/src/api/schema.d.ts",
        "apps/admin/src/api/schema.d.ts",
        "docs/features/catalog-admin/spec.md",
        "docs/features/search/spec.md",
        "docs/features/admin-features/spec.md",
        "docs/features/quality-fixes/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-2-3",
      "title": "Reguła tekstu w Ordering: dane zamówienia, notatki pracownika, frazy zamówień i klientów, odczyt zapisanych zamówień",
      "scope": "apps/api: Ordering. Specyfikacja, „Etap 2: reguła tekstu”: „Pola treści JSON” (fullName, street, city, postalCode, phone, email w POST /orders i POST /me/orders; note zmiany statusu i wykonania zwrotu), „Frazy wyszukiwania” (GET /admin/customers, GET /admin/orders), „Odczyt zapisanych wierszy” (Ordering: DoctrineOrderRepository), „Rozwiązanie” punkty 2–4 dla Ordering (ControlCharacters, StaffNote, Contact, ShippingAddress, EmailAddress, OrderSearchTerm, BackofficeCustomerSearchTerm, klasy żądań złożenia, zmiany statusu i zwrotu), „Wdrożenie i klienci” (skutek powtórzenia złożenia), „Dokumentacja razem z implementacją” (checkout, order-management, quality-fixes). Wiersze w pliku Ordering testu kontraktu tekstów. Nie obejmuje: discountCode, trackingNumber, expectedLines, składu odcisku żądania, parametru customerId, strażnika.",
      "acceptance_criteria": [
        "Każdy wiersz Ordering tabeli „Pola treści JSON” odpowiada na klasy tekstu z tabeli wykonań statusem, polem i komunikatem ze specyfikacji, w POST /api/v1/orders i w POST /api/v1/me/orders (token klienta).",
        "POST /api/v1/orders z fullName, street albo city równym U+00A0, U+200B albo U+3164 to 422 przy polu z komunikatem pola wymaganego; z U+009F w środku to 422 z komunikatem znaku niedozwolonego pola (#443, p. 1).",
        "Każdy przypadek tabel „Pola z formatem: przypadki” specyfikacji daje podany status i zapisaną wartość albo komunikat: phone i postalCode w POST /api/v1/orders i POST /api/v1/me/orders, email w POST /api/v1/orders (m.in. `600100200`, `+48 600-100-200` i `600100200` między U+00A0, między U+200B i po U+FEFF zapisane jako `+48600100200`; `00-950` między tymi znakami zapisane jako `00-950`; adres e-mail między tymi znakami zapisany jako sam adres, z zachowaną wielkością liter; adres 254 znaków między U+00A0 przyjęty; znak z T2 albo biały znak inny niż spacja ASCII w środku wartości to 422 z komunikatem formatu).",
        "fullName, street i city między U+00A0 albo między U+200B są zapisane bez tych znaków, a limit długości liczy się po przycięciu; wiersz POST /orders email jest w tabeli testu kontraktu tekstów jako pole z formatem.",
        "Notatka zmiany statusu pusta według T3 przy cancel i return to 422 przy note „Notatka jest wymagana.”, przy pozostałych przejściach i przy wykonaniu zwrotu brak notatki; notatka z U+009F to 422 „Notatka zawiera niedozwolone znaki.”; notatka między U+200B jest zapisana bez tych znaków.",
        "GET /api/v1/admin/customers i GET /api/v1/admin/orders: q puste według T3 (także trzy U+200B albo U+3164) i q z U+009F to 400 przy q z komunikatem swojej listy ze specyfikacji.",
        "GET /api/v1/admin/customers i GET /api/v1/admin/orders: dla klienta z zamówieniem i zamówienia założonych przez fiksturę odpowiedź na frazę czystą zawiera ten rekord, a odpowiedzi na tę samą frazę między U+200B i między U+00A0 mają te same pozycje w tej samej kolejności i ten sam licznik.",
        "Zamówienie zapisane w bazie z fullName równym U+00A0, ze street równym U+200B albo z tabulatorem w city jest zwracane przez listę i szczegóły panelu i przez odczyt klienta bez zmiany, przechodzi opłacenie webhookiem, wygaszenie i zmianę statusu przez pracownika.",
        "Powtórzenie złożenia z tym samym Idempotency-Key i tą samą treścią dalej daje 200, także gdy pola tekstowe mają na brzegach znaki z T2; skład odcisku żądania i reguły expectedLines się nie zmieniają, a testy współbieżności i idempotencji złożenia przechodzą bez zmiany asercji.",
        "Komunikaty, propertyPath i liczba naruszeń pól Ordering poza powyższymi nie zmieniają się; testy value objectów i ForbiddenCharactersInRequestsTest dostają przypadki reguły, a przypadki starego zachowania zmieniają się razem z nią; testy generatora danych demo przechodzą.",
        "docs/features/checkout/spec.md nie mówi już, że pola zamówienia przycina trim() tylko z ASCII, a zdania o notatce pracownika w order-management/spec.md i o kopiach wzorców w quality-fixes/spec.md są zgodne z regułą.",
        "Raport zadania zawiera komendy i wyniki RED i GREEN; make -C apps/api check i make -C apps/api test przechodzą, a pliki OpenAPI i klienci TS są aktualne."
      ],
      "risk": "CRITICAL",
      "depends_on": ["API-INPUT-2-1"],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/src/DemoData/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/api/openapi-admin.json",
        "apps/store/src/api/schema.d.ts",
        "apps/admin/src/api/schema.d.ts",
        "docs/features/checkout/spec.md",
        "docs/features/order-management/spec.md",
        "docs/features/quality-fixes/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-2-4",
      "title": "Reguła tekstu w Inventory i Pricing: notatki magazynu, nazwa promocji, frazy stanów, cen i kodów",
      "scope": "apps/api: Inventory i Pricing. Specyfikacja, „Etap 2: reguła tekstu”: „Pola treści JSON” (note korekty i przyjęcia stanu; name promocji), „Frazy wyszukiwania” (GET /admin/stock, GET /admin/prices, GET /admin/discount-codes), „Rozwiązanie” punkty 2–3 dla Inventory i Pricing (StockNote, CorrectStockRequest, ReceiveStockRequest, BackofficeStockQuery, Promotion::validName, klasy żądań promocji, BackofficePricesQuery, DiscountCodeListQuery), „Dokumentacja razem z implementacją” (inventory, promotions, admin-features). Wiersze w plikach Inventory i Pricing testu kontraktu tekstów. Nie obejmuje: code kodu rabatowego i DiscountCodeValue (lista „Pola tekstowe poza regułą”), zmian odczytu (specyfikacja: Pricing i Inventory ich nie wymagają), dwóch naruszeń q przy productId (#632 F3), strażnika.",
      "acceptance_criteria": [
        "Notatka korekty stanu z powodem „inne” pusta według T3 (U+00A0, U+200B, U+3164) to 422 przy note „Przy powodzie „inne” notatka jest wymagana.”; przy innych powodach i w przyjęciu stanu to brak notatki (#443, p. 2).",
        "Notatka korekty i przyjęcia z U+0000, U+0085, U+009F, U+2028 albo U+2029 to 422 przy note „Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).”; notatka z U+0009 i U+000A jest zapisana bez zmian; notatka między U+00A0 albo między U+200B jest zapisana bez tych znaków.",
        "Nazwa promocji zakończona flagą Anglii, z emoji z U+200D i z polskimi znakami jest zapisana bez zmian w POST i PUT (#305); nazwa pusta według T3 i nazwa ze znakiem niedozwolonym to 422 przy name „Nazwa promocji musi mieć od 1 do 100 znaków, bez znaków sterujących.”; nazwa między U+00A0 albo między U+200B jest zapisana bez tych znaków.",
        "GET /api/v1/admin/stock i GET /api/v1/admin/prices: q puste według T3 (także same spacje ASCII) to 400 przy q „Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków.”, q z U+0000, U+009F albo U+2028 to 400 „Szukana fraza zawiera niedozwolone znaki.”; GET /api/v1/admin/discount-codes: q puste i q ze znakiem niedozwolonym to 400 „Początek kodu musi mieć od 1 do 32 znaków.”. Wiersze etapu 1 dla tych parametrów przechodzą bez zmian.",
        "GET /api/v1/admin/stock, GET /api/v1/admin/prices i GET /api/v1/admin/discount-codes: dla produktu i kodu rabatowego założonych przez fiksturę odpowiedź na frazę czystą (dla kodów: początek kodu) zawiera ten rekord, a odpowiedzi na tę samą frazę między U+200B i między U+00A0 mają te same pozycje w tej samej kolejności i ten sam licznik.",
        "Liczba albo lista w miejscu notatki i nazwy promocji to 422 z komunikatem kolumny „Zły typ”; komunikaty, propertyPath i liczba naruszeń pozostałych pól Inventory i Pricing się nie zmieniają; testy, które sprawdzały stare zachowanie (np. przyjęcie U+2028 w notatce, obcięcie znaków formatujących z końca nazwy promocji), zmieniają się razem z regułą.",
        "Zdania o notatce w docs/features/inventory/spec.md, o nazwie promocji w promotions/spec.md i o frazach list stanów i cen w admin-features/spec.md są zgodne z regułą.",
        "Raport zadania zawiera komendy i wyniki RED i GREEN; make -C apps/api check i make -C apps/api test przechodzą, a openapi-admin.json i klient TS panelu są aktualne."
      ],
      "risk": "STANDARD",
      "depends_on": ["API-INPUT-2-1"],
      "paths": [
        "apps/api/src/Inventory/",
        "apps/api/src/Pricing/",
        "apps/api/tests/",
        "apps/api/openapi-admin.json",
        "apps/admin/src/api/schema.d.ts",
        "docs/features/inventory/spec.md",
        "docs/features/promotions/spec.md",
        "docs/features/admin-features/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-2-5",
      "title": "Strażnik zamkniętej listy pól tekstowych i dokumentacja etapu",
      "scope": "apps/api (testy) i dokumentacja. Specyfikacja, „Etap 2: reguła tekstu”: „Testy” (strażnik zamkniętej listy pól tekstowych), „Pola tekstowe poza regułą” (lista wyjątków z powodami), „Dokumentacja razem z implementacją” (zasada w apps/api/CLAUDE.md o wierszu albo wpisie dla nowego pola tekstowego; sekcja etapu 2 specyfikacji opisuje stan obecny). Nie obejmuje zmian kodu produkcyjnego ani nowych wierszy pól: brak wiersza wykryty przez strażnika wraca do zadania modułu.",
      "acceptance_criteria": [
        "Test strażnika przechodzi na apps/api/openapi.json i apps/api/openapi-admin.json: każda właściwość typu string w treści żądań i każdy parametr zapytania q ma wiersz w tabeli testu kontraktu tekstów albo wpis z powodem na liście wyjątków równej liście „Pola tekstowe poza regułą” specyfikacji; poza strażnikiem są tylko właściwości z enum i z formatem uuid, date-time, date albo binary, więc POST /orders email (format: email) jest nim objęte.",
        "Cztery próby obejścia przerywają test: kopia dokumentu z nowym polem tekstowym bez wiersza i bez wpisu (także z formatem spoza zamkniętej listy); tabela z wierszem pola, którego dokumenty nie opisują; lista wyjątków z wpisem bez powodu; kopia tabeli bez wiersza POST /orders email.",
        "Strażnik nie generuje przypadków i nie zmienia tabeli; strażnik etapu 1 (ClosedInputListTest) przechodzi bez zmian.",
        "apps/api/CLAUDE.md, sekcja „API”: nowe pole tekstowe treści żądania albo parametr q dostaje wiersz w tabeli testu kontraktu tekstów albo wpis z powodem na liście wyjątków.",
        "Sekcja „Etap 2: reguła tekstu” specyfikacji opisuje stan obecny: wstęp pliku nie mówi „do implementacji”, „Decyzje” są decyzjami właściciela bez „do akceptacji”, a tabele zgadzają się z tabelą testu.",
        "make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": ["API-INPUT-2-2", "API-INPUT-2-3", "API-INPUT-2-4"],
      "paths": [
        "apps/api/tests/Functional/Shared/InputContract/",
        "apps/api/CLAUDE.md",
        "docs/features/api-input-contract/spec.md"
      ],
      "parallel_with": []
    }
  ]
}
```
