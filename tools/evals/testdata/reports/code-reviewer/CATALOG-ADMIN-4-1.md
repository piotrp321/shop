**Review CATALOG-ADMIN-4: werdykt PASS. Nie znalazłem nic Critical ani Important, jest 5 uwag Minor.**

- SHA: 9dff259690010ba84cd7885c7716afa30901f92c (HEAD i czyste drzewo potwierdzone na początku i na końcu), diff 35007f1..9dff259.
- Tryb: FULL.
- Testów nie uruchamiałem, bo pełny `make test` puszcza orkiestrator. Ocena opiera się na kodzie i na dzienniku dowodów (ostatni wpis: pełny `make test` na 9dff259 z czystym drzewem, 3311 testów, exit 0).

## Trzy kontrole obowiązkowe

**1. Istniejące testy: bez uwag.** Diff nie zawiera `skip`, `markTestSkipped` ani `only` i nie zmienia `phpunit.dist.xml`. Zmienione oczekiwania mają podstawę w specyfikacji:
- `CreateProductHandlerTest` i `EditProductHandlerTest`: `CategoryNotFound` zmienione na `UnknownCategory` z pozycją. Spec: „Kategoria nieistniejąca → 422 (pole kategorii), nie 404”.
- `FlysystemProductImageStorageTest`: komunikat angielski zmieniony na polski. Spec: komunikaty Catalog po polsku.
- `ProductChangeOutboxTest`, `RepriceOnTaxClassChangeTest`, `EventFlowTest`, `SearchReindexFlowTest`: dopisany tylko setup `OriginalImages::exist`, którego wymaga nowe sprawdzanie `exists`. Asercje bez zmian.

**2. Zakres: w normie.**
- Dwa pliki są spoza listy `scope`, ale mają podstawę w specyfikacji:
  - `Catalog/Domain/Product/UnknownCategory.php`: 422 przy polu kategorii.
  - testy integracyjne Pricing i Shared: setup.
- `deptrac.yaml` opisuję w uwadze M1.
- `openapi.json` bez zmian.
- `openapi-admin.json` zmienia się tylko przez dodanie: żadna ścieżka ani schemat nie zniknął ani się nie zmienił, doszło 15 schematów, w tym `ProductConflictProblem` z 6 wartościami `enum`.
- `schema.d.ts` jest wygenerowany. Tego, że zgadza się z `openapi-admin.json`, nie sprawdzałem (wymaga uruchomienia `make -C apps/admin check` albo CI).

**3. RED przed implementacją: bez zgłoszeń.** Każdy RED (wpisy 0, 2, 5, 8, 11, 16, 19) ma w `dirty` i w `head` tylko testy, bez pliku produkcyjnego testowanego zachowania. Nazwy testów są w `output_tail`.
- Wpis 2 (`CreateProductHandlerTest` i `EditProductHandlerTest`) padł na `TypeError` sygnatury konstruktora, a nie na asercji `exists`. To słaby, ale prawdziwy RED: produkcja nie była zmieniona.
- Wpisy 20–22 mają `label: green` przy `exit_code 2`, czyli są oznaczone błędnie. Faktyczny GREEN testów funkcjonalnych to wpis 25 (pełny `make test`, exit 0).
- Bez osobnego RED weszły w 9dff259 dwie zmiany:
  - zamiana parametru `:status` na literał w `DbalBackofficeProductSource`, to optymalizacja (uwaga M2);
  - `ProductWriteData` i `data()` w DTO, które pokrywa RED funkcjonalny z wpisu 19.

## Zgodność z kryteriami akceptacji
- **Autoryzacja:** wszystkie 8 endpointów ma `#[IsGranted]`. GET listy, widoku i klas podatkowych wymaga `VIEW_PRODUCTS`. Zapis, publikacja, wycofanie i upload wymagają `MANAGE_PRODUCTS` (`admin`, `marketing`). Firewall `backoffice` wymaga `ROLE_STAFF`.
- **Testy uprawnień:** przypadki bez tokena, z tokenem klienta, `support`, `warehouse` i pracownika bez roli są pokryte. Na zapisach i uploadzie te role dostają 403.
- **Kody błędów:**
  - 400: złe parametry i kursor;
  - 404: widok, PUT, publikacja i wycofanie oraz nieznana kategoria na liście;
  - 409: wszystkie 6 typów, w tym `product-modified` po POST i PUT z nieaktualną wersją oraz `product-not-publishable` przy publikacji bez zdjęć;
  - 422: dokładna lista `violations` ze ścieżkami i polskimi komunikatami, duplikat klucza zdjęcia i brak oryginału przy `images[i].key`, duplikat według `comparisonKey` przy `variants[i].values`;
  - 503: Garage przy POST, PUT i uploadzie, bez `detail`.
- **Upload:** JPEG 5 MB daje 201 z kluczem i podglądem `thumbnail`. SVG, tekst z rozszerzeniem `.jpg` i obraz ponad 50 MP dają 422 z polskim `detail`. Typ jest rozpoznawany po treści (finfo), wymiary z nagłówka, a sprawdzanie `Content-Length` jest przed szukaniem pliku.
- **Path traversal:** niemożliwy. `ImageKey` ma wzorzec `#^originals/[a-f0-9]{64}\.(ext)$#D`, a klucz przy zapisie liczy się z treści pliku.
- **Klucz podpisu:** w odpowiedziach są tylko podpisane URL-e z `ImageUrlGenerator`.
- **Lista:** keyset z filtrem statusu działa, test stronicuje szkice przy `perPage=2`.
- **Cache-Control:** odpowiedzi 2xx mają `private, no-store`, test sprawdza to na każdym endpoincie. Kształty odpowiedzi test porównuje ze schematami OpenAPI.
- **Architektura:** wersja jest sprawdzana przed `exists` i regułami domeny. Zmiana `CategoryNotFound` na `UnknownCategory` dotyczy tylko `ProductInput`. Seed (`DemoCatalogLoader`) zapisuje oryginały przed `CreateProduct`, więc `exists` przechodzi, a nikt inny nie łapał `CategoryNotFound` przy tych komendach.
- **Dokumentacja:** `docs/vision.md` (katalog edytują `admin` i `marketing`; role od cen i stanów się zgadzają) i `apps/api/CLAUDE.md` opisują nowy stan.

## Uwagi Minor

**M1. Zmiana `deptrac.yaml` (Catalog_UI → Backoffice_Application), `apps/api/deptrac.yaml:174-178`.**
- `apps/api/CLAUDE.md` mówi „Jeśli Deptrac zgłasza błąd, popraw projekt, nie edytuj `deptrac.yaml`”, a `scope` taska tego pliku nie wymienia.
- Wzorzec jest ten sam co w Pricing_UI i Inventory_UI: aktor z `AuthenticatedStaffMember`, z identycznym komentarzem. Podstawa w specyfikacji to zapis `sub` pracownika.
- Ryzyka nie ma.
- Sugestia: ujawnić to w PR jako Ruling. Alternatywa bez zmiany reguł to `#[CurrentUser] UserInterface` i `getUserIdentifier()`, które zwraca `sub`.

**M2. Test indeksu częściowego nie sprawdza kodu, `tests/Integration/Catalog/Query/BackofficeProductViewsTest.php:125`.**
- `testTheDraftListUsesThePartialIndexOfDrafts` robi `EXPLAIN` na ręcznie napisanym SQL z literałem `'draft'`, a nie na zapytaniu z `DbalBackofficeProductSource::page`. Przechodzi niezależnie od tego, co robi produkcja: powrót do `p.status = :status` albo inny warunek go nie złamie.
- Uzasadnienie literału w `DbalBackofficeProductSource.php:57-60` („a bound parameter could not use it in a generic plan”) jest wątpliwe: DBAL/PDO przygotowuje zapytanie przy każdym wywołaniu, więc plan jest zawsze dopasowany do parametru. Literał jest za to bezpieczny, bo to wartość enuma.
- Sugestia: w teście wykonać `EXPLAIN` z SQL budowanym przez źródło, albo usunąć test i poprawić komentarz.

**M3. Limity PHP są wpiekane w obraz, `apps/api/Dockerfile:19` (`COPY .../app.ini`).**
- `make up` nie robi `--build`, więc istniejący stack lokalny albo długo żyjący stack worktree zostaje z domyślnymi limitami PHP (2M i 8M).
- Skutek: upload 5 MB dostaje 422 „Plik jest za duży.”, a `Content-Length` jest porównywany z `ini_get` równym 8M. Błąd jest cichy.
- Testy funkcjonalne tego nie wykryją, bo czytają `ini_get` w czasie testu.
- Sugestia: w `apps/api/CLAUDE.md` przy limitach dopisać, że zmiana `app.ini` wymaga `docker compose build api`. Albo upewnić się, że E2E z CATALOG-ADMIN-7 działa na świeżo zbudowanym obrazie.

**M4. Limity 64 MB i 50 MP są zduplikowane w konfiguracji testowej, `config/services.yaml:206-212`.**
- `when@test` przepisuje argumenty `FlysystemProductImageStorage` ręcznie, z tymi samymi wartościami co w `services.yaml:47-51`. Zmiana limitu w produkcji nie zmieni go w testach.
- Sugestia: przenieść limity do parametrów (`%app.product_images.max_bytes%`) albo w teście tylko upublicznić usługę, bez powtarzania argumentów.

**M5. Dziennik dowodów ma wpisy 20–22 z `label: green` przy `exit_code 2`.** To nie jest błąd kodu, ale dziennik przez to wprowadza w błąd. Sugestia: przy kolejnych taskach oznaczać nieudane uruchomienia pośrednie inaczej niż green.

## Werdykt
**PASS.** Wszystkie kryteria akceptacji, które da się ocenić z kodu i dziennika, są spełnione; pięć uwag Minor jest wyżej. Do potwierdzenia w CI zostają `make -C apps/admin check` i aktualność `schema.d.ts`.

Kluczowe pliki:
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/src/Catalog/UI/Http/UploadProductImageController.php
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/src/Catalog/UI/Http/Validation/ValidProductWriteValidator.php
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/src/Catalog/UI/Http/ProductWriteFailures.php
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/src/Catalog/Infrastructure/Security/ProductVoter.php
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/deptrac.yaml
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/config/services.yaml
- <home>/Development/claude/shop-CATALOG-ADMIN-4/apps/api/tests/Integration/Catalog/Query/BackofficeProductViewsTest.php