# Profil `server` na worf.pl: linia bazowa przed optymalizacjami (2026-11-26)

Rewizja kodu na serwerze: `a5056dbc` (wdrożona 2026-11-25). Dane: profil `server` (`apps/api/fixtures/demo-data/profiles.yaml`),
wygenerowane na worf.pl 2026-11-25/26. Sprzęt bez zmian: OVH VPS, 8 vCPU, 22,9 GiB RAM, bez swapu, ok. 207 GB dysku;
limity: Postgres 2 GiB (domyślna konfiguracja), Elasticsearch 4 GiB (heap 2 GiB, 4 shardy), sklep 1,5 GiB, Job generatora 4 GiB.
Nic nie zoptymalizowano, nie zmieniono konfiguracji ani zasobów, sklep przez cały czas był zamknięty (reguła `shop-closed`).

Raport rozdziela **potwierdzone** (zmierzone albo odczytane z logów i bazy) od **hipotez** (wyjaśnienia, których nie sprawdzono).
Wymagania W1–W20 pochodzą z `docs/features/performance-program/plan.md`.

## 1. Zakres i ograniczenia

- Pomiary wykonano **wewnątrz klastra** (Job w namespace `shop`, przez Service `varnish` i `store:3000`, z pominięciem
  Traefika i Cloudflare), więc czasy są czasami serwera, bez sieci i bez TLS. Nie zastępują pomiaru publicznego sklepu
  (§8). Ścieżka żądania: klient → Varnish → API → Elasticsearch/Postgres oraz klient → sklep (Next.js) → Varnish → API.
- Narzędzie: istniejące `load/baseline.py` (GET), uruchamiane przez nowy cel `make -C infra/k8s measure` (PR do
  akceptacji). Pod pomiarowy zużył najwyżej 524 mCPU i 132 MiB (`top.log`), czyli ok. 6% CPU węzła.
- **Nie wykonano:** k6 (`saturate.js`, `steady.js` zapisują dane przez checkout; wymagają osobnej zgody i osobnego
  przygotowania danych), W5–W7 (koszyk, zamówienia, panel), W9–W12 poza wzrostem tempa listingów i stron produktów
  (poniżej), W13, W14, W16–W18. Grafana Cloud nie była czytana w tym raporcie: zasoby pochodzą z `kubectl top` co 15 s
  (`top.log`) i z próbek co 5 min podczas generowania (`generator/samples.log`).
- Zimne i ciepłe: „zimne” = adres pierwszy raz widziany przez Varnish i ISR sklepu (wiersze `cache=miss`), „ciepłe” =
  ten sam adres natychmiast po zimnym. Request cache Elasticsearcha nie był opróżniany osobno; adresy z mieszanki są unikalne,
  ale listing „po filtrze” może trafić w cache silnika. Nie rozdzielono tych wpływów.
- Próba: pojedyncze przebiegi (nie trzy), więc rozrzutu między przebiegami nie znamy; różnic poniżej ok. 20% nie uznajemy za efekt.

## 2. Generowanie danych (W20) i przebudowa indeksu (W19)

Źródło: `generator/samples.log` (próbki co 5 min: ogon logu Joba, dysk, pamięć, CPU węzła, rozmiar bazy), log Joba.

| Faza | Początek (UTC) | Koniec (UTC) | Czas |
|---|---|---|---|
| produkty, ceny, historia cen, zdjęcia | 11-25 19:41 | ok. 11-25 23:30 | ok. 3,8 h |
| import 1 mln klientów do Keycloak | ok. 23:30 | ok. 00:40 | ok. 1,2 h |
| zamówienia (5 mln) | 11-26 00:44 | ok. 11-26 03:00 | ok. 2,3 h |
| ruchy magazynowe i modele odczytu | ok. 03:00 | 05:35 | ok. 2,6 h |
| `catalog:reindex` (10 000 191 dokumentów) | 05:35 | ok. 12:50 | ok. 7,25 h |
| `demo-data:verify` | ok. 12:50 | nieukończone | przerwane limitem 24 h Joba |

Granice faz są przybliżone (próbki co 5 min, ostatnia linia logu). Pewne są: start 19:40:21, limit 24 h o 19:40:21
(`DeadlineExceeded`) i czas reindeksu z nazwy indeksu (`products_20261126053552075`) oraz postępu (9 886 500 dokumentów
o 12:47).

- **Potwierdzone:** generator danych trwał ok. 17 h do końca reindeksu (W20: do 6 h), a pełny reindeks ok. 7,25 h przy
  ok. 390 dok./s (W19: do 60 min). Ten sam indeks na 1 mln produktów szedł z ok. 880 dok./s (medium).
- **Potwierdzone:** `demo-data:verify` nie skończył w 24 h. W momencie przerwania działało zapytanie o stany
  (`inventory_stock_item … JOIN LATERAL …`) od 14 min, wcześniej inne (m.in. `SELECT DISTINCT p.slug FROM
  pricing_variant_price …` ok. 25 min), wszystkie w stanie `IO`. Dane, indeks i cache były kompletne od ok. 12:50, więc
  kilkugodzinna weryfikacja nie zmieniała stanu sklepu.
- **Hipoteza (nie sprawdzono):** zapytania weryfikacyjne przeglądają niemal całe tabele (10M–30M wierszy) przy Postgresie
  z 2 GiB pamięci i ograniczają go dysk; na planie `EXPLAIN` widać pełny skan produktów z sondą wariantów (zapytanie
  `NOT EXISTS … ORDER BY p.slug LIMIT 11`, planner szacuje wiele wyników, a faktycznie jest 0).
- **Hipoteza:** wolniejszy reindeks niż na `medium` (390 vs 880 dok./s) wynika z większych tabel i cache plików
  Postgresa/ES zbyt małego dla danych; przyczyny nie ustalono (do sprawdzenia w Grafanie i przez profil zapytań).
- **Zasoby w czasie generowania (potwierdzone):** szczyt dysku 103,1 GB użytych (plan: `diskPeakGb` 96), minimum wolnej
  pamięci węzła 11,8 GiB, szczyt CPU węzła 4,8 rdzenia, zero restartów usług (workery rotują co 24 h przez limit
  czasu Messengera, wyjście 0). Baza po generowaniu: 63 GB.
- Błąd po stronie hosta: o 14:41–14:43 UTC `kubectl` z Maca zgłosił `network is unreachable` (nieosiągalna sieć klienta);
  Job i `generate.sh` nie ucierpiały. Wcześniejsze sformułowanie o chwilowej niedostępności API k3s było błędne.
- Joby `queue-check` i `rate-limit-check` kończą się statusem `Error` także po zakończeniu generowania (widziane
  o 19:40 UTC i później). Przyczyny nie badano; hipoteza z poprzednich przebiegów (wyścig z regułą sieciową) jest nieaktualna,
  bo reguła nadal działa i błędy trwają.

## 3. Kompletność i spójność danych (potwierdzone zapytaniami)

| Obiekt | Wartość |
|---|---|
| produkty (`catalog_product`) | 10 000 191; dokumenty w aliasie `products` (`_count`): 10 000 191 |
| warianty, ceny wariantów, pozycje stanu | po 30 076 881 |
| historia cen | ok. 39,1 mln (oszacowanie `reltuples`) |
| zamówienia | 5 000 000 (4 454 725 `delivered`, 300 054 `cancelled`, 207 203 `returned`, 22 558 `shipped`, 15 460 `paid`) |
| klienci w Keycloak (`shop`) | 1 000 000; konta back-office: 501 (realm `backoffice`) |
| `ordering_customer` | 880 082; tyle samo różnych `customer_id` w zamówieniach |
| promocje / kody rabatowe | 43 / 17 |
| indeks ES | 1 indeks, 4 shardy, 9,9 GB, status green, 0 nieprzypisanych shardów |

Wiersz klienta w `ordering_customer` powstaje tylko dla klienta, który złożył zamówienie (880 082 z 1 mln); pozostałe
ok. 120 tys. istnieje wyłącznie w Keycloak. To zgodne z liczbami; czy to zamierzone, nie sprawdzano w specyfikacji.
Nie wykonano pełnej weryfikacji `demo-data:verify` (limit czasu), więc spójności szczegółowej (np. sum zamówień z liniami,
stanów z ruchami, indeksu z bazą dla próby) nie potwierdzono; to luka do uzupełnienia przed uznaniem profilu za zweryfikowany.

## 4. Wyniki pomiarów: listingi (W1, W2)

Przebieg 1, etap `single`: 900 adresów (każdy raz zimno, potem ciepło), bez równoległości, 1800 próbek, 374 niepowodzenia.
Czas po stronie serwera (API = wywołanie przez Varnish, sklep = HTML strony). Wiersze `cold … miss`:

| Rodzaj | Cel | n | pełnych | p50 | p95 | statusy |
|---|---|---|---|---|---|---|
| dział (department) | API | 7 | 7 | 1,86 s | 2,02 s | 200 |
| strona głęboka działu | API | 48 | 26 | 1,84 s | 2,03 s | 200: 26, **503: 22** |
| strona główna | API | 1 | 0 | – | – | **503** |
| strona głęboka głównej | API | 48 | 0 | – | – | **503: 48** |
| liść kategorii | API | 39 | 39 | 1,02 s | 1,21 s | 200 |
| liść z filtrem | API | 46 | 46 | 1,06 s | 1,22 s | 200 |
| fraza | API | 18 | 10 | 1,23 s | 2,03 s | 200: 10, **503: 8** |
| fraza, strona głęboka | API | 38 | 24 | 1,14 s | 1,97 s | **503: 14** |
| fraza z filtrem | API | 42 | 31 | 1,20 s | 1,94 s | **503: 11** |
| liść / liść z filtrem | sklep | 40 / 49 | 40 / 49 | 1,13 s / 1,13 s | 1,35 s / 1,38 s | 200 |
| strona główna i głęboka | sklep | 49 | 0 | – | – | **500: 49** |

- **Potwierdzone (W1):** zimny listing działa w 1,0–1,9 s p50 (wymaganie: p50 200 ms, p95 500 ms, p99 1000 ms), czyli
  5–10 razy wolniej. Z 287 zimnych listingów API 104 (36%) zwróciło 503; sklep zwrócił 500 w 96 z 300.
- **Potwierdzone (przyczyna 503/500):** log API: `ServiceUnavailableHttpException` w `ListProductsController` z
  `SearchUnavailable`: „Elasticsearch is unreachable (search): Idle timeout reached for http://elasticsearch:9200/products/_search”.
  Odpowiedzi z błędem mają czas ok. 2,0 s, czyli limit czasu odpowiedzi klienta ES w API; klient nie ponawia żądania
  (komentarz w `ElasticsearchClientFactory`). 500 sklepu to błąd wywołania API z kodem 503 (log sklepu:
  `GET /api/v1/products failed with status 503`).
- **Potwierdzone:** strona główna i jej głębokie strony (`page` 2–200) zawsze kończą się 503 w API (49 z 49 zimnych i 49 z 49 ciepłych) i 500 w sklepie.
- **Potwierdzone (W2, ciepłe):** odpowiedź z Varnisha trwa p50 3 ms (p95 ≤ 7 ms) dla listingów, które kiedyś się powiodły;
  HTML sklepu p50 43–64 ms, p95 65–94 ms (W2: p50 30 ms, p95 100 ms, p99 200 ms: p50 sklepu powyżej wymagania, p95 w normie).
  **Błędy 503 nie są cache'owane**: ten sam adres wołany zaraz po błędzie znów trafia w ES i znów wygasa.
- **Hipoteza:** czas zapytania ES rośnie z liczbą dopasowanych dokumentów i zakresem agregacji (facety) na 10 mln
  produktów (strona główna i duże działy skanują największe zbiory); do sprawdzenia przez `_search` z `profile` i
  porównanie z 1 mln produktów.

## 5. Strony produktów (W3, W4) i wzrost tempa

Etap `single`, strony produktów (150 adresów, `cold … miss`): API p50 32 ms, p95 42 ms, p99 43 ms; sklep (HTML) p50 99 ms,
p95 134 ms, p99 138 ms (W3: 80/250/500 ms, p50 sklepu o ok. 19 ms powyżej, reszta w normie). Ciepłe: API p50 3 ms; sklep
p50 11 ms, p95 17 ms, p99 21 ms (W4: 15/50/100 ms: w normie). Wszystkie 150 odpowiedzi 200.

Przebieg 2, tempo otwarte (stałe tempo, każdy adres raz), strony produktów, czasy po stronie serwera:

| Tempo (req/s) | API p50 / p95 / p99 | sklep p50 / p95 / p99 | pełne / wszystkie |
|---|---|---|---|
| 2 | 30 / 40 / 40 ms | 120 / 150 / 160 ms | 120 / 120 |
| 5 | 30 / 40 / 40 ms | 110 / 130 / 150 ms | 300 / 300 |
| 10 | 30 / 40 / 50 ms | 110 / 150 / 170 ms | 600 / 600 |
| 20 | 30 / 40 / 50 ms | 120 / 160 / 200 ms | 1200 / 1200 |
| 40 | 40 / 50 / 60 ms | 200 / 430 / 510 ms | 2400 / 2400 |

- **Potwierdzone:** strony produktów utrzymały 40 req/s (mieszanka API + sklep) bez błędów; do 20 req/s czasy są płaskie,
  przy 40 req/s p50 sklepu rośnie do 200 ms, a p95 do 430 ms. **Nasycenia nie osiągnięto**: tempa powyżej 40 req/s nie
  sprawdzono. Zużycie przy 40 req/s: sklep do 1,16 rdzenia, API do 1,07, Postgres do 0,76, węzeł do 61% CPU.
- Listingi `leaf` i `leaf_filter` (jedyne rodzaje, które działały w próbie sekwencyjnej), tempo otwarte, osobny przebieg:

| Tempo (req/s) | API pełne / wszystkie | API p50 / p95 | sklep pełne / wszystkie | sklep p50 / p95 |
|---|---|---|---|---|
| 1 | 28 / 28 | 80 ms / 1,26 s | 32 / 32 | 1,27 s / 1,45 s |
| 2 | 11 / 15 | 0,49 s / 1,88 s | 8 / 12 | 1,40 s / 1,86 s |
| 4 | 5 / 19 | 0,54 s / 2,06 s | 1 / 13 | 2,01 s / 2,01 s |
| 8 | 0 / 26 | – | 0 / 23 | – |
| 16 | 0 / 57 | – | 0 / 51 | – |

  Przebiegi przerywały się po przekroczeniu 20% niepowodzeń, więc przy 2–16 req/s wysłano tylko część planowanych
  żądań (np. 15 z 120). **Potwierdzone:** już przy 2 req/s zimnych listingów pierwsze błędy; przy 8 req/s żaden listing
  nie kończy się pełną odpowiedzią. W9 wymaga co najmniej 30 zimnych listingów na sekundę, więc przepustowość zimnych
  listingów jest o rząd wielkości za niska (ok. 1–2 na sekundę).
  Wartość „API p50 80 ms” przy 1 req/s wynika z mieszanki trafień w cache Varnisha i silnika, nie z zimnej ścieżki.
- **Zasoby przy tych przebiegach (potwierdzone, `top.log`):** Elasticsearch do 4,58 rdzenia CPU w przebiegu 1 (3,2 w
  przebiegu 2), pamięć 3,6–4,0 GiB przy limicie 4 GiB (88–98%). Pozostałe usługi poniżej 1,2 rdzenia. W15 (żaden kontener
  powyżej 85% limitu pamięci) jest przekroczone przez Elasticsearch (miara: working set z `kubectl top`, czyli z cache
  plików; udział cache nie jest rozdzielony).

## 6. Współbieżność: skok 100 jednoczesnych żądań (W8)

Przebieg 1, etap `burst`: 3 rundy po 100 adresów z mieszanki (listingi i strony produktów, API i sklep), bez ruchu w tle.

| Runda | listingi pełne / wszystkie | strony produktów pełne / wszystkie |
|---|---|---|
| 1 | 0 / 53 | 47 / 47 |
| 2 | 0 / 54 | 46 / 46 |
| 3 | 0 / 54 | 46 / 46 |

- **Potwierdzone:** żaden z 161 listingów nie zwrócił pełnej odpowiedzi (503 w API, 500 w sklepie); wymaganie W8 to 100 pełnych
  odpowiedzi, najwolniejsza do 2 s. Strony produktów odpowiadały, ale wolno: API p50 0,22–0,37 s, p95 3,8 s; sklep p50 7,4 s,
  p95 7,6–8,6 s (W3 w normie to 80–250 ms). Skok listingów zatem spowalnia także strony produktów (równolegle wysycony ES i CPU).
- **Hipoteza:** czas stron produktów w skoku rośnie, bo żądania czekają na CPU/pulę wątków sklepu (Next.js) i API,
  zajęte obsługą wygasających zapytań do ES; nie rozdzielono, która z tych warstw jest wąskim gardłem.

## 7. Wąskie gardła (stan na teraz)

Potwierdzone:

1. **Zapytania listingowe do Elasticsearcha:** zimny listing 1–2 s, część nie mieści się w limicie czasu API (ok. 2 s) i
   daje 503; strona główna i jej głębokie strony zawsze. Przepustowość zimnych listingów ok. 1–2/s.
2. **Brak ochrony przed błędem w warstwie cache:** 503 nie jest cache'owane, więc każdy powtórzony adres obciąża ES na nowo.
3. **Pamięć Elasticsearcha** na granicy limitu (do 98%) przy obciążeniu listingami.
4. **Generator i narzędzia operacyjne nie skalują się liniowo:** 17 h (W20 6 h), reindeks 7,25 h (W19 60 min), weryfikacja
   `demo-data:verify` > 24 h.

Hipotezy do sprawdzenia: koszt zapytania zależny od liczby dopasowań i facetów; za mały cache plików przy 63 GB bazy i
9,9 GB indeksu; kolejność kolejek w sklepie w skoku; przyczyna wolniejszego reindeksu (390 vs 880 dok./s).

## 8. Co jest poza tym raportem

- Pomiar **publicznego sklepu** (przez Cloudflare, Traefik i TLS) ma osobny cel: wymaga otwarcia sklepu przez właściciela
  (`make open`). Wyniki wewnętrzne z tego raportu są jego dolną granicą czasu serwera i nie zawierają sieci.
- Ścieżki zapisu (W5–W7, W11, W13, W14, W16–W18) i k6.
- Opróżnianie request cache Elasticsearcha między pomiarami (osobne pytanie o to, ile z zimnego czasu daje silnik, a ile
  jego cache).
- Trzy powtórzenia i rozrzut (plan PERF-0 wymaga środkowego z trzech przebiegów dla wyniku obowiązującego).

## 9. Powtarzalność

`make -C infra/k8s measure KUBE_DIR=<katalog> MEASURE_OUT=<katalog poza repo>` (zgoda w rozmowie: operacja i host; pyta
o `tak`). Zmienne: `MEASURE_RATES`, `MEASURE_LISTING_RATES`, `MEASURE_STEP_SECONDS`, `MEASURE_BURST`, `MEASURE_STAGES`
(opis w nagłówku `infra/k8s/scripts/measure.sh`). Job i ConfigMap są usuwane po przebiegu; po obu przebiegach w
namespace `shop` nie zostały obiekty `perf-measure`. Katalog z wynikami: `run1-single-burst/` (etapy `single` i `burst`,
etapy `open-*` tego przebiegu przerwane przy 20% niepowodzeń, mieszanka listingów i produktów, dla porównania),
`run2-open/` (tempo otwarte osobno dla produktów i listingów), `generator/samples.log`, `raw-samples.tgz` (próbki JSONL).
