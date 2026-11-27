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
- **Wykonano później (§10–§13):** trzy powtórzenia całego zestawu (§10), k6 tylko do odczytu (`saturate.js` bez koszyka
  i zamówień, §10), analiza przyczyny kosztu zapytań Elasticsearcha (§11), historia zasobów z Grafany (§12), uzupełnienie
  spójności danych (§3) i tabela W1–W20 (§14).
- **Nie wykonano:** żadnych scenariuszy zapisu (koszyk, zamówienia, panel, ceny, stany; W5–W7, W10–W14, W16–W18):
  `steady.js` zapisuje dane przez koszyk i checkout, a zamówienia nie mają celu sprzątającego, więc wymagają osobnej zgody
  (§13). Zasoby w §2 i §5 pochodzą z `kubectl top` co 15 s (`top.log`) i z próbek co 5 min (`generator/samples.log`),
  historia z Grafany jest w §12.
- Zimne i ciepłe: „zimne” = adres pierwszy raz widziany przez Varnish i ISR sklepu (wiersze `cache=miss`), „ciepłe” =
  ten sam adres natychmiast po zimnym. Request cache Elasticsearcha nie był opróżniany osobno; adresy z mieszanki są unikalne,
  ale listing „po filtrze” może trafić w cache silnika. Nie rozdzielono tych wpływów.
- Próba: §4–§6 opisują przebiegi 1 i 2 (pojedyncze). Wyniki obowiązujące to mediana z trzech przebiegów w §10; te
  trzy zostały wykonane w jednakowych warunkach (ten sam stos, zamknięty sklep, kolejno, odstęp ok. 2 min).

## 2. Generowanie danych (W20) i przebudowa indeksu (W19)

Źródło: `generator/samples.log` (próbki co 5 min: ogon logu Joba, dysk, pamięć, CPU węzła, rozmiar bazy), log Joba.

| Faza | Początek (UTC) | Koniec (UTC) | Czas |
|---|---|---|---|
| produkty, ceny, historia cen, zdjęcia | 11-25 19:41 | ok. 11-25 23:30 | ok. 3,8 h |
| import 1 mln klientów do Keycloak | ok. 23:30 | ok. 00:40 | ok. 1,2 h |
| zamówienia (5 mln) | 11-26 00:44 | ok. 11-26 03:00 | ok. 2,3 h |
| ruchy magazynowe i modele odczytu | ok. 03:00 | 05:35 | ok. 2,6 h |
| `catalog:reindex` (10 000 191 dokumentów) | 05:35 | ok. 12:50 | ok. 7,25 h |
| `demo-data:verify` | ok. 12:50 | 19:40:21 (przerwane) | nieukończone po ok. 6,8 h; przerwał je limit 24 h całego Joba (liczony od 19:40:21 poprzedniego dnia) |

Granice faz są przybliżone (próbki co 5 min, ostatnia linia logu). Pewne są: start 19:40:21, limit 24 h o 19:40:21
(`DeadlineExceeded`) i czas reindeksu z nazwy indeksu (`products_20261126053552075`) oraz postępu (9 886 500 dokumentów
o 12:47).

- **Potwierdzone:** generator danych trwał ok. 17 h do końca reindeksu (W20: do 6 h), a pełny reindeks ok. 7,25 h przy
  ok. 390 dok./s (W19: do 60 min). Ten sam indeks na 1 mln produktów szedł z ok. 880 dok./s (medium).
- **Potwierdzone:** `demo-data:verify` nie skończył w ok. 6,8 h, które zostały mu do limitu 24 h Joba (start weryfikacji ok. 12:50, limit 19:40:21). Limit 24 h dotyczył całego Joba, nie samej weryfikacji. W momencie przerwania działało zapytanie o stany
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
ok. 120 tys. istnieje wyłącznie w Keycloak. **Zgodne ze specyfikacją** (`docs/features/admin-features`, A4: „tylko konta
z zamówieniami”). Dodatkowo: `sum(order_count)` = 3 000 222 = liczba zamówień z `customer_id` (pozostałe 1 999 778 to
zamówienia gości), żaden wiersz nie ma `order_count` < 1, `sum(paid_count)` = 2 879 893.

Pełna weryfikacja `demo-data:verify` nie skończyła się (limit Joba po ok. 6,8 h). Zamiast niej wykonano tanie próbki
(zapytania tylko do odczytu, 2026-11-27); wszystkie **bez rozbieżności**:

| Kontrola | Próba | Wynik |
|---|---|---|
| suma zamówienia vs suma linii | 9 888 zamówień | 0 rozbieżności |
| stany ujemne, rezerwacja ponad stan fizyczny | wszystkie 30 076 881 pozycji | 0 |
| stan vs ostatni ruch magazynowy | 27 827 pozycji | 0 rozbieżności |
| unikalność numerów zamówień | 250 868 | brak duplikatów |
| płatności bez zamówienia | próba | 0 |
| `order_count` klienta vs zamówienia (`owner_type='customer'`) | 666 klientów | 0 rozbieżności |
| produkty z bazy obecne w indeksie ES | 400 id | 400 / 400 |

Granica: to próby losowe, nie dowód pełnej zgodności. Wiadomości w stanie `failed`: nie sprawdzano (W13 nie testowano).

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
   weryfikacja `demo-data:verify` > 6,8 h (przerwana limitem Joba).

Przyczynę kosztu zapytań ES ustalono w §11 (potwierdzone pomiarem). Pozostają hipotezy: kolejność kolejek w sklepie w
skoku; przyczyna wolniejszego reindeksu (390 vs 880 dok./s), przy czym §12 pokazuje, że nic nie było wtedy nasycone
(pętla szeregowa).

## 8. Co jest poza tym raportem

- Pomiar **publicznego sklepu** (przez Cloudflare, Traefik i TLS) ma osobny cel: wymaga otwarcia sklepu przez właściciela
  (`make open`). Wyniki wewnętrzne z tego raportu są jego dolną granicą czasu serwera i nie zawierają sieci.
- Ścieżki zapisu (W5–W7, W10–W14, W16–W18): analiza i propozycja bezpiecznego uruchomienia w §13; nic nie uruchomiono.
- Pomiar z k6 mieszanki z koszykiem i zamówieniami (`steady.js`).

## 9. Powtarzalność

`make -C infra/k8s measure KUBE_DIR=<katalog> MEASURE_OUT=<katalog poza repo>` (zgoda w rozmowie: operacja i host; pyta
o `tak`). Zmienne: `MEASURE_RATES`, `MEASURE_LISTING_RATES`, `MEASURE_STEP_SECONDS`, `MEASURE_BURST`, `MEASURE_STAGES`
(domyślnie `single open burst`; `k6` jest etapem dodatkowym), `MEASURE_K6_RUNS` (opis w nagłówku
`infra/k8s/scripts/measure.sh`). Job i ConfigMap są usuwane po przebiegu; po wszystkich przebiegach w namespace `shop`
nie zostały obiekty `perf-measure`, a reguła `shop-closed` działa. Wyniki w katalogu obok raportu:

- `run1-single-burst/`, `run2-open/`: przebiegi 1 i 2 (§4–§6);
- `repeat-1/`, `repeat-2/`, `repeat-3/`: trzy powtórzenia całego zestawu (§10; `*.txt` = raporty, `top.log` = zasoby co 15 s,
  `measure.log`), `raw-repeats.tgz` = próbki JSONL;
- `diagnostics/`: skrypty i notatki analizy Elasticsearcha i Grafany (§11–§12);
- `generator/samples.log`, `raw-samples.tgz` (przebiegi 1 i 2).

## 10. Trzy powtórzenia i k6 (obowiązujące wyniki)

Trzy pełne przebiegi (11:37, 12:14 i 12:54 UTC, 2026-11-27) tego samego zestawu: `single` (500 adresów z mieszanki, zimno
i ciepło), tempo otwarte stron produktów (2–40 req/s, po 60 s) i listingów (1–16 req/s), skok 100 (3 rundy), k6 odczytu.
Każdy przebieg to osobny Job i osobny losowy zestaw adresów, ten sam stos. W tabelach: wartości z trzech przebiegów.

**Zimne i ciepłe** (czasy po stronie serwera, tylko pełne odpowiedzi 200; `n` błędnych podane osobno):

| Pomiar | przebieg 1 | przebieg 2 | przebieg 3 | Wymaganie |
|---|---|---|---|---|
| zimny listing, liść (API) p50 / p95 | 1,06 / 1,17 s | 1,08 / 1,29 s | 1,06 / 1,20 s | W1: 200 / 500 ms |
| zimny listing, liść z filtrem (API) p50 / p95 | 0,82 / 1,13 s | 1,04 / 1,23 s | 0,59 / 1,18 s | W1 |
| zimny dział (API) p50 | 1,61 s | 1,63 s | 1,77 s | W1 |
| zimne listingi (API): błędy 503 | 36 / 150 | 50 / 150 | 33 / 150 | 0 |
| zimne listingi (sklep): błędy 500 | 38 / 150 | 53 / 150 | 32 / 150 | 0 |
| strona główna (API), 49 zimnych + 49 ciepłych | 100% 503 | 100% 503 | 100% 503 | 0 |
| ciepły listing (API) p50 / p95 | 3 / 4 ms | 3 / 4 ms | 3 / 4 ms | W2: 30 / 100 ms |
| ciepły listing (sklep) p50 / p95 | 37–48 / 52–73 ms | 47–56 / 67–95 ms | 37–51 / 53–63 ms | W2 |
| zimna strona produktu (sklep) p50 / p95 / p99 | 92 / 123 / 144 ms | 95 / 129 / 147 ms | 92 / 119 / 143 ms | W3: 80 / 250 / 500 ms |
| zimna strona produktu (API) p50 | 30 ms | 30 ms | 30 ms | – |
| ciepła strona produktu (sklep) p50 / p95 / p99 | 11 / 19 / 23 ms | 11 / 21 / 27 ms | 10 / 18 / 21 ms | W4: 15 / 50 / 100 ms |
| niepowodzenia z 1000 żądań | 140 | 195 | 125 | 0 |

Rozrzut: czasy odpowiedzi poprawnych są powtarzalne (zimny liść p50 1,06–1,08 s, strona produktu 92–95 ms). Zmienny jest
**udział błędów** (125–195 z 1000), bo zależy od tego, ile ciężkich adresów (dział, fraza, strona główna) wylosowała
mieszanka; liczbę błędów podajemy jako zakres, nie jako jedną liczbę.

**Tempo otwarte** (żądania niezależne od odpowiedzi; `achieved_send_rate` = zadane, `late_over_100ms` = 0 we wszystkich):

| Tempo | przebieg 1 | przebieg 2 | przebieg 3 |
|---|---|---|---|
| strony produktów 2 / 10 / 40 req/s: błędy | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| sklep 40 req/s p50 / p95 / p99 | 183 / 581 / 704 ms | 197 / 497 / 666 ms | 181 / 416 / 720 ms |
| listingi 1 req/s: błędy; API zimne p50 | 0; 0,63–0,81 s | 0; 0,97–1,06 s | 0; 0,60–1,09 s |
| listingi 2 req/s: wysłano / planowano, błędy | 24 / 120, 9 (przerwane) | 38 / 120, 12 (przerwane) | 27 / 120, 9 (przerwane) |
| listingi 4 req/s: wysłano / planowano, błędy | 31 / 240, 27 (przerwane) | 31 / 240, 30 (przerwane) | 34 / 240, 27 (przerwane) |

Powtarzalne: strony produktów do 40 req/s bez błędu (p95 sklepu 0,42–0,58 s); listingi w 3 z 3 przebiegów **tracą
pełną odpowiedź już przy 2 req/s**, przy 4 req/s 87–97% żądań kończy się błędem. Przebieg listingów przerywa się po
przekroczeniu 20% niepowodzeń (potwierdzone w `run:` / `ABORTED`, §15).

**Skok 100 żądań** (3 rundy, §6): listingi pełne 0 / 157, 0 / 153, 0 / 159; strony produktów pełne 143 / 143, 147 / 147,
141 / 141. Strona produktu w skoku: API p50 0,18–0,20 s, p95 3,7–4,0 s; sklep p50 6,2–6,9 s, p95 8,4–9,0 s.

**k6** (`load/saturate.js`, tylko odczyty; Job w klastrze, obraz k6 jak w `load/k6.sh`; zasoby poda k6 ≤ 0,3 rdzenia):

| Mieszanka | przebieg 1 | przebieg 2 | przebieg 3 |
|---|---|---|---|
| odczyt (strona główna 10, kategoria 20, produkt 35, wyszukiwanie 25), krok 10/s | **przerwane po kroku 0**: 5xx 12,5% (2 z 16) | przerwane: 5xx 30,4% (7 z 23) | przerwane: 5xx 13,5% (5 z 37) |
| w tym strona główna / kategoria / wyszukiwanie | 100% błędów | 100% błędów | 100% błędów |
| w tym produkt | 0% błędów, p50 130 ms, p95 1,3 s | 0% błędów | 0% błędów, p50 119 ms |
| tylko produkt, 10 → 190 req/s (kroki co 20, po 30 s) | 0% błędów, p95 6–9 ms, nienasycone | 0%, p95 6–11 ms, nienasycone | 0%, p95 6–9 ms, nienasycone |

- **Potwierdzone:** mieszanka odczytu W10 (listing 60% + produkty) przerywa się już w pierwszym kroku 10 req/s, bo
  strona główna, kategoria i wyszukiwanie kończą się 5xx (p95 ok. 4,2 s, czyli czas do limitu). Wymagane jest 150 stron/s
  przez 30 min.
- **Ograniczenie:** rampa „tylko produkt” używa 60 stron z sitemapy, więc po pierwszych żądaniach odpowiada z Varnisha
  (p50 4 ms). Pokazuje nośność Varnisha i `varnish → klient` (do 190 req/s bez błędu, nienasycone, k6 nie zużył więcej
  niż 0,3 rdzenia), nie zimnej ścieżki strony produktu. Zimną ścieżkę (W3) mierzy `single` i tempo otwarte z unikalnymi adresami.

**Zasoby (maksima z `top.log`, 15 s):**

| Usługa | przebieg 1 | przebieg 2 | przebieg 3 |
|---|---|---|---|
| Elasticsearch CPU (rdzenie) / pamięć | 3,80 / 3966 MiB | 3,90 / 4057 MiB | 4,31 / 4084 MiB |
| API CPU / pamięć | 1,16 / 207 MiB | 1,04 / 216 MiB | 1,07 / 217 MiB |
| sklep CPU / pamięć | 1,11 / 522 MiB | 1,09 / 546 MiB | 1,11 / 538 MiB |
| Postgres CPU / pamięć | 0,67 / 318 MiB | 0,71 / 309 MiB | 0,74 / 312 MiB |
| Varnish CPU / pamięć | 0,05 / 148 MiB | 0,05 / 168 MiB | 0,05 / 176 MiB |
| pod pomiarowy (baseline.py) | 0,53 / 73 MiB | 0,53 / 82 MiB | 0,50 / 133 MiB |

Elasticsearch jest jedyną usługą blisko limitu (pamięć 3966–4084 MiB przy limicie 4096 MiB; CPU 3,8–4,3 rdzenia z 8). Pod
pomiarowy zużywa ok. 6% CPU węzła, więc nie zniekształca wyników.

## 11. Przyczyna kosztu zapytań Elasticsearcha (strona główna i listingi)

Zmierzono bezpośrednio na indeksie `products_20261126053552075_f3e89f` (4 shardy po 2,4 GB, 9,6 GB; 88,9 mln dokumentów
Lucene = 10,0 mln produktów + 30,1 mln wariantów (nested) + ok. 48,7 mln par opcji (nested)), `request_cache=false`,
3 powtórzenia, spokojny klaster. Zapytanie listingu: `apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php`
(filtr `status=published`, opcjonalnie `category_ancestor_ids`, sortowanie po nazwie, `track_total_hits: true`, agregacje
`available`, `price`, `free`, opcjonalnie `categories`). Skrypty i surowe wyniki: `diagnostics/`.

Czas jednego zapytania z kolejnymi agregacjami (sekundy, zakres z 3 powtórzeń):

| Zapytanie (liczba trafień) | bez agregacji | + `available` | + `price` | + `free` | wszystkie |
|---|---|---|---|---|---|
| strona główna (9 800 130) | 1,1–1,3 | 1,9–2,0 | 2,1–2,2 | 4,0–4,3 | 5,2–6,1 |
| duży dział (1 520 000) | 0,25–0,66 | 0,9–1,2 | 1,24 | 1,55 | 1,9 |
| mediana liścia (102 794) | 0,94–0,97 | 0,94–1,03 | 1,03–1,16 | 1,15–1,26 | 1,15–1,24 |

Odczyt z urządzenia na jedno zapytanie (`fs.io_stats` węzła): liść bez agregacji 1006 MiB (411 ms; to samo zapytanie
powtórzone: 132 MiB, 59 ms); liść ze wszystkimi agregacjami 3059 MiB (1086 ms); strona główna bez agregacji 3436 MiB
(1226 ms); strona główna ze wszystkimi 4122 MiB (5350 ms).

Profil strony głównej (shard 0, `profile`, całość 7,7 s z narzutem profilu): `QueryPhaseCollector` 6,3 s;
`available` 1,1 s; `price` 1,3 s (min 0,72 + max 0,60); **`free` 3,8–5,7 s** (terms po osiach par 5,0 s, po wartościach
2,4 s, dwa `reverse_nested` do produktu po ok. 0,9 s); samo zapytanie (`term status`, `match_all`) 0,3–0,5 s.

**Potwierdzone (pomiar):**

1. Koszt rośnie z liczbą dopasowanych dokumentów i z agregacjami po zagnieżdżonych wariantach i parach. Strona główna
   (9,8 mln trafień) jest 4–5 razy droższa niż liść; sama agregacja `free` (facet opcji) to ok. 2/3 jej czasu.
2. Każdy zimny listing czyta z urządzenia 1–4 GiB (tabela). Dane Lucene nie mieszczą się w cache plików: cgroup
   Elasticsearcha ma limit 4 GiB, z czego heap to 2 GiB, a cache plików zajmuje resztę (zużycie 4 290 830 336 B z limitu
   4 294 967 296 B, wolne 3 MiB węzła) przy indeksie 9,6 GB.
3. Silnik nie jest przeciążony kolejką: pula `search` 13 wątków, kolejka 0, odrzuconych 0 (19 593 ukończonych), cache
   zapytań 156 MB (trafienia 14 223, chybienia 626 452, 0 usunięć), request cache 0 trafień / 8 chybień, GC: 653 młodych
   (6,3 s) i 0 starych, 29–30 segmentów na shard.
4. **503 to nie „sam timeout”:** timeout klienta w API (ok. 2 s) odcina zapytania, których koszt (powyżej) przekracza
   2 s; strona główna zawsze, duże działy i frazy często. Liść mieści się w 1,0–1,2 s i przechodzi.

**Wniosek z pomiaru, ale z założeniem (do potwierdzenia w PERF-2 B):** zapytanie jest ograniczone przez I/O i CPU
agregacji nested, nie przez konfigurację timeoutów. Czas listingu zależy od czterech składników: liczby trafień,
agregacji `free`, agregacji `available`/`price` nad wszystkimi wariantami i nasycenia cache plików. Nie rozdzielono, ile
z 5,2–6,1 s strony głównej znika przy większym cache plików; to pytanie dla PERF-1 (test przy większym limicie pamięci)
i dla PERF-2 B, które i tak wymaga pomiaru przy docelowym limicie (kryterium 2 specyfikacji).

## 12. Historia zasobów w Grafanie (generowanie, indeksowanie, testy)

Okno 2026-11-25 19:40 – 2026-11-26 21:40 UTC, krok 5 min, źródło `grafanacloud-prom` (średnia / maksimum; CPU w rdzeniach,
I/O w MB/s). **Ograniczenie:** w Grafanie Cloud nie ma metryk JVM, GC, pul wątków ani cache Elasticsearcha, metryk
PostgreSQL ani `node_disk_*` i dławienia CFS (dostępne: `container_*`, `node_cpu`, `node_memory`, `node_filesystem`,
`kube_*`). Wartości JVM, GC, kolejek i cache pochodzą ze zrzutu `_nodes/stats` z 2026-11-27 (§11), nie z historii.

| Faza | CPU Postgres | I/O Postgres (odczyt / zapis) | Pozostałe |
|---|---|---|---|
| produkty (3,8 h) | 1,00 / 2,31 | 113 / 191 odczyt, 186 / 405 zapis | iowait węzła 0,14 / 0,23; generator 0,25 / 0,80 |
| klienci (Keycloak) | 0,33 / 0,97 | 12 / 143, 22 / 173 | węzeł user 0,50 (import) |
| zamówienia | 1,25 / 1,38 | 180 / 209, 39 / 49 | iowait 0,19: **odczyt bazy ogranicza** |
| modele odczytu | 0,57 / 0,88 | 58 / 79, 137 / 352 | – |
| reindeks 05:40–12:45 | 0,31 / 0,33 | 37 / 42 | ES CPU 0,14 / 0,52, pamięć 3921 MiB, generator 0,11, iowait 0,06 |
| `verify` 13:00–19:35 | 0,29 / 0,74 | 67 / 157 | iowait 0,09 |
| bench 1 (20:43–21:13) | – | – | ES CPU 2,92 / 3,20, dysk ES odczyt 1383 / 1573 MB/s, iowait 0,05 |
| bench 2 (21:21–21:36) | – | – | ES CPU 0,64 / 0,97, dysk ES odczyt 606 / 827 MB/s |

- **Potwierdzone:** w czasie reindeksu (7,25 h) nic nie było nasycone: Postgres 0,3 rdzenia, ES 0,14, generator 0,11,
  iowait 0,06. To szeregowa pętla (390 dok./s), a nie zasób; ma to znaczenie dla PERF-6 (W19).
- **Potwierdzone:** zamówienia były ograniczone odczytem dysku bazy (180–209 MB/s), produkty zapisem (do 405 MB/s).
- **Potwierdzone:** w teście listingów ES czytał z dysku ok. 1,4–1,6 GB/s (§11, cache plików nie mieści indeksu).
- Wartość `iowait` węzła w testach (0,05) jest niska, bo czytanie z urządzenia nie blokuje rdzeni, tylko wydłuża zapytania.

## 13. Scenariusze zapisu: analiza i bezpieczne uruchomienie (nic nie uruchomiono)

Brak pomiaru: W5, W6 (koszyk, zamówienie), W7 (panel), W10–W12, W13, W14, W16–W18 (zapis, ceny, stany, przeniesienie
działu). Powody, dla których nie uruchamiamy ich na worf.pl bez osobnej zgody:

- **Dane produkcyjne demo nie mają jak wrócić.** Zamówienia dopisują się do 5 mln i zmieniają `ordering_customer`,
  stany i modele odczytu; cel sprzątający dla zamówień nie istnieje, a `make restore` jest ciężki (baza 63 GB, wolne
  ok. 104 GB, więc kopia fizyczna się nie mieści). Zmiana cen i stanów (W11, W16, W17) i przeniesienie działu
  (W18, 20% katalogu) przebudowują indeks i wywołują zdarzenia.
- **Powtarzalność linii bazowej:** każdy zapis zmienia stan, na którym stoją pomiary odczytu (§10).
- `steady.js` zawiera koszyk i checkout, więc nie jest uruchamiany; `saturate.js` w trybie odczytu jest bezpieczny
  (§10).

**Propozycja (do osobnej akceptacji, nic nie robimy bez niej):**

1. Pomiary zapisu W5–W6, W10–W14 i W16–W18 wykonać **lokalnie na `server` albo `medium`** (k3d), tak jak ustala plan
   PERF-0 (§„Pomiar”): tam można odtworzyć dane po każdym przebiegu (`make seed`/restore bez ryzyka dla serwera).
2. Jeśli właściciel chce zapisów na worf.pl: osobna zgoda nazywająca operację i hosta; wyłącznie na oznaczonych danych
   testowych (osobne konta klientów i warianty z prefiksem, które da się usunąć albo które nie wchodzą do pomiarów
   odczytu); wykonywane po komplecie pomiarów odczytu (żeby nie psuć powtarzalności); szacowany przyrost: 20 zam./s przez
   30 min = 36 000 zamówień (0,7% z 5 mln), a koszyki i płatności proporcjonalnie; zakończenie sprawdzeniem W13 (stany,
   sumy, indeks, 0 wiadomości `failed`) i zapisaniem, ile obiektów dodano.
3. Przed pierwszym zapisem: dodać cel sprzątający dla danych testowych albo ustalić, że rekordy zostają i są liczone w
   skali (decyzja właściciela).

## 14. Tabela W1–W20: wymagania i wyniki

Status: **PASS** = spełnione zmierzonym wynikiem, **FAIL** = zmierzone i niespełnione, **NOT TESTED** = nie zmierzono
(powód podany). Pomiary wewnątrz klastra (dolna granica czasu serwera, §1).

| Req. | Wymaganie (p50 / p95 / p99) | Wynik rzeczywisty | Status |
|---|---|---|---|
| W1 | listing zimny 200 / 500 / 1000 ms | liść p50 1,06 s, p95 1,17–1,29 s; duży dział 1,6–1,8 s; strona główna zawsze 503; 22–33% zimnych listingów to błędy (§10) | **FAIL** |
| W2 | listing ciepły 30 / 100 / 200 ms | API p50 3 ms, p95 4 ms (PASS); HTML sklepu p50 37–56 ms, p95 52–95 ms (p50 powyżej 30 ms) | **FAIL** (sklep, p50) |
| W3 | produkt zimny 80 / 250 / 500 ms | sklep p50 92–95 ms, p95 119–129 ms, p99 143–147 ms; API p50 30 ms | **FAIL** (p50 o 12–15 ms) |
| W4 | produkt ciepły 15 / 50 / 100 ms | sklep p50 10–11 ms, p95 18–21 ms, p99 21–27 ms | **PASS** |
| W5 | koszyk 50 / 150 / 300 ms | zapisy nie uruchamiane (§13) | NOT TESTED |
| W6 | złożenie zamówienia 150 / 500 / 1000 ms | j.w. | NOT TESTED |
| W7 | panel: listy, wyszukiwanie, filtry 100 / 500 / 2000 ms | panel nie mierzony (PERF-7 ma własny pomiar) | NOT TESTED |
| W8 | skok 100 zimnych wejść: 100 pełnych odpowiedzi, najwolniejsza ≤ 2 s | listingi pełne 0 / 157, 0 / 153, 0 / 159; strony produktów w tym czasie p50 6,2–6,9 s (sklep) | **FAIL** |
| W9 | zimne listingi ≥ 30/s przez 30 min | błędy od 2/s, przy 4/s 87–97% błędów (3 z 3 przebiegów); 30 min nie uruchamiano, bo brak 1 min bez błędów przy 2/s | **FAIL** |
| W10 | ruch mieszany ≥ 150 stron/s przez 30 min | k6 odczytu przerwany w kroku 10/s (5xx 12,5–30%); koszyk i zamówienia nie mierzone | **FAIL** (odczyt) |
| W11 | zamówienia ≥ 20/s + 50 zmian/s + panel | zapisy nie uruchamiane | NOT TESTED |
| W12 | 2 × przepustowość przez 5 min, powrót w 60 s, 0 restartów | brak przepustowości zapisów; odczytowo 0 restartów, brak przeciążenia 2× | NOT TESTED |
| W13 | zgodność po zapisach, 0 `failed` | próbki spójności danych po generowaniu bez rozbieżności (§3); po zapisach nie mierzono | NOT TESTED |
| W14 | bieg 2 h przy 70% z zapisami | nie uruchamiano | NOT TESTED |
| W15 | CPU węzła ≤ 70%, iowait ≤ 5%, kontener ≤ 85% pamięci | węzeł: CPU do 61% (pomiar), iowait 0,05 (PASS); Elasticsearch 3966–4084 MiB z 4096 MiB = 97–100% (miara: working set z cache plików) | **FAIL** (ES) |
| W16 | cena/stan widoczne p95 10 s, p99 60 s | nie uruchamiano | NOT TESTED |
| W17 | 100 tys. zmian cen w 15 min | nie uruchamiano | NOT TESTED |
| W18 | przeniesienie działu w 30 min, W1 w trakcie | nie uruchamiano | NOT TESTED |
| W19 | pełny reindeks ≤ 60 min | 7,25 h (390 dok./s) | **FAIL** |
| W19a | zapis w trakcie przebudowy obecny w nowym indeksie | znane niespełnione (audyt, P13); nie testowano | NOT TESTED |
| W20 | generator `server` ≤ 6 h | ok. 9,9 h do reindeksu, ok. 17 h razem z reindeksem; zero restartów, szczyt dysku 103,1 GB | **FAIL** |

Bilans: PASS 1 (W4), FAIL 9 (W1, W2, W3, W8, W9, W10, W15, W19, W20; W2 i W3 tylko o niewielki margines), NOT TESTED 11
(W5–W7, W11–W14, W16–W18 i W19a). W2 i W3 to p50 sklepu (Next.js), nie API.

## 15. Rzetelność narzędzia pomiarowego

Sprawdzono, czy `load/baseline.py` i k6 poprawnie raportują błędy, tempo i przerwania, a nie tylko czasy udanych odpowiedzi:

- **Błędy HTTP:** każdy status poza 2xx to niepowodzenie (kolumna `statuses`, liczniki `failures`, `by_status`); kolumny
  `n` (wysłane) i `full` (pełne odpowiedzi 200) są osobno, a percentyle liczone są z pełnych. Wiersz z samymi błędami
  daje `None`, nie zaniżony czas (np. strona główna: `n=49, full=0`).
- **Rzeczywiste tempo:** plik `<wynik>.meta.json` i wiersz `run:` podają `planned`, `sent`, `finished`, `aborted`,
  `target_rate`, `achieved_send_rate` i `late_over_100ms` (opóźnienie wysyłki modelu otwartego). We wszystkich
  przebiegach `late_over_100ms=0` i `achieved_send_rate` równy zadanemu (2, 10, 40), więc czasy nie są zaniżone przez
  koordynowaną omyłkę (żądania wysyłane w terminie, niezależnie od odpowiedzi).
- **Przerwane scenariusze:** przy ponad 20% niepowodzeń przebieg kończy się `ABORTED`, kodem wyjścia 1 i liczbą wysłanych z
  planowanych (np. „stopped after 24 of 120 requests”); 9 z 9 przebiegów listingów 2–4 req/s w trzech powtórzeniach
  ujawniło to poprawnie. k6 podaje `5xx/net` dla każdego kroku, `saturated: step 0 (p95)` i `dropped iterations`.
- **Ścieżka błędu:** pierwsze uruchomienie z brakującym `load/baseline.py` i z 503 na próbkowanym listingu wykryło dwa
  błędy mechanizmu (pusty ConfigMap bez komunikatu; przerwanie całego Joba przez jedno 503), oba naprawione i sprawdzone
  ponownie (PR mechanizmu).
- **Granice:** czasy ze strony serwera, bez sieci i TLS; k6 bez koszyka; 60 stron w rampie „tylko produkt” oznacza
  pomiar Varnisha (§10).

## 16. Proponowana kolejność istniejących etapów PERF

Wynika z zmierzonych wąskich gardeł, według planu „Kolejność” (`docs/features/performance-program/plan.md`). Nie dodajemy
nowych epików: każda z poniższych luk jest już w zakresie istniejącego etapu.

1. **PERF-2 etap B (model wyszukiwania) razem z PERF-1 (zasoby i pamięć)**, równolegle (bez wspólnych plików): to
   rozwiązuje główne FAIL-e W1, W8, W9, W10, W15 (§11). Mierzone: koszt `free`, `available`, `price` nad wariantami i
   par; ES z cache plików mniejszym niż indeks. Kryterium 2 specyfikacji PERF-2 B już wymaga pomiaru przy docelowym limicie
   pamięci i jednym shardzie. PERF-1 dostaje z tego raportu decyzje: limit pamięci ES względem 9,6 GB indeksu, limity
   zapytań, I/O Postgresa.
2. **PERF-7 (zapytania panelu)** równolegle (niezależne pliki): W7 jest NOT TESTED; zapytania `verify`/panelu na 10–30 mln wierszy
   były ograniczone I/O (§2).
3. Po wdrożeniu 1: **ponowny pomiar na worf.pl tym samym `make measure`** (§9), potem **PERF-3 (izolacja)**: 503 nie
   cache'owane, błąd ES nie powinien dawać 500 strony ani spowalniać stron produktów (skok 100: sklep p50 6–7 s).
4. **PERF-6 (async, reindeks)**: reindeks 7,25 h przy braku nasycenia zasobów (§12), czyli problem pętli szeregowej;
   W19 i W20.
5. **PERF-4, PERF-8, PERF-5** według planu, pomiar zapisów lokalnie (§13).
6. **PERF-9** (odbiór): komplet W1–W20 po zakończeniu powyższych.

Ten raport nie rozstrzyga decyzji PERF-1 o zasobach ani nie zmienia konfiguracji; to pozycje wejściowe dla tych etapów.
