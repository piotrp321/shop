# ASYNC-INDEXING: plan zadań

Specyfikacja: `docs/features/async-indexing/spec.md`. Plan jest dokumentem roboczym: po ukończeniu funkcji go usuń.
Plan zakłada rekomendacje D1–D5 ze specyfikacji; zmiana któregokolwiek rozstrzygnięcia zmienia zadania 2–6.
Przed zadaniem 2 użytkownik zatwierdza decyzje DDD (D1, D3, D5: klucz typu, zakres, granice modułów i miejsce rejestru
przebiegu).

## Status

| Fala | Zadania | Status |
|---|---|---|
| 0 | 1 (pomiar przyczyny, bez zmiany kodu aplikacji) | |
| 1 | 2 (wiadomość, rejestr, handler paczki) | |
| 2 | 3 (przebieg i rejestr w Postgresie) | |
| 3 | 4 (producent i komenda), 5 (compose i chart) | |
| 4 | 6 (nadzór, abort, status), 7 (integracje i dokumentacja) | |
| 5 | 8 (pomiar po wdrożeniu) | |

Zadania 4 i 5 są niezależne (komenda kontra konfiguracja usług; wspólny kontrakt to nazwa transportu `indexing`
i polecenie workera, ustalone w zadaniu 2). Pozostałe idą po kolei. Każde zadanie to osobny PR, z wyjątkiem 2 i 5, które
mogą być jednym, jeśli diff zostaje czytelny. Nowy transport i worker (zadanie 5) trafiają do compose i chartu w tym samym PR,
w którym zaczynają być używane; do czasu zadania 4 nic nie wysyła na `indexing`, więc konsumpcja pustej kolejki jest bezpieczna.

## Zadania

### 1. Pomiar przyczyny tempa (bez zmiany kodu aplikacji)

- **Cel:** ustalić, co ogranicza dziś 391 dokumentów/s, zanim dołożymy równoległość (hipotezy w specyfikacji; żadnej nie
  przyjmujemy za fakt). Bez tego nie wiadomo, czy zadania 2–6 przyspieszą przebudowę.
- **Zakres:** pomiar na lokalnym stosie z dużym katalogiem (profil `medium`, 1 mln) i, tylko po zgodzie użytkownika
  w rozmowie, która nazywa operację i host, odczyt z serwera przez cele `infra/k8s/Makefile` (bez dotykania serwera, na
  którym trwa generowanie). Czas składowych jednej paczki: `ProductSearchSource::page`, `ProductOfferFacts::of`,
  `ProductDocumentBuilder::build`, `SearchIndexMaintenance::write`; obciążenie Postgresa i Elasticsearcha w trakcie
  (Grafana, opcjonalnie SPX w obrazie `dev`). Kontrolny przebieg z 2 równoległymi procesami zapisu do osobnych indeksów,
  żeby sprawdzić, czy zasób się skaluje.
- **Wynik:** raport w `docs/reports/` (przypięty do rewizji, niezmienny) z czasami składowych, wnioskiem „co jest wąskim
  gardłem, a co hipotezą” i rekomendacją rozmiaru paczki do zadania 8. Jeśli wąskim gardłem jest zasób, którego
  równoległość nie rozszerza (np. CPU jednego węzła Postgresa), użytkownik decyduje, czy kontynuować zadania 2–6.
- **Akceptacja:** raport podaje metodę, komendy i liczby dla każdej składowej; hipotezy bez pomiaru są jako takie oznaczone.
- **Ryzyko:** FAST (dokument i pomiar). Testów kodu nie ma; weryfikacja to powtarzalność komend z raportu.

### 2. Wiadomość `IndexDocuments`, rejestr typów i handler paczki

- **Cel:** ogólny mechanizm zapisu paczki identyfikatorów (D1, D3, D5).
- **Zakres:** `IndexDocuments` (dla trybu bez przebiegu `runId`, `batch` i `target` są `null`), port `DocumentIndexer`,
  rejestr `DocumentIndexers` (tagowane usługi, `UnknownDocumentType`), `IndexDocumentsHandler` (`command.bus`),
  `ProductDocumentIndexer` w Catalog, `ProductSearchSource::findMany(list<string>)` (to samo zapytanie co `page` i `find`),
  routing `IndexDocuments: indexing`, definicja transportu `indexing` w `messenger.yaml` z komentarzem, parametr
  `app.search.indexing_batch_size` ze zmiennej `SEARCH_INDEX_BATCH_SIZE` (walidacja `1..5000`), limit długości `ids`.
  Reguły Deptrac projektujemy tak, by przechodziły bez edycji `deptrac.yaml`; jeśli któraś decyzja D5 tego wymaga, wraca do
  użytkownika.
- **Testy (TDD: RED przed implementacją):** handler z atrapą rejestru; `ProductDocumentIndexer` na bazie i Elasticsearchu
  testowym (produkt w bazie, brak produktu w bazie jest pominięty, produkt-szkic ma status `draft`, dokument z faktami
  ofert); `findMany` zwraca to samo co `page` i `find` dla tych samych produktów (test zgodności zapytań); ta sama paczka
  dwa razy daje te same dokumenty (idempotencja zapisu); nieznany klucz, zły UUID, pusta lista i lista powyżej limitu
  kończą się błędem trwałym bez zapisu; błąd przejściowy ES i ofert rzuca wyjątek ponowienia, a trwały nie; ID wielkimi
  literami i duplikaty w jednej paczce; `SEARCH_INDEX_BATCH_SIZE` spoza zakresu i nieliczbowa odrzucone przy starcie.
- **Akceptacja:** kryteria 2 (część), 4 (zapis dokumentu), 6 ze specyfikacji; `make -C apps/api check` i testy API.
- **Ryzyko:** STANDARD. Zależy od: decyzji D1, D3, D5.

### 3. Przebieg i rejestr paczek w Postgresie

- **Cel:** stan przebiegu, liczenie paczek raz i dokładnie jedna finalizacja.
- **Zakres:** migracja (nowa; tabele `search_rebuild_run` i `search_rebuild_batch`, unikalny indeks częściowy „jeden aktywny
  przebieg na typ”), rekordy ORM i repozytorium przebiegu (agregat `SearchRebuildRun` z przejściami `producing → running →
  finalizing → succeeded|failed|aborted`, nazwane konstruktory, `reconstitute()` bez zdarzeń), `FinalizeSearchRebuild`
  (`verify`, `activate`, `deleteIndex`, jak dziś w `ProductSearchIndexRebuilder`), weryfikacja `target` względem przebiegu,
  odrzucenie paczki przebiegu nieaktywnego. Istniejąca blokada `SearchIndexRebuildLock` zostaje dla trybu synchronicznego;
  `setup` i synchroniczna przebudowa odmawiają przy aktywnym przebiegu.
- **Testy (TDD):** przejścia stanu i niedozwolone przejścia; powtórzony zapis paczki liczy raz (`ON CONFLICT`); dwa procesy
  kończące ostatnie paczki równocześnie dają jedną finalizację (jak `CategoryReindexConcurrencyTest`); finalizacja
  powtórzona bez skutku; `verify` z sumą dokumentów z rejestru; porażka `verify` usuwa nowy indeks, alias bez zmian;
  `activate` w stanie `Unknown` zachowuje oba indeksy; `target` spoza przebiegu odrzucony; drugi aktywny przebieg tego samego
  typu odrzucony; `setup` odmawia przy przebiegu aktywnym; migracja zgodna z mapowaniem (test schematu modułu).
- **Akceptacja:** kryteria 3, 4, 5 ze specyfikacji; mapowanie równe schematowi.
- **Ryzyko:** CRITICAL (współbieżność, przełączenie aliasu, nowa persystencja). Zależy od: 2.

### 4. Producent i komenda `catalog:reindex --async`

- **Cel:** rozesłanie paczek i opcje komendy.
- **Zakres:** producent czytający same identyfikatory po kursorze (zapytanie samych kluczy, bez joinów), wysyłka paczek na
  `indexing` z potwierdzeniem publikacji, `total_batches` po ostatniej paczce, heartbeat, obsługa błędu RabbitMQ
  (przebieg `failed`, usunięcie nowego indeksu); opcje `--async`, `--batch-size`, `--wait` (czekanie na koniec; limit
  braku postępu; kody wyjścia jak dziś 0/1/2); sprzątanie osieroconych indeksów jak dziś (Z5 w `demo-scale`). Bez `--async`
  komenda działa bez zmian (D4).
- **Testy (TDD):** liczba wiadomości równa `ceil(N / rozmiar)` dla N = 0, 1, rozmiar − 1, rozmiar, rozmiar + 1, wielokrotności;
  katalog pusty (przebieg kończy się od razu, alias na pustym indeksie jak dziś); producent przerwany w połowie (przebieg
  `failed`/martwy, indeks usunięty); RabbitMQ niedostępny przy wysyłce; `--batch-size` 0, ujemny i powyżej limitu; `--wait`
  bez postępu kończy się po limicie z komunikatem; kody wyjścia; test end-to-end na stosie z workerem (`test_messaging`):
  produkty w bazie, przebieg do `succeeded`, dokumenty w indeksie, alias przełączony; paczka celowo nieudana nie przełącza
  aliasu, po ponowieniu przebieg kończy się; restart workera w trakcie (kill po zapisie do ES, przed ack).
- **Akceptacja:** kryteria 1, 2, 3, 7, 8 ze specyfikacji.
- **Ryzyko:** CRITICAL. Zależy od: 3. Równolegle z: 5.

### 5. Transport `indexing` i worker w compose i chart

- **Cel:** worker skalowany niezależnie (specyfikacja: „Transport `indexing` i worker”).
- **Zakres:** usługa `worker-indexing` w `infra/docker-compose.yml` (ten sam obraz, `messenger:consume indexing
  --time-limit=86400 --memory-limit=...`, zależność od RabbitMQ i `api`, healthcheck jak pozostałe, `make up` startuje ją
  razem z workerami); chart: Deployment `worker-indexing` z `workers.indexing.replicas` (domyślnie 1) i zasobami (`resources`),
  `initContainers` jak `worker` (migracje, RabbitMQ), zmienna `SEARCH_INDEX_BATCH_SIZE` w kontenerach API; test chartu
  „każdy transport poza `failed` ma konsumenta” obejmuje `indexing`; `make -C infra/k8s check` i test zgodności compose
  ↔ chart. Jeśli zmiana dotknie `infra/k8s/charts`, uruchamia to pełne `make -C infra/k8s check` w CI.
- **Testy:** test chartu (konsument każdego transportu, replik z wartości, zasoby ustawione); test negatywny: transport
  bez konsumenta i `replicas: 0` bez jawnego powodu odrzucone, jeśli test chartu to dziś obejmuje; ścieżka błędu:
  worker bez RabbitMQ nie przechodzi healthchecku.
- **Akceptacja:** kryterium 9 i 10 (część infrastrukturalna).
- **Ryzyko:** STANDARD. Nie dotyka serwera ani kubeconfigu. Wdrożenie zmiany na serwer to osobna operacja użytkownika
  (`gh workflow run deploy.yml`, tylko na jego polecenie). Równolegle z: 4.

### 6. Nadzór, `abort` i `status`

- **Zakres:** `catalog:reindex:status` (przebieg, paczki zrobione/razem, dokumenty, tempo od startu i z ostatniej minuty,
  liczba paczek w `failed`), `catalog:reindex:abort` (martwy przebieg: status `aborted`, usunięcie indeksu, zwolnienie blokady;
  przebieg żywy wymaga `--force`), wykrywanie martwego przebiegu po `heartbeat_at` (ustawienie, domyślnie 30 minut),
  decyzja o `queue-check`: kolejka AMQP `indexing` w nadzorze (głębokość, wiek najstarszej) albo zapisana decyzja, że jej nie
  obejmuje (`docs/features/async-reliability/spec.md`), oraz opróżnienie wiadomości przerwanego przebiegu przez sam handler
  (kończą się bez skutku).
- **Testy (TDD):** `status` dla każdego stanu i przebiegu bez paczek; `abort` martwego, żywego bez `--force` (odmowa),
  z `--force`, nieistniejącego; wykrycie martwego po heartbeacie (zegar sztuczny); `abort` przy niedostępnym ES (przebieg
  oznaczony, indeks jako osierocony do sprzątnięcia, komunikat); paczki przerwanego przebiegu kończą się bez zapisu.
- **Akceptacja:** kryterium 7 i część opisu nadzoru.
- **Ryzyko:** STANDARD. Zależy od: 4.

### 7. Integracje i dokumentacja

- **Zakres:** `bin/generate-demo` i `make -C infra/k8s reindex` wołają `catalog:reindex --async --wait` (ze skalowaniem
  `worker-indexing` w ścieżkach `generate`, `open`, `restore` tam, gdzie dziś skaluje się `worker`, i odmową, gdy przebieg
  jest aktywny); aktualizacja: `docs/features/search/spec.md`, `docs/features/demo-scale/spec.md`, `apps/api/CLAUDE.md`,
  `infra/k8s/CLAUDE.md`, `infra/k8s/README.md` (runbook), skrypty smoke/E2E, które wołają przebudowę (`git grep catalog:reindex`
  i `SEARCH_` poza już uwzględnionymi). Specyfikacja tej funkcji zostaje; plan jest usuwany po zadaniu 8.
- **Testy:** ścieżka błędu `generate-demo` bez działającego workera (kończy się po limicie brakiem postępu, nie zawiesza);
  cele Makefile `infra/k8s` na ścieżce błędu (przebieg aktywny, brak workera); `shellcheck` skryptów; testy skryptu, jeśli istnieją.
- **Akceptacja:** kryterium 10 (`generate-demo`), spójność dokumentów (nic o „wcześniej było”).
- **Ryzyko:** STANDARD. Zależy od: 4, 5.

### 8. Pomiar po wdrożeniu (kryterium, nie obietnica)

- **Warunek:** wdrożenie zmiany na serwer na polecenie użytkownika i zgoda użytkownika w rozmowie, która nazywa operację
  i host; użycie wyłącznie celów `infra/k8s/Makefile` (`reindex`).
- **Zakres:** przebieg `--async --wait` na profilu `server` (10 mln produktów) przy kilku liczbach replik `worker-indexing`
  (np. 1, 2, 4) i dwóch rozmiarach paczki (z zadania 1), z odczytem obciążenia Postgresa i Elasticsearcha w Grafanie
  (tylko do odczytu); tempo dokumentów/s i czas całkowity; wpływ na sklep w trakcie (czas odpowiedzi listingu).
- **Wynik:** raport w `docs/reports/`, który porównuje z 391 dokumentami/s i ~7 godzinami z pomiaru z 2026-11-26. Tempo
  docelowe ustala użytkownik po tym pomiarze; do tego czasu wydajność nie jest uznana za osiągniętą. Wynik, który wskaże
  wąskie gardło po stronie bazy lub Elasticsearcha, tworzy osobne zadanie (zasoby, ustawienia), nie jest tu poprawiany.
- **Ryzyko:** FAST (pomiar); zależy od: 7.

## Wpływ na skalę, infrastrukturę i dokumentację

- **Skala (10 mln):** 20 000 wiadomości i 20 000 wierszy rejestru przy paczce 500; producent i rejestr nie ładują katalogu
  do pamięci; jedyny punkt wspólny to wiersz przebiegu (zmiany tylko przy przejściu stanu i heartbeacie). Wpływ na tempo
  zależy od wąskiego gardła z zadania 1; do pomiaru z zadania 8 niczego nie twierdzimy.
- **Chart i compose:** nowy transport `indexing`, nowa usługa `worker-indexing`, zmienna `SEARCH_INDEX_BATCH_SIZE`, replik
  i zasoby w `values.yaml` (zadanie 5, ten sam PR co pierwszy kod wysyłający na `indexing` albo wcześniej); każda zmiana
  `infra/docker-compose.yml` uruchamia w CI `make -C infra/k8s check`.
- **Dokumentacja:** w tym samym PR co kod (zadania 2–7), bez historii zmian. Po zadaniu 8 plan jest usuwany.
- **Etap demo:** przestój i odtworzenie danych są dopuszczalne, więc nie projektujemy zgodności z wiadomościami ze starej
  wersji ani migracji danych przebiegów.

## Weryfikacja (wspólna)

Każde zadanie z kodem: TDD (RED przed implementacją, komendy i wyniki obu uruchomień w raporcie), `make check` oraz testy
API (`make -C apps/api check`, `make -C apps/api test`), a przy zmianie `infra/k8s` także `make -C infra/k8s check`.
Skrypty i cele Makefile testowane na ścieżce błędu. Przegląd `code-reviewer` dla zadań STANDARD i CRITICAL; na końcu przegląd
całej zmiany (`architecture-reviewer`, `qa-adversarial` ze względu na współbieżność i przełączenie aliasu).
