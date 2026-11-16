Przegląd FULL taska CATALOG-ADMIN-3 „Reprice variant prices after a tax class change” (ryzyko CRITICAL: ceny, współbieżność).

Checkout: <home>/Development/claude/shop-CATALOG-ADMIN-3. Zamrożony HEAD: 1c35b5d45ddbbdb916edc610df9276ef0c2a25f3. Diff: `git diff 74b693a5c2fc6386f4100576815f3c0a66c57902..1c35b5d` (merge-base z origin/epic/catalog-admin). Nie modyfikuj plików.

Specyfikacja: docs/features/catalog-admin/spec.md, sekcja „Pricing: przeliczenie przy zmianie klasy (K6)” i decyzja K6; zadanie w docs/features/catalog-admin/plan.md (id CATALOG-ADMIN-3).
Scope: apps/api: Pricing Application (pętla wydzielona z ReconcileTax) i Infrastructure (konsument i adapter portu), docs. Konsument RepriceOnTaxClassChange na transporcie events dla integracyjnego ProductChanged; warianty i klasa z Catalog ListProductVariants([productId], includeDrafts: true); każda cena z klasą inną niż klasa produktu przeliczana (to samo brutto, źródło tax_reconciliation, VariantPriceChanged) pętlą reprice-save-retry wspólną z pricing:reconcile-tax. Bez zmian w Catalog.
Kryteria akceptacji:
1. Po zmianie klasy ceny wariantów mają nową stawkę, to samo brutto i wpis historii ze źródłem tax_reconciliation (test integracyjny).
2. A → B → A ze zdarzeniami w dowolnej kolejności i powtórzone zdarzenie kończą się cenami w klasie A; obsługa, gdy ceny już mają bieżącą klasę, nic nie zapisuje (testy jednostkowe).
3. Klasa bez stawki → wiadomość do failed z komunikatem; drugi konflikt wersji → wyjątek do ponowienia; nieznany produkt obsłużony bez zmian.
4. pricing:reconcile-tax zachowuje działanie na wydzielonej pętli (istniejące testy przechodzą).
5. docs/features/pricing/spec.md i apps/api/CLAUDE.md opisują przeliczenie.
6. make -C apps/api check i make -C apps/api test przechodzą (orkiestrator uruchomił: oba exit 0, 3035 testów, 1 pominięty wcześniej istniejący).

tdd.status: verified. Dziennik dowodów: <home>/.local/state/ao-catalog-admin/tasks/CATALOG-ADMIN-3/delivery-state.json.evidence.jsonl.

Decyzje developera do oceny: (a) nowa metoda taxClassOf w porcie Pricing ProductDirectory; (b) PriceReconciler tworzony w konstruktorach ReconcileTax i RepriceProductPrices zamiast DI; (c) cena w klasie produktu z nieaktualną stawką nie jest zapisywana przez konsumenta; (d) ponowny odczyt klasy po zapisach i TaxClassChangedWhileRepricing → ponowienie (wyścig dwóch workerów A→B / B→A); (e) konflikt wersji rzucany po obsłużeniu pozostałych cen; brak stawki → UnrecoverableMessageHandlingException od razu.

Sprawdź zgodność ze spec, błędy logiczne (zwłaszcza współbieżność, idempotencja, transakcje/sesja Doctrine w konsumencie, zachowanie ponowień messengera i limit retry), architekturę (Deptrac, granice), przypadki brzegowe, testy (także czy RED był prawdziwy) i zbędną złożoność. Zwróć listę ustaleń z wagą (Critical/Important/Minor), plikiem:linią i uzasadnieniem, oraz werdykt.
