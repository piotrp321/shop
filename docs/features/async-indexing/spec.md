# ASYNC-INDEXING: asynchroniczne indeksowanie do Elasticsearcha

Pełna przebudowa indeksu wyszukiwania (`catalog:reindex`) jest dziś jedną synchroniczną pętlą w jednym procesie. Na
serwerze demo (profil `server`, 10 mln produktów, pomiar z 2026-11-26) szła z tempem około 391 dokumentów/s, czyli około
7 godzin; przy 1 mln produktów około 880 dokumentów/s. Funkcja przenosi indeksowanie na kolejkę Symfony Messenger
(RabbitMQ): przebudowę dzieli na paczki o konfigurowalnym rozmiarze, które obsługuje dowolna liczba workerów
równolegle, a alias przełącza dopiero po ostatniej udanej paczce.

Status: **zaimplementowana** (zadania 2–6), pomiar po wdrożeniu (zadanie 7) czeka. D1–D5 zaakceptowane pod warunkami:
rozwiązanie jest możliwie proste, tryb synchroniczny i asynchroniczny dzielą logikę indeksowania, a logika domenowa
Catalog nie trafia do `Shared`.

Zakres: `apps/api` (Catalog, cienka warstwa w Shared), `infra/docker-compose.yml`, `infra/k8s`, `apps/api/bin/generate-demo`,
`docs/features/search/spec.md`, `docs/features/demo-scale/spec.md`, `apps/api/CLAUDE.md`.

## Cel i zakres

- Wiadomość `IndexDocuments` niesie klucz typu dokumentu i listę identyfikatorów (najwyżej tyle, ile wynosi rozmiar
  paczki). Handler wczytuje dane z bazy, buduje dokumenty i zapisuje je jednym bulkiem do Elasticsearcha.
- Rozmiar paczki (liczba elementów w jednej wiadomości) jest konfigurowalny: domyślnie ze zmiennej środowiskowej,
  nadpisywany opcją komendy.
- Osobny transport `indexing` (RabbitMQ) i osobny worker, skalowany niezależnie od `worker` i `worker-commands`.
- Pełna przebudowa jako przebieg (`SearchRebuildRun`): producent dzieli identyfikatory na paczki i rozsyła je, rejestr
  przebiegu liczy paczki, a alias zmienia się atomowo dopiero po ostatniej udanej paczce. Błąd paczki nie przełącza
  aliasu.
- Pominięcie outboxa: paczki przebiegu idą wprost do RabbitMQ (zob. D2). Jedyna wiadomość przebiegu, która nie może zginąć
  (finalizacja), idzie istniejącym transportem transakcyjnym (zob. „Finalizacja”: transport `indexing_finalize`).
- Poza zakresem: zmiana tego, co jest w dokumencie, zapytań, shardów, ustawień Elasticsearcha i pamięci Postgresa
  (jeśli pomiar wskaże je jako wąskie gardło, to osobne zadanie), indeksowanie pojedynczych zmian produktu (zostaje
  przez zdarzenia i outbox, `docs/features/search/spec.md`, „Wyzwalacze”), przebudowa online (dziś zapis pojedynczy idzie do aliasu i do aliasu przebudowy, więc zmiana produktu, ceny albo stanu
  w trakcie budowy trafia do nowego indeksu: `docs/features/search/spec.md`, „Zapisy w trakcie przebudowy”; produkt
  nie da się utworzyć w trakcie przebudowy, 409 `search-rebuild-running`; tworzenie bez odmowy: #979), nowy framework pomiarowy (pomiar: Grafana, logi i istniejące narzędzia).

## Stan wyjściowy (do zachowania albo zmiany)

- `ProductSearchIndexRebuilder::rebuild` bierze blokadę `SearchIndexRebuildLock` (znika z procesem), tworzy nowy indeks,
  wypełnia go pętlą po kursorze `afterId` (`ProductSearchSource::page`, fakty ofert `ProductOfferFacts::of`, bulk
  `SearchIndexMaintenance::write`), woła `verify` i `activate` jednym żądaniem, usuwa poprzedni indeks.
- Wzorzec asynchronicznych paczek już istnieje: łańcuch `ReindexCategoryBatch` na transporcie `jobs` z rejestrem
  `CategoryReindexRun` (`apps/api/CLAUDE.md`, „Wzorzec łańcucha partii”). Jest to łańcuch **szeregowy** (następna partia
  powstaje dopiero po poprzedniej), więc nie daje równoległości; nowa funkcja potrzebuje wachlarza (fan-out), a nie
  łańcucha.
- Przyczyna tempa 391 dokumentów/s nie jest ustalona. Hipotezy: odczyt produktów (`page`), doliczanie ofert
  (`ProductOfferFacts::of`, wywołanie przez `query.bus`), zapis do Elasticsearcha (w pomiarze bazowym: 4 shardy, heap 2 GiB), pamięć
  Postgresa (2 GiB, ustawienia domyślne). Równoległość pomoże tylko w tej części, która się skaluje na dodatkowe procesy
  (zob. „Skala” i kryterium 12); specyfikacja nie obiecuje żadnego tempa.

## Decyzje (zaakceptowane z warunkami)

D1–D5 zaakceptowane w rekomendowanym wariancie (opcje alternatywne zostają jako uzasadnienie).

### D1. Klucz typu dokumentu zamiast nazwy klasy encji

- (a) **Klucz zarejestrowany w kodzie, np. `product` (rekomendacja).** Wiadomość niesie `documentType: 'product'`.
  Rejestr (`DocumentIndexers`, tagowane usługi) mapuje klucz na implementację. Powody: wiadomość z nazwą klasy
  w kolejce wiąże format wiadomości z nazwą klasy (zmiana nazwy lub przeniesienie klasy psuje wiadomości w kolejce),
  łamie granice contextów (Deptrac: wiadomość i handler ogólny musiałyby znać klasę encji albo rekord ORM innego
  modułu), a klucz z kolejki wskazujący dowolną klasę to wektor ataku (nieznany klucz = odrzucenie, nie
  instancjonowanie). Rekord ORM nie jest dokumentem: to, co indeksujemy, to dokument zbudowany z kilku źródeł
  (produkt, warianty, oferta).
- (b) Pełna nazwa klasy encji w wiadomości: bez rejestru, ale z wadami wyżej; wymagałaby jeszcze listy dozwolonych klas.

Użytkownik mówił o „klasie entity”; klucz `product` jest propozycją, jeszcze niepotwierdzoną.

### D2. Zakres pominięcia outboxa

- (a) **Tylko pełna przebudowa (rekomendacja).** Wiadomości `IndexDocuments` przebiegu idą wprost na transport AMQP
  `indexing`. Przebieg jest powtarzalny: utrata wiadomości (np. utrata kolejki przez broker) zostawia przebieg
  niedokończony, wykrywany po czasie (zob. „Scenariusze błędów”), a `catalog:reindex` zaczyna od nowa. Zmiany
  pojedynczych produktów zostają przez outbox (`DomainEvent`, gwarancja transakcyjna: zmiana i zdarzenie w jednej
  transakcji), bo ich nie da się odtworzyć bez pełnej przebudowy.
- (b) Opcja pominięcia outboxa także dla wiadomości wysyłanych z transakcji domenowej: zysk niewielki (zdarzenia
  produktu są pojedyncze), a koszt to utrata gwarancji, że zmiana i jej indeksowanie są zatwierdzane razem.

W obu wariantach wiadomość `IndexDocuments` nie jest `DomainEvent`, więc nie trafia do routingu `outbox`; różnica to
tylko to, kto ją tworzy.

### D3. Zakres mechanizmu

- (a) **Ogólny mechanizm, jedna implementacja: `product` (rekomendacja).** Wiadomość, rejestr i handler są ogólne (klucz
  typu), ale w tym epiku rejestrujemy tylko `product`; przebieg i rejestr przebiegów są przez to z góry podzielone na
  część ogólną (paczki, kolejka, worker) i część produktową (źródło danych, dokument, indeks). Kategorie, klienci
  i zamówienia panelu nie mają dziś osobnego indeksu Elasticsearcha ani problemu skali, więc dla nich nic nie robimy.
- (b) Tylko produkty, bez rejestru: najmniej kodu, ale dodanie drugiego typu zmieniałoby wiadomość i handler.
- (c) Ogólny mechanizm i od razu drugi typ (kategorie albo inne): kosztuje rozszerzenie bez uzasadnienia pomiarem.

### D4. Domyślny tryb komendy

- (a) **`catalog:reindex` zostaje synchroniczny; asynchroniczny wybiera `--async` (rekomendacja).** Testy, `make seed`
  w compose i lokalna praca nie wymagają workera `indexing`. `bin/generate-demo` i `make -C infra/k8s reindex` wołają
  `--async --wait` (zob. „Integracje”).
- (b) Domyślnie asynchroniczny: jeden tryb, ale komenda bez workera zawiesza się albo wymaga flagi `--sync` wszędzie,
  gdzie dziś działa.

### D5. Granice modułów

W `Shared\Infrastructure\Indexing` tylko cienka, ogólna warstwa: wiadomość `IndexDocuments`, port `DocumentIndexer`
(tag z interfejsu) i `IndexDocumentsHandler`, który jest zarazem rejestrem typów: wybiera indeksator po kluczu i oddaje mu
wiadomość, a nieznany klucz to `UnknownDocumentType` jako błąd trwały. Wszystko, co jest wiedzą o indeksie
wyszukiwania i produkcie, zostaje w Catalog: dokument, budowanie dokumentu, zapis, przebieg przebudowy
(`SearchRebuildRun`), jego rejestr w Postgresie, finalizacja i przełączenie aliasu. `Shared` nie zna Elasticsearcha,
produktu ani przebiegu; implementacja `DocumentIndexer` dla klucza `product` jest w Catalog i sama rozlicza paczkę
w rejestrze przebiegu. Deptrac: `Catalog_Infrastructure` (albo Application) zależy od `Shared`, nie odwrotnie. Projekt ma
przejść reguły bez edycji `deptrac.yaml`; gdy się nie da, zadanie wraca do użytkownika.

### Wspólna logika trybów

Tryb synchroniczny i asynchroniczny używają tego samego kodu zapisu i przełączenia; asynchroniczność zmienia tylko to, kto
woła go i kiedy:

- `ProductBatchIndexer::write(string $index, list<ProductSearchSnapshot>, int $version): int` (Catalog Application): fakty ofert
  (`ProductOfferFacts::of`), wersja dokumentu (czas przed odczytem), budowa dokumentów, zapis jednym bulkiem
  i sprawdzenie `allIndexed()` (błąd, gdy cokolwiek się nie zapisało). To dzisiejsza treść pętli `fill`; `fill` woła ją
  na stronie z `page`, handler paczki woła ją na wyniku `findMany(ids)`.
- **Wersja dokumentu jest wyznaczana przed odczytem.** Dziś `fill` bierze `ProductIndexing::version($clock->now())` przed
  `page`, a wersja przechodzi do `builder->build`. Po wydzieleniu `write` dostaje już odczytane migawki, więc nie może sam
  brać czasu (wersja z chwili po odczycie złamałaby regułę z `ProductIndexing`: odczyt rozpoczęty później widzi co najmniej
  te same dane). Dlatego `write(string $index, list<ProductSearchSnapshot> $snapshots, int $version)` **przyjmuje wersję od
  wołającego**, a ten bierze ją bezpośrednio przed odczytem (`fill` przed `page`, handler paczki przed `findMany`);
  zapisywany jest czas przed odczytem migawek, a fakty ofert czyta `write` po nim. Test porządku: zegar sztuczny, odczyt
  i zapis zapisują kolejność wywołań, wersja dokumentu równa czasowi sprzed `page`/`findMany`, także po ponowieniu paczki
  (nowa wersja przy każdej obsłudze).
- `SearchIndexSwitch` (Catalog Application): `verify`, `activate`, usunięcie poprzedniego indeksu i zbudowanie
  `RebuildReport`, wydzielone z `replaceIndex`; woła je `ProductSearchIndexRebuilder` (synchronicznie) i finalizacja
  przebiegu (zob. niżej, z etapami wznawialnymi).

## Rozwiązanie

### Wiadomość i rejestr typów

```
IndexDocuments {
    documentType: string       // klucz z rejestru, np. "product"
    ids: list<string>          // 1..N identyfikatorów (UUID dla `product`), N = rozmiar paczki
    runId: string              // przebieg, do którego należy paczka
    batch: int                 // numer paczki w przebiegu (0..total-1)
}
```

- Wiadomość niesie tylko klucz, identyfikatory i przebieg; nie niesie nazwy indeksu. Indeks docelowy (`target_index`) handler
  czyta z wiersza przebiegu, więc spreparowana wiadomość nie wskaże aliasu ani cudzego indeksu. Nie ma wiadomości paczki bez
  przebiegu: pojedyncze zmiany produktu zostają przy `ProductIndexing` i zdarzeniach (`docs/features/search/spec.md`).
- Dane dokumentu czyta handler z bazy w chwili obsługi, nigdy z wiadomości, więc powtórzenie i spóźnienie nie zapisują
  nieaktualnych danych.
- `DocumentIndexer`: `type(): string` i `index(IndexDocuments): void`. Rejestr rzuca `UnknownDocumentType` dla klucza
  spoza listy (błąd trwały, `UnrecoverableMessageHandlingException`, od razu `failed`).
- `ProductDocumentIndexer` (Catalog): odrzuca wiadomość ze złym UUID, pustą listą i listą dłuższą niż 5000
  (błąd trwały); czyta przebieg (bez blokady) i kończy bez zapisu, gdy go nie ma albo nie jest `producing`/`running`
  (zob. niżej); wczytuje migawki jednym zapytaniem (`ProductSearchSource::findMany`, ta sama definicja zapytania co `page`
  i `find`), woła `ProductBatchIndexer::write(target_index, …)` i rozlicza paczkę w rejestrze. Rozmiar wiadomości: UUID
  to 36 znaków, paczka 500 to około 20 KB, 5000 około 200 KB.

### Rozmiar paczki

- Zmienna środowiskowa `SEARCH_INDEX_BATCH_SIZE` (parametr `app.search.indexing_batch_size`, domyślnie 500, jak dzisiejszy
  `app.search.batch_size`), dostępna dla wszystkich kontenerów z kodem API (compose, chart). Wartość spoza `1..5000` kończy
  start komendy błędem z nazwą zmiennej (jak `SEARCH_REFRESH_INTERVAL`).
- Opcja `--batch-size` komendy nadpisuje domyślną dla jednego przebiegu i jest zapisana w przebiegu; zmiana zmiennej
  w trakcie przebiegu go nie zmienia. Ten sam parametr i ten sam zakres obowiązują przebudowę synchroniczną.
- To liczba elementów jednej **wiadomości**; jedna wiadomość to jeden bulk do Elasticsearcha. Podział na mniejsze bulki
  w ramach wiadomości jest poza zakresem, dopóki pomiar go nie uzasadni.

### Szyna, transport i worker `indexing`

- **Szyna bez transakcji.** `command.bus` ma middleware `doctrine_transaction` (`config/packages/messenger.yaml`), więc
  handler na niej trzyma otwartą transakcję przez cały zapis do Elasticsearchu. Handler paczki i finalizacji (operacje na
  ES trwają sekundy do minut) nie może na niej działać. Nowa szyna `indexing.bus` ma tylko `CorrelationIdMiddleware`
  (bez `doctrine_transaction`), jak szyny `query.bus` i `event.bus`, i obsługuje `IndexDocuments` oraz
  `FinalizeSearchRebuild`. Rozliczenie paczki w rejestrze i przejścia stanu przebiegu to krótkie transakcje
  (zob. niżej), wykonywane przez komendy na `command.bus` wołane z handlera, już poza operacją na ES, albo jawną transakcją
  DBAL; wybór (komenda czy transakcja DBAL) zapada w zadaniu 3 według tego, co prostsze w teście.
- Transport AMQP `indexing` (kolejka trwała, publikacja z potwierdzeniem `confirm_timeout`, obowiązkowy routing przez
  `RoutedAmqpTransport` jak `events`), serializer `messenger.transport.symfony_serializer`. Ponowienia paczki: błąd
  przejściowy ES albo ofert (`SearchUnavailable`, `OfferFactsUnavailable`) ponawia transport (5 prób po 1, 3, 9, 27 i 81 s),
  potem `failed`. Błąd trwały (`SearchIndexError`, w tym nie zapisany dokument z bulka, nieznany typ, zła wiadomość) idzie od
  razu do `failed`. Routing: `IndexDocuments: indexing` (nie `DomainEvent`, więc poza outboxem).
- Worker `worker-indexing` z tego samego obrazu (compose i chart): `messenger:consume indexing --time-limit=86400
  --memory-limit=...`; liczba replik to ustawienie chartu (`workers.indexing.replicas`), `prefetch_count` 1. Worker nie
  obsługuje `outbox`, `events` ani transportów Postgresa, więc długa kolejka przebudowy nie opóźnia zdarzeń sklepu
  (webhooków, wygaszania zamówień). Każdy transport poza `failed` ma konsumenta (test chartu); nowy transport i worker trafiają
  do compose i chartu w tym samym PR co definicja transportu w `messenger.yaml` (`infra/k8s/CLAUDE.md`): transporty
  `indexing` i `indexing_finalize`, szyna `indexing.bus`, usługa `worker-indexing` (compose i chart), zmienna
  `SEARCH_INDEX_BATCH_SIZE`, `redeliver_timeout` i test chartu „każdy transport ma konsumenta” (zadanie 2).
- `FinalizeSearchRebuild` idzie własnym transportem Doctrine `indexing_finalize` (Postgres, ta sama baza, `redeliver_timeout`
  ponad najdłuższy etap finalizacji, np. 3600 s; ponowienia 1, 3, 9, 27, 81 s, potem `failed`), konsumowanym przez
  `worker-indexing` (`messenger:consume indexing_finalize indexing`, finalizacja pierwsza). **Nie** idzie przez `jobs`:
  `worker-commands` obsługuje `payment_simulator scheduled promotions jobs` po kolei, jednym procesem, więc finalizacja
  (`verify` z `refresh` i liczeniem dokumentów oraz usunięcie indeksu 10 mln dokumentów trwają minuty, a przy błędach dłużej)
  wstrzymałaby webhooki symulatora płatności, wygaszanie zamówień i granice promocji. Na `worker-indexing` finalizacja
  nie blokuje niczego poza paczkami tej samej przebudowy, które w tym czasie już się skończyły. Obsługuje ją handler
  na `indexing.bus`: `DbalSearchRebuildRuns` wysyła ją przez `indexing.bus` w jawnej transakcji DBAL przejścia do
  `finalizing`; transport Doctrine zapisuje wiersz na tym samym połączeniu, więc wiadomość istnieje dokładnie wtedy, gdy
  przejście jest zatwierdzone, a `BusNameStamp('indexing.bus')` kieruje ją u workera na szynę bez transakcji
  (`FinalizationTransactionTest`).

### Przebieg (`SearchRebuildRun`) i rejestr

Tabela `catalog_search_rebuild_run` (jeden wiersz na przebieg) i `catalog_search_rebuild_batch` (jeden wiersz na paczkę
wysłaną przez producenta, klucz główny `(run_id, batch)`: `ids_hash` to SHA-256 identyfikatorów paczki złączonych
przecinkami, `documents` jest puste do rozliczenia). Producent zapisuje wiersz paczki przed jej wysłaniem. Przebieg nie ma agregatu ORM: stan i przejścia zmienia `DbalSearchRebuildRuns`
warunkowymi `UPDATE` (rekordy tylko definiują schemat), statusy są w `SearchRebuildStatus`. Przebieg aktywny to wiersz bez
`finished_at` (ustawiany przy statusie końcowym); unikalny indeks częściowy na `document_type` `WHERE finished_at IS NULL`:

| Pole przebiegu | Znaczenie |
|---|---|
| `id`, `document_type` | UUID i klucz typu |
| `status` | `producing`, `running`, `finalizing`, `verified`, `activating`, `activated`, `succeeded`, `failed`, `aborted` |
| `target_index`, `previous_index` | budowany indeks i ten, na który wskazywał alias na początku |
| `batch_size`, `total_batches` | rozmiar paczki i liczba paczek (`null`, dopóki producent nie skończy) |
| `done_batches`, `documents` | liczniki zaktualizowane razem z wierszem paczki |
| `started_at`, `heartbeat_at`, `finished_at`, `failure` | czasy i tekst pierwszego błędu trwałego |

**Wzajemne wykluczanie.** Wszystkie operacje na indeksie (przebudowa synchroniczna, asynchroniczna, `setup`, `reset`
w `DbalCatalogReset`) wykluczają się wzajemnie jedną regułą:

- Każda z nich najpierw bierze dzisiejszą blokadę doradczą Postgresa `SearchIndexRebuildLock` (`pg_try_advisory_lock`,
  znika z procesem), a potem sprawdza, że nie ma aktywnego przebiegu (status inny niż `succeeded`, `failed`, `aborted`).
  Aktywny przebieg to odmowa z jego identyfikatorem. Operacja synchroniczna trzyma blokadę przez całą pracę.
- Start przebiegu asynchronicznego robi to samo, w tej samej kolejności, **zakłada wiersz przebiegu** (`producing`)
  i dopiero wtedy zwalnia blokadę. Unikalny indeks częściowy „jeden aktywny przebieg na typ” jest dodatkowym zabezpieczeniem
  na poziomie bazy.
- Dowód wzajemnego wykluczenia: sprawdzenie „brak aktywnego przebiegu” i założenie wiersza dzieją się pod blokadą, więc
  operacja synchroniczna widzi albo wiersz (odmawia), albo, przy zajętej blokadzie, odmowa blokady; start asynchroniczny przy
  trwającej operacji synchronicznej nie dostaje blokady (odmowa), a po jej końcu widzi stan indeksu po niej. Nie ma kolejności,
  w której obie ruszają.
- `setup`, `reset` i przebudowa synchroniczna odmawiają również podczas finalizacji (status `finalizing`…`activated` jest
  aktywny), więc nie ścigają się z `activate` ani `deleteIndex`.

**Rozliczenie paczki** (po udanym zapisie do ES, jedna krótka transakcja, bez operacji na ES w środku):

1. `UPDATE` przebiegu: `done_batches = done_batches + 1`, `documents = documents + :n`, `heartbeat_at = now()`, warunek
   `status IN ('producing','running')`; 0 wierszy kończy rozliczenie bez skutku.
2. `UPDATE` wiersza paczki: `documents = :n` z warunkiem `ids_hash` równym odciskowi identyfikatorów wiadomości
   i `documents IS NULL`. 0 wierszy: paczka już rozliczona (ten sam odcisk) kończy rozliczenie bez skutku, a paczka,
   której producent nie wysłał (nieznany numer albo inne identyfikatory, np. poprawny UUID nieistniejącego produktu),
   wycofuje transakcję i trafia od razu do `failed` (`ForeignRebuildBatch`); prawdziwa paczka o tym numerze rozlicza się
   nadal.
3. Jeśli po zmianie `total_batches IS NOT NULL AND done_batches = total_batches` i status `running`, ta sama transakcja
   przestawia `running → finalizing` warunkowym `UPDATE` (`WHERE status = 'running'`) i wysyła `FinalizeSearchRebuild`
   transportem `indexing_finalize`. Wiersz przebiegu jest blokowany przez `UPDATE` (blokada wiersza do końca transakcji), więc współbieżne
   rozliczenia paczek ustawiają się w kolejce; dokładnie jedno widzi komplet. Nie ma `count(*)` po tabeli paczek: licznik
   jest kolumną, więc koszt rozliczenia nie zależy od liczby paczek.

   Koszt: jedna blokada krótkiego wiersza na paczkę (20 000 aktualizacji jednego wiersza przy 10 mln i paczce 500, w skali
   godzin) i `heartbeat_at` w tym samym `UPDATE`, więc nie ma osobnych zapisów heartbeatu z paczek. Wiersz może puchnąć
   od martwych krotek; autovacuum je odzyskuje, a pomiar w zadaniu 4 sprawdza, że rozliczenie nie jest wąskim gardłem.

**Producent kończy po części paczek lub po wszystkich.** Producent czyta same identyfikatory po kursorze i wysyła paczki.
`total_batches` jest nieznane do końca produkcji, więc komplet może pojawić się u któregokolwiek z dwóch uczestników:

- ostatnia rozliczona paczka widzi `total_batches IS NULL` i nic nie robi (krok 3 się nie spełnia);
- producent po wysłaniu ostatniej paczki wykonuje jedną transakcję: blokuje wiersz przebiegu, ustawia `total_batches`
  i `status = 'running'` (warunek `status = 'producing'`), a jeśli `done_batches = total_batches`, od razu przestawia na
  `finalizing` i wysyła `FinalizeSearchRebuild`.

Oba warunki sprawdzane są pod blokadą wiersza przebiegu, więc zawsze dokładnie jeden z nich zauważa komplet, bez względu na
kolejność. Pusty katalog to `total_batches = 0`, `done_batches = 0`: producent od razu przechodzi do finalizacji z 0
dokumentów (indeks pusty, alias przełączony, jak dziś). Producent wysyła paczki bez transakcji z rozliczeniem, więc paczka może
zakończyć się przed końcem produkcji; to ścieżka opisana wyżej.

### Finalizacja i jej wznawianie

Handler `FinalizeSearchRebuild` (na `indexing.bus`, bez transakcji wokół ES) przechodzi przez etapy; po każdym zapisuje status
krótką transakcją, a każdy etap jest idempotentny, więc ponowienie wiadomości (transport `indexing_finalize`; po `failed`
`app:messaging:failed-retry`) albo restart workera wznawia od zapisanego etapu:

| Status przy starcie | Co robi | Stan po awarii w tym miejscu |
|---|---|---|
| `finalizing` | porównuje `documents` z liczbą produktów katalogu (`SELECT count(*)`: w trakcie przebiegu blokada odmawia tworzenia produktów, a usuwania produktów nie ma), potem `verify(target, liczba produktów)` (przywraca `refresh_interval`, `refresh`, zdrowie, liczbę dokumentów, skrót definicji; idempotentne), potem status `verified` | powtórzony `verify`; niepowodzenie weryfikacji to `failed`, nowy indeks usunięty (nie wskazuje go alias), alias bez zmian |
| `verified` | status `activating` (zapis przed wysłaniem żądania) | bez zmiany aliasu |
| `activating` | czyta `activeIndex()`. Równy `previous_index`: woła `activate(target, previous)` (jedno atomowe żądanie), potem czyta alias; nadal `previous_index` (przełączenie odrzucone albo niewysłane): błąd przejściowy, ponowienie etapu, nowy indeks zostaje. Równy `target_index`: alias już przełączony (żądanie zdążyło przed awarią albo zrobił to drugi handler). Inny: `failed`, oba indeksy zachowane, kod wyjścia 2. Nieczytelny: błąd przejściowy, ponowienie wiadomości od tego etapu | `activate` powtórzony nie przełączy dwa razy: oczekuje poprzedniego indeksu |
| `activated` (alias na `target`) | `deleteIndex(previous)`, potem `succeeded`. Błąd usunięcia to ostrzeżenie (indeks osierocony), przebieg i tak `succeeded`, jak dziś | powtórzone usunięcie; brak indeksu (404) liczy się w `deleteIndex` jako usunięty |
| `succeeded`, `failed`, `aborted` | koniec bez skutku | — |

**Dwie wiadomości finalizacji naraz.** Zdarza się to przy zwykłym użyciu: `redeliver_timeout` minął, a pierwszy handler
jeszcze pracuje (długi `verify` albo usuwanie dużego indeksu), albo wiadomość ponowiono ręcznie. Rozwiązanie bez dzierżawy
i bez blokady trzymanej podczas operacji na ES:

- każdy etap jest idempotentny (`verify`, `deleteIndex` z 404 jako sukcesem), a `activate(target, previous)` jest jednym
  atomowym żądaniem z oczekiwanym poprzednim indeksem, po którym zawsze następuje odczyt aliasu: odczyt równy `target`
  to sukces niezależnie od tego, które z dwóch żądań go wykonało;
- **każde przejście statusu to warunkowy `UPDATE … WHERE status = :widziany`**, także przejścia do `failed`: handler, który
  przegrał, zmienia 0 wierszy i kończy się bez skutku (albo przechodzi dalej od aktualnego statusu), więc spóźniony
  handler nie nadpisze `succeeded` przez `failed` ani nie cofnie statusu;
- dwa handlery mogą więc wykonać ten sam etap dwa razy (zbędna praca, nie błąd), ale nigdy nie przełączą aliasu dwa razy
  ani nie zakończą przebiegu dwa razy. Dzierżawy (`lease_until`) nie dodajemy: dałaby tylko oszczędność pracy kosztem
  kolumny, odnawiania i ścieżek wygaśnięcia.

Test: dwa procesy obsługujące tę samą wiadomość w każdym statusie (wstrzymywanie ES w atrapie), atrapa liczy żądania
`activate` i `delete`; końcowy stan `succeeded`, alias na `target`, `previous` usunięty, brak zmiany statusu przez przegranego.

`deleteIndex` już dziś odmawia usunięcia indeksu, na który wskazuje alias (`ElasticsearchSearchIndexMaintenance::deleteIndex`),
więc usunięcie błędnego indeksu nie jest możliwe także przy błędzie logiki.

**Abort** (`catalog:reindex:abort`) działa tylko na przebieg w stanie `producing` albo `running`: warunkowy `UPDATE … WHERE
status IN ('producing','running')` ustawia `aborted` (pod blokadą wiersza, więc nie ściga się z przejściem `running →
finalizing`; jedna z dwóch transakcji wygrywa). Dopiero po zatwierdzeniu `aborted` usuwa `target_index`, i tylko jeśli nie
wskazuje go alias (sprawdza to `deleteIndex`). Przebieg w `finalizing` i dalej: abort odmawia z komunikatem, bo finalizację
dokańcza ponowienie jej wiadomości (jeśli wiadomość jest w `failed`, ponawia ją `app:messaging:failed-retry`). Przebieg
żywy (heartbeat nie starszy niż limit) wymaga `--force`; martwy (limit domyślnie 30 minut) nie.

**Wznowienie finalizacji** (`catalog:reindex:resume`): gdy wiadomość finalizacji zginęła (usunięta z `failed`), komenda
w jednej transakcji odświeża heartbeat przebiegu w etapie finalizacji (`finalizing`, `verified`, `activating`,
`activated`) i zapisuje nową `FinalizeSearchRebuild`; handler rusza od zapisanego etapu. Finalizacja jest idempotentna,
więc kopia w drodze albo powtórzona komenda niczego nie psuje. Dla przebiegu, który przyjmuje paczki, i bez aktywnego
przebiegu kończy się kodem 1.

**Po odtworzeniu bazy z kopii** (`catalog:reindex:abort --restored`, woła go Job `restore` w trybie `reindex` przed
synchronicznym `catalog:reindex`): kopia może mieć przebieg aktywny w chwili backupu, który zablokowałby przebudowę.
Komenda ustawia go na `aborted` w dowolnym etapie (workery są wtedy zatrzymane, kolejki RabbitMQ wyczyszczone)
i usuwa jego nowy indeks, jeśli nie wskazuje go alias. Jego wiadomości odtworzone z bazą (finalizacja, paczki w `failed`)
działają tylko na przebieg niekońcowy, więc nie robią nic. Bez aktywnego przebiegu kończy się kodem 0.

**Paczki a stan przebiegu (paczka w trakcie zapisu podczas abortu lub finalizacji).** Handler paczki ma stałą kolejność:
(1) czyta przebieg bez blokady i kończy bez zapisu, gdy nie jest `producing`/`running`; (2) zapisuje do `target_index`
(bez transakcji); (3) rozlicza warunkowym `UPDATE … WHERE status IN ('producing','running')` i wiersz paczki w jednej
transakcji; 0 zmienionych wierszy (przebieg już `aborted`, `failed` albo `finalizing`) kończy się bez skutku.
`abort` nie czeka na paczki w trakcie zapisu. Skutki, bez dodatkowego mechanizmu:

- **Abort w trakcie zapisu paczki.** Paczka zapisana już po usunięciu `target_index` odtwarza w Elasticsearchu
  (domyślne `action.auto_create_index`) indeks o tej nazwie. Rozliczenie takiej paczki kończy się bez skutku, a handler,
  który widzi przebieg `aborted` albo `failed` przed kompletem paczek, usuwa `target_index` ponownie (`deleteIndex`
  odmawia, gdy wskazuje go alias). Usuwa po własnym zapisie, więc ostatni piszący sprząta ostatni.
- **Paczka w trakcie zapisu, gdy rusza finalizacja.** Finalizacja startuje dopiero po rozliczeniu wszystkich paczek, więc
  w trakcie zapisu może być tylko **kopia już rozliczonej paczki** (redelivery po restarcie albo ponowieniu). Taka kopia
  czyta aktualny stan produktów, zapisuje te same identyfikatory z nowszą wersją (`external_gte`), więc nie zmienia liczby
  dokumentów, `verify` ani wyniku; po przełączeniu aliasu to zapis do żywego indeksu aktualnych danych, poprawny jak
  zapis `ProductIndexing`. Rozliczenie takiej kopii kończy się bez skutku (paczka już rozliczona, status).
- Po `failed` przebiegu kopie w kolejce kończą się bez zapisu (krok 1).

Testy (prawdziwy ES): abort przed i po żądaniu bulk paczki: rozliczenie bez skutku, nowy indeks nie zostaje, alias
nietknięty; kopia rozliczonej paczki po przełączeniu: liczba dokumentów i alias bez zmian; worker zabity po zapisie,
przed rozliczeniem: paczka liczy się raz po ponownym doręczeniu; nieudana paczka (przejściowo i trwale) i jej ponowienie:
przebieg kończy się `succeeded`; obca paczka i produkt pominięty przez paczki; utracona finalizacja i `resume`; baza
odtworzona z przebiegiem w każdym etapie.

### Przebieg komendy

1. **Start** (`catalog:reindex --async [--batch-size N] [--wait]`): blokada i sprawdzenie aktywnego przebiegu (wyżej),
   `activeIndex`, `createIndex` (z aliasem przebudowy), zapis
   przebiegu `producing`, zwolnienie blokady, produkcja. Indeks osierocony po przerwanym przebiegu wypisuje `setup`; nowy
   start zdejmuje z niego alias przebudowy (jak przebudowa synchroniczna).
2. **Produkcja** jak wyżej; producent odświeża `heartbeat_at` co kilkadziesiąt paczek i co kilkanaście sekund. Błąd publikacji
   do RabbitMQ (potwierdzenie) kończy przebieg jako `failed` (warunkowy `UPDATE`), usuwa nowy indeks i zwraca błąd.
   Producent zabity w trakcie zostawia przebieg `producing` bez heartbeatu: martwy po limicie, usuwa go `abort`. Producent,
   którego przebieg przerwano (`abort --force`), kończy wysyłanie przy najbliższym heartbeacie.
3. **`--wait`** odpytuje wiersz przebiegu aż do stanu końcowego i wypisuje postęp (paczki, dokumenty, tempo); kończy się
   błędem, gdy `heartbeat_at` nie rusza dłużej niż limit (`app.search.rebuild_stall_seconds`, 30 minut; ten sam limit
   uznaje przebieg za martwy w `abort`), np. bez workera. Kody wyjścia jak dziś według stanu aliasu po końcu albo po
   limicie: 0 alias na nowym indeksie, 1 alias bez zmian (także odmowa startu), 2 stan aliasu nieznany.
4. Bez `--async` komenda działa jak dziś (D4), z tą różnicą, że przy aktywnym przebiegu odmawia.

### Integracje i pomiar

- `bin/generate-demo`: ostatni krok `catalog:reindex` zastępuje `catalog:reindex --async --wait`. Wymaga działającego
  `worker-indexing` w klastrze i na stosie lokalnym. Cele `generate`, `open` i `reindex` w `infra/k8s` skalują go jak `worker`
  i odmawiają przy aktywnym przebiegu (`docs/features/demo-scale/spec.md`, Z4).
- **Postęp i pomiar bez nowych narzędzi.** Postęp przebiegu to log `info` kanałem `search_rebuild` (przebieg, paczki zrobione
  i razem, dokumenty, `documents_per_second`), trafiający do Grafany jak dziś log postępu przebudowy; wynik `--wait` na
  konsoli. Głębokość kolejki `indexing` odczytuje się z RabbitMQ (zarządzanie albo metryki, jeśli już są zbierane) i zadanie
  planu sprawdza, co jest dostępne; nowego eksportera, panelu ani frameworka benchmarkowego nie budujemy. Pomiary zadań 1 i 7
  używają Grafany, logów przebiegu i czasów z SPX/logów (`docs/reports/`), zgodnie z `apps/api/CLAUDE.md` („Profilowanie”).
- Nadzór kolejki `indexing` w `queue-check`: zadanie 5 ocenia, czy dodać (głębokość i wiek AMQP), czy zapisać decyzję, że jej
  nie obejmuje (przebieg ma własny sygnał: martwy przebieg po heartbeacie i brak postępu w `--wait`).

## Skala (10 mln produktów)

- Przy paczce 500: 20 000 wiadomości, 20 000 wierszy paczek i 20 000 krótkich aktualizacji jednego wiersza przebiegu
  (w skali godzin, nie sekund); producent czyta 10 mln identyfikatorów po kursorze (same klucze, pamięć zależy od rozmiaru
  paczki). Rejestr nie ma zapytań rosnących z liczbą paczek.
- Równoległość zależy od liczby replik i od wąskiego gardła. Zapis do ES (w pomiarze bazowym: 4 shardy, heap 2 GiB), odczyt z Postgresa
  (2 GiB) i doliczanie ofert mogą się nasycić; to hipotezy do zmierzenia, nie fakty. Specyfikacja nie twierdzi, że wymagania
  wydajnościowe są spełnione, dopóki nie potwierdzą tego pomiary.
- Rozmiar paczki dobiera się pomiarem: za mała zwiększa narzut, za duża wydłuża paczkę, pamięć handlera i koszt ponowienia.

## Scenariusze błędów

| Zdarzenie | Skutek |
|---|---|
| Przejściowy błąd ES albo ofert w paczce | Ponowienie transportu (1, 3, 9, 27, 81 s), potem `failed`; przebieg `running`, alias bez zmian |
| Bulk z częściowym błędem (część dokumentów odrzucona) | Przejściowe pozycje ponawia `write`; po wyczerpaniu prób paczka z samymi pozycjami przejściowymi to błąd przejściowy (ponowienie transportu), a z pozycją trwale odrzuconą błąd trwały (`failed`); paczka nie jest rozliczona, ponowienie zapisuje całą paczkę z nową wersją (idempotentne) |
| Błąd trwały paczki (`SearchIndexError`, nieznany typ, zła wiadomość, `ids` ponad limit) | Od razu `failed`; przebieg `running`, alias bez zmian |
| Paczka z `failed` naprawiona przez `app:messaging:failed-retry` | Wykonuje się i jest rozliczona raz |
| Worker zabity po zapisie do ES, przed rozliczeniem albo ack | Wiadomość wraca, paczka zapisuje się ponownie (wersja zewnętrzna), wiersz paczki rozlicza się raz (`documents IS NULL`), licznik rośnie raz |
| Paczka, której producent nie wysłał (inny numer albo inne identyfikatory) | Odrzucona do `failed` bez rozliczenia; prawdziwa paczka o tym numerze rozlicza się nadal |
| Produkt pominięty przez paczki (zapis z pominięciem blokady, błąd licznika) | `verify` porównuje z liczbą produktów katalogu: `failed`, nowy indeks usunięty, alias bez zmian |
| Baza odtworzona z kopii z aktywnym przebiegiem | Job `restore` (tryb `reindex`) woła `catalog:reindex:abort --restored`, potem synchroniczną przebudowę |
| Dwie kopie tej samej paczki naraz | Obie zapisują dokumenty (nieszkodliwe), rozliczenie przechodzi raz |
| Wszystkie paczki rozliczone przed końcem produkcji | Komplet wykrywa producent w swojej końcowej transakcji i wysyła finalizację |
| Pusty katalog | `total_batches = 0`; producent od razu finalizuje, indeks pusty, alias przełączony |
| Dwa rozliczenia ostatnich paczek jednocześnie | Blokada wiersza przebiegu: jedno widzi `done = total` i wysyła finalizację, drugie widzi `finalizing` i nic nie wysyła |
| Utrata finalizacji między commitem a publikacją | Niemożliwa: wiadomość jest wierszem tabeli transportu `indexing_finalize` w tej samej transakcji co przejście `running → finalizing` |
| Awaria w trakcie finalizacji (każdy etap) | Ponowienie wiadomości wznawia od zapisanego statusu (tabela „Finalizacja”); alias przełącza się najwyżej raz |
| Dwie wiadomości finalizacji naraz (redelivery przy długim etapie, ręczne ponowienie) | Etapy idempotentne, przejścia statusu warunkowe; alias przełączony najwyżej raz, `succeeded` nie jest nadpisany; zbędna praca możliwa |
| Paczka w trakcie zapisu podczas abortu | Rozliczenie bez skutku; indeks odtworzony przez ten zapis handler usuwa ponownie |
| Kopia rozliczonej paczki zapisuje się podczas finalizacji | Te same identyfikatory, nowsza wersja; liczba dokumentów i alias bez zmian |
| Długa finalizacja (minuty) | Obsługuje ją `worker-indexing`; `worker-commands` (webhooki, wygaszanie zamówień, promocje) jej nie widzi |
| Finalizacja w `failed` (po ponowieniach) | Status zostaje etapem (np. `activating`), alias bez zmian albo już przełączony; operator ponawia wiadomość (`app:messaging:failed-retry`) |
| Wiadomość finalizacji usunięta z `failed` | Przebieg czeka w etapie finalizacji (`abort` odmawia, tworzenie produktu też); `catalog:reindex:resume` wysyła finalizację ponownie od zapisanego etapu |
| `verify` nie przechodzi | `failed`, nowy indeks usunięty, alias bez zmian |
| `activate` w stanie nieznanym | `failed`, oba indeksy zachowane, kod wyjścia 2 |
| Abort w trakcie przejścia `running → finalizing` | Warunkowe `UPDATE`: wygrywa jedno; abort przegrany odmawia i nie usuwa niczego; abort nigdy nie usuwa indeksu aliasu |
| Producent zabity w trakcie rozsyłania | Przebieg `producing` bez heartbeatu; martwy po limicie; `abort` usuwa indeks; wysłane paczki kończą się bez zapisu |
| RabbitMQ niedostępny przy produkcji | Przebieg `failed`, nowy indeks usunięty, alias bez zmian |
| Wiadomości paczek utracone w brokerze | Przebieg nie dochodzi do kompletu; martwy po limicie heartbeatu; `abort` i powtórzenie od początku |
| Jednoczesne `catalog:reindex` (sync/async), `setup`, `reset` | Wzajemnie wykluczone blokadą i wierszem przebiegu (wyżej); druga odmawia z przyczyną |
| Restart całego stosu w trakcie | Kolejka trwała, rejestr i finalizacja w Postgresie; przebieg kończy się, gdy workery wrócą |
| Produkt zmieniony w trakcie przebiegu | Jak dziś (`docs/features/search/spec.md`, „Zapisy w trakcie przebudowy”): zapis pojedynczy idzie do aliasu i do aliasu przebudowy, więc trafia też do nowego indeksu; tworzenie produktu w trakcie przebiegu jest odrzucane (409 `search-rebuild-running`; tworzenie bez odmowy: #979) |

## Model zagrożeń

- Klucz typu z kolejki nigdy nie wskazuje klasy: nieznany klucz to odrzucenie wiadomości. Identyfikatory są walidowane jako
  UUID przed zapytaniem i idą jako parametry zapytania. Lista `ids` ma limit długości.
- Wiadomość nie niesie nazwy indeksu; indeks docelowy pochodzi z wiersza przebiegu, a `deleteIndex` odmawia indeksów spoza
  aliasu i tego, na który wskazuje alias.
- Logi nie zawierają danych produktów, tylko identyfikatory przebiegu, numery paczek i liczby.

## Poza zakresem

- Indeksowanie pojedynczych zmian przez paczki, przebudowa online, zmiana tego, co jest w dokumencie, dodatkowe typy
  dokumentów (D3), skalowanie Elasticsearcha i Postgresa na serwerze demo (zmiany zasobów wskazane pomiarem to osobne
  zadanie), komenda `status` i nowy eksporter metryk (postęp w logach i `--wait`).

## Kryteria akceptacji

1. Przebieg `--async` na stosie z workerem `indexing` kończy się `succeeded`, alias wskazuje nowy indeks, liczba dokumentów
   równa się liczbie produktów, poprzedni indeks jest usunięty.
2. Liczba wiadomości równa `ceil(produkty / rozmiar)` (także 0, 1, rozmiar ± 1); rozmiar spoza `1..5000` kończy się błędem.
3. Przebieg z paczką w `failed` nie przełącza aliasu; po ponowieniu kończy się `succeeded`. Częściowy błąd bulka też.
4. Powtórzenie paczki nie zmienia liczby dokumentów ani liczników.
5. Dwa procesy kończące ostatnie paczki jednocześnie dają dokładnie jedną finalizację; paczki kończące się przed końcem
   produkcji i pusty katalog kończą się finalizacją.
6. Zła wiadomość trafia do `failed` bez zapisu.
7. Wiadomość finalizacji nie ginie: nie istnieje bez zatwierdzonego przejścia statusu i istnieje po jego zatwierdzeniu.
   Przerwanie w każdym etapie finalizacji i jego ponowienie, a także dwie wiadomości finalizacji obsługiwane równocześnie,
   kończą przebieg poprawnie; alias zmienia się najwyżej raz, a `succeeded` nie jest nadpisany.
   Finalizacja nie opóźnia wiadomości `worker-commands`. Wersja dokumentu jest czasem sprzed odczytu migawek także w paczce.
8. `abort` nie usuwa indeksu aliasu, nie ściga się z finalizacją, odmawia po `finalizing`.
9. Przebudowa synchroniczna, asynchroniczna, `setup` i `reset` wykluczają się w obu kolejnościach startu.
10. `catalog:reindex` bez `--async` działa jak dziś; zdarzenia sklepu nie czekają na `indexing`.
11. `make check` i testy API przechodzą; chart i compose mają `worker-indexing`; `generate-demo` kończy `--async --wait`.
12. **Kryterium wydajnościowe** (zatwierdzone 2026-11-26 na podstawie
    `docs/reports/async-indexing-measurement-2026-11-26.md`): na profilu `server` przy ≤ R = 4 replikach `worker-indexing`
    i paczce B = 500 stałe tempo ≥ T = 800 dokumentów/s (mediana ze środkowych 80% przebiegu), czas przebiegu 10 mln
    produktów ≤ C = 3 godz. 45 min. Cel ambitny: T = 1200 dokumentów/s. Punkt odniesienia: 391 dokumentów/s i 7,27 godz.
    (przebieg 2026-11-26 przed zmianą). Pomiar zadania 7 porównuje 1, 2 i 4 repliki na tym samym zbiorze 10 mln i tej samej
    konfiguracji infrastruktury, i podaje spełnienie wprost; do pomiaru nie twierdzimy, że wydajność jest osiągnięta. Odczyt z
    dysku Postgresa jest dobrze poparta, ale nie udowodnioną przyczyną niskiego tempa (raport, pkt 6): jeśli skalowanie
    liczby replik nie podnosi tempa, użytkownik decyduje, czy kontynuować, a przyczyną zajmują się zadania PERF.

## Dokumentacja

- `docs/features/search/spec.md`: „Zapis i kolejność”, „Infrastruktura” (przebieg, `--async`, transport, `SEARCH_INDEX_BATCH_SIZE`).
- `docs/features/demo-scale/spec.md`: krok indeksowania generatora i cel `reindex`.
- `apps/api/CLAUDE.md`: transport `indexing`, worker `worker-indexing`, szyna `indexing.bus` bez transakcji, wzorzec wachlarza
  paczek obok łańcucha.
- `infra/k8s/CLAUDE.md` i runbook `infra/k8s/README.md`: worker, repliki, kolejka.
- Po ukończeniu funkcji plan jest usuwany; ta specyfikacja zostaje i opisuje stan obecny.

## Ryzyka

- **Wąskie gardło niezależne od liczby workerów.** Plan zaczyna od pomiaru przyczyny i ma bramkę po nim.
- **Obciążenie sklepu na serwerze demo.** Równoległa przebudowa konkuruje ze sklepem o Postgresa i ES; repliki i rozmiar
  paczki są ustawieniami.
- **Gorący wiersz przebiegu.** Aktualizacja per paczka jednego wiersza: pomiar w zadaniu 4; gdyby był wąskim gardłem, rozliczanie
  w grupach (np. wiersz paczki bez licznika, licznik z `count` co N paczek) to zmiana lokalna.
- **`BusNameStamp` na `indexing_finalize`.** Mechanizm finalizacji z szyną bez transakcji wymaga potwierdzenia testem (zadanie 3) i ma
  zapasowy wariant opisany wyżej.
