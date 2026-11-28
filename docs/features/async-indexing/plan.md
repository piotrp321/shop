# PERF-6 – plan zadań

Specyfikacja: `docs/features/async-indexing/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 0 | ASYNC-INDEXING zadanie 1 (pomiar przyczyny tempa, raport `docs/reports/async-indexing-measurement-2026-11-26.md`) | zrobione 2026-11-26 |
| 1 | PERF-6-1, PERF-6-2, PERF-6-3 | |
| 2 | PERF-6-4 | |
| 3 | PERF-6-5 | |
| 4 | PERF-6-6, PERF-6-8 | |
| 5 | PERF-6-7, PERF-6-9 | |
| 6 | PERF-6-10 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Start: po akceptacji specyfikacji (D6–D15) i planu przez właściciela i po zakończeniu bieżących epików, które zmieniają
wyszukiwarkę API, `messenger.yaml` albo chart (program: wyszukiwarkę zmienia naraz jeden epik). Pomiary lokalne
(PERF-6-1, PERF-6-10) w oknach uzgodnionych z sesjami PERF. Po merge'u epiku do `main`: pomiar akceptacyjny na worf.pl
(spec, „Pomiar i akceptacja”; każda operacja za osobną zgodą właściciela), potem osobny PR z wartościami domyślnymi
i usunięciem przegranej kolejności odczytu (D13).

## Praca szeregowa

- PERF-6-6, PERF-6-8: `apps/api/src/Catalog/`, `docs/features/search/spec.md`; producent przebudowy
  i przebieg kategorii zmieniają ten sam rejestr przebiegów i źródła identyfikatorów w Catalog (jedna odpowiedzialność:
  przebiegi indeksowania Catalog).
- PERF-6-7, PERF-6-9: `docs/features/search/spec.md`, `apps/api/CLAUDE.md`; abort i integracje opisują tę samą sekcję
  „Infrastruktura” przebudowy i te same zasady workera w `apps/api/CLAUDE.md`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PERF-6",
  "spec": "docs/features/async-indexing/spec.md",
  "tasks": [
    {
      "id": "PERF-6-1",
      "title": "Scenariusze W17, W18, W19 i pomiar przed zmianą na medium",
      "scope": "Spec: „Pomiar i akceptacja” (przed zmianą), D6. load/: scenariusz W17 (100 promocji po 1000 wariantów startujących w tej samej minucie przez API panelu, zamówienie złożone w trakcie, czas do wysłania e-maila z logu handlera, czas do zgodności sklepu), W18 (przeniesienie działu z ok. 20% katalogu i powrót, czas do końca przebiegu, W1 próbkowane w trakcie przez load/baseline.py), W19 (przebudowa z zapisami i tworzeniem produktów w trakcie, tempo z logu search_rebuild); każdy kończy się kontrolą W13 (próba indeksu wobec bazy, 0 w failed). Pomiar stanu przed zmianą na lokalnym stosie medium (rewizja bazowa epiku): przepustowość zdarzeń (seria PromotionPricesChanged z audytu, P1), scenariusz zapisów W16 przy 50 zmianach/s, W17, W18, W19, trzy przebiegi z rozrzutem. Raport w docs/reports/. Nie zmienia kodu aplikacji.",
      "acceptance_criteria": [
        "Scenariusze W17, W18 i W19 uruchamia się jednym poleceniem opisanym w load/README.md; każdy kończy się kontrolą W13 i kodem niezerowym przy niezgodności, odpowiedzi błędnej albo przekroczeniu limitu czasu przebiegu",
        "Skrypty scenariuszy mają testy logiki na ścieżce błędu (stos niedostępny, brak danych, niezgodny indeks) z wynikiem obu ścieżek w raporcie zadania",
        "Raport przed zmianą podaje dla W16, W17, W18, W19 i W13 wartość zmierzoną na medium, sposób pomiaru, komendę i rozrzut trzech przebiegów; to, czego nie zmierzono, jest oznaczone z powodem",
        "Raport rozdziela zmierzone od hipotez i nie twierdzi niczego o profilu server"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": ["load/", "docs/reports/"],
      "parallel_with": ["PERF-6-2", "PERF-6-3", "PERF-6-4", "PERF-6-5", "PERF-6-6", "PERF-6-7", "PERF-6-8", "PERF-6-9"]
    },
    {
      "id": "PERF-6-2",
      "title": "Kolejki i workery: relay osobno, kolejka notifications, alert zaległości",
      "scope": "Spec: „Kolejki i workery”, „Alert zaległości”, „Przejście”, D14. apps/api: exchange zdarzeń typu topic pod nową nazwą, relay publikuje z kluczem trasy z nazwy klasy (RoutedAmqpTransport, publikacja obowiązkowa), kolejka events wiąże wszystko, nowy transport notifications z powiązaniami klas handlerów Send* (fromTransport: notifications), --fetch-size relaya i projekcji; app:messaging:check czyta events, notifications (wiek z head_message_timestamp, próg 10 min) i indexing (konsumenci) z API zarządzania RabbitMQ, zamknięta lista kolejek. infra: usługi worker-relay, worker (events), worker-notifications w compose i Deploymenty w chartcie z replikami i zasobami, test chartu „każdy transport ma konsumenta”, krok runbooka usuwający stary exchange i kolejkę. Nie obejmuje wsadowych handlerów (PERF-6-3) ani transportów indeksowania (PERF-6-4).",
      "acceptance_criteria": [
        "E-maile zamówień obsługuje transport notifications; przy zaległości w events e-mail złożonego zamówienia jest obsłużony (test na stosie test_messaging)",
        "Zbiór kluczy powiązanych z kolejką notifications równa się zbiorowi klas wiadomości handlerów z fromTransport notifications (test z kontenera, także przypadek handlera bez powiązania); każdy handler Send* ma fromTransport notifications",
        "Zdarzenie bez trasy kończy relay błędem i ponowieniem, nie znika (test publikacji obowiązkowej)",
        "Każdy transport poza failed ma konsumenta w compose i w chartcie (test chartu z przypadkiem negatywnym); worker-commands konsumuje tylko payment_simulator scheduled promotions jobs",
        "queue-check zgłasza: wiek najstarszej gotowej wiadomości events albo notifications ponad próg, brak konsumenta niepustej indexing, niedostępne API zarządzania (błąd, nie zdrowe), kolejkę z listy nieobecną w brokerze (błąd); kolejka spoza listy daje ostrzeżenie",
        "Dokumentacja: apps/api/CLAUDE.md (workery i transporty), infra/k8s/CLAUDE.md i runbook (workery, repliki, przejście), docs/features/async-reliability/spec.md (kolejki RabbitMQ w alercie)",
        "make check, testy API i make -C infra/k8s check przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/config/packages/messenger.yaml",
        "apps/api/config/packages/test_messaging/",
        "apps/api/src/Shared/Infrastructure/Messaging/",
        "apps/api/src/Ordering/Application/EventHandler/",
        "apps/api/src/Backoffice/",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "infra/docker-compose.yml",
        "infra/k8s/",
        "docs/features/async-reliability/spec.md"
      ],
      "parallel_with": ["PERF-6-1", "PERF-6-3"]
    },
    {
      "id": "PERF-6-3",
      "title": "Wsadowe odświeżanie produktów z zapisem po widoczności i wersją z zegara bazy",
      "scope": "Spec: „Wsadowe odświeżanie produktów”, „Zapis wsadu i widoczność”, „Wersja dokumentu”, D9, D10. Catalog: ProductIndexing::reindexMany (wersja z clock_timestamp() Postgresa przed odczytem, ProductSearchSource::findMany, fakty ofert jednym wywołaniem dla wsadu), ProductIndexer::indexMany (bulk do aliasu przebudowy, potem do aliasu z refresh=wait_for), reindex jako reindexMany jednego produktu; wersja z zegara bazy także w przebudowie synchronicznej. Shared: StorefrontCache::invalidateProducts w częściach po 100 tagów. Handlery wsadowe (BatchHandlerInterface) RefreshProductsOnPriceChange (Pricing), RefreshProductsOnStockLevelChange (Inventory), RefreshProductsOnProductChange (Catalog) zastępują pary reindeks + unieważnienie; rozmiar wsadu i maksymalne czekanie jako parametry. Cart: ścieżka faktów ofert dla wsadu bez limitu MAX_PRODUCTS endpointu. Nie obejmuje transportów ani workerów (PERF-6-2) ani paczek przebudowy (PERF-6-4).",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Wsad zdarzeń cen, promocji, stanów i produktów daje jeden odczyt migawek, jedno wywołanie faktów ofert, dwa _bulk w kolejności alias przebudowy, potem alias z wait_for, i unieważnienie w żądaniach po najwyżej 100 tagów; powtórzone produkty zapisane raz",
        "Wynik każdej wiadomości osobno: przestarzały potwierdzony, przejściowy ponowiony, trwały w failed, pozostałe wiadomości wsadu potwierdzone; źródła seed i tax_reconciliation bez zapisu; ProductCreated nieistniejącego produktu w failed",
        "PURGE i rewalidacja po odpowiedzi zapisu z wait_for; żądanie z wait_for nie obejmuje aliasu przebudowy (test kolejności wywołań)",
        "W19a: istniejące testy zapisu w trakcie przebudowy przechodzą dla dwóch żądań, także zapis w chwili przepięcia aliasu i zapis do usuniętego starego indeksu (błąd przejściowy, ponowienie)",
        "Wersja dokumentu z zegara bazy sprzed odczytu we wsadzie i w przebudowie synchronicznej; dwa równoległe odświeżenia tego samego produktu kończą się dokumentem nowszego odczytu",
        "Wsad opróżnia się po rozmiarze, po maksymalnym czekaniu najstarszej wiadomości i przy bezczynności workera (test z zegarem sztucznym)",
        "Deptrac przechodzi bez edycji deptrac.yaml; docs/features/search/spec.md (zapis i kolejność, zapisy w trakcie przebudowy, wyzwalacze) i specyfikacje catalog-admin, pricing, inventory opisują wsady",
        "make check i testy API przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/src/Pricing/",
        "apps/api/src/Inventory/",
        "apps/api/src/Cart/",
        "apps/api/src/Shared/Infrastructure/Http/Cache/",
        "apps/api/config/services.yaml",
        "apps/api/tests/",
        "docs/features/search/spec.md",
        "docs/features/catalog-admin/spec.md",
        "docs/features/pricing/spec.md",
        "docs/features/inventory/spec.md"
      ],
      "parallel_with": ["PERF-6-1", "PERF-6-2"]
    },
    {
      "id": "PERF-6-4",
      "title": "Paczki indeksowania: IndexDocuments, szyna indexing.bus, transporty i worker-indexing",
      "scope": "Spec: „Paczki indeksowania (wachlarz)”, D1–D5. Refaktor bez zmiany zachowania: ProductBatchIndexer::write(index, snapshots, version) z pętli fill, SearchIndexSwitch z replaceIndex. Shared: IndexDocuments, DocumentIndexer, DocumentIndexers (UnknownDocumentType), IndexDocumentsHandler. Catalog: ProductDocumentIndexer (walidacja, findMany, zapis; bez rozliczenia, które dodaje PERF-6-5). Szyna indexing.bus bez doctrine_transaction, transport AMQP indexing i Doctrine indexing_finalize, SEARCH_INDEX_BATCH_SIZE (1..5000); w tym samym zadaniu worker-indexing w compose i chartcie (repliki, zasoby, initContainers) i test „każdy transport ma konsumenta” dla obu transportów.",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Istniejące testy ProductSearchIndexRebuilderTest przechodzą bez zmian po refaktorze",
        "Nieznany klucz, zły UUID, pusta lista i lista ponad 5000 to błąd trwały bez zapisu; wyjątki przejściowe nie są trwałe",
        "Handler paczki nie działa w transakcji bazy (Connection::isTransactionActive() fałsz w trakcie zapisu do ES)",
        "SEARCH_INDEX_BATCH_SIZE 0, ujemna, nieliczbowa i ponad 5000 odrzucone przy starcie z nazwą zmiennej",
        "Wersja dokumentu paczki z zegara bazy sprzed findMany, także po ponowieniu",
        "Każdy transport poza failed ma konsumenta w compose i w chartcie (przypadek negatywny); Deptrac bez edycji deptrac.yaml",
        "apps/api/CLAUDE.md: szyna indexing.bus bez transakcji i wzorzec wachlarza paczek obok łańcucha",
        "make check, testy API i make -C infra/k8s check przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-6-2", "PERF-6-3"],
      "paths": [
        "apps/api/config/",
        "apps/api/src/Shared/",
        "apps/api/src/Catalog/",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "infra/docker-compose.yml",
        "infra/k8s/"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-5",
      "title": "Rejestr przebiegów, wykluczanie, rozliczenie paczek, finalizacja z uzgodnieniem zbioru",
      "scope": "Spec: „Przebieg i rejestr”, „Pełna przebudowa w kilku procesach” (punkt 4 i 7), „Uzgodnienie zbioru dokumentów”, D8. Najpierw test mechanizmu FinalizeSearchRebuild przez command.bus z BusNameStamp('indexing.bus') na indexing_finalize (wariant zapasowy ze spec, decyzja dopisana do spec). Migracja tabel catalog_search_rebuild_run (z kind) i catalog_search_rebuild_batch, unikalny indeks częściowy jednego aktywnego przebiegu przebudowy na typ, rekordy ORM i repozytorium. Wzajemne wykluczanie przebudowy synchronicznej, asynchronicznej, setup, reset i operacji usuwających produkty (DbalCatalogReset bierze blokadę przed TRUNCATE, infra/k8s/scripts/smoke.sh nie usuwa produktów w trakcie przebudowy). Rozliczenie paczki, finalizacja z etapami finalizing, reconciled, activating, activated, succeeded; uzgodnienie zbioru zastępuje równość liczby dokumentów także w przebudowie synchronicznej; deleteIndex z 404 jako sukcesem.",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Wiadomość finalizacji nie istnieje po wycofaniu przejścia stanu i istnieje po zatwierdzeniu; obsługa na szynie bez transakcji",
        "Powtórne rozliczenie paczki nie zmienia liczników; dwa procesy rozliczające ostatnie paczki dają jedną finalizację; paczki rozliczone przed końcem produkcji i pusty zbiór kończą się finalizacją",
        "Finalizacja przerwana na każdym etapie i wznowiona kończy się succeeded, alias zmienia się najwyżej raz; dwie finalizacje naraz w każdym statusie: alias raz, succeeded nienadpisany; activating z aliasem na trzecim indeksie albo nieczytelnym: failed, oba indeksy zachowane",
        "Uzgodnienie: produkt utworzony w trakcie (identyfikator poniżej i powyżej kursora, po ostatniej paczce, w trakcie uzgodnienia) jest w nowym indeksie i przebudowa kończy się powodzeniem; utracony zapis paczki jest dopisany i zgłoszony w wyniku; dokument bez produktu w bazie daje failed z nowym indeksem usuniętym i aliasem bez zmian; to samo w przebudowie synchronicznej",
        "Przebudowa synchroniczna, start asynchroniczny, setup, reset i usuwanie produktów wykluczają się w obu kolejnościach; seed w trakcie przebudowy odmawia przed TRUNCATE i niczego nie usuwa; smoke na k3d odmawia tak samo (ścieżka błędu w raporcie)",
        "Rozliczenie używa klucza głównego (plan bez Seq Scan po tabeli paczek); migracja zgodna z mapowaniem (test schematu modułu)",
        "docs/features/search/spec.md (weryfikacja przez uzgodnienie) i docs/features/walking-skeleton/spec.md („Ograniczenia tej przebudowy”) bez zdań, które przestały być prawdziwe",
        "make check, testy API i make -C infra/k8s check przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": ["PERF-6-4"],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/migrations/",
        "apps/api/tests/",
        "infra/k8s/scripts/smoke.sh",
        "docs/features/search/spec.md",
        "docs/features/walking-skeleton/spec.md"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-6",
      "title": "Producent przebudowy i catalog:reindex --async z obiema kolejnościami odczytu",
      "scope": "Spec: „Pełna przebudowa w kilku procesach” (punkty 1–3 i 5), D4, D13. Producent czyta same identyfikatory w kolejności id (kursor) albo physical (zakresy bloków sterty catalog_product, ostatni zakres otwarty) i wysyła paczki na indexing z potwierdzeniem publikacji; końcowa transakcja producenta; heartbeat; błąd RabbitMQ (przebieg failed, nowy indeks usunięty); sprzątanie osieroconych indeksów; opcje --async, --batch-size, --order, --wait (postęp, limit braku postępu, kody 0/1/2); log postępu search_rebuild. Bez --async bez zmian.",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Liczba paczek ceil(N / rozmiar) dla N = 0, 1, rozmiar − 1, rozmiar, rozmiar + 1 i wielokrotności, w obu kolejnościach",
        "Obie kolejności dają ten sam zbiór dokumentów, także gdy w trakcie produkcji produkty są tworzone i aktualizowane (wiersz przeniesiony w stercie)",
        "Producent zabity w połowie zostawia przebieg producing bez heartbeatu; RabbitMQ niedostępny przy wysyłce daje failed i usunięty nowy indeks; --batch-size 0, ujemny i ponad limit odrzucone",
        "--wait bez workera kończy się po limicie i nie wisi; kody wyjścia 0, 1, 2",
        "Test na stosie z workerem (test_messaging): przebieg do succeeded, alias przełączony; częściowy błąd bulka nie przełącza aliasu, po ponowieniu przebieg się kończy; worker zabity po zapisie, przed rozliczeniem: komplet dokumentów i jedna finalizacja",
        "Przebieg z dużą liczbą małych paczek kończy się w czasie niezależnym od liczby wierszy paczek (brak rosnącego kosztu rozliczenia)",
        "make check i testy API przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": ["PERF-6-5"],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/tests/",
        "docs/features/search/spec.md"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-7",
      "title": "Abort przebiegu przebudowy",
      "scope": "Spec: „Pełna przebudowa w kilku procesach” (punkt 6). catalog:reindex:abort [--force]: warunkowe UPDATE na aborted tylko z producing/running, potem usunięcie target_index, jeśli nie wskazuje go alias; odmowa od finalizing; przebieg żywy (heartbeat młodszy niż 30 min) wymaga --force. Opis w docs/features/search/spec.md (Infrastruktura) i zasada workera w apps/api/CLAUDE.md, jeśli się zmienia.",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Abort martwego przebiegu, żywego bez --force (odmowa), z --force, nieistniejącego, w finalizing, reconciled, activating i activated (odmowa, nic nie usunięte)",
        "Abort ścigający się z przejściem running → finalizing (dwa procesy): wygrywa jedno, indeks aliasu nigdy nie jest usunięty",
        "Abort przy niedostępnym ES: przebieg aborted, indeks osierocony, komunikat; paczka w trakcie zapisu podczas abortu kończy się rozliczeniem bez skutku; paczki przerwanego przebiegu kończą się bez zapisu",
        "make check i testy API przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-6-6"],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "docs/features/search/spec.md"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-8",
      "title": "Reindeks kategorii jako przebieg wachlarza",
      "scope": "Spec: „Reindeks kategorii (W18)”, D15. Zmiana drzewa zakłada przebieg kind = category zamiast CategoryReindexRun; producent to łańcuch partii na jobs: krok czyta stronę identyfikatorów poddrzewa, publikuje jedną paczkę IndexDocuments na indexing i wysyła następny krok w tej samej transakcji; paczki zapisują przez aliasy (ProductIndexer::indexMany) i unieważniają swoje produkty; komplet kończy przebieg jednym invalidateCatalog(). Migracja przenosząca rejestr kategorii (stare tabele usunięte; etap demo, bez zachowania danych przebiegów). Opis wzorca w apps/api/CLAUDE.md.",
      "acceptance_criteria": [
        "TDD: testy uruchomione przed implementacją nie przechodzą, po niej przechodzą; komendy i wyniki obu uruchomień w raporcie",
        "Zmiana nazwy i przeniesienie działu dają paczki na indexing pokrywające całe poddrzewo (także poddrzewo puste i o liczności rozmiar paczki ± 1); komplet kończy przebieg jednym invalidateCatalog()",
        "Krok producenta wycofany po publikacji paczki daje kopię rozliczaną raz; ponowiony krok nie tworzy drugiej serii",
        "Dwie zmiany drzewa naraz kończą się obie; przebieg kategorii nie jest blokowany przez trwającą przebudowę i odwrotnie, a jego zapisy trafiają do nowego indeksu",
        "worker-commands obsługuje wiadomość scheduled, gdy przebieg kategorii produkuje paczki (test kolejki)",
        "apps/api/CLAUDE.md: wzorzec łańcucha partii jako producent wachlarza; make check i testy API przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": ["PERF-6-5"],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/migrations/",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "docs/features/catalog-admin/spec.md",
        "docs/features/search/spec.md"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-9",
      "title": "Integracje: generator, cele infra/k8s i dokumentacja przebudowy",
      "scope": "Spec: „Integracje”, „Przejście”, „Dokumentacja”. bin/generate-demo kończy catalog:reindex --async --wait; cele generate, open i reindex w infra/k8s skalują worker-indexing jak dziś worker, odmawiają przy aktywnym przebiegu, komunikat reindex bez zakazu tworzenia produktów; activeDeadlineSeconds CronJoba reindex dobrany do W19 z zapasem (nie krótszy niż 2 h); runbook i CLAUDE.md obszarów; docs/features/demo-scale/spec.md i docs/features/search/spec.md (Infrastruktura); wywołania przebudowy w skryptach smoke i E2E (git grep catalog:reindex).",
      "acceptance_criteria": [
        "generate-demo bez działającego workera kończy się po limicie braku postępu, nie wisi",
        "Cele infra/k8s reindex, generate i open na ścieżce błędu (aktywny przebieg, brak workera indexing) odmawiają bez zmian; wynik obu ścieżek w raporcie; shellcheck przechodzi",
        "Komunikat celu reindex i runbook mówią, że tworzenie produktów w trakcie jest dopuszczalne; dokumenty bez opisu historii",
        "make check, make -C infra/k8s check i testy dotkniętych aplikacji przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-6-6"],
      "paths": [
        "apps/api/bin/generate-demo",
        "apps/api/CLAUDE.md",
        "infra/k8s/",
        "e2e/",
        "docs/features/demo-scale/spec.md",
        "docs/features/search/spec.md"
      ],
      "parallel_with": ["PERF-6-1"]
    },
    {
      "id": "PERF-6-10",
      "title": "Pomiar po zmianie na medium i raport",
      "scope": "Spec: „Pomiar i akceptacja” (po zmianie lokalnie), kryterium 16. Te same scenariusze i dane co PERF-6-1 na gałęzi epiku: W16 przy refresh_interval 1 s i 5 s, W17, W18, W19 przy 1, 2 i 4 replikach worker-indexing w obu kolejnościach odczytu, W13 po każdym; porównanie z raportem przed zmianą, rozrzut trzech przebiegów. Raport w docs/reports/ z propozycją wartości do pomiaru akceptacyjnego na worf.pl; status PERF-6 w docs/features/performance-program/plan.md. Bez zmian kodu aplikacji.",
      "acceptance_criteria": [
        "Raport podaje dla W16, W17, W18, W19 i W13 wartość przed i po na medium, komendę każdego pomiaru i rozrzut; różnica mniejsza niż rozrzut nie jest nazwana efektem",
        "Raport rozdziela zmierzone od hipotez, nie twierdzi spełnienia wymagań na server i wymienia kroki pomiaru akceptacyjnego na worf.pl wymagające zgody właściciela",
        "docs/features/performance-program/plan.md: status PERF-6 zgodny z raportem"
      ],
      "risk": "FAST",
      "depends_on": ["PERF-6-1", "PERF-6-7", "PERF-6-8", "PERF-6-9"],
      "paths": ["docs/reports/", "docs/features/performance-program/plan.md"],
      "parallel_with": []
    }
  ]
}
```
