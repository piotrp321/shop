# Inventory – plan zadań

Specyfikacja: `docs/features/inventory/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | INVENTORY-1 | |
| 2 | INVENTORY-2, INVENTORY-4, INVENTORY-5 | |
| 3 | INVENTORY-3, INVENTORY-6, INVENTORY-7 | |
| 4 | INVENTORY-8 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "INVENTORY",
  "spec": "docs/features/inventory/spec.md",
  "tasks": [
    {
      "id": "INVENTORY-1",
      "title": "Model stanu, persystencja i port rezerwacji dla Ordering",
      "scope": "apps/api: nowy context Inventory. Domain: StockItem (reserve, release, commit, available, niezmiennik; receive i correct należą do INVENTORY-2), Reservation (linie, statusy, przejścia), value objecty ProductId, ReservationKey, StockChangeOrigin, StockMovement, AvailabilityLevel. Migracja wszystkich czterech tabel Inventory z CHECK i indeksami (spec: „Persystencja i transakcje”). Rekordy Doctrine, repozytoria z lockMany (FOR UPDATE w kolejności product_id) i INSERT … ON CONFLICT DO NOTHING rezerwacji. Application: ReserveStock, ReleaseReservation, CommitReservation z ReservationView i wyjątkami portu (spec: „Idempotencja”, „Warstwa aplikacji”), GetStockLevels. Testy współbieżności C1, C2, C3, C5 i część C6 (CHECK przez bezpośredni SQL) na prawdziwym PostgreSQL z pcntl_fork i barierą advisory lock (spec: „Testy – Współbieżność”); stan początkowy ustawiany w testach przez repozytorium lub fixture testowy. Deptrac: warstwy Inventory_Domain/Application/Infrastructure/UI i próby obejścia Catalog_* → Inventory_*, Pricing_* → Inventory_*, Inventory_* → Pricing_*, Inventory_Domain → Backoffice_Application, Inventory_Application → Backoffice_Application. Dokumentacja: docs/vision.md i apps/api/CLAUDE.md – model mieszany blokowania (spec: „Dokumentacja do aktualizacji”, część o blokowaniu). Nie obejmuje: przyjęć, korekt, endpointów, Catalog, seeda, frontów.",
      "acceptance_criteria": [
        "Niezmiennik 0 ≤ reserved ≤ physical ≤ 1 000 000 pilnuje domena i CHECK w bazie; bezpośredni SQL łamiący CHECK kończy się błędem",
        "ReserveStock jest „wszystko albo nic”: niedobór dowolnej linii daje InsufficientStock z listą shortages {productId, requested, available} dla wszystkich brakujących linii i nie zapisuje rezerwacji ani klucza",
        "Ponowione ReserveStock z tym samym kluczem i liniami zwraca aktualny status rezerwacji (active, released albo committed) bez zmiany stanów i bez wpisu historii; inne linie z tym kluczem dają ReservationKeyReused",
        "ReleaseReservation i CommitReservation są idempotentne; commit zwolnionej i release zatwierdzonej dają ReservationStateConflict; nieznany klucz daje ReservationNotFound",
        "Rezerwacja nie wygasa; walidacja linii: 1–100 linii, ilość 1–1000, bez powtórzonego produktu (InvalidReservation)",
        "Każda zmiana stanu dopisuje w tej samej transakcji wpis historii ze stanem po operacji, źródłem system i referencją klucza; wymuszony błąd zapisu historii wycofuje całą transakcję",
        "GetStockLevels zwraca dla 1–100 id {productId, physical, reserved, available, level, version} jednym zapytaniem; produkt bez rekordu ma zera i version null; AvailabilityLevel: 0 out_of_stock, 1–5 low_stock, ≥ 6 in_stock",
        "Testy C1, C2, C3 i C5 przechodzą w 5 rundach na PostgreSQL w make -C apps/api test (więc w CI), z niezmiennikiem po każdej rundzie",
        "Deptrac zawiera warstwy Inventory i odrzuca wymienione próby obejścia",
        "docs/vision.md i apps/api/CLAUDE.md opisują model mieszany: wersja dla edycji z widoku, FOR UPDATE dla atomowych operacji na stanie",
        "Przechodzą make -C apps/api check i make -C apps/api test"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "INVENTORY-2",
      "title": "Przyjęcia i korekty w API",
      "scope": "apps/api: StockItem.receive i correct, ReceiptKey, CorrectionReason, StockNote; komendy ReceiveStock i CorrectStock (spec: „Model domenowy”, „Idempotencja – Przyjęcie”, „Warstwa aplikacji”). Catalog: zapytanie GetProductExists i adapter portu ProductCatalog w Inventory_Infrastructure. StockVoter (MANAGE_STOCK dla admin i warehouse). Endpointy POST /api/v1/admin/products/{productId}/stock/receipts (nagłówek Idempotency-Key, 201/200) i POST …/stock/corrections (spec: „API”), eksport openapi-admin.json i klient TS panelu. Testy współbieżności C4 i C6 (korekty w dół równolegle z rezerwacjami). Deptrac: reguły Inventory_Infrastructure → Catalog_Application, Inventory_Infrastructure → Backoffice_Application, Inventory_UI → Backoffice_Application i próby obejścia Inventory_Domain → Catalog_*, Inventory_Application → Catalog_Domain, Inventory_Infrastructure → Backoffice_Infrastructure. Dokumentacja: Idempotency-Key przy przyjęciu w docs/vision.md i apps/api/CLAUDE.md, komentarze reguł w deptrac.yaml. Nie obejmuje: ekranów panelu, seeda, listy i historii.",
      "acceptance_criteria": [
        "Przyjęcie dodaje 1–100 000 sztuk; wynik ponad 1 000 000 daje 422; nieznany produkt w Catalog daje 404",
        "To samo Idempotency-Key z tym samym produktem, ilością i notatką zwiększa stan dokładnie raz (201, potem 200 ze stanem bieżącym); inna treść albo inny produkt z tym kluczem daje 409; brak lub zły klucz daje 400",
        "Korekta ustawia stan policzony 0–1 000 000 z powodem inventory_count, damage, loss albo other (notatka wymagana przy other); ta sama wartość nie tworzy wpisu ani nowej wersji",
        "Korekta poniżej stanu zarezerwowanego daje 409 z liczbą zarezerwowanych w detail; nieaktualna wersja (także null przy istniejącym rekordzie) daje 409 z innym detail",
        "Przyjęcie i korektę wykonują tylko admin i warehouse; support i marketing dostają 403; bez tokena i z tokenem shop 401; uprawnienie sprawdzane przed treścią",
        "Typy treści ścisłe (422), zły JSON 400, treść inna niż JSON 415, id niebędące UUID 404; aktor z tokena zapisany w historii",
        "Testy C4 i C6 przechodzą w 5 rundach na PostgreSQL w make -C apps/api test",
        "Deptrac zawiera nowe reguły i odrzuca wymienione próby obejścia",
        "openapi-admin.json i klient TS panelu są aktualne; dokumentacja Idempotency-Key przy przyjęciu zaktualizowana",
        "Przechodzą make -C apps/api check i test oraz make -C apps/admin check"
      ],
      "risk": "CRITICAL",
      "depends_on": ["INVENTORY-1"]
    },
    {
      "id": "INVENTORY-3",
      "title": "Dane demo stanów",
      "scope": "apps/api: Catalog zapytanie ListProductIdentities ({id, slug} partiami po id), port ProductsForInventory z adapterem, komenda inventory:seed-demo z DbalInventoryReset, fixtures/stock.yaml (spec: „Warstwa aplikacji – Dane demo”). Główny Makefile: inventory:seed-demo w make seed po pricing:seed-demo. Dokumentacja: opis seeda w apps/api/CLAUDE.md. Nie obejmuje: zmian frontów.",
      "acceptance_criteria": [
        "inventory:seed-demo czyści tabele Inventory i nadaje każdemu produktowi demo stan z pierwszego pasującego wzorca fixtures/stock.yaml",
        "Stan > 0 przez ReceiveStock ze źródłem seed i kluczem seed:<productId>; stan 0 jako rekord bez przyjęcia",
        "Większość produktów 20–50 sztuk, kilka 2–3, kilka 0, co najmniej jeden produkt demo ma 0",
        "Produkt demo bez pasującego wzorca kończy seed błędem bez zmian w danych; w prod komenda wymaga --force-reset",
        "make seed uruchamia seed stanów po cenach i po nim każdy produkt demo ma rekord stanu",
        "apps/api/CLAUDE.md opisuje inventory:seed-demo w make seed",
        "Przechodzą make -C apps/api check i make -C apps/api test"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-2"]
    },
    {
      "id": "INVENTORY-4",
      "title": "Dostępność w sklepie",
      "scope": "apps/api: publiczny GET /api/v1/availability (spec: „API – Sklep”) na GetStockLevels, Cache-Control public, max-age=60, eksport openapi.json. apps/store: klient TS, loadCatalog pobiera dostępność równolegle z cenami, ProductTile pokazuje etykietę poziomu, błąd endpointu nie psuje listy (spec: „Sklep”). Nie obejmuje: Varnisha, zdarzeń, ISR, Elasticsearch.",
      "acceptance_criteria": [
        "GET /api/v1/availability przyjmuje 1–48 productId; brak, za dużo albo zły UUID daje 400",
        "Odpowiedź 200 {items: [{productId, level}]} zawiera każde żądane id; produkt bez rekordu i nieznany Catalogowi ma out_of_stock; liczba sztuk nie jest zwracana",
        "Odpowiedź ma Cache-Control public, max-age=60",
        "Kafelek pokazuje Dostępny, Ostatnie sztuki albo Niedostępny według level z API, bez liczenia na froncie",
        "Błąd endpointu dostępności: kafelki bez etykiety dostępności, błąd w logu serwera, lista i ceny działają",
        "openapi.json i klient TS sklepu są aktualne",
        "Przechodzą make -C apps/api check i test oraz make -C apps/store check i test"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-1"]
    },
    {
      "id": "INVENTORY-5",
      "title": "Lista stanów w panelu",
      "scope": "apps/api: ListBackofficeStock z portem ProductDirectory (Catalog SearchBackofficeProducts) i GET /api/v1/admin/stock (spec: „API – Panel”), eksport openapi-admin.json. apps/admin: pozycja menu Stany magazynowe, trasa /inventory, lista z wyszukiwaniem, filtrem kategorii i stronicowaniem kursorami (spec: „Panel”, lista). Nie obejmuje: dialogów przyjęcia, korekty i historii.",
      "acceptance_criteria": [
        "GET /api/v1/admin/stock zwraca {items: [{productId, name, stock: {physical, reserved, available, level, version, updatedAt} | null}], next}; stany strony jednym zapytaniem",
        "Złe parametry dają 400, nieznana kategoria 404, bez tokena i z tokenem shop 401; każdy pracownik ma dostęp; odpowiedź private, no-store",
        "Panel ma pozycję Stany magazynowe i trasę /inventory z kolumnami nazwa, fizyczny, zarezerwowany, dostępny, poziom; produkt bez rekordu pokazuje zera",
        "Wyszukiwanie po nazwie od 2 znaków z opóźnieniem, filtr kategorii z drzewa, stronicowanie następna/poprzednia",
        "openapi-admin.json i klient TS panelu są aktualne",
        "Przechodzą make -C apps/api check i test oraz make -C apps/admin check i test"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-1"]
    },
    {
      "id": "INVENTORY-6",
      "title": "Przyjęcie i korekta w panelu",
      "scope": "apps/admin: dialogi Przyjęcie i Korekta w widoku /inventory, widoczne tylko dla ról admin i warehouse (role z /api/v1/admin/me), obsługa 403, 409 i 422 (spec: „Panel”). Korzysta z endpointów z INVENTORY-2. Nie obejmuje: zmian API.",
      "acceptance_criteria": [
        "Przyciski Przyjęcie i Korekta widzą tylko admin i warehouse",
        "Dialog przyjęcia generuje klucz UUID przy otwarciu i używa go ponownie przy ponowieniu po błędzie sieci; nowy dialog to nowy klucz",
        "Dialog korekty ma stan policzony, powód (Inwentaryzacja, Uszkodzenie, Zaginięcie, Inne), notatkę wymaganą przy Inne i wysyła wersję z wiersza",
        "409 wersji pokazuje „Stan został w międzyczasie zmieniony – odśwież” i odświeża wiersz; 409 poniżej zarezerwowanego i 409 klucza pokazują detail z API; 422 pokazuje komunikat przy polu",
        "Po sukcesie wiersz pokazuje stan zwrócony przez API",
        "Przechodzą make -C apps/admin check i test"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-2", "INVENTORY-5"]
    },
    {
      "id": "INVENTORY-7",
      "title": "Historia stanów",
      "scope": "apps/api: GetStockHistory i GET /api/v1/admin/products/{productId}/stock-history z kursorem keyset (spec: „API – Panel”, „Warstwa aplikacji”), eksport openapi-admin.json. apps/admin: dialog Historia w widoku /inventory z Pokaż więcej i etykietami typów, powodów i źródeł (spec: „Panel”). Nie obejmuje: dialogów przyjęcia i korekty.",
      "acceptance_criteria": [
        "Historia od najnowszych po (occurred_at, id) malejąco, perPage 1–100 (domyślnie 25), kursor z next; zły kursor lub parametr daje 400",
        "Nieznany produkt daje 404; produkt bez rekordu daje pustą listę; każdy pracownik ma dostęp; bez tokena i z tokenem shop 401",
        "Wpis zawiera occurredAt (UTC, RFC 3339), type, delty, stany po operacji, reason, note, reference, source i actorName, bez identyfikatora aktora",
        "Panel pokazuje historię z Pokaż więcej i etykietami: Przyjęcie, Korekta, Rezerwacja, Zwolnienie, Zatwierdzenie; powody; źródła Pracownik, Dane demo, System",
        "openapi-admin.json i klient TS panelu są aktualne",
        "Przechodzą make -C apps/api check i test oraz make -C apps/admin check i test"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-1", "INVENTORY-5"]
    },
    {
      "id": "INVENTORY-8",
      "title": "E2E: przyjęcie w panelu widoczne w sklepie",
      "scope": "e2e (Playwright): pracownik warehouse przyjmuje towar w panelu dla produktu demo ze stanem 0, sklep pokazuje Dostępny (spec: „Testy – Pozostałe”, E2E). Nie obejmuje: zmian API ani frontów poza ewentualnymi atrybutami testowymi.",
      "acceptance_criteria": [
        "Test loguje się jako warehouse, wykonuje przyjęcie produktu demo ze stanem 0 i sprawdza w panelu nowy stan",
        "Sklep pokazuje dla tego produktu Dostępny w ciągu 70 s",
        "Przechodzi make e2e"
      ],
      "risk": "STANDARD",
      "depends_on": ["INVENTORY-3", "INVENTORY-4", "INVENTORY-6"]
    }
  ]
}
```
