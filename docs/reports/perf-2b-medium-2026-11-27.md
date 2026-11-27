# PERF-2 etap B: weryfikacja lokalna na `medium` (2026-11-27)

Zadanie PERF-2B-8 epiku PERF-2B (plan: [`docs/features/search/plan.md`](../features/search/plan.md); specyfikacja:
[`docs/features/search/spec.md`](../features/search/spec.md), kryterium 10 i „Weryfikacja wydajności (PERF-2 etap B)”).
Raport po zapisaniu nie zmienia się. Skrypty i wyniki: [`perf-2b-medium-2026-11-27/`](perf-2b-medium-2026-11-27/)
(`run.sh`, `compare.py`, `dbsums.sql`, `es.override.yaml`, `results/`); pełne odpowiedzi API i logi poleceń leżą poza repozytorium
(`$TMPDIR/PERF-2B-8/`), w repozytorium są digesty, wyniki porównania i próbki czasów.

**Nic tu nie jest wynikiem akceptacyjnym programu.** Kryteria pomiarowe etapu B (spec, kryterium 13; plan programu, „Kryteria etapu B”)
mierzy się na worf.pl przy 10 mln produktów. Ten raport zamyka część lokalną: równoważność odpowiedzi (kryterium 10, część druga)
i próbę narzędzi pomiaru zapisów. Czasy lokalne pochodzą z API w trybie dev na Macu i służą tylko porównaniu przed i po.

## 1. Wynik

| Pytanie | Wynik |
|---|---|
| Odpowiedzi `GET /api/v1/products` przed i po etapie B, ten sam stan danych, 1 shard | **0 różnic w 300 listingach** (liczba trafień, fasety z liczbami, zakres cen, pierwsza strona) |
| Przejście ze starego indeksu (M7) | `setup` na indeksie starej definicji ostrzega i kończy się kodem 0; `catalog:reindex` przepina alias |
| Rozmiar indeksu | dokumenty Lucene 8 891 973 → 4 009 541; 1 058 MB → 984 MB (1 000 191 produktów) |
| Przebieg zapisów `clean12` (12/s, 320 s), wersja z dowodem tempa (sekcja 4a) | kod 0, werdykt „pomiar przy zadanym tempie, indeks zgodny”, wszystkie 320 okien 1 s w paśmie 10–14 (12 w każdym), 0 niezgodności, 0 wiadomości w `failed` |
| Przebieg zapisów `lag50` (50/s, 320 s), wersja z dowodem tempa (sekcja 4a) | kod 99 (oczekiwany: API dev nie przyjmuje 50/s), `send_lag` p99,9 2 ms, max 4 ms, 0 niezgodności, 0 w `failed`, kolejka pusta |
| Lokalne `single` przed i po | wstępnie: mediana 100 → 77 ms, p95 645 → 124 ms (sekcja 5) |

## 2. Rewizje, dane, środowisko

- **Przed:** `98ac711b69a41dc85d0a19c230f990c69b83a7f4` (`main`, ostatni stan sprzed epiku; osobny worktree).
- **Po:** `d14bff34`, czyli gałąź zadania z mergem `origin/feat/perf-2b` `378d1f48` (zawiera zmergowane PERF-2B-1…7) i skryptami tego
  katalogu; kod aplikacji jest kodem epiku. Przebiegi zapisów z sekcji 4a: rewizja `9d763656` (merge `origin/feat/perf-2b` z PERF-2B-2b do worktree zadania); kod
  aplikacji niezmieniony względem `378d1f48`. Rewizje: `results/revisions.txt`.
- **Dane:** kopia `medium` (wolumeny `perf2b-8_postgres-data`, `perf2b-8_garage-*`) z `main` `98ac711b`: 1 000 191 produktów,
  3 009 350 wariantów, 1 000 000 zamówień. Stan danych sprawdzony sumami kontrolnymi wszystkich 33 tabel (liczba wierszy i suma
  hashy wierszy, `dbsums.sql`): pięć odczytów (przed 1–3, po 1–2) jest identycznych, więc oba indeksy powstały z tych samych danych.
  Workery nie działały w fazach porównania, nic nie zapisywało do danych. Zapisy próby zmieniają dane dopiero po porównaniu.
- **Stos:** Docker Desktop 16 CPU, 31 GiB; Elasticsearch 9.4, 1 shard, heap 2 GiB (`es.override.yaml`; compose ma 512 MB, za mało na
  1 mln produktów; to ustawienie lokalne, nie PERF-1), bez limitu pamięci kontenera; `SEARCH_REFRESH_INTERVAL=5s`; API w trybie dev,
  bez Varnisha na ścieżce odczytów (adres API wprost). Oba stosy w jednym projekcie Compose, jeden po drugim, na tych samych wolumenach.
  Keycloak: po zmianie portów baza `keycloak` w kopii została zaimportowana ponownie (`make keycloak-reset`; realm kopii miał adresy
  stosu źródłowego); baza `shop` nietknięta.
- **Mieszanka adresów:** `load/baseline.py urls` (ziarno 20261127, 300 listingów, 150 produktów), `results/urls.json`. Do porównania
  weszło 300 listingów API (dział, liść, filtry, frazy, głębokie strony, strona główna); strony produktów nie są wynikami wyszukiwania.
  30 listingów ma 0 trafień, największy 980 083. Narzędzie `baseline.py` z gałęzi epiku dla obu stron (hash w `revisions.txt`).

## 3. Porównanie przed i po (kryterium 10, druga część)

Przebieg: stos „przed” → `catalog:reindex` → odpowiedzi API dla 300 listingów; `down`; stos „po” na tym samym wolumenie Elasticsearcha
(indeks starej definicji) → `catalog:reindex` → te same adresy → `compare.py diff`.

- **Wynik: 300 adresów, 300 identycznych, 0 różnic, 0 odpowiedzi innych niż 200** (`results/diff.txt`, `diff.json`). Porównanie
  obejmuje `total`, `pageCount`, `facets.categories`, `facets.options` (wartości i liczby), `facets.price`,
  `facets.availableCount` oraz id i treść elementów pierwszej strony, z kolejnością; sama inna kolejność byłaby zgłoszona osobno.
- **Powtarzalność:** drugie pobranie odpowiedzi „przed” na tym samym indeksie: 300 identycznych (`diff-before-repeat.txt`), więc
  odpowiedzi są deterministyczne i każda różnica wynikałaby ze zmiany. `compare.py selftest` pokazuje, że diff wykrywa zmianę
  `total`, licznika fasety, kolejności fasety, zakresu ceny, `availableCount`, kolejności i treści pierwszej strony oraz statusu.
- **„Trafność”:** kolejność przy równych wynikach rozstrzygają `name.sort` i `id` (`ProductSearchQuery::sort()`, bez zmian w obu
  rewizjach), przy 1 shardzie dla obu stron. Wśród 300 listingów jest 96 z frazą (`phrase`, `phrase_deep`, `phrase_filter`); wszystkie identyczne, z kolejnością.
- **Przejście M7:** `catalog:search-index:setup` na wolumenie z indeksem `products_20261127202739743_83e7d8` (stara definicja)
  wypisał „The index … was built from another index definition and is left as it is. Search results are wrong until you run
  catalog:reindex” i zakończył się kodem 0; po `catalog:reindex` alias wskazuje nowy indeks, stary został usunięty.
- **Indeks (`results/es-before.txt`, `es-after.txt`):**

  | | przed | po |
  |---|---:|---:|
  | produkty (`_count`) | 1 000 191 | 1 000 191 |
  | dokumenty Lucene | 8 891 973 | 4 009 541 |
  | rozmiar indeksu | 1 058 188 081 B | 983 628 815 B |
  | shardy / `refresh_interval` | 1 / ustawiany przez setup | 1 / 5 s |
  | czas `catalog:reindex` | 253 s | 246 s |

  Lucene na produkt: 8,89 → 4,01.

## 4. Zapisy, pierwsza wersja scenariusza (bez dowodu tempa)

**Te dwa przebiegi poszły na wersji `load/writes.js` bez poprawki PERF-2B-2b** (okna 1 s, histogram `send_lag`, p99,9, próg przestoju); nie dowodzą utrzymania tempa. Obowiązują przebiegi z sekcji 4a, a poniższe zostają jako dowód, że scenariusz i zgodność indeksu działały już wcześniej (surowe wyniki: `results/writes-v1/`).

Stos „po” w całości (`make up`: Varnish, store, workery), `load/writes.sh` z tokenem z `load/admin-token.py`, API wprost
(bez Varnisha), próba wariantów z cenami w opublikowanych produktach. Wyniki: `results/writes/<przebieg>/writes.json` i `output.log`.

| | `clean12` | `lag50` |
|---|---:|---:|
| ustawienia | 12/s, 320 s, próba 60 wariantów | 50/s, 320 s, próba 40 wariantów, tylko przyjęcia, do 2000 w locie |
| kod wyjścia (oczekiwany) | 0 (0) | 99 (99) |
| werdykt | pomiar przy zadanym tempie, indeks zgodny | brak pomiaru: 40 zapisów nie wysłano w tempie |
| zmiany wysłane / zrobione | 4033 (2080 cen, 1953 przyjęć), 12,6/s | 15 982 (22 ceny, 15 960 przyjęć), 49,9/s |
| `late` / `dropped` / `unanswered` / `refused` | 0 / 0 / 0 / 0 | 0 / 40 / 0 / 0 |
| `send_lag` (generator) p50 / p95 / p99 / max | 1 / 2 / 3 / 9 ms | 1 / 1 / 2 / 5 ms |
| odnowienia tokenu | 1 w tle, 0 blokujących | 1 w tle, 0 blokujących |
| czas odpowiedzi zapisu: przyjęcie p50 / p95 / max | 129 / 177 / 298 ms (cena: 135 / 184 / 271 ms) | 23,5 / 43,9 / 45,4 s (cena: 7,2 / 34,4 / 40,6 s) |
| świeżość ceny p50 / p95 / p99 / max (próbek) | 4,9 / 5,4 / 5,6 / 6,7 s (193) | 12,2 / 66,0 / 80,7 / 84,3 s (22) |
| timeouty świeżości | 0 | 0 |
| zgodność indeksu z bazą | 0 niezgodności (0 cen, 0 dostępności), zgodny po 3,6 s | 0 niezgodności, zgodny po 8,3 s |
| `messenger:failed:show` po przebiegu | 0 | 0 |

- **`failed`:** przed pierwszym przebiegiem 0, po `clean12` 0, po `lag50` 0, po wyczekaniu na opróżnienie kolejki RabbitMQ (pusta
  1 s po końcu kontroli zgodności, `queue-lag50.txt`) i na końcu 0 (`failed-*.txt`).
- **`lag50` to nie pomiar tempa:** API dev przyjęło 49,9 zapisów na sekundę, ale 40 z 16 022 nie wyszło w tempie (limit w locie),
  a czasy odpowiedzi rosną do 45 s: API w dev jest tu nasycone. Werdykt „brak pomiaru” jest oczekiwany; ocenia się rozkład opóźnienia
  generatora (`send_lag` p99 2 ms, max 5 ms przy tolerancji 1000 ms), czyli generator nadąża. Świeżość z `lag50` (p95 66 s, 22 próbki
  zmian ceny) wynika z zaległości kolejki przy nasyconym API dev i nie jest wynikiem W16 (cel: p95 10 s, p99 60 s przy 50/s na worf.pl).
- **Ograniczenia tej wersji narzędzia (usunięte w 4a):** `writes.json` przed PERF-2B-2b nie ma histogramu `send_lag`, p99,9 ani okien 1-sekundowych
  (pola `send_lag_histogram`, `windows` nie istnieją w `load/writes.js` tej rewizji); podaję p50/p95/p99/max. Zmierzone tempo
  to `per_second` (średnia fazy zapisów).
- **Stan request cache Elasticsearcha** (`_nodes/stats/indices/request_cache`, liczniki węzła, `results/cache-*.txt`):

  | moment | trafienia | chybienia | rozmiar |
  |---|---:|---:|---:|
  | przed `singles before` (stos „przed”) | 1 | 1 | 0 |
  | po `singles before` | 1 | 1 | 0 |
  | przed `singles after` | 105 | 131 | 0 B |
  | po `singles after` | 443 | 261 | 236 120 B |
  | przed `clean12` | 443 | 261 | 236 120 B |
  | po `clean12` | 7512 | 1593 | 344 072 B |
  | po `lag50` | 8476 | 2040 | 173 616 B |

  Licznik modelu „przed” prawie nie rośnie w całym przebiegu (zapytania z `size > 0` nie trafiają do request cache), a modelu „po”
  rośnie (fasety jako osobne żądania `size: 0`). Rozmiar po `lag50` jest mniejszy niż po `clean12`: odświeżenia indeksu
  unieważniają wpisy (to wniosek z przebiegu, nie osobny test).
- **Stan cache plików systemu** (`file` z `memory.stat` kontenera Elasticsearcha; kontener bez limitu pamięci, anon ok. 2,9–3,2 GiB):
  przed `singles before` 974 MiB, przed `singles after` 1141 MiB, **przed `clean12` 675 MiB** (po `down` stosu „przed”, starcie stosu „po”
  i `make up`, który dołożył store, admin, Varnisha i inne usługi; przyczyny spadku nie badałem), po `clean12` 1046 MiB, po `lag50` 1046 MiB. Indeks „po” zajmuje 938 MiB,
  więc mieści się w cache plików, przy czym w `clean12` zaczynał z jego częścią.
  Co poprzedziło przebieg: `clean12` poprzedził start całego stosu, 300 odpowiedzi „po” i `singles after`; `lag50` poprzedził
  `clean12` na tym samym indeksie.

## 4a. Zapisy, wersja z dowodem tempa (PERF-2B-2b), kryterium 3, część lokalna

Rewizja: `d14bff34` + merge `origin/feat/perf-2b` `9d763656` (zawiera PERF-2B-2b), ten sam indeks i dane po przebiegach z sekcji 4, ten
sam stos co w sekcji 4 po `down` i ponownym `make up` (workery, Varnish, store). Dane po zapisach z sekcji 4 różnią się od kopii.
`setup` nie ostrzegał: indeks jest w bieżącej definicji, przebudowa nie była potrzebna. Wyniki: `results/writes/<przebieg>/`.

| | `clean12` | `lag50` |
|---|---:|---:|
| ustawienia | 12/s, 320 s, próba 60 wariantów | 50/s, 320 s, próba 40 wariantów, tylko przyjęcia, do 2000 w locie |
| kod wyjścia (oczekiwany) | 0 (0) | 99 (99) |
| werdykt | pomiar przy zadanym tempie, indeks zgodny | brak pomiaru: 692 zapisów nie wysłano w tempie, 78 okien 1 s poza pasmem, 247 zapisów bez odpowiedzi |
| `rate.windows` (320 okien 1 s) | pasmo 10–14: min 12, p5 12, p50 12, p95 12, max 12; poniżej 0, powyżej 0 | pasmo 45–55: min 37, p5 40, p50 50, p95 50, max 50; **poniżej 78**, powyżej 0 |
| średnia wysłanych na sekundę | 12 | 47,8 |
| `catch_up` (zapisy nadrabiane po przestoju) | 0 zapisów, max w oknie 0 | 0 zapisów, max w oknie 0 |
| `send_lag` p50 / p95 / p99 / p99,9 / max | 1 / 2 / 3 / 5 / 10 ms | 1 / 1 / 2 / 2 / 4 ms |
| `send_lag_histogram` (le_1 / le_5 / le_20 / le_100 / le_1000 / gt_1000) | 2808 / 1028 / 4 / 0 / 0 / 0 | 15 033 / 275 / 0 / 0 / 0 / 0 |
| `late` / `dropped` / `unanswered` / `refused` | 0 / 0 / 0 / 0 | 0 / 692 / 247 / 0 |
| próg przestoju generatora | 100 ms | 100 ms |
| czas odpowiedzi zapisu p50 / p99 / max | przyjęcie 167 / 250 / 349 ms; cena 175 / 259 / 347 ms | przyjęcie 30,5 s / 60,0 s / 60,0 s; cena 7,9 / 46,3 / 47,6 s |
| świeżość ceny p50 / p95 / p99 / p99,9 / max (próbek) | 4,8 / 7,6 / 8,2 / 8,2 / 8,2 s (193) | 10,8 / 49,5 / 53,2 / 54,0 / 54,1 s (15); 1 zmiana poza limitem świeżości, percentyle nieważne |
| zgodność indeksu z bazą | 0 niezgodności (0 cen, 0 dostępności), zgodny po 5,6 s | 0 niezgodności (0 cen, 0 dostępności), zgodny po 22,0 s |
| `messenger:failed:show` przed, po przebiegu, po wyczekaniu na kolejkę | 0, 0 | 0, 0, 0 (kolejka RabbitMQ pusta 1 s po końcu kontroli; na końcu 0) |

- **`clean12` dowodzi tempa:** każde z 320 okien 1 s zawiera dokładnie 12 zapisów, bez nadrabiania, a opóźnienie generatora ma ogon do
  10 ms (p99,9 5 ms) przy progu błędu 1000 ms.
- **`lag50` nie dowodzi tempa i tak miało być** (oczekiwany kod 99): API w trybie dev nie nadąża przy 50 zapisach na sekundę.
  Zapisy czekają do limitu 60 s żądania (przyjęcie p99 60 s), 247 kończy się przekroczeniem czasu (wszystkie 248 ostrzeżeń w logu to
  „request timeout”, żadnego innego błędu), więc po osiągnięciu 2000 w locie generator pomija zapisy (692) i część okien spada do 37–40
  zapisów. Generator sam nadąża (p99,9 `send_lag` 2 ms, max 4 ms, 0 nadrabianych), czyli opóźnienie wysyłki nie jest źródłem
  braku tempa; to nasycone API. Wynik oceniany po rozkładzie `send_lag`, nie po werdykcie.
- **Zgodność mimo zapisów bez odpowiedzi:** 0 niezgodności, 0 wariantów sprawdzanych wobec kilku stanów, w tym po 247 przekroczeniach
  czasu (przyjęcia zostawiają wariant dostępnym, więc każdy możliwy stan jest zgodny z indeksem). `failed` 0.
- **Wcześniejsza wersja `lag50` (sekcja 4):** 40 pominiętych, 0 bez odpowiedzi. Różnica między przebiegami (40 → 692 pominiętych,
  0 → 247 bez odpowiedzi) wynika z innego stanu: tu start ES był zimny, a przebieg wszedł po `clean12` na bazie z wcześniejszymi
  zapisami; przyczyny nie badałem, API dev jest na granicy nasycenia przy tym tempie, więc wynik jest wrażliwy na stan. W czasie powtórzonych przebiegów na tym samym hoście działał też stos i testy przeglądu adwersaryjnego epiku (`perf2b-qa`), więc host był równolegle obciążony; czasy i przepustowość z sekcji 4a nie są wynikiem pomiaru.
- **Świeżość `clean12`:** p50 4,8 s, p99 8,2 s, mieści się w W16 (p95 10 s, p99 60 s) przy `refresh_interval` 5 s, ale przy 12/s, a nie 50/s.
  Świeżość `lag50` (p95 49,5 s) jest przy nasyconym API i 15 próbkach, nie wynik W16.
- **Stan request cache** (`results/cache-writes-*.txt`; liczniki węzła):

  | moment | trafienia | chybienia | rozmiar |
  |---|---:|---:|---:|
  | przed `clean12` | 0 | 0 | 0 |
  | po `clean12` | 9467 | 1520 | 311 440 B |
  | po `lag50` | 10 947 | 1958 | 174 736 B |

  Przed `clean12` Elasticsearch był świeżo uruchomiony (po `down` i `make up`), więc liczniki i cache były puste, a pierwsze listingi
  trafiały w zimny cache.
- **Stan cache plików systemu** (`file` z `memory.stat` kontenera ES, bez limitu pamięci, anon ok. 2,7–2,9 GiB): przed `clean12`
  **8,6 MiB** (zimny start kontenera; indeks 938 MiB nie był w pamięci), po `clean12` 911 MiB, przed `lag50` 911 MiB, po `lag50` 915 MiB.
  Co poprzedziło `clean12`: `down` stosu, `make up` i pierwsze zapytania zapisu próby (`discover`); co `lag50`: `clean12` na tym samym indeksie.

## 5. Lokalne porównanie `single` przed i po (WSTĘPNE, NIE wynik akceptacyjny)

`baseline.py run --mode single --target api`, 300 listingów po 2 próbki (zimna, ciepła), API w trybie dev na Macu, request cache
Elasticsearcha opróżniony (`POST /products/_cache/clear?request=true`) tuż przed przebiegiem, 0 niepowodzeń. Jeden przebieg na
rewizję: to nie jest „trzy przebiegi, mediana i rozrzut” z kryterium 5, tylko kontrola, że narzędzie działa i kierunek jest dobry.
Pełna tabela: `results/singles-comparison.txt` (próbki: `singles-{before,after}.jsonl`).

| zimna ścieżka | n | p50 przed / po | p95 przed / po | max przed / po |
|---|---:|---:|---:|---:|
| wszystkie | 300 | 0,100 / 0,077 s | 0,645 / 0,124 s | 0,686 / 0,153 s |
| `home_deep` (cały katalog, strony 2–200) | 48 | 0,635 / 0,108 s | 0,675 / 0,137 s | 0,686 / 0,151 s |
| `department_deep` | 51 | 0,191 / 0,100 s | 0,250 / 0,128 s | 0,257 / 0,153 s |
| `leaf` | 46 | 0,081 / 0,071 s | 0,102 / 0,085 s | 0,110 / 0,103 s |
| `phrase` | 15 | 0,096 / 0,077 s | 0,212 / 0,098 s | 0,212 / 0,098 s |

- Ciepła ścieżka jest prawie taka sama jak zimna (brak cache HTTP; w modelu „po” część faset trafia w request cache także na
  „zimnej” ścieżce, bo opróżnienie było raz przed przebiegiem: w `singles after` 338 trafień i 130 chybień).
- Najmniejsze czasy (ok. 70 ms dla liścia) to narzut API w trybie dev, nie Elasticsearch; poprawa dotyczy listingów z dużą liczbą
  trafień, czyli tych, dla których fasety kosztowały najwięcej.
- Zimny request cache na worf.pl opróżnia `make -C infra/k8s search-cache-clear`; tu użyto tego samego wywołania lokalnie.
- **Przebieg odrzucony:** pierwszy `singles before` (20:49:36–20:51:46 UTC) wystartował przed ogłoszeniem ciszy na hoście (20:51),
  więc inne sesje mogły jeszcze obciążać maszynę; wynik leży w `results/singles-before-early-20-49/` i nie jest użyty. Przebieg
  w tabeli to powtórka po ciszy (20:52:12–20:54:22). Błąd skryptu (zmienna wspólna funkcji `dc`/`es`/`console`) nadał pierwszemu
  przebiegowi złe nazwy plików; poprawiony, adnotacja w `events.log`.

## 6. Uwagi

- **`catalog:reindex` w trybie dev wyczerpuje pamięć na `medium`; przyczyna: zbieranie danych debugowania (`kernel.debug`), nie kod
  przebudowy i nie sam `APP_ENV=dev`** (sekcja 8). Pierwsze przebiegi wyników poszły z `php -d memory_limit=3G`; po zbadaniu
  `run.sh` używa `bin/console catalog:reindex --no-debug` bez podnoszenia limitu. Nieudane próby zostawiły osierocone indeksy
  `products_*`, usunięte ręcznie z lokalnego Elasticsearcha. Wyniki sekcji 3–5 nie zależą od trybu (zapytania i dokumenty są te same).
- Heap Elasticsearcha 2 GiB i `refresh_interval` 5 s to ustawienia tego pomiaru; wartości docelowe wynikają z PERF-1 i PERF-6.
- Dane po przebiegach zapisów różnią się od kopii (receipts i ceny próby); porównanie odpowiedzi odbyło się przed zapisami.
- Pomiar na worf.pl (W1, W8, W9 przy 10 mln, `refresh_interval` 5 s, zapisy 50/s) nie był wykonywany i wymaga osobnej zgody
  właściciela; cel `make -C infra/k8s measure` i `search-cache-clear` z PERF-2B-3 jest do tego gotowy.

## 7. Polecenia

Wszystko z katalogu worktree, `PERF2B8_WINDOW=start` (skrypt odmawia startu kontenerów bez niej):

```sh
sh docs/reports/perf-2b-medium-2026-11-27/run.sh prepare                      # .env, .env.local, worktree rewizji „przed”
sh docs/reports/perf-2b-medium-2026-11-27/run.sh up before                    # stos bez workerów
sh docs/reports/perf-2b-medium-2026-11-27/run.sh dbsums before-1
sh docs/reports/perf-2b-medium-2026-11-27/run.sh reindex before                  # catalog:reindex --no-debug
sh docs/reports/perf-2b-medium-2026-11-27/run.sh urls                         # miks, raz
sh docs/reports/perf-2b-medium-2026-11-27/run.sh fetch before
sh docs/reports/perf-2b-medium-2026-11-27/run.sh singles before
sh docs/reports/perf-2b-medium-2026-11-27/run.sh down                         # bez -v
sh docs/reports/perf-2b-medium-2026-11-27/run.sh up after
sh docs/reports/perf-2b-medium-2026-11-27/run.sh dbsums after-1
sh docs/reports/perf-2b-medium-2026-11-27/run.sh reindex after
sh docs/reports/perf-2b-medium-2026-11-27/run.sh fetch after
sh docs/reports/perf-2b-medium-2026-11-27/run.sh diff                         # kod 0 = 0 różnic
sh docs/reports/perf-2b-medium-2026-11-27/run.sh singles after
sh docs/reports/perf-2b-medium-2026-11-27/run.sh writes-up
sh docs/reports/perf-2b-medium-2026-11-27/run.sh writes clean12
sh docs/reports/perf-2b-medium-2026-11-27/run.sh writes lag50
sh docs/reports/perf-2b-medium-2026-11-27/run.sh drain lag50
sh docs/reports/perf-2b-medium-2026-11-27/run.sh failed final
python3 -I docs/reports/perf-2b-medium-2026-11-27/compare.py singles results/singles-before.jsonl results/singles-after.jsonl
```

Kolejność z dziennika: `results/events.log`.

## 8. Pamięć `catalog:reindex` w trybie dev (OOM przy ok. 250 tys. produktów)

Pytanie właściciela: skąd wyczerpanie `memory_limit` 256 MB w `catalog:reindex` w stosie dev, bez podnoszenia limitu jako
rozwiązania i bez przypisywania przyczyny trybowi dev bez dowodu. Skrypty: `probe-reindex.php` (sonda; czytana z pliku w kontenerze
API, nic nie dodaje do `/app`), `run.sh probe`, wyniki `results/probe/*.jsonl` (jedna linia na paczkę 500 produktów: dokumenty,
`memory_get_usage()`, szczyt, co 10. paczkę pamięć po `gc_collect_cycles()` i liczniki kolektorów).

### Kod: co mogłoby trzymać pamięć

Przeczytane na rewizji `9d763656` (worktree zadania; kod przebudowy jest ten sam co w `d14bff34`):

- `ProductSearchIndexRebuilder::fill()` (`apps/api/src/Catalog/Application/Search/`): w pętli przechowuje tylko `$written` (liczba),
  `$afterId` i zmienne paczki (`$page`, `$facts`, wynik `write()`), nadpisywane w każdym obrocie; log postępu dostaje trzy skalary.
- `DbalProductSearchSource::page()` czyta przez DBAL (`executeQuery()->fetchAllAssociative()` jednej paczki), bez EntityManagera, więc
  nie ma co czyścić przez `clear()`. `QueryBusProductOfferFacts::of()` zwraca tablicę paczki.
- Klient Elasticsearcha to `HttpClient::create()` w `ElasticsearchClientFactory`, nie usługa `http_client` frameworka, więc nie jest
  owinięty w `TraceableHttpClient`; odpowiedzi HTTP nie są zbierane.
- Monolog w dev: handler `stderr` typu `stream`, bez bufora (bufor `fingers_crossed` jest tylko w `when@prod`, a kanał
  `search_rebuild` jest z niego wyłączony).
- Tryb debugowania (`kernel.debug`) dodaje kolektory, które trzymają dane do końca procesu: **(1)** DoctrineBundle:
  `profiling` domyślnie `%kernel.debug%` (`doctrine-bundle/src/DependencyInjection/Configuration.php:179`, rejestracja w
  `DoctrineExtension.php:543`), middleware zapisuje każde zapytanie w `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder::addQuery()`:
  SQL, parametry, typy, czas (tablica `$data`, bez limitu, czyszczona tylko przez `reset()`); **(2)** `debug.stopwatch`
  (`FrameworkExtension.php:1226`, usługa trybu debugowania): `StopwatchEvent::stop()` dopisuje okres do `$periods`
  (`StopwatchEvent.php:94`), a `TraceableMiddleware` Messengera startuje i zatrzymuje go w każdej magistrali przy każdej
  wiadomości (`TraceableMiddleware.php:61/71/79`). Przebudowa wysyła na każdą paczkę kilka wiadomości zapytań (`ListProductVariants`,
  `GetCurrentPrices`, `GetStockLevels`, `GetProductOffers`) i ok. 45 zapytań SQL, część z listami identyfikatorów produktów w parametrach.

### Pomiar: pamięć na paczkę w każdym trybie

Stos `perf2b-8`, rewizja `9d763656`, dane `medium`, `php -d memory_limit=256M` (jak w obrazie), `catalog:reindex` do 300 tys.
dokumentów (potem sonda przerywa, nowy indeks jest usuwany), tryb prod do końca (1 000 191 dokumentów). Host był w tym czasie
obciążony innymi sesjami (load average 21–27), co wydłuża czas, nie zmienia pamięci.

| tryb | pamięć po paczce 100 / 200 / 300 / 450 / 600 (MiB) | wzrost na paczkę | wynik |
|---|---|---:|---|
| dev, `APP_DEBUG=1` (domyślny w dev) | 102,9 / 140,6 / 177,2 / 233,5 / n.d. | 0,372 MiB (781 B/produkt) | wyczerpuje 256 MB po ok. 250 tys. produktów |
| dev, `APP_DEBUG=0` (jak `--no-debug`) | 63,9 / 63,9 / 63,9 / 64,0 / 64,1 | 0,000 | 300 tys. bez wzrostu, szczyt 79,6 MiB |
| prod (obraz `--target prod`, `APP_ENV=prod`, `APP_DEBUG=0`) | 62,1 przy paczce 100, 62,0–62,2 do paczki 2000 | 0,000 | cała przebudowa, 2001 paczek, kod 0, szczyt 79,2 MiB |

`gc_collect_cycles()` zwracał 0 w każdym wywołaniu i nie zmieniał pamięci: to żywe referencje, nie śmieci czekające na kolektor.

Które z dwóch kolektorów (dev, `APP_DEBUG=1`, ta sama sonda, po każdej paczce `reset()` jednego z nich):

| wariant | wzrost na paczkę | pamięć po 600 paczkach |
|---|---:|---:|
| bez resetu | 0,372 MiB | (OOM po ok. 500 paczkach) |
| `DebugDataHolder::reset()` po każdej paczce (zostaje Stopwatch) | 0,060 MiB (126 B/produkt) | 101,5 MiB |
| `Stopwatch::reset()` po każdej paczce (zostaje DBAL) | 0,312 MiB (655 B/produkt) | 218,5 MiB |
| `--no-debug` | 0,000 | 64,1 MiB |

W paczce 450 (225 tys. produktów): 20 251 zapytań w `DebugDataHolder` (SQL 5,2 MB, parametry po serializacji do JSON 82 MB), 218 264
okresów Stopwatch w 24 zdarzeniach (485 na paczkę). 0,060 + 0,312 = 0,372 MiB: dwa kolektory tłumaczą cały wzrost, ok. 84% to
zapytania DBAL, 16% Stopwatch.

### Wniosek

- **Przyczyna jest w kolektorach `kernel.debug` (DBAL `DebugDataHolder` i `Stopwatch`), nie w kodzie przebudowy ani w samym `APP_ENV=dev`:**
  dev bez debugowania i prod mają płaską pamięć (62–64 MiB, szczyt 79 MiB) przy tym samym kodzie i danych. W obrazie produkcyjnym
  (lokalnie, `APP_ENV=prod`) przebudowa 1 mln produktów mieści się w 256 MB z zapasem; limity pamięci na worf.pl nie były tu mierzone.
- **Poprawka w naszym kodzie nie jest potrzebna.** Zalecenie dla długich poleceń konsoli w dev: `bin/console catalog:reindex --no-debug`
  (zweryfikowane: `bin/console about --no-debug` pokazuje `Environment dev`, `Debug false`; sonda z `APP_DEBUG=0` jest płaska).
  Sam `doctrine.dbal.profiling: false` w `when@dev` usunąłby ok. 84% wzrostu, ale Stopwatch zostaje (0,06 MiB na paczkę): na profilu
  `server` (10 mln produktów, 20 000 paczek) to ok. 1,2 GB, więc nie wystarcza; `--no-debug` jest jedynym ustawieniem, które to zamyka.
  Wartość `memory_limit` nie była podnoszona w sondzie ani w pomiarach z `--no-debug`.
- Do zgłoszenia (nie zaimplementowane w tym zadaniu): dopisać zalecenie `--no-debug` dla `catalog:reindex` (i innych długich poleceń
  w dev: `app:backfill`, `*:seed-demo` na dużych profilach) do dokumentacji lokalnej pracy `apps/api`. `make seed` (profil demo,
  kilka tysięcy produktów) nie dochodzi do progu.
- Odchylenie procesowe: raz uruchomiłem `php -l` na hoście (sprawdzenie składni sondy), wbrew zasadzie „PHP tylko w Dockerze”;
  nic nie zmieniło, ale pomiary i sondy działały wyłącznie w kontenerze API.

## 9. `lag50` w trybie prod (API i oba workery na obrazie produkcyjnym)

Rewizja: merge `origin/feat/perf-2b` `70ed29a2` (`17fe9983` w worktree zadania), obraz `perf2b-8-api:prod` zbudowany z niej
(`build-prod`), ten sam indeks i dane co w sekcji 4a (po przebiegach zapisów z sekcji 4 i 4a; indeks w bieżącej definicji, `setup`
nie wymagał przebudowy; z indeksu usunąłem dwa osierocone indeksy po przerwanych sondach z sekcji 8 przed przebiegiem). Reszta stosu
(Postgres, ES z heapem 2 GiB, `refresh_interval` 5 s, RabbitMQ, Keycloak, Varnish, store) bez zmian; `.env` bez `SEARCH_SHARDS`.
`prod-up` potwierdził `APP_ENV=prod`, `APP_DEBUG=0` i brak `phpunit` w `api`, `worker` i `worker-commands` (`results/prod-env.txt`).
Dwa kolejne przebiegi tego samego scenariusza (`lag50`: 50/s, 320 s, próba 40 wariantów, tylko przyjęcia, do 2000 w locie).
Wyniki: `results/writes-prod/run1/lag50/` i `results/writes-prod/lag50/` (przebieg 2), `host-*.txt`, `hoststats.txt`, `queues.txt`.

| | przebieg 1 (23:17 UTC) | przebieg 2 (23:23 UTC) |
|---|---:|---:|
| kod wyjścia | 0 | 0 |
| werdykt | pomiar przy zadanym tempie, indeks zgodny | pomiar przy zadanym tempie, indeks zgodny |
| zmiany zrobione | 16 130 (130 cen, 16 000 przyjęć), 50,4/s | 16 128 (128 cen, 16 000 przyjęć), 50,4/s |
| `late` / `dropped` / `unanswered` / `refused` | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 |
| `rate.windows` (320 okien 1 s, pasmo 45–55) | min = p5 = p50 = p95 = max = 50, poniżej 0, powyżej 0 | to samo |
| `catch_up` | 0 zapisów, max w oknie 0 | 0 zapisów, max w oknie 0 |
| `send_lag` p50 / p95 / p99 / p99,9 / max | 1 / 3 / 4 / 5 / 6 ms | 1 / 2 / 4 / 6 / 6 ms |
| `send_lag_histogram` le_1 / le_5 / le_20 / le_100 / le_1000 / gt_1000 | 13 505 / 2482 / 13 / 0 / 0 / 0 | 13 596 / 2385 / 19 / 0 / 0 / 0 |
| czas odpowiedzi przyjęcia p50 / p95 / p99 / max | 8 / 9 / 10 / 114 ms | 8 / 9 / 10 / 16 ms |
| czas odpowiedzi zmiany ceny p50 / p99 / max | 9 / 20 / 23 ms | 9 / 13 / 14 ms |
| świeżość ceny p50 / p95 / p99 / p99,9 / max (próbek) | 5,0 / 5,3 / 5,3 / 5,3 / 5,3 s (130) | 5,0 / 5,2 / 5,3 / 5,3 / 5,3 s (128) |
| timeouty świeżości | 0 | 0 |
| zgodność indeksu z bazą | 0 niezgodności, zgodny po 0,8 s | 0 niezgodności, zgodny po 0,5 s |
| `messenger:failed:show` przed, po, po wyczekaniu kolejki, na końcu | 0, 0, 0 (pusta 0 s po końcu), 0 | 0, 0, 0 (pusta 0 s po końcu), 0 |

- **W prod 50 zapisów na sekundę jest utrzymane w obu przebiegach**, bez jednego pominiętego ani bez odpowiedzi; W16 w tym stanie
  lokalnym: świeżość p95 5,3 s i p99 5,3 s przy celu p95 10 s, p99 60 s (to pomiar lokalny na `medium`, przy `refresh_interval` 5 s;
  wynik na worf.pl przy 10 mln produktów pozostaje do zmierzenia i nie jest tym wynikiem).
- **Gdzie stoi czas** (docker stats co 10 s, 27 próbek w przebiegu 1 i 25 w przebiegu 2; średnia i maksimum CPU w % jednego rdzenia):

  | usługa | przebieg 1 średnia / max | przebieg 2 średnia / max |
  |---|---:|---:|
  | api | 43 / 57 | 40 / 50 |
  | postgres | 25 / 31 | 25 / 29 |
  | elasticsearch | 19 / 108 | 7 / 12 |
  | rabbitmq | 9 / 86 | 10 / 85 |
  | worker (zdarzenia) | 0,3 / 1,0 | to samo rzędu |
  | worker-commands | 0,1 / 0,9 | to samo rzędu |

  Żadna usługa nie jest blisko nasycenia; przyjęcie kończy się w 8–10 ms, a kolejki RabbitMQ miały 0 wiadomości we wszystkich 24
  próbkach `rabbitmqctl list_queues` (co 20 s, `queues.txt`), więc workery nadążają, a świeżość 5 s to `refresh_interval`. Krótkie
  skoki ES (108%) w przebiegu 1 i RabbitMQ (85%) w obu to pojedyncze próbki, bez wpływu na czasy odpowiedzi.
- **Obciążenie hosta jako dowód braku równoległej pracy** (`host-before.txt`, `host-after.txt`, `hoststats.txt`): load average 2,6 / 3,0
  / 3,9 przed i 2,2 / 2,5 / 3,1 po (w sekcji 8 było 21–27). W 56 próbkach `docker stats` z całego okna (oba przebiegi i przerwa
  między nimi) 40 kontenerów innych projektów miało łącznie średnio 31% jednego rdzenia, bez RabbitMQ i eksportera metryk 11%
  (maksimum 28%); pojedyncze próbki do 171% to RabbitMQ innych stosów (`perf7-rabbitmq-1`, `async-idx-m1-rabbitmq-1`, 74–83%, tak jak
  RabbitMQ naszego stosu), czyli tło brokera, nie zadanie. Procesy hosta: najbardziej obciążony jest wirtualizator Dockera (nasz
  stos), dalej przeglądarka (kilkanaście procent) i `sysmond`. To dowód, że żaden stos innej sesji nie wykonywał testów ani
  pomiarów w tym czasie, ale nie izolacja: 16-rdzeniowy host miał ok. 0,3 rdzenia obcego tła.
- **Porównanie z dev (sekcja 4a) bez przypisywania przyczyny:** ten sam indeks, dane, tempo i próba wariantów, kod aplikacji różni się tylko o FIX1 epiku (6 plików: definicja indeksu, `setup`, `SEARCH_SHARDS`; bez ścieżki zapisu, bez `load/`); w dev ta sama próba
  dała 692 pominięte i 247 bez odpowiedzi, przyjęcie p50 30,5 s, a w prod 0 / 0 i 8 ms. Poza tym różni się tryb kontenerów API
  i workerów (obraz dev z debugowaniem i SPX, opcache z kontrolą znaczników czasu, vs obraz prod). **Nie rozłożyłem tej różnicy
  na składniki** (kolektory debugowania z sekcji 8, SPX, opcache, FrankenPHP): CPU API w dev nie było wtedy zapisywane, więc nie
  stwierdzam, który z nich ją tłumaczy, tylko że tryb prod utrzymuje 50/s, a dev nie. Przebiegi dev i prod nie są pomiarem
  z jednego dnia stanu bazy (po drodze zapisy z innych przebiegów), co jest drugim, niezbadanym czynnikiem.
- **Zgodność i nowe wiadomości w `failed`:** 0 po obu przebiegach, także po wyczekaniu na kolejkę.

Polecenia (po `build-prod`; wykonane w tej kolejności, hoststats w tle):

```sh
export PERF2B8_WINDOW=start
R=docs/reports/perf-2b-medium-2026-11-27/run.sh
sh $R build-prod
sh docs/reports/perf-2b-medium-2026-11-27/hoststats.sh <plik> <plik-stop> 10 &     # obciążenie hosta
MODE=prod sh $R prod-up
MODE=prod sh $R writes lag50                 # results/writes-prod/lag50/, cache-writes-lag50-prod-*, failed-lag50-prod-*
MODE=prod sh $R drain lag50-prod
MODE=prod sh $R failed final-prod
sh $R down                                   # bez -v
```
