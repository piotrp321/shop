# ASYNC-INDEXING: plan zadań

Specyfikacja: `docs/features/async-indexing/spec.md`. Plan jest dokumentem roboczym: po ukończeniu funkcji go usuń.
Plan zakłada zaakceptowane D1–D5 (z warunkami: prostota, wspólna logika trybów, brak logiki domenowej Catalog w `Shared`).
Istotne decyzje DDD są w specyfikacji; zmiana któregokolwiek rozstrzygnięcia zmienia zadania 2–5.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 0 | 1 (pomiar przyczyny) + **brama**: użytkownik zatwierdza kryterium wydajnościowe (spec, kryterium 12) | zrobione 2026-11-26 (R=4, B=500, T=800, C=3 godz. 45 min) |
| 1–4 | 2–6 jednym PR i w jednej sesji (polecenie użytkownika 2026-11-28) | zaimplementowane 2026-11-28, przegląd `code-reviewer` (rdzeń współbieżności i finalizacji) po poprawkach; PR do akceptacji użytkownika |
| 5 | 7 (pomiar po wdrożeniu, tylko za zgodą użytkownika) | |

**Realizacja zadań 2–6:** jeden PR, jedna sesja, testy celowane lokalnie na własnym stosie worktree, pełne testy w CI.
Bez ciężkich testów kolidujących z generowaniem profilu `server` przez PERF-7, bez dotykania worf.pl. Przegląd ograniczony
do logiki współbieżności, finalizacji i przełączenia aliasu.

## Dostosowanie do PERF-2B (stan `main` z 2026-11-28)

PERF-2B zmienił przebudowę synchroniczną; zadania 2–6 przyjmują poniższe zamiast sprzecznych fragmentów niżej:

- **Alias przebudowy (W19a, M5).** `createIndex` tworzy indeks z aliasem `<alias>-rebuild` (zapisy pojedyncze trafiają do
  budowanego indeksu), `activate` zdejmuje go w żądaniu przepięcia, usunięcie indeksu usuwa go z indeksem. Przebieg
  asynchroniczny trzyma alias przebudowy na `target_index` od startu do `activate`, więc zapisy pojedyncze w trakcie paczek
  i finalizacji trafiają do nowego indeksu bez nowego kodu. Nieudany i przerwany przebieg usuwa `target_index` (z aliasem).
- **Tworzenie produktu w trakcie przebudowy (#982).** Dziś `CreateProduct` bierze współdzieloną blokadę transakcji na
  kluczu `SearchIndexRebuildLock`, a przebudowa trzyma ją na wyłączność przez całą pracę. Przebieg asynchroniczny zwalnia
  blokadę po starcie, więc reguła „trwa przebudowa” to: blokada zajęta **albo** istnieje aktywny przebieg. Sprawdza to
  `PostgresSearchIndexRebuildLock` w obu metodach (`tryAcquire`: blokada, potem brak aktywnego przebiegu, inaczej zwolnienie
  i odmowa; `tryShareInTransaction`: blokada współdzielona, potem osobnym zapytaniem brak aktywnego przebiegu, więc odczyt
  widzi wiersz zatwierdzony przed zwolnieniem blokady startu). Dzięki temu `setup`, `reset`, przebudowa synchroniczna
  i `CreateProduct` odmawiają w trakcie przebiegu bez zmian u wołających (kryterium 9 i dokładna weryfikacja liczby).
- **Pozycje przestarzałe.** `write` nie traktuje 409 (zapis pojedynczy przez alias przebudowy był nowszy) jako błędu, więc
  `ProductBatchIndexer` liczy paczkę jako zapisaną w całości; licznik `documents` przebiegu = suma migawek paczek.
- **Weryfikacja.** `verify` (interwał odświeżania, `refresh`, zdrowie, dokładna liczba, skrót definicji) zostaje bez zmian
  i jest etapem `finalizing`; katalog nie usuwa produktów, a tworzenie jest wstrzymane, więc dokładna równość obowiązuje
  także w trybie asynchronicznym.
- **Termin Joba `reindex` (#981, 12 h)** zostaje; `make -C infra/k8s reindex` woła `catalog:reindex --async --wait`
  i skaluje `worker-indexing`.
- **Uproszczenia wobec specyfikacji (bez zmiany zachowania):** rejestr typów jest częścią `IndexDocumentsHandler`
  (bez osobnej klasy `DocumentIndexers`); warstwa ogólna leży w `Shared\Infrastructure\Indexing` (Shared nie ma warstwy
  Application dostępnej z Catalog, Deptrac bez zmian); przebieg nie ma agregatu ORM: stan i przejścia to warunkowe
  `UPDATE` DBAL w `DbalSearchRebuildRuns` (rekordy tylko definiują schemat, wzorzec `DbalCategoryReindexRuns`, wpis na liście
  wyjątków w `apps/api/CLAUDE.md`), a dozwolone statusy są w enumie `SearchRebuildStatus`; finalizacja idzie wprost
  `indexing.bus` w jawnej transakcji DBAL (wiersz transportu Doctrine na tym samym połączeniu dołącza do transakcji bez
  `BusNameStamp`); jeden parametr rozmiaru paczki (`app.search.indexing_batch_size` z `SEARCH_INDEX_BATCH_SIZE`) zastępuje
  `app.search.batch_size` dla obu trybów; `activating` z nieczytelnym aliasem ponawia wiadomość (status zostaje etapem),
  zamiast kończyć `failed`.

## Zadania

### 1. Pomiar przyczyny tempa i propozycja kryterium (bez zmiany kodu aplikacji)

- **Cel:** ustalić, co ogranicza dziś 391 dokumentów/s, zanim dołożymy równoległość; zaproponować mierzalne kryterium
  wydajnościowe.
- **Zakres:** istniejące narzędzia: logi postępu przebudowy (`search_rebuild`, pole `documents_per_second`), Grafana
  (Postgres, Elasticsearch, węzeł), SPX w obrazie `dev` na lokalnym stosie z dużym katalogiem (profil `medium`, 1 mln), czasy
  składowych jednej paczki (`ProductSearchSource::page`, `ProductOfferFacts::of`, `ProductDocumentBuilder::build`,
  `SearchIndexMaintenance::write`) z tymczasowego, niezacommitowanego skryptu pomiarowego. Kontrolny przebieg z 2 i 4
  równoległymi procesami zapisu do osobnych indeksów sprawdza, czy zasób się skaluje. Odczyt z serwera (Grafana, tylko do
  odczytu) tylko po zgodzie użytkownika w rozmowie, która nazywa operację i host; serwera ani klastra nie dotykamy, gdy trwa
  na nim generowanie. Nie budujemy frameworka benchmarkowego.
- **Wynik:** raport w `docs/reports/` (przypięty do rewizji, niezmienny): metoda, komendy, liczby składowych i skalowania,
  rozdział „zmierzone” od „hipotez”, rekomendowany rozmiar paczki i **propozycja kryterium**: tempo T dokumentów/s (mediana
  ze środkowych 80% przebiegu), czas C dla 10 mln przy R replikach i paczce B.
- **Brama:** użytkownik zatwierdza (R, B, T, C) i wpisujemy je do specyfikacji (kryterium 12). Gdy wąskie gardło nie skaluje
  się z liczbą procesów, użytkownik decyduje, czy kontynuować.
- **Akceptacja:** raport podaje metodę, komendy i liczby; hipotezy bez pomiaru są oznaczone. Ryzyko: FAST (pomiar).

### 2. Wspólna logika, wiadomość `IndexDocuments`, rejestr typów, szyna, transporty, compose i chart

- **Cel:** jeden kod zapisu i przełączenia dla obu trybów; cienka warstwa ogólna w `Shared` (D5).
- **Zakres:**
  - refaktor bez zmiany zachowania: `ProductBatchIndexer::write` (z `fill`), `SearchIndexSwitch` (z `replaceIndex`: `verify`,
    `activate`, usunięcie poprzedniego, `RebuildReport`); `ProductSearchIndexRebuilder` woła je zamiast własnej treści;
  - `Shared`: `IndexDocuments`, port `DocumentIndexer`, `DocumentIndexers` (rejestr, `UnknownDocumentType`),
    `IndexDocumentsHandler`;
  - Catalog: `ProductDocumentIndexer` (walidacja UUID i limitu 5000, `ProductSearchSource::findMany`, zapis przez
    `ProductBatchIndexer`; rozliczenie paczki dopiero w zadaniu 3);
  - `ProductBatchIndexer::write(index, snapshots, version)` **przyjmuje wersję od wołającego**, który bierze ją tuż przed
    odczytem migawek (`fill` przed `page`, handler przed `findMany`); wersja nie jest brana wewnątrz `write`;
  - szyna `indexing.bus` bez `doctrine_transaction`, transport AMQP `indexing` i transport Doctrine `indexing_finalize`
    (`redeliver_timeout` ponad najdłuższy etap finalizacji) z routingiem i komentarzem w `messenger.yaml`, parametr
    `app.search.indexing_batch_size` z `SEARCH_INDEX_BATCH_SIZE` (walidacja `1..5000`);
  - **w tym samym PR infrastruktura:** usługa `worker-indexing` w `infra/docker-compose.yml` (`messenger:consume
    indexing_finalize indexing --time-limit=86400 --memory-limit=...`, zależność od RabbitMQ i `api`, healthcheck jak
    pozostałe, `make up`), Deployment w chartcie z `workers.indexing.replicas` (domyślnie 1), zasobami i `initContainers`
    jak `worker`, `SEARCH_INDEX_BATCH_SIZE` w kontenerach API, test chartu „każdy transport poza `failed` ma konsumenta”
    obejmujący oba nowe transporty, `make -C infra/k8s check`.
- **Testy (TDD: RED przed implementacją, komendy i wyniki obu uruchomień w raporcie):** istniejące testy
  `ProductSearchIndexRebuilderTest` przechodzą bez zmian po refaktorze (zachowanie sync nietknięte); `ProductBatchIndexer`:
  paczka w całości zapisana, `allIndexed` fałsz kończy się błędem (przejściowa i trwała pozycja bulka, stale), fakty ofert
  niedostępne nic nie zapisują; `findMany` zwraca to samo co `page` i `find` (test zgodności zapytań), ID wielkimi
  literami, duplikaty, nieistniejące ID pominięte; handler: nieznany klucz, zły UUID, pusta lista, lista ponad limit =
  błąd trwały bez zapisu; wyjątki przejściowe nie są trwałe; **handler paczki nie działa w transakcji bazy** (test:
  `Connection::isTransactionActive()` fałsz w trakcie zapisu do ES; to samo dla `command.bus` byłoby prawdą, więc test
  pilnuje wyboru szyny); `SEARCH_INDEX_BATCH_SIZE` 0, ujemna, nieliczbowa i powyżej limitu odrzucone przy starcie; Deptrac
  przechodzi bez edycji `deptrac.yaml`; **test wersji:** zegar sztuczny i atrapy zapisują kolejność, wersja dokumentu równa
  czasowi sprzed `page`/`findMany` (sync i handler paczki, także po ponowieniu); **test spójności infrastruktury:** każdy
  transport z `messenger.yaml` poza `failed` ma konsumenta w compose i w chartcie, polecenie workera w compose zgadza się
  z chartem (test chartu, w tym przypadek negatywny: transport bez konsumenta jest odrzucony), worker bez RabbitMQ nie
  przechodzi healthchecku, a `worker-commands` nadal konsumuje tylko `payment_simulator scheduled promotions jobs`.
- **Akceptacja:** kryteria 6, 10 i 11 (część infrastrukturalna), zachowanie sync bez zmian; `make -C apps/api check`, testy API
  i `make -C infra/k8s check`. Nie dotyka serwera; wdrożenie to osobna operacja użytkownika (`gh workflow run deploy.yml`,
  tylko na jego polecenie).
- **Ryzyko:** STANDARD.

### 3. Przebieg, rejestr, wzajemne wykluczanie i finalizacja

- **Cel:** bezpieczny stan przebiegu, liczenie paczek raz, dokładnie jedna finalizacja wznawialna na każdym etapie.
- **Zakres:** (a) **najpierw test mechanizmu**: wiadomość `FinalizeSearchRebuild` (transport `indexing_finalize`) wysłana przez `command.bus` z `BusNameStamp
  ('indexing.bus')` powstaje w transakcji nadawcy (wycofana transakcja: brak wiersza w tabeli transportu) i po stronie konsumenta jest
  obsłużona na szynie bez transakcji; jeśli nie, wariant zapasowy ze spec. (b) migracja (nowa; tabele przebiegu i paczek,
  unikalny indeks częściowy „jeden aktywny przebieg na typ”), rekordy ORM, repozytorium i agregat `SearchRebuildRun`
  z przejściami statusów; (c) wzajemne wykluczanie: blokada `SearchIndexRebuildLock` + sprawdzenie aktywnego przebiegu
  w `ProductSearchIndexRebuilder::rebuild`, `reset` (używany przez `DbalCatalogReset`) i `catalog:search-index:setup`;
  (d) rozliczenie paczki (`INSERT … ON CONFLICT`, `UPDATE` licznika i `heartbeat_at`, warunkowe `running → finalizing`
  i wysłanie finalizacji w tej samej transakcji); (e) handler `FinalizeSearchRebuild` z etapami `finalizing → verified →
  activating → activated → succeeded`; sprawdzenie i ewentualna poprawka idempotencji `deleteIndex` (brak indeksu = usunięty).
- **Testy (TDD):** przejścia statusów i niedozwolone; powtórne rozliczenie tej samej paczki nie zwiększa liczników;
  **dwa procesy** rozliczające ostatnie paczki naraz dają jedną finalizację (jak `CategoryReindexConcurrencyTest`);
  paczki rozliczone przed `total_batches`, potem producent kończy: finalizacja wysłana raz (obie kolejności); `total = 0`
  (pusty katalog): od razu finalizacja; wiadomość finalizacji nie istnieje po wycofaniu przejścia i istnieje po zatwierdzeniu
  (awaria procesu między statusem a wysyłką niemożliwa: test na prawdziwej transakcji); **dwa procesy obsługujące tę samą wiadomość finalizacji** w każdym statusie (atrapa ES wstrzymuje etap, liczy żądania `activate` i `delete`): `succeeded`, alias na `target`, brak nadpisania statusu przez przegranego; finalizacja przerwana w każdym
  etapie (`finalizing`, `verified`, `activating` z aliasem na `previous` i z aliasem już na `target`, `activated`) i wznowiona
  kończy się `succeeded`, a alias zmienia się najwyżej raz (atrapa ES liczy żądania `activate`); `activating` z aliasem na
  trzecim indeksie i nieczytelnym aliasem: `failed`, oba indeksy zachowane; porażka `verify`: nowy indeks usunięty, alias bez
  zmian; błąd usunięcia poprzedniego to ostrzeżenie, przebieg `succeeded`; paczka przebiegu nieaktywnego bez zapisu; **paczka w trakcie zapisu podczas abortu** (zapis wstrzymany, abort, zwolnienie): rozliczenie bez skutku, indeks aliasu nietknięty; **kopia rozliczonej paczki zapisywana podczas `verify` i po `activate`:** liczba dokumentów i alias bez zmian;
  wykluczanie: sync rebuild, async start, `setup` i `reset` w obu kolejnościach (druga odmawia z przyczyną; dwa procesy
  startujące naraz: jeden wygrywa); `setup` i `reset` odmawiają w każdym aktywnym statusie, także `activating`; migracja
  zgodna z mapowaniem (test schematu modułu); plan zapytania rozliczenia używa klucza głównego (bez `Seq Scan` po tabeli
  paczek).
- **Akceptacja:** kryteria 3, 4, 5, 7, 9 ze specyfikacji; finalizacja w toku nie opóźnia `worker-commands` (test: kolejka `scheduled`
  jest obsługiwana, gdy `indexing_finalize` ma wiadomość w toku).
- **Ryzyko:** CRITICAL (współbieżność, przełączenie aliasu, nowa persystencja). Zależy od: 2.

### 4. Producent i komenda `catalog:reindex --async`

- **Zakres:** producent czytający same identyfikatory po kursorze (zapytanie samych kluczy, bez joinów) i wysyłający paczki
  na `indexing` z potwierdzeniem publikacji; końcowa transakcja producenta (`total_batches`, `producing → running`, ewentualna
  finalizacja, spec: „Producent kończy…”); heartbeat producenta co kilkadziesiąt paczek i kilkanaście sekund; błąd RabbitMQ
  (przebieg `failed`, usunięcie nowego indeksu); sprzątanie osieroconych indeksów jak dziś (Z5); opcje `--async`,
  `--batch-size`, `--wait` (postęp na konsoli, kończy się błędem po limicie braku postępu; kody wyjścia 0/1/2); log postępu
  przebiegu kanałem `search_rebuild`. Bez `--async` bez zmian (D4).
- **Testy (TDD):** liczba wiadomości `ceil(N / rozmiar)` dla N = 0, 1, rozmiar − 1, rozmiar, rozmiar + 1, wielokrotność;
  producent zabity w połowie (przebieg `producing` bez heartbeatu); RabbitMQ niedostępny przy wysyłce; `--batch-size` 0,
  ujemny, ponad limit; `--wait` bez postępu (brak workera) kończy się po limicie i nie wisi; kody wyjścia; test na stosie
  z workerem (`test_messaging`): produkty w bazie, przebieg do `succeeded`, dokumenty w indeksie, alias przełączony;
  paczka celowo nieudana (częściowy błąd bulka) nie przełącza aliasu, po ponowieniu przebieg się kończy; restart workera
  (kill po zapisie do ES, przed rozliczeniem) daje komplet dokumentów i jedną finalizację; **koszt rozliczenia**: przebieg
  z dużą liczbą małych paczek (np. 5000 paczek po 1) kończy się w czasie niezależnym od liczby paczek w tabeli (brak rosnącego
  kosztu rozliczenia).
- **Akceptacja:** kryteria 1, 2, 3, 5, 10 ze specyfikacji. **Ryzyko:** CRITICAL. Zależy od: 3.

### 5. Abort i nadzór

- **Zakres:** `catalog:reindex:abort` (warunkowy `UPDATE … WHERE status IN ('producing','running')`, potem usunięcie
  `target_index`; odmowa od `finalizing`; przebieg żywy wymaga `--force`, martwy po limicie heartbeatu, domyślnie 30 minut,
  nie); decyzja o `queue-check` dla kolejki AMQP `indexing` (dodać głębokość i wiek, jeśli RabbitMQ to udostępnia bez nowego
  komponentu, albo zapisać decyzję, że kolejki nie obejmuje, a przebieg ma własny sygnał); sprawdzenie, co o kolejce jest
  już w Grafanie.
- **Testy (TDD):** abort martwego, żywego bez `--force` (odmowa), z `--force`, nieistniejącego, w `finalizing`/`activating`/
  `activated` (odmowa, nic nie usunięte); **abort ścigający się z przejściem `running → finalizing`** (dwa procesy: wygrywa
  jedno, indeks aliasu nigdy nie jest usunięty); abort, gdy `deleteIndex` odmawia (alias wskazuje indeks): stan i komunikat;
  abort przy niedostępnym ES (przebieg `aborted`, indeks osierocony, komunikat); paczki przerwanego przebiegu kończą się bez
  zapisu; wykrycie martwego przebiegu zegarem sztucznym.
- **Akceptacja:** kryterium 8. **Ryzyko:** STANDARD (współbieżność: CRITICAL dla części abort/finalizacja). Zależy od: 4.

### 6. Integracje i dokumentacja

- **Zakres:** `bin/generate-demo` i `make -C infra/k8s reindex` wołają `catalog:reindex --async --wait` (ze skalowaniem
  `worker-indexing` tam, gdzie dziś skaluje się `worker`, i odmową przy aktywnym przebiegu); aktualizacja dokumentów ze
  specyfikacji („Dokumentacja”) i skryptów smoke/E2E, które wołają przebudowę (`git grep catalog:reindex`).
- **Testy:** `generate-demo` bez działającego workera kończy się po limicie brakiem postępu, nie wisi; cele Makefile
  `infra/k8s` na ścieżce błędu (przebieg aktywny, brak workera); `shellcheck`.
- **Akceptacja:** kryterium 11; dokumenty bez opisu historii. **Ryzyko:** STANDARD. Zależy od: 4.

### 7. Pomiar po wdrożeniu (kryterium, nie obietnica)

- **Warunek:** wdrożenie na polecenie użytkownika i zgoda w rozmowie nazywająca operację i host; tylko cele
  `infra/k8s/Makefile`.
- **Zakres:** przebieg `--async --wait` na profilu `server` (10 mln) według (R, B) z kryterium 12 oraz wariantów wokół nich;
  odczyt z logów przebiegu i Grafany (tylko odczyt), wpływ na sklep w trakcie.
- **Wynik:** raport w `docs/reports/` z porównaniem do (T, C) i do 391 dokumentów/s; spełnienie albo niespełnienie kryterium
  podane wprost. Wąskie gardło po stronie bazy lub ES tworzy osobne zadanie. Ryzyko: FAST; zależy od: 6.

## Wpływ na skalę, infrastrukturę i dokumentację

- **Skala (10 mln):** 20 000 wiadomości, 20 000 wierszy paczek i 20 000 krótkich aktualizacji wiersza przebiegu przy paczce
  500; brak `count(*)` po paczkach; producent i rejestr nie ładują katalogu do pamięci. Wpływ na tempo zależy od wąskiego
  gardła z zadania 1; do pomiaru z zadania 7 niczego nie twierdzimy.
- **Chart i compose:** transport `indexing`, usługa `worker-indexing`, `SEARCH_INDEX_BATCH_SIZE`, repliki i zasoby
  (zadanie 2); zmiana `infra/docker-compose.yml` uruchamia w CI `make -C infra/k8s check`.
- **Dokumentacja:** w tym samym PR co kod (zadania 2–6), bez historii; plan usuwany po zadaniu 7.
- **Etap demo:** przestój i odtworzenie danych są dopuszczalne; nie projektujemy zgodności ze starymi wiadomościami.
  Przebudowa online poza zakresem (ograniczenie z `walking-skeleton` zostaje).

## Weryfikacja (wspólna)

Każde zadanie z kodem: TDD (RED przed implementacją; komendy i wyniki obu uruchomień w raporcie), `make check` i testy API
(`make -C apps/api check`, `make -C apps/api test`), przy zmianie `infra/k8s` także `make -C infra/k8s check`. Skrypty i cele
Makefile na ścieżce błędu. Przegląd `code-reviewer` dla zadań STANDARD i CRITICAL; na końcu przegląd całej zmiany
(`architecture-reviewer`, `qa-adversarial` ze względu na współbieżność i przełączenie aliasu).
