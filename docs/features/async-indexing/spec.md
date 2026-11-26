# ASYNC-INDEXING: asynchroniczne indeksowanie do Elasticsearcha

Pełna przebudowa indeksu wyszukiwania (`catalog:reindex`) jest dziś jedną synchroniczną pętlą w jednym procesie. Na
serwerze demo (profil `server`, 10 mln produktów, pomiar z 2026-11-26) szła z tempem około 391 dokumentów/s, czyli około
7 godzin; przy 1 mln produktów około 880 dokumentów/s. Funkcja przenosi indeksowanie na kolejkę Symfony Messenger
(RabbitMQ): przebudowę dzieli na paczki o konfigurowalnym rozmiarze, które obsługuje dowolna liczba workerów
równolegle, a alias przełącza dopiero po ostatniej udanej paczce.

Status: **szkic do akceptacji**. Decyzje z sekcji „Decyzje do podjęcia” rozstrzyga użytkownik; do tego czasu opisują je
rekomendacje, a reszta specyfikacji zakłada rekomendowane odpowiedzi tam, gdzie od nich zależy.

Zakres: `apps/api` (Catalog, Shared), `infra/docker-compose.yml`, `infra/k8s`, `apps/api/bin/generate-demo`,
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
- Pominięcie outboxa: wiadomości przebiegu idą wprost do RabbitMQ (zob. D2).
- Poza zakresem: zmiana tego, co jest w dokumencie, zapytań, shardów, ustawień Elasticsearcha i pamięci Postgresa
  (jeśli pomiar wskaże je jako wąskie gardło, to osobne zadanie), indeksowanie pojedynczych zmian produktu (zostaje
  przez zdarzenia i outbox, `docs/features/search/spec.md`, „Wyzwalacze”), przebudowa online (zmiany w trakcie budowy
  mają to samo ograniczenie co dziś, `docs/features/walking-skeleton/spec.md`, „Ograniczenia tej przebudowy”).

## Stan wyjściowy (do zachowania albo zmiany)

- `ProductSearchIndexRebuilder::rebuild` bierze blokadę `SearchIndexRebuildLock` (znika z procesem), tworzy nowy indeks,
  wypełnia go pętlą po kursorze `afterId` (`ProductSearchSource::page`, fakty ofert `ProductOfferFacts::of`, bulk
  `SearchIndexMaintenance::write`), woła `verify` i `activate` jednym żądaniem, usuwa poprzedni indeks.
- Wzorzec asynchronicznych paczek już istnieje: łańcuch `ReindexCategoryBatch` na transporcie `jobs` z rejestrem
  `CategoryReindexRun` (`apps/api/CLAUDE.md`, „Wzorzec łańcucha partii”). Jest to łańcuch **szeregowy** (następna partia
  powstaje dopiero po poprzedniej), więc nie daje równoległości; nowa funkcja potrzebuje wachlarza (fan-out), a nie
  łańcucha.
- Przyczyna tempa 391 dokumentów/s nie jest ustalona. Hipotezy: odczyt produktów (`page`), doliczanie ofert
  (`ProductOfferFacts::of`, wywołanie przez `query.bus`), zapis do Elasticsearcha (4 shardy, heap 2 GiB), pamięć
  Postgresa (2 GiB, ustawienia domyślne). Równoległość pomoże tylko w tej części, która się skaluje na dodatkowe procesy
  (zob. „Pomiar”); specyfikacja nie obiecuje żadnego tempa.

## Decyzje do podjęcia

Każda ma rekomendację; do rozstrzygnięcia przez użytkownika przed implementacją.

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

Wiadomość, rejestr, rejestr przebiegów i handler ogólny w `Shared` (warstwa Application i Infrastructure), a źródło
danych, budowanie dokumentu i zapis w Catalog jako implementacja portu `DocumentIndexer` dla klucza `product`
(rekomendacja). Alternatywa: cały mechanizm w Catalog, ogólny dopiero po drugim typie. To decyzja DDD (granice modułów,
persystencja rejestru przebiegu): przedstawiana użytkownikowi przed implementacją, razem z propozycją zmian Deptrac
w planie (nie edytujemy `deptrac.yaml`, żeby przeszedł; projekt ma przejść jego reguły).

## Rozwiązanie

### Wiadomość i rejestr typów

```
IndexDocuments {
    documentType: string       // klucz z rejestru, np. "product"
    ids: list<string>          // 1..N identyfikatorów (UUID dla `product`), N = rozmiar paczki
    runId: ?string             // przebieg, do którego należy paczka; null = paczka bez przebiegu
    batch: ?int                // numer paczki w przebiegu (0..total-1)
    target: ?string            // fizyczny indeks, do którego pisze paczka przebiegu; null = alias
}
```

- Wiadomość niesie tylko identyfikatory i klucz, nigdy dane dokumentu: handler czyta aktualny stan z bazy, więc
  powtórzenie i spóźnienie nie zapisują nieaktualnych danych.
- `DocumentIndexer` (port, implementacja per klucz): `type(): string` i
  `index(list<string> $ids, ?string $target, int $version): IndexBatchResult` (liczba zapisanych, pominiętych, bo
  rekordu już nie ma, i przestarzałych). Rejestr rzuca `UnknownDocumentType` dla klucza spoza listy, a handler traktuje
  to jako błąd trwały (`UnrecoverableMessageHandlingException`, od razu `failed`).
- `ProductDocumentIndexer` (Catalog): wczytuje migawki jednym zapytaniem po `id = ANY(:ids)` (nowa metoda
  `ProductSearchSource::findMany`, ta sama definicja zapytania co `page` i `find`), fakty ofert jednym wywołaniem
  `ProductOfferFacts::of`, buduje dokumenty `ProductDocumentBuilder`, pisze jednym bulkiem. Produktu, którego nie ma
  w bazie, pomija. Wersja zewnętrzna dokumentu (`external_gte`) jest pobrana przed odczytem paczki, jak w `ProductIndexing`.
- Długość `ids` ma górny limit (rekomendacja: 5000) niezależnie od konfiguracji; wiadomość z większą listą jest
  odrzucana jako błąd trwały. Rozmiar wiadomości: UUID to 36 znaków, więc paczka 500 ma około 20 KB, a 5000 około 200 KB.

### Rozmiar paczki

- Zmienna środowiskowa `SEARCH_INDEX_BATCH_SIZE` (parametr `app.search.indexing_batch_size`, domyślnie 500, jak dzisiejszy
  `app.search.batch_size`), dostępna dla wszystkich kontenerów z kodem API (compose, chart). Wartość spoza `1..5000` kończy
  start komendy błędem z nazwą zmiennej (jak `SEARCH_SHARDS`).
- Opcja `--batch-size` komendy nadpisuje domyślną dla jednego przebiegu. Rozmiar paczki jest zapisany w przebiegu (jedna
  wartość na przebieg), więc zmiana zmiennej w trakcie przebiegu go nie zmienia.
- Rozmiar paczki to liczba elementów jednej **wiadomości**, nie liczba dokumentów w jednym żądaniu bulk do
  Elasticsearcha. Domyślnie jedna wiadomość to jeden bulk; podział na mniejsze bulki w ramach wiadomości jest poza zakresem,
  dopóki pomiar go nie uzasadni.

### Transport `indexing` i worker

- Transport AMQP `indexing` (kolejka trwała, publikacja z potwierdzeniem `confirm_timeout`, obowiązkowy routing przez
  `RoutedAmqpTransport` jak `events`), serializer `messenger.transport.symfony_serializer`. Ponowienia paczki: przejściowy
  błąd Elasticsearcha albo ofert ponawia transport (rekomendacja: 5 prób po 1, 3, 9, 27 i 81 s), potem `failed` z błędem
  w logu. Błąd trwały (`SearchIndexError`, nieznany typ, zła wiadomość) idzie od razu do `failed`.
- Routing: `App\Shared\Application\Indexing\IndexDocuments: indexing` (nie `DomainEvent`, więc poza outboxem).
- Nowy worker `worker-indexing` z tego samego obrazu (compose i chart): `messenger:consume indexing --time-limit=86400
  --memory-limit=...`. Liczba replik jest ustawieniem chartu (`workers.indexing.replicas`), a liczba paczek obsługiwanych
  jednocześnie przez jedną replikę to jeden proces. Worker nie obsługuje `outbox`, `events` ani transportów Postgresa:
  długa kolejka przebudowy nie opóźnia zdarzeń sklepu (webhooków, wygaszania zamówień) i odwrotnie.
- Limit liczby nieodebranych paczek na kolejce (`prefetch_count`) jest ustawiony tak, żeby wolny worker nie zabierał paczek
  innym (rekomendacja: 1).
- Test chartu wymaga, żeby każdy transport poza `failed` konsumował któryś worker (`apps/api/CLAUDE.md`, „Infrastruktura”);
  nowy transport i worker trafiają do compose i chartu w tym samym PR co kod.

### Przebieg (`SearchRebuildRun`) i rejestr

Stan przebiegu w Postgresie (tabela `search_rebuild_run`, własność `Shared`, albo Catalog zgodnie z D5):

| Pole | Znaczenie |
|---|---|
| `id` | UUID przebiegu |
| `document_type` | klucz typu |
| `status` | `producing`, `running`, `finalizing`, `succeeded`, `failed`, `aborted` |
| `target_index`, `previous_index` | indeks budowany i ten, na który wskazuje alias na początku |
| `batch_size`, `total_batches` | rozmiar paczki i liczba paczek (znana po zakończeniu produkcji) |
| `started_at`, `heartbeat_at`, `finished_at` | czasy; `heartbeat_at` odświeża producent i każda zakończona paczka |
| `failure` | tekst pierwszego błędu trwałego |

Zakończone paczki: tabela `search_rebuild_batch` (`run_id`, `batch`, `documents`, `finished_at`, klucz główny
`(run_id, batch)`). Zapis paczki to `INSERT … ON CONFLICT DO NOTHING`, więc powtórna obsługa tej samej paczki (ponowienie,
`app:messaging:failed-retry`, restart workera po zapisie do Elasticsearcha, ale przed ack) nie liczy jej dwa razy.
Suma `documents` z tabeli daje liczbę dokumentów do `verify`. Około 20 000 wierszy przebiegu przy 10 mln i paczce 500.

Przebieg:

1. **Start** (`catalog:reindex --async [--batch-size N] [--wait]`): bierze blokadę przebiegu (zob. niżej), czyta
   `activeIndex`, usuwa osierocone indeksy jak dziś (`docs/features/demo-scale/spec.md`, Z5), tworzy nowy indeks
   (`createIndex`), zapisuje przebieg `producing`.
2. **Produkcja**: producent czyta same identyfikatory po kursorze (zapytanie samych kluczy po indeksie głównym, bez
   joinów; pamięć zależy od rozmiaru paczki, nie katalogu) i wysyła `IndexDocuments(runId, batch, ids, target)`. Po
   ostatniej paczce ustawia `total_batches` i status `running`. Producent odświeża `heartbeat_at`; przerwany producent
   (kill, błąd RabbitMQ) kończy przebieg jako `failed` i usuwa nowy indeks, o ile może; inaczej przebieg zostaje
   niedokończony i wykrywa go heartbeat.
3. **Paczka**: handler (komenda na `command.bus`, krótka transakcja wokół zapisu rejestru, nie wokół wywołania ES) zapisuje
   dokumenty do `target`, potem w jednej transakcji `INSERT` wiersza paczki i sprawdza, czy przebieg ma komplet paczek
   (`total_batches` znane i `count = total_batches`). Przejście `running → finalizing` robi się warunkowym `UPDATE`,
   więc dokładnie jedna paczka wysyła wiadomość `FinalizeSearchRebuild(runId)`. Paczka przebiegu, który nie jest
   `running`/`producing` (przerwany, nieudany), kończy się bez zapisu.
4. **Finalizacja** (`FinalizeSearchRebuild`, tak samo jak dziś: `verify(target, sumaDokumentów)`, `activate(target, previous)`,
   `deleteIndex(previous)`): sukces to `succeeded`, błąd weryfikacji to `failed` z usunięciem nowego indeksu i aliasem
   bez zmian, niepewność aliasu (`ActivationResult::Unknown`) to `failed` z informacją „oba indeksy zachowane”, jak kod
   wyjścia 2 dziś. Finalizacja jest idempotentna: powtórna wiadomość przebiegu, który nie jest `finalizing`, kończy się
   bez skutku.
5. **Błąd paczki**: po wyczerpaniu ponowień wiadomość trafia do `failed`, a przebieg zostaje `running` (nie przełącza aliasu).
   Po `app:messaging:failed-retry` paczka wykona się i zostanie policzona. Przebieg niedokończony po czasie bez
   `heartbeat_at` (rekomendacja: 30 minut, ustawienie) uznaje się za martwy: `catalog:reindex:abort` albo następny
   `catalog:reindex` oznacza go `aborted` i usuwa jego indeks.

**Blokada.** Dzisiejsza blokada znika z procesem; przebieg asynchroniczny przeżywa proces startujący. Blokadą jest więc
istnienie przebiegu w stanie `producing`, `running` albo `finalizing` (unikalny indeks częściowy: najwyżej jeden
przebieg aktywny na typ). `catalog:search-index:setup` i synchroniczny `catalog:reindex` odmawiają, gdy taki przebieg jest
(jak dziś przy zajętej blokadzie), a martwy przebieg (brak heartbeatu) odblokowuje `catalog:reindex:abort`. Podczas
`finalizing` obowiązuje to samo, więc `activate` i `deleteIndex` nie biegną równolegle ze `setup`.

**Idempotencja zapisu dokumentu.** Dokument ma wersję zewnętrzną (`external_gte`, znacznik czasu przed odczytem
paczki). Powtórzenie paczki zapisuje ten sam dokument z nowszą wersją, więc jest nieszkodliwe; zapis starszego odczytu po
nowszym to wynik „przestarzały”, który dla indeksu budowanego przez przebieg nie jest błędem paczki (w świeżym indeksie
nie powinien się zdarzyć, a zdarzenie jest liczone i logowane). Wersja nie zależy od kolejności paczek, więc kolejność
obsługi paczek jest dowolna.

### Integracje

- `bin/generate-demo`: ostatni krok `catalog:reindex` zastępuje `catalog:reindex --async --wait` (czeka na `succeeded`,
  kod wyjścia jak dziś: 0 alias na nowym indeksie, 1 alias bez zmian, 2 stan aliasu nieznany). Wymaga działającego
  `worker-indexing` w klastrze i na stosie lokalnym; brak workera to wyraźny błąd po limicie czasu bez postępu
  (`--wait` kończy się, gdy `heartbeat_at` nie rusza dłużej niż limit), a nie zawieszenie. `make -C infra/k8s reindex`
  używa tego samego trybu; cele `generate`, `open` i `reindex` odmawiają, gdy przebieg jest aktywny, i zakładają
  `worker-indexing` w skalowaniu jak `worker` (zob. `docs/features/demo-scale/spec.md`, Z4).
- Pomiar postępu: komenda `catalog:reindex:status` (przebieg, paczki zrobione i razem, dokumenty, tempo w dokumentach/s od
  startu i z ostatniej minuty, błędne paczki w `failed`) i log `info` kanałem `search_rebuild` z tym samym polem
  `documents_per_second`.
- Długość kolejki `indexing` i liczba wiadomości w `failed` należą do nadzoru kolejek (`queue-check`,
  `docs/features/async-reliability/spec.md`): nowy transport dostaje swoje miejsce w tym nadzorze albo odnotowaną
  decyzję, że go nie ma (zadanie w planie).

## Skala (10 mln produktów)

- Przy paczce 500: 20 000 wiadomości, 20 000 wierszy rejestru paczek, producent czyta 10 mln identyfikatorów
  po kursorze. Rejestr i producent nie ładują katalogu do pamięci. Jedyny punkt wspólny paczek to wiersz przebiegu
  (aktualizowany tylko przy przejściu stanu i heartbeacie, nie przy każdej paczce) i wstawienie wiersza paczki
  (brak blokad między paczkami).
- Równoległość zależy od liczby replik `worker-indexing` i od tego, co jest wąskim gardłem. Zapis do Elasticsearcha na
  4 shardach, odczyt z Postgresa (2 GiB) i doliczanie ofert mogą się nasycić przy kilku workerach; to hipotezy
  do zmierzenia przed i po (zob. „Pomiar”), nie fakty. Specyfikacja nie twierdzi, że wymagania wydajnościowe są spełnione,
  dopóki nie potwierdzą tego pomiary.
- Rozmiar paczki dobiera się pomiarem: za mała zwiększa narzut wiadomości i transakcji, za duża wydłuża pojedynczą
  paczkę, pamięć handlera i koszt ponowienia.

## Scenariusze błędów

| Zdarzenie | Skutek |
|---|---|
| Przejściowy błąd ES albo ofert w paczce | Ponowienie transportu (1, 3, 9, 27, 81 s), potem `failed`; przebieg `running`, alias bez zmian |
| Błąd trwały paczki (`SearchIndexError`, nieznany typ, zła wiadomość) | Od razu `failed`; przebieg `running`, alias bez zmian |
| Paczka w `failed` naprawiona przez `app:messaging:failed-retry` | Wykonuje się, liczy raz; przebieg dobiega końca |
| Worker zabity po zapisie do ES, przed ack | Wiadomość wraca do kolejki, paczka powtarza zapis (wersja zewnętrzna), wiersz paczki raz (`ON CONFLICT`) |
| Producent zabity w trakcie rozsyłania | Przebieg `producing` bez heartbeatu; po limicie martwy; `catalog:reindex:abort` albo następny `catalog:reindex` usuwa jego indeks i zaczyna od nowa; już wysłane paczki kończą się bez skutku (przebieg nieaktywny) |
| RabbitMQ niedostępny przy produkcji | Producent kończy przebieg `failed` (publikacja z potwierdzeniem), alias bez zmian |
| Wiadomości przebiegu utracone w brokerze | Przebieg nie dochodzi do kompletu; martwy po limicie heartbeatu; powtórzenie od początku |
| Dwa `catalog:reindex` jednocześnie | Drugi odmawia (przebieg aktywny), jak dziś przy blokadzie |
| `verify` nie przechodzi (liczba dokumentów, zdrowie shardów) | `failed`, nowy indeks usunięty, alias bez zmian |
| `activate` w stanie nieznanym | `failed` z informacją „oba indeksy zachowane”, kod wyjścia 2 |
| Restart całego stosu w trakcie | Kolejka trwała, rejestr w Postgresie; przebieg dochodzi do końca, gdy workery wrócą |
| Produkt zmieniony w trakcie przebiegu | Jak dziś: zapis przez alias idzie do poprzedniego indeksu; to samo ograniczenie, że nowy indeks może mieć starszy stan do następnej zmiany lub przebudowy |

## Model zagrożeń

- Klucz typu z kolejki nigdy nie wskazuje klasy: nieznany klucz to odrzucenie wiadomości. Identyfikatory są walidowane jako
  UUID przed zapytaniem (parametry zapytania, nie sklejanie tekstu). Lista `ids` ma limit długości.
- `target` z wiadomości nie może wskazywać dowolnego indeksu: handler akceptuje tylko `target` równy `target_index` aktywnego
  przebiegu o danym `runId`; inna wartość to błąd trwały. Zapobiega to zapisowi do indeksu aktywnego aliasu albo cudzego
  indeksu przez spreparowaną wiadomość.
- Logi nie zawierają danych produktów, tylko identyfikatory przebiegu, numery paczek i liczby.

## Poza zakresem

- Indeksowanie pojedynczych zmian przez paczki (mikro-batching zdarzeń), przebudowa online, zmiana tego, co jest w dokumencie.
- Dodatkowe typy dokumentów (kategorie, klienci, zamówienia panelu): zob. D3.
- Skalowanie Elasticsearcha i Postgresa na serwerze demo; ewentualne zmiany zasobów zgłasza pomiar jako osobne zadanie.

## Kryteria akceptacji

1. Przebieg `--async` na stosie z workerem `indexing` kończy się `succeeded`, alias wskazuje nowy indeks, liczba dokumentów
   równa się liczbie produktów, a poprzedni indeks jest usunięty.
2. Rozmiar paczki z `SEARCH_INDEX_BATCH_SIZE` i z `--batch-size`: liczba wiadomości równa `ceil(produkty / rozmiar)`,
   każda wiadomość ma najwyżej rozmiar paczki identyfikatorów; wartość spoza `1..5000` kończy się błędem.
3. Przebieg z jedną paczką w `failed` nie przełącza aliasu; po `app:messaging:failed-retry` kończy się `succeeded`.
4. Powtórzenie tej samej paczki (ponowienie, restart workera po zapisie) nie zmienia liczby dokumentów ani licznika paczek.
5. Dwie jednoczesne obsługi ostatniej paczki dają dokładnie jedną finalizację (test dwóch procesów jak
   `CategoryReindexConcurrencyTest`).
6. Zła wiadomość (nieznany klucz, zły UUID, `target` spoza przebiegu, lista dłuższa od limitu) trafia do `failed` bez zapisu.
7. Producent zabity w trakcie: przebieg martwy po limicie heartbeatu, `catalog:reindex:abort` zwalnia blokadę i usuwa indeks,
   alias bez zmian.
8. `catalog:reindex` bez `--async` działa jak dziś (testy dotychczasowego zachowania bez zmian).
9. Zdarzenia sklepu (webhooki, wygaszanie zamówień, unieważnianie cache) nie czekają na kolejkę `indexing`: osobny worker.
10. `make check` i testy API przechodzą; chart i compose mają worker `indexing` (test chartu: każdy transport ma konsumenta);
    `bin/generate-demo` kończy się `--async --wait`.
11. **Pomiar po wdrożeniu (kryterium, nie obietnica):** na profilu `server` (10 mln produktów) zmierzyć tempo
    dokumentów/s przy różnej liczbie replik `worker-indexing` i rozmiarze paczki (zob. plan, zadanie pomiarowe) i zapisać
    wynik w `docs/reports/`. Wymagane tempo docelowe ustala użytkownik po pierwszym pomiarze; do tego czasu nie
    twierdzimy, że wydajność jest osiągnięta.

## Dokumentacja

- `docs/features/search/spec.md`: „Zapis i kolejność” i „Infrastruktura” (przebieg asynchroniczny, `catalog:reindex --async`,
  transport `indexing`, zmienna `SEARCH_INDEX_BATCH_SIZE`).
- `docs/features/demo-scale/spec.md`: krok indeksowania generatora i cel `reindex`.
- `apps/api/CLAUDE.md`: transport `indexing` i worker `worker-indexing` w „Infrastrukturze” (lista workerów i transportów),
  wzorzec wachlarza paczek obok wzorca łańcucha.
- `infra/k8s/CLAUDE.md` i runbook `infra/k8s/README.md`: worker, skalowanie replik, kolejka w nadzorze.
- Po ukończeniu funkcji plan jest usuwany; ta specyfikacja zostaje i opisuje stan obecny.

## Ryzyka

- **Wąskie gardło niezależne od liczby workerów.** Jeśli ogranicza je pojedynczy zasób (CPU Postgresa, indeksowanie
  w Elasticsearchu), więcej workerów nie przyspieszy przebiegu, a może go spowolnić. Dlatego plan zaczyna od pomiaru
  przyczyny (zadanie 1) i kończy pomiarem skali (zadanie 9).
- **Obciążenie sklepu na serwerze demo.** Równoległa przebudowa konkuruje z ruchem sklepu o Postgresa i Elasticsearcha;
  liczba replik i rozmiar paczki są ustawieniami, nie stałymi.
- **Spóźnione paczki po przerwaniu.** Wiadomości już w kolejce po przerwaniu przebiegu kończą się bez skutku, ale zajmują worker;
  `abort` może opróżnić kolejkę przebiegu (zadanie 6).
- **Dwa miejsca prawdy o liczbie dokumentów** (rejestr paczek i Elasticsearch): `verify` porównuje je przed przełączeniem aliasu.
