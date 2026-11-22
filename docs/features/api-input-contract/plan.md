# API-INPUT-CONTRACT – plan zadań

Specyfikacja: `docs/features/api-input-contract/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | API-INPUT-CONTRACT-1 | zmergowane do gałęzi epiku, 01e48d0f |
| 2 | API-INPUT-CONTRACT-2 | zmergowane do gałęzi epiku, 06630596 |
| 3 | API-INPUT-CONTRACT-3, API-INPUT-CONTRACT-4 | 4 zmergowane do gałęzi epiku, 492636b1; 3 zmergowane do gałęzi epiku, 92f19b15 |
| 4 | API-INPUT-CONTRACT-5 | zmergowane do gałęzi epiku, 9846abd6 |
| poprawka | API-INPUT-CONTRACT-6 (I1: `page=1.5`, `perPage=1.5` na `GET /products`; pozostałe listy: #844) | zmergowane do gałęzi epiku, 4b179616; przegląd epiku zakończony |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Praca szeregowa

- API-INPUT-CONTRACT-3, API-INPUT-CONTRACT-4: `apps/api/openapi.json`, `apps/api/openapi-admin.json`,
  `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`; oba zmieniają klasy zapytań i kontrolery, więc
  oba uruchamiają eksport OpenAPI i generowanie klientów, a wynik to wspólne pliki. Specyfikacja nie przewiduje
  różnicy, ale nie jest to sprawdzone przed implementacją.

## TDD w zadaniach

Zadanie nie może zostawić gałęzi z czerwonymi testami, więc nie ma osobnego zadania „same testy”. Każde zadanie:

1. dopisuje swoje wiersze tabeli przypadków i testy, bez zmiany kodu produkcyjnego;
2. uruchamia je i zapisuje w raporcie komendę i wynik RED (które wiersze nie przechodzą i jakim tekstem albo
   kształtem odpowiedzi);
3. wprowadza zmianę i zapisuje komendę i wynik GREEN, potem `make -C apps/api check` i `make -C apps/api test`.

Wiersze tabeli B2 specyfikacji (komunikat bez zmian) przechodzą już przed zmianą: są regresją, a RED zadania dotyczy
wierszy sekcji A, B1, C i D. Strażniki (klas żądań w zadaniu 1, zamkniętej listy w zadaniu 5) pokazują RED próbą
obejścia w teście.

## Przewidywany rozmiar

Szacunek przed implementacją, bez `composer.lock`:

| Zadanie | Kod produkcyjny | Testy nowe | Testy zmienione |
|---|---|---|---|
| 1 | 1 plik, ok. 40 linii | ok. 300 linii (klasa testu, przypadki sekcji A, test subscribera, strażnik klas żądań) | ok. 20 linii w 12 plikach |
| 2 | konfiguracja i katalog tłumaczeń, ok. 15 linii | ok. 250 linii (wiersze B1 z katalogu frameworka i B2, fikstury) | kilka linii |
| 3 | ok. 8 plików, ok. 40 linii | ok. 70 linii | ok. 5 linii w 2 plikach |
| 4 | ok. 18 plików, ok. 50 linii | ok. 90 linii | ok. 8 linii w 6 plikach |
| 5 | brak | ok. 60 linii | brak |

Razem około 150 linii kodu produkcyjnego i 800 linii testów; dokumentacja około 20 linii.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "API-INPUT-CONTRACT",
  "spec": "docs/features/api-input-contract/spec.md",
  "tasks": [
    {
      "id": "API-INPUT-CONTRACT-1",
      "title": "Polskie odpowiedzi ogólne, bez identycznych naruszeń, test kontraktu z sekcją A i strażnik klas żądań",
      "scope": "apps/api, Shared. Specyfikacja: „A. Odpowiedzi ogólne”, „Rozwiązanie” punkty 2 (tylko ProblemDetailsSubscriber), 3 i 5, „Testy” (klasa testu kontraktu, sekcja A, pozostałe testy: subscriber i strażnik klas żądań). Zadanie zakłada klasę testu kontraktu w apps/api/tests/Functional/Shared/InputContract z mechanizmem wiersza tabeli (dwa osobne wykonania na wiersz, fikstury z istniejących fabryk i pomocników) i przypadkami sekcji A; wierszy sekcji B i C jeszcze nie dodaje. Nie obejmuje: translatora, tekstów klas zapytań i wyjątków modułów, strażnika zamkniętej listy.",
      "acceptance_criteria": [
        "Każda operacja z routera odpowiada na metodę spoza trasy 405 z detail „Metoda nie jest dozwolona.”, a każda wymagająca tokenu odpowiada bez tokenu 401 z detail „Wymagane jest uwierzytelnienie.”; listy operacji test bierze z routera.",
        "Operacje z tabeli A odpowiadają: 404 „Nie znaleziono zasobu.” na identyfikator ścieżki w formacie, którego trasa nie przyjmuje; 415 „Nieobsługiwany typ treści.” na Content-Type inny niż JSON przy poprawnym tokenie; 400 „Żądanie jest nieprawidłowe.” na zepsuty JSON, niepoprawny UTF-8 i zagnieżdżenie ponad limit (także webhook).",
        "Test jednostkowy ProblemDetailsSubscriber sprawdza wszystkie stałe teksty ze specyfikacji (400, 401, 403, 404, 405, 406, 409, 415, 422, 429 i tekst zapasowy).",
        "Odpowiedź z naruszeniami pomija naruszenie o tym samym propertyPath i title co wcześniejsze; dwa różne naruszenia jednego pola zostają (test jednostkowy obu przypadków).",
        "GET /api/v1/admin/stock i GET /api/v1/admin/prices z productId równym nil UUID dają jedno naruszenie productId „Identyfikator produktu musi być poprawnym UUID.”.",
        "Mechanizm wiersza tabeli wykonuje wartość poprawną (oczekiwane 2xx) i wartość błędną jako osobne testy, każdy na stanie zbudowanym od zera tą samą fiksturą, z nowym kluczem idempotencji; używa wyłącznie istniejących fabryk i pomocników wymienionych w specyfikacji.",
        "Test strażnika przechodzi po klasach z #[RequiredKeysBody] znalezionych w kontrolerach z routera i wymaga parametrów konstruktora bez typu albo mixed; klasa testowa z parametrem int go łamie.",
        "Istniejące testy asercji angielskich tekstów ogólnych zmieniają się razem z tekstami; title problemu zostaje frazą statusu HTTP.",
        "Raport zadania zawiera komendę i wynik RED testów sekcji A, tekstów subscribera i duplikatów uruchomionych przed zmianą kodu oraz komendę i wynik GREEN po zmianie; make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": ["apps/api/src/Shared/", "apps/api/tests/"],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-CONTRACT-2",
      "title": "Tłumaczenia frameworka i wiersze parametrów zapytań z katalogu walidatora",
      "scope": "apps/api: zależność symfony/translation, konfiguracja, katalog tłumaczeń projektu. Specyfikacja: decyzja D2, „Rozwiązanie” punkt 1, tabele B1 (przypadki z komunikatami T, pusta, UUID, krótka, długa, znaki, opcja, zakres, „Ta wartość powinna być dodatnia.”, „Ten zbiór powinien zawierać 1 lub więcej elementów.”) i B2. Zadanie zakłada pliki tabeli przypadków podzielone według modułu właściciela operacji i dodaje do nich te wiersze dla wszystkich 19 operacji z parametrami zapytań. Nie obejmuje: wierszy z własnymi tekstami modułów (zadania 3 i 4), parametru version usuwania promocji, nagłówka Idempotency-Key.",
      "acceptance_criteria": [
        "symfony/translation jest zależnością API, domyślny język to pl; z plików dodanych przez przepis Fleksa zostają tylko potrzebne.",
        "Błąd typu parametru zapytania i pola treści ma tekst „Ta wartość ma nieprawidłowy typ.”, bez nazwy typu PHP, z jednego wpisu katalogu projektu w domenie validators.",
        "Każdy przypadek tabeli B1 specyfikacji, którego komunikat pochodzi z katalogu frameworka albo jest tekstem T, daje 400 z dokładnie oczekiwanym zbiorem naruszeń przy wskazanym propertyPath (także productId[0] dla GET /api/v1/product-offers), na wszystkich operacjach wymienionych w wierszu, w tym GET /api/v1/me/orders z tokenem klienta.",
        "Każdy wiersz tabeli B2 daje 400 przy parametrze z komunikatem bez zmian, w tym discountCode na GET /api/v1/me/cart z tokenem klienta.",
        "Każdy wiersz ma kontrolę dodatnią 2xx wykonaną jako osobny test na niezależnym stanie.",
        "Własne polskie komunikaty odpowiedzi 422 nie zmieniają się: istniejące testy treści żądań przechodzą bez zmiany oczekiwanych tekstów.",
        "Raport zadania zawiera komendę i wynik RED wierszy B1 uruchomionych przed dodaniem translatora (wiersze B2 przechodzą już wtedy jako regresja) oraz komendę i wynik GREEN; make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["API-INPUT-CONTRACT-1"],
      "paths": ["apps/api/composer.json", "apps/api/composer.lock", "apps/api/symfony.lock", "apps/api/config/", "apps/api/translations/", "apps/api/tests/"],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-CONTRACT-3",
      "title": "Polskie komunikaty zapytań i wyjątków Catalog",
      "scope": "apps/api, Catalog. Specyfikacja: tabela B1 (wiersze z własnymi tekstami: q z NUL na liście produktów panelu, priceMin i priceMax, sort relevance bez q, option, status i after listy produktów panelu) i sekcja D (ProductNotFound), „Rozwiązanie” punkt 2 w części Catalog. Dopisuje wiersze do pliku przypadków Catalog. Nie obejmuje pozostałych modułów.",
      "acceptance_criteria": [
        "GET /api/v1/products: priceMin i priceMax z abc, -1 albo 1.5 dają „Cena musi być liczbą całkowitą groszy, 0 albo więcej.”; priceMin większe niż priceMax daje przy priceMin „Cena minimalna nie może być wyższa niż maksymalna.”; sort=relevance bez q daje „To sortowanie wymaga szukanej frazy.”.",
        "GET /api/v1/products, parametr option: sześć przypadków tabeli B1 daje dokładnie sześć polskich komunikatów ze specyfikacji przy option.",
        "GET /api/v1/admin/products: q=ab%00 daje „Szukana fraza zawiera niedozwolone znaki.”, nieznany status daje „Status produktu musi być jednym z: draft, published.”, after=abc daje „Kursor stronicowania jest nieprawidłowy.”.",
        "Nieznany produkt w operacjach panelu, które dziś odpowiadają „Product not found.”, daje 404 z detail „Produkt nie istnieje.”; type zostaje about:blank.",
        "Każdy nowy wiersz tabeli ma kontrolę dodatnią 2xx wykonaną jako osobny test na niezależnym stanie; status i propertyPath odpowiedzi się nie zmieniają.",
        "make -C apps/api openapi i make api-client są uruchomione; różnica, jeśli wystąpi, jest w commicie zadania.",
        "Raport zadania zawiera komendę i wynik RED nowych wierszy i testu 404 uruchomionych przed zmianą kodu oraz komendę i wynik GREEN; make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["API-INPUT-CONTRACT-2"],
      "paths": ["apps/api/src/Catalog/", "apps/api/tests/", "apps/api/openapi.json", "apps/api/openapi-admin.json", "apps/store/src/api/schema.d.ts", "apps/admin/src/api/schema.d.ts"],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-CONTRACT-4",
      "title": "Polskie komunikaty zapytań, nagłówka Idempotency-Key i konfliktów wersji w Inventory, Pricing, Ordering i Payment",
      "scope": "apps/api: Inventory, Pricing, Ordering, Payment. Specyfikacja: tabela B1 (q z NUL na listach stanów i cen, after list stanów, cen, zamówień panelu, historii stanu, historii cen i zamówień klienta, status listy zamówień panelu, version usuwania promocji), sekcje C i D (oba konflikty wersji), „Rozwiązanie” punkty 2 (w części tych modułów) i 4, „Zmiany zachowania API”. Dopisuje wiersze do plików przypadków tych modułów. Nie obejmuje Catalog ani osobnych type problemów konfliktów (#583).",
      "acceptance_criteria": [
        "GET /api/v1/admin/stock i GET /api/v1/admin/prices: q=ab%00 daje „Szukana fraza zawiera niedozwolone znaki.”.",
        "after=abc daje „Kursor stronicowania jest nieprawidłowy.” na GET /api/v1/me/orders, /api/v1/admin/stock, /api/v1/admin/orders, /api/v1/admin/prices, /api/v1/admin/variants/{variantId}/stock-history i /api/v1/admin/variants/{variantId}/price-history.",
        "GET /api/v1/admin/orders z nieznanym status daje „Status zamówienia jest nieprawidłowy.”.",
        "DELETE /api/v1/admin/promotions/{id}?version[]=x daje 400 z naruszeniem version „Wersja musi być dodatnią liczbą całkowitą.” i bez detail; promocja nie jest usunięta.",
        "Na pięciu operacjach sekcji C brak nagłówka Idempotency-Key daje 400 z naruszeniem Idempotency-Key „Nagłówek Idempotency-Key jest wymagany.”, a nagłówek pusty, 101 znaków albo ze znakiem spoza ASCII daje „Nieprawidłowy klucz żądania.”; dotyczy to także przyjęcia magazynowego, którego dotychczasowy angielski tekst znika.",
        "Korekta stanu z nieaktualną wersją daje 409 z detail „Stan został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.”, zmiana ceny z nieaktualną wersją 409 z detail „Cena została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.”; type obu zostaje about:blank.",
        "Każdy nowy wiersz tabeli ma kontrolę dodatnią 2xx wykonaną jako osobny test na niezależnym stanie, z nowym kluczem idempotencji w każdym wykonaniu; statusy odpowiedzi się nie zmieniają.",
        "make -C apps/api openapi i make api-client są uruchomione; różnica, jeśli wystąpi, jest w commicie zadania.",
        "Raport zadania zawiera komendę i wynik RED nowych wierszy i testów 409 uruchomionych przed zmianą kodu oraz komendę i wynik GREEN; make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["API-INPUT-CONTRACT-2"],
      "paths": ["apps/api/src/Inventory/", "apps/api/src/Pricing/", "apps/api/src/Ordering/", "apps/api/src/Payment/", "apps/api/tests/", "apps/api/openapi.json", "apps/api/openapi-admin.json", "apps/store/src/api/schema.d.ts", "apps/admin/src/api/schema.d.ts"],
      "parallel_with": []
    },
    {
      "id": "API-INPUT-CONTRACT-5",
      "title": "Strażnik zamkniętej listy wejść i zasady w dokumentacji",
      "scope": "apps/api: test kontraktu wejścia i dokumentacja. Specyfikacja: „Testy” (Router i OpenAPI: strażnik zamkniętej listy), „Dokumentacja razem z implementacją”. Nie zmienia kodu produkcyjnego.",
      "acceptance_criteria": [
        "Parametr zapytania albo nagłówek Idempotency-Key opisany w openapi.json lub openapi-admin.json bez wiersza w tabeli przypadków przerywa test; test pokazuje to próbą obejścia (parametr dopisany do kopii dokumentu albo wiersz usunięty z kopii tabeli).",
        "Trasa /api/v1 z routera bez wpisu w żadnym z dwóch dokumentów OpenAPI przerywa test.",
        "Na stanie epiku strażnik przechodzi: wszystkie 63 parametry zapytań i nagłówek Idempotency-Key pięciu operacji mają wiersze.",
        "apps/api/CLAUDE.md, sekcja „API”, opisuje domyślny język komunikatów walidatora (pl, katalog frameworka, nadpisany błąd typu) i zasadę, że nowy parametr zapytania albo nagłówek żądania dostaje wiersz w tabeli testu kontraktu.",
        "docs/features/api-input-contract/spec.md opisuje stan obecny, bez odwołań do stanu sprzed etapu.",
        "Raport zadania zawiera komendę i wynik próby obejścia (test strażnika nie przechodzi na zmienionej kopii) oraz wynik na stanie epiku; make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": ["API-INPUT-CONTRACT-3", "API-INPUT-CONTRACT-4"],
      "paths": ["apps/api/tests/Functional/Shared/InputContract/", "apps/api/CLAUDE.md", "docs/features/api-input-contract/spec.md"],
      "parallel_with": []
    }
  ]
}
```
