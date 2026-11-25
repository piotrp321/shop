# PRODUCT-DATA etap 1 – plan zadań

Specyfikacja: `docs/features/product-data/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

Start dopiero, gdy PR #915 jest na `main` (specyfikacja, „Wymagane przed startem”).

## Status

Epik w toku: gałąź `feat/product-data-1`, start 2026-11-25.

| Fala | Zadania | Status |
|---|---|---|
| 1 | PRODUCT-DATA-1 | zmergowane do gałęzi epiku, ed0403b4 |
| 2 | PRODUCT-DATA-2 | zmergowane do gałęzi epiku, 9f8355bc |
| 3 | PRODUCT-DATA-3, PRODUCT-DATA-4, PRODUCT-DATA-5 | zmergowane do gałęzi epiku: 3 `726e1a40`, 4 `79734393`, 5 `7afa8348` |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## TDD w zadaniach

Każde zadanie z logiką domeny albo aplikacji dopisuje najpierw testy, uruchamia je i zapisuje w raporcie komendę
i wynik RED, potem wprowadza zmianę i zapisuje GREEN, `check` i testy dotkniętych aplikacji. Treść danych demo
(`catalog.yaml`, słowniki generatora) nie ma fazy RED poza testami reguł, które ją sprawdzają. Test skrótu z kryterium
14 jest regresją: przechodzi przed zmianą generatora i po niej, co raport zadania 5 pokazuje dwoma uruchomieniami.
Kryteria 16–18 to pomiary i rachunek: raport zadania 5 podaje komendy i wyniki obu pomiarów (przed zmianą i po niej).

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PRODUCT-DATA",
  "spec": "docs/features/product-data/spec.md",
  "tasks": [
    {
      "id": "PRODUCT-DATA-1",
      "title": "Marka, opis i parametry produktu: API i formularz panelu",
      "scope": "Catalog (domena, komendy, persystencja, HTTP panelu i sklepu), migracja kolumn `brand`, `description`, `parameters` w `catalog_product`, eksport OpenAPI i klienci TS, formularz produktu w `apps/admin` (pola „Marka” i „Opis”, sekcja „Parametry”, wysyłka nowych pól bez przycinania, normalizacja porównania „Sprawdź stan” jako kopia reguły tekstu T1–T3 w panelu). Specyfikacja: „Pola i reguły” (wiersze `brand`, `description`, `parameters`), „Naruszenia”, „Model i persystencja”, „API”, „Panel” z „Nieznany wynik zapisu” w części pól produktu, „Wdrożenie i zgodność wersji”. Seed i generator przekazują puste wartości nowych pól (treść danych demo to zadania 4 i 5). Nie obejmuje SKU i EAN (zadanie 2) ani karty w sklepie (zadanie 3). Dokumentacja: `docs/features/catalog-admin/spec.md` (długi opis i marka znikają z „Poza zakresem”, tabele treści żądania, opis formularza), `docs/features/api-input-contract/spec.md` (tabela pól treści JSON), `docs/features/admin-ux-fixes/spec.md` (B4: zdanie o przyciętych spacjach).",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla marki, opisu i parametrów: zapis z panelu zwraca wartości w `GET /api/v1/admin/products/{id}` i w `GET /api/v1/products/{slug}`, teksty bez brzegów (T2), tekst pusty według T3 jako brak wartości.",
        "Kryterium 2 dla wierszy `brand`, `description` i `parameters` tabeli „Naruszenia”: 422 z podanym `propertyPath` i komunikatem po polsku, także brak klucza i zły typ; nigdy 500.",
        "Kryterium 3 dla `brand`, `description` (dozwolony U+000A), `parameters[n].name` i `parameters[n].value`: wiersze w `CatalogTextRows`, `ClosedTextFieldListTest` przechodzi.",
        "Kryterium 5 dla nowych pól produktu: zmiana samej marki, opisu albo parametrów podnosi wersję, publikuje `ProductChanged` i unieważnia odpowiedź szczegółów; stara wersja to 409 `product-modified`.",
        "Kryterium 6: wiersz produktu sprzed migracji (puste nowe pola) czyta się i zapisuje; odczyt z bazy odtwarza value objecty przez `stored()` bez walidacji.",
        "Kryterium 7 dla pól „Marka”, „Opis” i sekcji „Parametry”: dodanie, usunięcie, zmiana kolejności, limit 30, naruszenia API przy właściwych polach, pola zablokowane w trakcie żądania (test wstrzymuje odpowiedź zapisu), focus po dodaniu i usunięciu parametru, obsługa z klawiatury, 375 px bez poziomego przewijania strony.",
        "Kryterium 9a i 9e dla marki, opisu i parametrów: żądanie zapisu niesie te pola tak, jak są w szkicu, z brzegami i wszystkimi znakami (test panelu sprawdza treść wysłanego żądania); marka z tabulatorem na końcu, wartość parametru z U+2028 na brzegu i opis z U+000D dają w API 422 przy polu i nic nie jest zapisane; normalizacja porównania nie zmienia pól formularza, żądania ani `dirty`.",
        "Kryterium 9b dla marki, opisu i parametrów: zapis wykonany na serwerze bez odpowiedzi (błąd sieci, 502, limit 10 s) daje po „Sprawdź stan” komunikat „Stan na serwerze odpowiada formularzowi.”, nową wersję i kolejny zapis bez 409, gdy wartości mają na brzegach spację, U+00A0, U+2003, U+202F, U+3000, U+180E, U+200B, U+2060, U+FEFF albo U+00AD (opis także U+000A) i gdy opis jest pusty według T3; znaki w środku tekstu (U+200B między literami, U+200D w emoji, znaki flagi na końcu) zostają i nie psują zgodności.",
        "Kryterium 9c i 9d dla marki, opisu i parametrów: test tabelaryczny normalizacji porównania w panelu po punktach kodowych (wartość ze szkicu, postać znormalizowana albo „brak postaci” dla T1, w tym U+0009, U+000D, U+0085, U+2028 na brzegu i w środku; opis z dozwolonym U+000A); szkic ze znakiem T1 nie jest zgodny z żadnym stanem serwera; test E2E w `catalog-admin.spec.ts` na prawdziwym API: zapis z wartościami tabeli w marce, opisie i parametrach dociera do API, odpowiedź jest odrzucona w przeglądarce, „Sprawdź stan” pokazuje zgodność dla każdego znaku z kryterium 9b, a marka z tabulatorem na końcu daje 422 przy polu.",
        "Kryterium 12: dokument i mapowanie Elasticsearch, `GET /api/v1/products`, `GET /api/v1/product-offers` i kontrakty między modułami bez zmian; skrót definicji indeksu się nie zmienia.",
        "Istniejące testy zapisu produktu zmieniają się razem z kontraktem: treść żądania ma nowe wymagane klucze (`brand`, `description`, `parameters`), także w wołających spoza wygenerowanych klientów (`git grep 'admin/products'`); `openapi.json`, `openapi-admin.json` i oba `schema.d.ts` są wyeksportowane w tym samym commicie co zmiana API.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/admin check`, `make -C apps/admin test` i `make -C apps/store check` przechodzą; trzy dokumenty ze `scope` opisują stan po zadaniu (kryterium 19 w tej części)."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/",
        "apps/admin/",
        "apps/store/src/api/schema.d.ts",
        "e2e/tests/catalog-admin.spec.ts",
        "docs/features/catalog-admin/spec.md",
        "docs/features/api-input-contract/spec.md",
        "docs/features/admin-ux-fixes/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "PRODUCT-DATA-2",
      "title": "SKU i EAN wariantu: API, unikalność SKU, `gtin` i formularz panelu",
      "scope": "Catalog (wariant w domenie, komendy, persystencja, HTTP panelu i sklepu), migracja kolumn `sku` i `ean` w `catalog_product_variant` i osobna nietransakcyjna migracja indeksu unikalnego `sku` (`CREATE UNIQUE INDEX CONCURRENTLY`), typ problemu `sku-taken`, pole `gtin` w odpowiedzi sklepu (reguła puli obiegu ograniczonego w domenie), eksport OpenAPI i klienci TS, kolumny „SKU” i „EAN” w tabeli wariantów formularza `apps/admin`, kody w operacjach edytora wariantów. Specyfikacja: „Pola i reguły” (wiersze `sku`, `ean`, „Kody należą do wariantu”, „EAN z puli obiegu ograniczonego”), „Naruszenia”, „Unikalność SKU przy równoległym zapisie”, „Model i persystencja”, „API”, „Panel” z „Kody przy zmianie osi i wierszy” i „Nieznany wynik zapisu” w części kodów, „Scenariusze błędów”. Zależy od zadania 1: rozszerza jego normalizację porównania „Sprawdź stan” o kody (T1, T2, wielkie litery SKU), jego test E2E zgodności i ten sam zapis produktu. Seed i generator zapisują warianty bez kodów (treść danych demo to zadania 4 i 5). Nie obejmuje wyszukiwania po kodzie, kodów na listach cen i stanów ani na zamówieniach (poza etapem). Dokumentacja: `docs/features/catalog-admin/spec.md` (SKU i EAN znikają z „Poza zakresem”, treść żądania, konflikt `sku-taken`, tabela wariantów formularza), `docs/features/variants/spec.md` („Poza zakresem”: SKU i EAN), `docs/features/api-input-contract/spec.md` („Pola tekstowe poza regułą”: `sku`, `ean`), `docs/features/admin-ux-fixes/spec.md` (B4: zajęty SKU po nieznanym wyniku utworzenia).",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla kodów: SKU i EAN wariantu zapisane z panelu wracają w `GET /api/v1/admin/products/{id}` i w `GET /api/v1/products/{slug}`; SKU wielkimi literami, kody bez brzegów (T2), pusty tekst jako brak kodu.",
        "Kryterium 2 dla wierszy `sku` i `ean` tabeli „Naruszenia”: 422 przy `variants[i].sku` albo `variants[i].ean` z komunikatem po polsku, także brak klucza, liczba JSON w miejscu tekstu, powtórzenie w żądaniu; cyfra kontrolna EAN sprawdzona dla 8, 12, 13 i 14 cyfr; nigdy 500.",
        "Kryterium 3 dla `sku` i `ean`: wpisy z powodem w `TextFieldExceptions`, `ClosedTextFieldListTest` przechodzi.",
        "Kryterium 4: SKU zajęty przez wariant innego produktu (także szkicu, także różniący się wielkością liter) daje 422 przy `variants[i].sku`; test repozytorium z pominięciem sprawdzenia przed zapisem daje `sku-taken` (409 w HTTP), a w bazie zostaje jeden wariant z tym kodem; zamiana kodów między wariantami jednego produktu w jednym zapisie przechodzi; kod usuniętego wariantu jest wolny od razu.",
        "Kryterium 5 dla kodów: zmiana samego SKU albo EAN (także po publikacji, bez naruszenia blokady tożsamości wariantu) podnosi wersję, publikuje `ProductChanged` i unieważnia odpowiedź szczegółów.",
        "Kryterium 6 dla wariantów sprzed migracji: wariant bez kodów czyta się i zapisuje; indeks unikalny przyjmuje wiele wariantów bez SKU; `CatalogSchemaTest` potwierdza zgodność mapowania ze schematem.",
        "Kryterium 7 dla kolumn „SKU” i „EAN”: pola w każdym wierszu wariantu, także dla produktu z jednym wariantem bez osi; naruszenia API przy właściwym wierszu; 409 `sku-taken` jako komunikat sekcji wariantów; po zapisie formularz pokazuje SKU z odpowiedzi API; pola zablokowane w trakcie żądania; 375 px bez poziomego przewijania strony.",
        "Kryterium 8a i 8b (API): zapis szkicu z dodaną, usuniętą albo przestawioną osią, zmienioną nazwą osi, zmienioną wartością wariantu i usuniętą ostatnią osią zostawia wariantowi o tym samym `variantId` SKU i EAN z żądania (odczyt po zapisie), także po publikacji dla zmian dozwolonych przez K5; zapis, który zmienia wyłącznie kod jednego wariantu, zapisuje ten kod i nie zmienia kodów pozostałych.",
        "Kryterium 8c (panel): każda operacja edytora wariantów (dodanie, usunięcie i przestawienie osi, zmiana nazwy osi, zmiana wartości) zostawia SKU i EAN wierszy, które zostają, w szkicu i w wysłanym żądaniu; usunięcie ostatniej osi zostawia pierwszy wiersz z jego identyfikatorem i kodami; nowy wiersz ma puste kody.",
        "Kryterium 9a i 9e dla kodów: żądanie zapisu niesie SKU i EAN tak, jak są w szkicu, z brzegami (test panelu sprawdza treść wysłanego żądania); API sprawdza T1 na wartości, jak przyszła, przed przycięciem: SKU albo EAN z tabulatorem albo U+2028 na brzegu daje 422 przy `variants[i].sku` albo `variants[i].ean` z komunikatem formatu, a kod nie jest zapisany; pole „SKU” pokazuje wielkie litery już przy wpisywaniu i nie usuwa żadnego znaku.",
        "Kryterium 9b dla kodów: zapis wykonany na serwerze bez odpowiedzi daje po „Sprawdź stan” komunikat „Stan na serwerze odpowiada formularzowi.”, nową wersję i kolejny zapis bez 409, gdy SKU jest małymi literami albo SKU i EAN mają na brzegach spację, U+00A0, U+2003, U+202F, U+3000, U+180E, U+200B, U+2060, U+FEFF albo U+00AD, także dla nowego wiersza wariantu z kodami (bez identyfikatora w szkicu).",
        "Kryterium 9c i 9d dla kodów: tabela normalizacji porównania w panelu ma przypadki SKU (T1, potem T2, potem wielkie litery ASCII) i EAN (T1, potem T2), w tym „brak postaci” dla znaku T1 na brzegu; test E2E zgodności z zadania 1 dostaje wartości SKU i EAN ze znakami z kryterium 9b i dalej kończy się zgodnością na prawdziwym API.",
        "Kryterium 9f: ten sam `PUT` ze starą wersją po udanym zapisie daje 409 `product-modified`, nie naruszenie przy SKU (kody własnych wariantów nie są zajęte).",
        "Kryterium 9g: po nieznanym wyniku utworzenia produktu ponowny zapis odrzucony z powodu zajętego SKU pokazuje przy komunikacie „Możliwe, że utworzył go Twój wcześniejszy zapis.”, dla 422 przy `variants[i].sku` i dla 409 `sku-taken`; drugi produkt z tym kodem nie powstaje.",
        "Kryterium 11a: wariant w odpowiedzi sklepu ma `gtin` równe `ean` dla globalnego numeru towaru i puste dla numeru obiegu ograniczonego według tabeli §2.1.11 specyfikacji (GS1 General Specifications 26.0); regułę rozstrzyga domena Catalog. Test tabelaryczny domeny obejmuje każdy przykład tabeli, w tym sześć numerów RCN-12 z prefiksem U.P.C. 0 z rysunku 2-1 (`001000000052`, `007999000097`, `001000001004`, `005000009992`, `000000000000`, `000000009997`), ten sam RCN-12 zapisany jako 13 cyfr z zerem z lewej, RCN-8 z prefiksem 0 i 2, prefiksy GS1 02, 04, 20 i 29, prefiksy U.P.C. 2 i 4, kod 14-cyfrowy nad RCN-13 oraz kody, które RCN nie są: prefiksy `03`, `05`, `19` i `30`, prefiksy firmowe U.P.C. `000999` i `008000`, kod 8-cyfrowy zaczynający się od `1` i `9`, GTIN-14 z cyfrą wskaźnika nad zwykłym GTIN-13.",
        "Kryterium 12: dokument i mapowanie Elasticsearch oraz kontrakty między modułami (`GetVariantSummaries`, `ListVariantIdentities`, `ListProductVariants`, `GetVariant`) bez zmian.",
        "Istniejące testy zapisu produktu zmieniają się razem z kontraktem: każdy element `variants` ma nowe wymagane klucze `sku` i `ean`, także w wołających spoza wygenerowanych klientów; pliki OpenAPI i oba `schema.d.ts` są wyeksportowane w tym samym commicie co zmiana API.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/admin check`, `make -C apps/admin test` i `make -C apps/store check` przechodzą; cztery dokumenty ze `scope` opisują stan po zadaniu (kryterium 19 w tej części)."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRODUCT-DATA-1"],
      "paths": [
        "apps/api/",
        "apps/admin/",
        "apps/store/src/api/schema.d.ts",
        "e2e/tests/catalog-admin.spec.ts",
        "docs/features/catalog-admin/spec.md",
        "docs/features/variants/spec.md",
        "docs/features/api-input-contract/spec.md",
        "docs/features/admin-ux-fixes/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "PRODUCT-DATA-3",
      "title": "Karta produktu w sklepie: marka, opis, parametry, kody i JSON-LD",
      "scope": "`apps/store`: `ProductDetailsView`, `VariantChoice`, `load-product` (strażnik kształtu odpowiedzi), `product-json-ld` i ich testy; nowy test E2E, który tworzy produkt w panelu z nowymi polami, publikuje go i sprawdza kartę oraz JSON-LD. Specyfikacja: „Sklep”, „Scenariusze błędów” (opis z samych białych znaków, opis ze znacznikami, EAN z puli obiegu ograniczonego). Sklep nie ocenia kodu EAN: `gtin` bierze z pola odpowiedzi. Bez zmian API, kafelka listy, koszyka i metadanych strony. Dokumentacja: `docs/features/product-page/spec.md` („Poza zakresem”, kształt `ProductDetails`, lista właściwości JSON-LD, opis karty).",
      "acceptance_criteria": [
        "Kryterium 10 specyfikacji: marka nad nazwą; sekcje „Opis” (akapity z pustego wiersza, złamanie wiersza z pojedynczego końca wiersza) i „Parametry” (lista opisowa w kolejności z API) z nagłówkami `h2`, każda tylko gdy ma treść; „Kod produktu” i „EAN” dla produktu z jednym wariantem zawsze (także niedostępnego), dla wielu wariantów po wyborze wariantu, każdy tylko gdy niepusty; pierwszy render taki sam dla każdego odwiedzającego; opis ze znacznikami HTML pokazany dosłownie; 375 px bez poziomego przewijania strony.",
        "Kryterium 11b i 11c: JSON-LD `Product` ma `brand` typu `Brand`, a `Offer` wariantu `sku` i `gtin` z pól odpowiedzi API, każde tylko przy niepustym polu; wariant z niepustym `ean` i pustym `gtin` ma na karcie „EAN: …”, a w JSON-LD nie ma `gtin`; `description` zostaje krótkim opisem; produkt bez nowych danych daje JSON-LD jak przed zadaniem.",
        "Kryterium 6 w części sklepu: produkt z pustymi nowymi polami nie ma na karcie marki, sekcji „Opis” i „Parametry” ani kodów, a reszta karty jest bez zmian.",
        "Test E2E: produkt utworzony w panelu z marką, opisem, parametrami i dwoma wariantami, z których jeden ma EAN spoza puli obiegu ograniczonego, a drugi EAN z prefiksem „2”, po publikacji pokazuje nowe pola na karcie; JSON-LD ma `brand`, `sku` obu wariantów i `gtin` tylko pierwszego; test przechodzi także na `STORE_MODE=production`.",
        "`make -C apps/store check`, `make -C apps/store test` i nowy test E2E przechodzą; `docs/features/product-page/spec.md` opisuje stan po zadaniu (kryterium 19 w tej części)."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRODUCT-DATA-1", "PRODUCT-DATA-2"],
      "paths": [
        "apps/store/src/catalog/",
        "apps/store/app/p/",
        "e2e/tests/product-data.spec.ts",
        "docs/features/product-page/spec.md"
      ],
      "parallel_with": ["PRODUCT-DATA-4", "PRODUCT-DATA-5"]
    },
    {
      "id": "PRODUCT-DATA-4",
      "title": "Seed: marka, opisy, parametry i kody 191 produktów",
      "scope": "`fixtures/catalog.yaml` (opis typu i parametry w szablonach, parametry i opcjonalny własny opis modeli) oraz klasy seeda Catalog (`Infrastructure/Demo`, `Application/Demo`): marka z listy `brands`, złożenie opisu i parametrów, SKU i EAN wariantów. Specyfikacja: „Dane demo” → „Seed”, decyzja D9. Seed dalej idzie przez `CreateProduct`. Istniejące klucze `catalog.yaml` zostają bez zmian (czyta je też `DemoData\\Verify\\SeedCatalog`); nazwy, slugi i identyfikatory produktów i wariantów się nie zmieniają. Nie obejmuje generatora (zadanie 5). Dokumentacja: `docs/vision.md` („Dane demo”: zawartość seeda), `docs/features/variants/spec.md` (zdania o marce tylko w nazwie produktu demo).",
      "acceptance_criteria": [
        "Kryterium 13 specyfikacji: po `make seed` każdy z 191 produktów ma markę równą marce z nazwy, opis (zdanie modelu i akapity typu albo własny opis modelu), co najmniej 3 parametry, z których żaden nie powtarza nazwy osi wariantów produktu; każdy wariant ma SKU zaczynający się literą, unikalny w seedzie, i poprawny EAN-13 z prefiksem „2” wyliczony z klucza wariantu, więc puste `gtin` w odpowiedzi sklepu.",
        "Liczby produktów i wariantów, slugi i identyfikatory produktów i wariantów są takie jak przed zadaniem (`RealDemoCatalogTest` z dotychczasowymi liczbami przechodzi); dopasowania `prices.yaml` i `stock.yaml` po kluczach wariantów działają bez zmian.",
        "Treść zgadza się z krótkim opisem modelu: „Namiot turystyczny Hallen Halny 4” ma parametr „Liczba osób: 4”; raport zadania pokazuje pełną treść (marka, opis, parametry, kody) trzech produktów z różnych działów do przeglądu sensu.",
        "Wszystkie wartości seeda przechodzą reguły zapisu z sekcji „Pola i reguły” (test na prawdziwym katalogu buduje je konstruktorami zapisu); błędna treść `catalog.yaml` (powtórzona nazwa parametru, parametr o nazwie osi, za długi opis) zatrzymuje seed przy wczytaniu katalogu, przed zmianą danych, z komunikatem wskazującym produkt (test ścieżki błędu).",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą; `docs/vision.md` i `docs/features/variants/spec.md` opisują stan po zadaniu (kryterium 19 w tej części)."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRODUCT-DATA-1", "PRODUCT-DATA-2"],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Demo/",
        "apps/api/src/Catalog/Application/Demo/",
        "apps/api/fixtures/catalog.yaml",
        "apps/api/tests/Unit/Catalog/Demo/",
        "apps/api/tests/Integration/Catalog/Demo/",
        "docs/vision.md",
        "docs/features/variants/spec.md"
      ],
      "parallel_with": ["PRODUCT-DATA-3", "PRODUCT-DATA-5"]
    },
    {
      "id": "PRODUCT-DATA-5",
      "title": "Generator danych w skali: nowe pola, verify, pomiar rozmiaru i szacunki dysku i czasu",
      "scope": "Moduł `DemoData` (generator produktów, `CatalogLoader` z nowymi kolumnami `COPY`, `CatalogVerifier`) i słowniki `fixtures/demo-data` (zestawy parametrów typów, pule zdań opisu), `profiles.yaml` (`diskPeakGb`). Specyfikacja: „Dane demo” → „Generator”, „Skala i dane na serwerze” (p. 3, 4 i 5), decyzja D9. Nowe losowania z osobnego strumienia generatora liczb. Bez zmian w krokach zdjęć, cen, zamówień i reindeksacji; indeks unikalny SKU zostaje na czas ładowania. Zadanie uruchamia lokalnie pełny przebieg `small` oraz same kroki katalogu `medium` (bez zdjęć, cen i zamówień), każdy przed zmianą i po niej; nie obejmuje pełnego przebiegu profilu innego niż `small` ani operacji na serwerze. Dokumentacja: `docs/features/demo-data/spec.md` (pola generatora, pomiar rozmiaru, rachunek szczytu dysku, czas kroku produktów, `diskPeakGb`).",
      "acceptance_criteria": [
        "Kryterium 14 specyfikacji: każdy wygenerowany produkt ma markę równą marce z nazwy, opis z 2–3 akapitów i 300–600 znaków inny niż krótki opis oraz 3–6 parametrów bez nazw osi wariantów typu; każdy wariant ma unikalny SKU zaczynający się numerem produktu dopełnionym zerami (z numeru produktu i pozycji wariantu, bez odczytów z bazy) i poprawny EAN-13 z prefiksem „2”; wszystkie wartości przechodzą reguły zapisu z sekcji „Pola i reguły”.",
        "Determinizm: te same ziarno, profil i chwila odniesienia dają te same nowe pola przy 1, 3 i 4 procesach (`ProcessCountDeterminismTest`); test ze skrótem kolumn `id`, `slug`, `name`, `short_description` i wariantów profilu `small`, dopisany i uruchomiony na kodzie sprzed zmiany generatora, przechodzi przed zmianą i po niej (raport pokazuje oba uruchomienia).",
        "Kryterium 15: `demo-data:verify` kończy się błędem z nazwą naruszenia, gdy wygenerowany produkt nie ma marki, opisu albo parametrów albo wygenerowany wariant nie ma SKU albo EAN (testy psują po jednym wierszu), i przechodzi na profilu `small` po pełnym przebiegu generatora.",
        "Kryterium 16 (pomiar): rozmiar sterty, TOAST i każdego indeksu `catalog_product` i `catalog_product_variant`, z indeksem unikalnym SKU jako osobną pozycją, zmierzony przed zmianą i po niej z tego samego ziarna: po pełnym przebiegu `small` i po krokach `demo-data:categories` i `demo-data:products` profilu `medium`; raport podaje komendy i wyniki, a `docs/features/demo-data/spec.md` bajty na produkt i na wariant jako pomiar z nazwą profilu i zakresem kroków.",
        "Kryterium 17 (szacunek szczytu dysku): `docs/features/demo-data/spec.md` ma rachunek przyrostu szczytu składnik po składniku (tabele i indeksy z pomiaru przeliczone na profil, druga kopia przyrostu wiersza produktu w kroku zdjęć, WAL nowych kolumn i indeksu SKU); wartości `medium`, `server` i `full` są oznaczone jako szacunek niezweryfikowany przebiegiem; `diskPeakGb` w `profiles.yaml` jest podniesione zgodnie z rachunkiem, a `ProfilesTest` zmienia się razem z wartościami; żadne zdanie nie podaje rozmiaru tabel jako szczytu dysku ani szacunku jako pomiaru.",
        "Kryterium 18 (czas i indeks SKU podczas `COPY`): czas kroku `demo-data:products` profilu `medium` zmierzony przed zmianą i po niej na tej samej maszynie i z tą samą liczbą procesów, z indeksem unikalnym SKU obecnym podczas ładowania, oraz czas nowych sprawdzeń `verify` na `small` są w raporcie; `docs/features/demo-data/spec.md` podaje różnicę jako pomiar `medium`, a czas `server` i `full` jako szacunek z zastrzeżeniem o wzroście szybszym niż liniowy i odniesieniem do limitu Joba. Gdy kroków katalogu `medium` nie da się uruchomić lokalnie, zadanie zgłasza to jako niespełnione wymaganie i nie zastępuje pomiaru szacunkiem z `small`.",
        "Słowniki: każdy typ produktu ma zestaw parametrów i pule zdań wystarczające do budżetu długości; test słowników sprawdza, że żadna nazwa parametru typu nie jest nazwą jego osi wariantów i że zdania opisu nie zawierają znaków niedozwolonych regułą tekstu.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą; `docs/features/demo-data/spec.md` opisuje stan po zadaniu (kryterium 19 w tej części)."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRODUCT-DATA-1", "PRODUCT-DATA-2"],
      "paths": [
        "apps/api/src/DemoData/",
        "apps/api/fixtures/demo-data/",
        "apps/api/tests/Unit/DemoData/",
        "apps/api/tests/Integration/DemoData/",
        "apps/api/tests/Functional/DemoData/",
        "docs/features/demo-data/spec.md"
      ],
      "parallel_with": ["PRODUCT-DATA-3", "PRODUCT-DATA-4"]
    }
  ]
}
```
