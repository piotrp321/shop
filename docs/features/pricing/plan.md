# Pricing – plan zadań

Specyfikacja: `docs/features/pricing/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PRICING-1 | |
| 2 | PRICING-2 | |
| 3 | PRICING-3, PRICING-4, PRICING-7 | |
| 4 | PRICING-5, PRICING-6 | |
| 5 | PRICING-8 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PRICING",
  "spec": "docs/features/pricing/spec.md",
  "tasks": [
    {
      "id": "PRICING-1",
      "title": "Catalog: klasa podatkowa TaxClassCode zamiast TaxCategory",
      "scope": "apps/api, moduł Catalog (spec: „Klasa podatkowa produktu (Catalog)”). Enum TaxClassCode z ośmioma klasami zastępuje TaxCategory w domenie, CreateProductHandler, repozytorium, rekordzie, YamlDemoCatalogSource, fabryce Foundry i testach. Nowa migracja: kolumna catalog_product.tax_category → tax_class z mapowaniem wartości ze specyfikacji. fixtures/catalog.yaml: klucz taxClass i dokładne klasy towarów demo. Dokumentacja: docs/vision.md (taxCategory → taxClass w regułach domenowych i na liście decyzji, stawki dla PL w kodzie Pricing). Bez modułu Pricing, bez zmian API, sklepu, panelu i indeksu ES.",
      "acceptance_criteria": [
        "Produkt w Catalog ma klasę podatkową TaxClassCode z kodami standard, printed_book, printed_map, freeze_dried_meal, energy_bar, energy_gel, protein_powder, electrolyte_tablet; TaxCategory nie istnieje w kodzie.",
        "Nowa migracja zmienia tax_category na tax_class i mapuje standard → standard, printed_publication → printed_book, food → freeze_dried_meal, food_supplement → protein_powder; istniejące migracje są niezmienione.",
        "Po make seed produkty demo mają klasy z tabeli specyfikacji (przewodniki printed_book, mapy printed_map, dania liofilizowane freeze_dried_meal, batony energy_bar, żele energy_gel, odżywki białkowe protein_powder, elektrolity electrolyte_tablet, reszta standard).",
        "Klasa podatkowa nie pojawia się w openapi.json, openapi-admin.json ani w indeksie ES.",
        "docs/vision.md używa klasy podatkowej (taxClass) zamiast kategorii podatkowej.",
        "Przechodzą make -C apps/api check i make -C apps/api test."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "PRICING-2",
      "title": "Pricing: model cen, persystencja, ustalanie ceny i ceny demo",
      "scope": "apps/api, nowy moduł src/Pricing oraz zapytania Catalog dla Pricing (spec: „Bounded contexty i zależności”, „Model domenowy (Pricing)”, „Persystencja i transakcje”, „Warstwa aplikacji”: SetProductPrice, GetProductTaxClass, ListProductsForPricing, pricing:seed-demo). Money, GrossPrice, VatRate, PolishVatRates (z adnotacją o stawkach przykładowych), PriceBreakdown, agregat ProductPrice, PriceHistoryEntry, PriceChangeOrigin. Tabele pricing_product_price i pricing_price_history (migracja), rekordy Doctrine, repozytorium z #[Version]. Handler SetProductPrice z portem ProductTaxClasses. Seed: fixtures/prices.yaml, komenda pricing:seed-demo, wywołanie w make seed, czyszczenie tabel Pricing przy resecie danych demo. Deptrac: warstwy Pricing, reguła Pricing_Infrastructure → Catalog_Application z komentarzem, próby obejścia. Test kontraktu TaxClassCode ↔ PolishVatRates. Dokumentacja: apps/api/CLAUDE.md (pricing:seed-demo w seedzie). Bez endpointów HTTP, GetCurrentPrices, reconcile i frontów.",
      "acceptance_criteria": [
        "PriceBreakdown liczy netto HALF_UP do grosza wyłącznie na liczbach całkowitych, VAT = brutto − netto; test własności dla każdego brutto 1–100 000 groszy i dla maksimum przy każdej stawce (netto + VAT = brutto, VAT ≥ 0, wynik równy obliczeniu referencyjnemu) oraz stawka 0 bp przechodzą.",
        "GrossPrice przyjmuje 1–99 999 999 groszy PLN; poza zakresem wyjątek InvalidInput.",
        "Każda faktyczna zmiana kwoty lub stawki tworzy w tej samej transakcji jeden wpis historii ze snapshotem netto, VAT, brutto, stawki, klasy, waluty, czasu, źródła i aktora; identyczny komplet nie tworzy wpisu ani nowej wersji.",
        "Wymuszony błąd zapisu historii wycofuje zmianę ceny (test integracyjny).",
        "SetProductPrice: nieznany produkt → ProductNotFound; zła wersja lub null dla istniejącej ceny → PriceModifiedConcurrently; dwie zmiany z tą samą wersją i dwa równoległe pierwsze zapisy kończą się jednym sukcesem i konfliktem, liczba wpisów historii równa liczbie sukcesów (testy integracyjne).",
        "Po make seed każdy produkt demo ma cenę ze źródłem seed; produkt demo bez wzorca w fixtures/prices.yaml kończy seed błędem.",
        "Deptrac przepuszcza Pricing_Infrastructure → Catalog_Application i odrzuca próby obejścia: Catalog_* → Pricing_*, Pricing_Domain → Catalog_*, Pricing_Application → Catalog_Domain.",
        "Test kontraktu: każdy przypadek TaxClassCode ma stawkę w PolishVatRates i każda stawka ma przypadek.",
        "Przechodzą make -C apps/api check i make -C apps/api test."
      ],
      "risk": "CRITICAL",
      "depends_on": ["PRICING-1"]
    },
    {
      "id": "PRICING-3",
      "title": "Ceny w sklepie: GetCurrentPrices, GET /api/v1/prices i kafelek produktu",
      "scope": "apps/api (Pricing: GetCurrentPrices, kontroler GET /api/v1/prices, publiczny dostęp w security.yaml, openapi.json) i apps/store (wygenerowany klient, loadCatalog, formatPrice, ProductTile) (spec: „Warstwa aplikacji” – GetCurrentPrices, „API” – sklep, „Sklep (apps/store)”). Bez zmian w GET /api/v1/products i w panelu.",
      "acceptance_criteria": [
        "GetCurrentPrices dla 1–100 id wykonuje jedno zapytanie po tabelach Pricing (test z licznikiem zapytań) i zwraca PriceSnapshot {productId, taxClass, vatRateBp, net, vat, gross, currency, version} tylko dla cen z aktualną stawką.",
        "GET /api/v1/prices?productId=… dla 1–48 id zwraca 200 {items: [{productId, gross, currency}]} z Cache-Control public, max-age=60; produkty bez aktualnej ceny i nieznane id są pominięte; brak id, ponad 48 id lub niepoprawny UUID → 400 problem details.",
        "openapi.json i klient TS sklepu są wygenerowane na nowo.",
        "Kafelek pokazuje brutto sformatowane przez formatPrice (pl-PL) albo „Cena niedostępna”; błąd endpointu cen nie psuje listy (produkty z „Cena niedostępna”, błąd w logu serwera).",
        "Przechodzą make -C apps/api check, make -C apps/api test, make -C apps/store check i make -C apps/store test."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRICING-2"]
    },
    {
      "id": "PRICING-4",
      "title": "Panel: lista cen z wyszukiwaniem i filtrem kategorii",
      "scope": "apps/api (Catalog: SearchBackofficeProducts z pg_trgm i indeksem GIN w nowej migracji, GET /api/v1/admin/categories; Pricing: ListBackofficePrices, GET /api/v1/admin/prices; openapi-admin.json) i apps/admin (wygenerowany klient, menu „Ceny”, trasa /prices, widok listy z wyszukiwaniem, filtrem kategorii, kolumnami i stronicowaniem po kursorach) (spec: „Warstwa aplikacji”, „API” – panel, „Panel (apps/admin)” bez zmiany ceny i historii). Bez PUT ceny i historii.",
      "acceptance_criteria": [
        "GET /api/v1/admin/prices?q&category&after&perPage zwraca 200 {items: [{productId, name, taxClass, applicableVatRate, price | null}], next} z private, no-store; price zawiera gross, net, vat, vatRate, currency, version, updatedAt, vatRateOutdated.",
        "Wyszukiwanie po nazwie (q 2–100 znaków) i filtr kategorii obejmujący podkategorie oraz kategorie dodatkowe działają w PostgreSQL ze stronicowaniem keyset po (name, id) (testy integracyjne); złe parametry → 400, nieznana kategoria → 404.",
        "GET /api/v1/admin/categories zwraca drzewo kategorii każdemu pracownikowi.",
        "Oba endpointy: bez tokena i z tokenem realmu shop → 401; każda rola pracownika ma dostęp.",
        "openapi-admin.json i klient TS panelu są wygenerowane na nowo.",
        "Panel ma pozycję „Ceny” i widok /prices z wyszukiwaniem od 2 znaków, filtrem kategorii, kolumnami nazwa, klasa, brutto, netto, VAT, stawka, oznaczeniem nieaktualnej stawki i stronicowaniem następna/poprzednia; widok obsługuje pusty wynik.",
        "Przechodzą make -C apps/api check, make -C apps/api test, make -C apps/admin check i make -C apps/admin test."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRICING-2"]
    },
    {
      "id": "PRICING-5",
      "title": "Panel: zmiana ceny przez administratora",
      "scope": "apps/api (Pricing: PUT /api/v1/admin/products/{productId}/price, PriceVoter, aktor z AuthenticatedStaffMember, openapi-admin.json) i apps/admin (dialog „Zmień cenę” w widoku /prices, zamiana tekstu PLN na grosze bez float) (spec: „API” – panel, „Panel (apps/admin)”, „Scenariusze błędów”). Bez historii.",
      "acceptance_criteria": [
        "PUT z {gross, version} zmienia cenę i zwraca 200 z ceną; ta sama kwota → 200 bez zmiany wersji i bez wpisu historii.",
        "Tylko rola admin może zmienić cenę; marketing, support i warehouse → 403; bez tokena i z tokenem shop → 401.",
        "Nieznany produkt → 404; nieaktualna wersja → 409 bez nadpisania; kwota 0, ujemna, ponad 99 999 999 groszy lub nie liczba całkowita → 422.",
        "Aktor wpisu historii (sub i nazwa) pochodzi z tokena, nie z treści żądania.",
        "Dialog widoczny tylko dla roli admin; podgląd stawki z applicableVatRate bez liczenia netto na froncie; 409 → komunikat „Cena została w międzyczasie zmieniona – odśwież” i odświeżenie wiersza; 422 → komunikat przy polu; zamiana kwoty na grosze ma testy Vitest.",
        "openapi-admin.json i klient TS panelu są wygenerowane na nowo.",
        "Przechodzą make -C apps/api check, make -C apps/api test, make -C apps/admin check i make -C apps/admin test."
      ],
      "risk": "CRITICAL",
      "depends_on": ["PRICING-4"]
    },
    {
      "id": "PRICING-6",
      "title": "Panel: historia cen produktu",
      "scope": "apps/api (Pricing: GetPriceHistory, GET /api/v1/admin/products/{productId}/price-history, openapi-admin.json) i apps/admin (dialog „Historia” w widoku /prices) (spec: „Warstwa aplikacji” – GetPriceHistory, „API” – panel, „Panel (apps/admin)”).",
      "acceptance_criteria": [
        "GET /api/v1/admin/products/{productId}/price-history?after&perPage zwraca 200 {items: [{changedAt, gross, net, vat, vatRate, taxClass, source, actorName}], next} od najnowszych, stronicowane keyset, z private, no-store.",
        "Nieznany produkt → 404; złe parametry → 400; bez tokena i z tokenem shop → 401; każda rola pracownika ma dostęp.",
        "Dialog „Historia” pokazuje wpisy od najnowszych z „Pokaż więcej” i etykietami źródeł Pracownik, Dane demo, Uzgodnienie podatkowe.",
        "openapi-admin.json i klient TS panelu są wygenerowane na nowo.",
        "Przechodzą make -C apps/api check, make -C apps/api test, make -C apps/admin check i make -C apps/admin test."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRICING-4"]
    },
    {
      "id": "PRICING-7",
      "title": "Komenda pricing:reconcile-tax",
      "scope": "apps/api (Pricing: ReconcileTax, komenda konsoli pricing:reconcile-tax [--apply]) (spec: „Reconcile”). Dokumentacja: apps/api/CLAUDE.md (opis komendy). Komenda nie jest dodawana do make init, make up ani make seed.",
      "acceptance_criteria": [
        "Komenda wykrywa ceny ze stawką różną od PolishVatRates i z klasą różną od bieżącej klasy produktu w Catalog.",
        "Bez --apply tylko raportuje; z --apply przelicza reprice (brutto bez zmian) ze źródłem tax_reconciliation, każdą cenę w osobnej transakcji; kolejne uruchomienie nic nie znajduje.",
        "Konflikt wersji z równoległą edycją: ponowny odczyt i jedna kolejna próba, potem pozycja w raporcie.",
        "Kody wyjścia: 0 spójne lub naprawione, 1 rozbieżności bez --apply, 2 błąd; ścieżka błędu przy niedostępnej bazie jest przetestowana.",
        "Komendy nie wywołuje make init, make up ani make seed.",
        "Przechodzą make -C apps/api check i make -C apps/api test."
      ],
      "risk": "CRITICAL",
      "depends_on": ["PRICING-2"]
    },
    {
      "id": "PRICING-8",
      "title": "E2E: zmiana ceny w panelu widoczna w sklepie",
      "scope": "e2e (Playwright) na pełnym stosie (spec: „Testy” – E2E, kryterium akceptacji 9). Bez zmian w aplikacjach poza ewentualnymi atrybutami do testów.",
      "acceptance_criteria": [
        "Test E2E: administrator zmienia cenę produktu w panelu, a nowa cena brutto jest widoczna na liście w sklepie (oczekiwanie do 70 s).",
        "Przechodzi make e2e i test działa w CI."
      ],
      "risk": "STANDARD",
      "depends_on": ["PRICING-3", "PRICING-5"]
    }
  ]
}
```
