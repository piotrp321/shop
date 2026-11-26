# PERF-2 etap A: model wyszukiwania i facetów na 10 mln produktów (2026-11-25)

Eksperyment z [`docs/features/performance-program/plan.md`](../features/performance-program/plan.md) (PERF-2, etap A),
rewizja `ef6f6473`. Nie zmienia aplikacji, worf.pl ani ASH. Skrypty i surowe wyniki:
[`perf-2-search-model-2026-11-25/`](perf-2-search-model-2026-11-25/) (`scripts/`, `results/`).

Oznaczenia: **[POMIAR]** wynik z tego eksperymentu, **[SZACUNEK]** przeliczenie pomiaru lokalnego na serwer,
**[KOD]** fakt z kodu repozytorium.

## 1. Wynik

**Rekomendacja do akceptacji: model R („pola produktu, `nested` tylko dla ceny łączonej z opcją”)**, opisany
w sekcji 3.

Na 10 mln produktów, na tej samej mieszance 294 listingów, bez żadnego cache [POMIAR, 8 rdzeni Maca]:

| | obecny model | model R | zmiana |
|---|---:|---:|---:|
| CPU Elasticsearcha na listing (mieszanka) | 818 ms | 157 ms | 5,2 razy mniej |
| przepustowość przy 8 równoległych | 8,7 zapytań/s | 48,3 zapytań/s | 5,6 razy więcej |
| skok 100 jednoczesnych listingów, najwolniejsza odpowiedź | 11,5–16,0 s | 1,9–3,3 s | |
| cały katalog bez filtrów, CPU / czas | 5800 / 5629 ms | 708 / 221 ms | |
| dział (2 mln produktów), CPU / czas | 1224 / 1217 ms | 163 / 44 ms | |
| liść (233 tys.), CPU / czas | 203 / 201 ms | 35 / 7 ms | |
| dokumenty Lucene na produkt | 8,9 | 4,0 | |
| rozmiar indeksu 10 mln | 9,5 GB | 8,5 GB | |

Wyniki modelu R są identyczne z obecnym modelem (liczba trafień, fasety kategorii, dostępności, cen i opcji, pierwsza
strona) dla 26 scenariuszy i całej mieszanki 294 listingów na 10 mln, 0 różnic. Zakres funkcjonalny bez zmian: fasety
na każdym listingu, filtry wariantu wszędzie, dokładne liczby, warunek „ten sam wariant”, sortowania, stronicowanie.

**Czego model R nie zamyka:** budżetu zimnej ścieżki z W8 i W9 na obecnym serwerze (sekcja 10). Dokładne fasety liczone
przy każdym żądaniu kosztują liniowo w liczbie pasujących produktów; dla całego katalogu 10 mln to ok. 0,6–0,7 s CPU
Maca, czyli ok. 2 s CPU serwera [SZACUNEK], w każdym zbadanym modelu liczonym na żywo. Zamknięcie tej różnicy wymaga
decyzji właściciela opisanych w sekcji 11, a nie innego modelu indeksu.

## 2. Środowisko i metoda

- **Elasticsearch:** istniejący kontener `es-audit`, ES 9.4.0, `--cpus=8`, heap 2 GiB. Limit pamięci kontenera
  podniosłem z 4 do 10 GiB (`docker update`), żeby gorące dane indeksu 10 mln mieściły się w cache plików (stan
  ustalony z planu, „Rodzaje cache”). Wynik przy limicie 4 GiB nie był mierzony; to decyzja PERF-1.
- **Zasoby przed generowaniem:** 173 GB wolnego dysku, 12 GiB wolnej pamięci Dockera; trzy stosy innych sesji działały
  bezczynnie. Sesja PERF-0 wstrzymała lokalne obciążenia na czas pomiarów.
- **Dane 10 mln:** indeks `medium` (1 000 191 produktów z prawdziwego generatora) zapisany 10 razy z nowymi
  identyfikatorami (`scripts/mk.py`): 10 001 910 produktów, 9 800 830 opublikowanych. Profil `server` różni się od
  `medium` wyłącznie licznościami [KOD `apps/api/fixtures/demo-data/profiles.yaml`], więc rozkłady (180 kategorii,
  21 osi, 208 wartości opcji, 1–8 wariantów, do 2 opcji na wariant) są te same. Skutek uboczny: każda nazwa występuje
  10 razy.
- **Indeksy:** `c10` – obecny mapping (88,9 mln dokumentów Lucene); `h10` – nadzbiór pól wszystkich kandydatów
  (40,1 mln); `r10` – sam model R z sortem indeksu (40,1 mln). Odpowiedniki 1 mln: `t_orig1` (z audytu), `h1`, `r1`.
- **Zapytania:** obecny model to `esq.py` z audytu (port 1:1 `ProductSearchQuery.php`); kandydaci w
  `scripts/models.py`. Każde żądanie z `request_cache=false`, o ile nie zaznaczono inaczej.
- **CPU na zapytanie:** z licznika cgroup kontenera (`cpu.stat`, `scripts/cpu.py`), nie z `took`. To istotne:
  ES 9 liczy agregacje pól produktu równolegle na segmentach jednego sharda, więc `took` bywa 3–5 razy mniejszy niż
  zużyty CPU (np. 8 ms czasu i 42 ms CPU). Audyt porównywał `took`; tutaj miarą modelu jest CPU.
- **Mieszanka:** 294 różne listingi wygenerowane z danych (`scripts/mix.py`, `results/mix.json`): cały katalog 4,1%
  i z filtrami 6,1%, dział 12,2% i 8,2%, kategoria środkowa 10,2% i 6,1%, liść 20,4% i 12,2%, fraza 14,3% i 4,1%,
  głęboka strona 2%. Filtry: jedna opcja, dwie wartości osi, dwie osie, cena, dostępność i ich kombinacje; 30%
  sortowań po cenie, 20% stron 2–5. **Mieszanka jest prowizoryczna**; oficjalną definiuje PERF-0. Koszt na klasę
  adresów (sekcja 5) pozwala przeliczyć wynik dla innej mieszanki.
- **Przeliczenie na serwer:** to samo zapytanie (obecny model, 1 mln, 1 shard, cały katalog) trwało na worf.pl
  1,64 s (1,86 s TTFB minus 0,22 s adresu bez wyszukiwarki, raport `search-shards-worf-2026-11-25.md`), a tu
  0,53–0,57 s [POMIAR]. Stosunek **2,9** to górna granica (czas serwera zawiera też API); audyt przyjmował 2,5–3,5.
  Przebiegi z `--cpus=3` przybliżają pojemność całego serwera oddanego Elasticsearchowi (3 × 2,9 ≈ 8,7 vCPU), ale nie
  czas pojedynczego zapytania na wolniejszym rdzeniu.
- **Ograniczenia:** przebiegi współbieżne są pojedyncze (skok: 3 rundy), nie trzy z medianą. Koszt tego samego
  zapytania zmieniał się do ok. 25% z układem segmentów (cały katalog w modelu R: 566 ms CPU na świeżym `h10`,
  660–790 ms po scaleniu segmentów). Porównania między modelami robiłem w tych samych warunkach.

## 3. Kandydaci

| Kandydat | Opis | Wynik |
|---|---|---|
| `cur` | obecny model: `nested` wariantów i par, `reverse_nested` | punkt odniesienia |
| `flat3` | alternatywa z audytu: pola produktu, gdy agregacja nie ma warunku na wariant; inaczej `nested` jednego poziomu | 2,3 razy mniej CPU niż `cur`; zdominowany |
| `pl` | każdy warunek bez zakresu cen jako zapytanie `term` na polu produktu; jedno żądanie | 2,7 razy mniej; zdominowany |
| **`plm` = model R** | `pl`, agregacje pogrupowane według zestawu warunków w jednym `_msearch` | **4,6 razy mniej na `h10`, 5,2 razy na `r10`** |
| wariant jako osobny dokument | płaski dokument na wariant, produkty liczone `cardinality` | niedokładny i 2–20 razy droższy; odrzucony |
| ustawienia silnika | shardy, równoległość segmentów, sort indeksu, `filters` zamiast `terms`, pole łączone | sekcja 6 |
| fasety osobnym zapytaniem z natywnym request cache | część struktury `plm` | sekcja 7 |
| materializowane liczniki | tylko gdy liczenie na żywo nie spełni budżetu | nie budowane; koszt zmierzony, sekcja 11 |

### Model R

Opiera się na dwóch regułach domeny [KOD]: produkt ma najwyżej 2 osie (`VariantAxes::MAX_AXES = 2`), a krotki opcji
wariantów jednego produktu są unikalne. Dzięki temu każdy warunek „istnieje wariant z opcjami X (i do kupienia)” jest
dokładnie równoważny obecności jednego tokenu w polu produktu.

**Dokument** (mapping w `scripts/mk.py`, rodzaj `r`):

- `variants` jako `nested` jednego poziomu: `gross`, `purchasable`, `options`, `axes`; `variants.pairs` znika;
- `facets` (keyword): `#kategoria` dla każdego przodka, `@oś`, `oś␟wartość`, `$` gdy produkt ma wariant do kupienia;
- `buy_facets`: to samo dla wariantów do kupienia;
- `pair_any`, `pair_buy`: dla wariantu z opcjami x i y tokeny `x␞y`, `y␞x`, `x␞@oś(y)`, `y␞@oś(x)`;
- `purchasable`, `max_gross` (obok istniejącego `from_gross`), `buy_prices` (ceny wariantów do kupienia, tylko do
  filtra zakresu), `sort_path` (identyfikatory ścieżki głównej kategorii, klucz sortu indeksu).

**Zapytania:**

| Warunki listingu | Trafienia i liczby | Fasety |
|---|---|---|
| brak, dostępność | pola produktu | jedno przejście `terms` po `facets` albo `buy_facets`: kategorie, osie, wartości i dostępność naraz |
| jedna opcja (jedna wartość) | `term` na `facets` | `terms` po `pair_*` z prefiksem wybranej opcji |
| dwie opcje tego samego wariantu | `terms` na `pair_*` | wartości wybranych osi z `pair_*`; innych osi nie ma (wariant ma najwyżej 2) |
| sama cena | `range` na `buy_prices` | `nested` (cena i opcja w tym samym wariancie) |
| cena z opcją, kilka wartości jednej osi w warunku fasety | `nested` zawężony polami produktu | `nested` z `reverse_nested` |

- Zakres cen listingu bez warunków opcji: dwa zapytania „top 1” z sortem po `from_gross` i `max_gross` (ok. 5 ms CPU
  na 1 mln zamiast 23 ms dla agregacji `min` i `max`) [POMIAR `prim.py`].
- Agregacje są pogrupowane według zestawu warunków; każda grupa to osobne wyszukiwanie w jednym `_msearch`, w którym
  warunki zawężają zapytanie. W jednym dużym żądaniu każda agregacja z filtrem sprawdza ten filtr na wszystkich
  dokumentach listingu, a `terms` z `include` schodzi na wolniejszą ścieżkę (profil: 230–430 ms na filtr przy całym
  katalogu); stąd różnica między `pl` a `plm`.
- Listing z frazą idzie jednym żądaniem, żeby dopasowanie tekstu nie powtarzało się w każdej grupie.
- Fasety nie zależą od strony ani sortowania i są osobnymi żądaniami `size: 0`, więc natywny request cache obsługuje
  je bez dodatkowego kodu. Żądanie faset listingu bez warunków służy też jako wartości wybranej osi listingu
  z jednym filtrem (to samo żądanie, ten sam wpis cache).
- Szkice (`draft`) zostają w indeksie jak dziś.

**Ustawienia silnika:** 1 shard, równoległe przeszukiwanie segmentów włączone (domyślne w ES 9), sort indeksu po
`sort_path`, heap 2 GiB.

## 4. Zgodność wyników

Automatyczne porównanie z obecnym modelem (`scripts/check.py`): liczba trafień, fasety kategorii, dostępność, zakres
cen, 10 osi po 30 wartości, wartości wybrane, identyfikatory strony.

| Porównanie | Przypadki | Różnice |
|---|---:|---:|
| `flat3`, `pl` na 10 mln (`c10` wobec `h10`), 26 scenariuszy | 2 × 26 | 0 |
| `flat3`, `pl`, `plm` na 1 mln, cała mieszanka | 3 × 294 | 0 |
| `plm` na 10 mln (`h10`), 26 scenariuszy i cała mieszanka | 320 | 0 |
| **model R na własnym indeksie (`r10`), 26 scenariuszy i cała mieszanka** | 320 | **0** |

Scenariusze obejmują cały katalog, dział, liść, frazę (także z literówką), jedną i dwie wartości osi, dwie i trzy
osie, cenę, dostępność, ich kombinacje na całym katalogu i w kategorii, sortowania i strony 100 i 400
(`results/equivalence_*.json`).

## 5. Pomiary na 10 mln

### Pojedyncze zapytania: `took` / CPU w ms (mediana z 7 po 2 rozgrzewkach)

| scenariusz | cur | flat3 | pl | plm (`h10`) | model R (`r10`) |
|---|---:|---:|---:|---:|---:|
| `all_name` | 5629 / 5800 | 1363 / 1374 | 922 / 931 | 169 / 566 | 221 / 708 |
| `all_price_asc` | 5671 / 5724 | 1384 / 1387 | 939 / 949 | 170 / 570 | 221 / 694 |
| `all_avail` | 5811 / 5874 | 5560 / 5766 | 981 / 985 | 156 / 617 | 197 / 726 |
| `all_price` | 5174 / 5223 | 3886 / 3922 | 2550 / 2608 | 525 / 1796 | 466 / 1619 |
| `all_color` | 3527 / 3570 | 2153 / 2156 | 2148 / 2160 | 187 / 994 | 236 / 980 |
| `all_color_avail` | 3513 / 3540 | 3431 / 3499 | 2005 / 2012 | 172 / 903 | 210 / 894 |
| `all_color_price` | 3057 / 3067 | 1848 / 1860 | 1924 / 1930 | 1037 / 1293 | 925 / 1106 |
| `all_color2_size` | 2572 / 2610 | 1265 / 1292 | 1834 / 1843 | 327 / 594 | 281 / 532 |
| `all_color_size_price` | 2382 / 2398 | 793 / 804 | 746 / 750 | 152 / 301 | 128 / 210 |
| `root_name` | 1217 / 1224 | 296 / 303 | 211 / 216 | 37 / 166 | 44 / 163 |
| `root_price_desc` | 1221 / 1246 | 304 / 307 | 216 / 256 | 37 / 170 | 44 / 172 |
| `root_color` | 841 / 849 | 501 / 507 | 505 / 512 | 42 / 232 | 46 / 205 |
| `root_color2` | 915 / 923 | 603 / 607 | 511 / 518 | 44 / 328 | 44 / 271 |
| `root_color_size` | 615 / 621 | 320 / 321 | 634 / 656 | 12 / 106 | 15 / 96 |
| `root_color2_size` | 659 / 663 | 382 / 385 | 468 / 474 | 85 / 158 | 59 / 122 |
| `root_price` | 1129 / 1146 | 829 / 856 | 569 / 575 | 111 / 403 | 101 / 353 |
| `root_avail` | 1235 / 1255 | 1169 / 1174 | 222 / 224 | 35 / 158 | 39 / 154 |
| `root_color_price` | 816 / 822 | 481 / 492 | 497 / 506 | 235 / 358 | 193 / 250 |
| `leaf_name` | 201 / 203 | 42 / 43 | 42 / 44 | 9 / 48 | 7 / 35 |
| `leaf_color_price` | 186 / 209 | 130 / 132 | 130 / 133 | 88 / 107 | 65 / 81 |
| `phrase_kurtka` | 285 / 307 | 46 / 156 | 32 / 111 | 19 / 117 | 24 / 77 |
| `phrase_typo` | 7 / 9 | 5 / 10 | 5 / 10 | 3 / 16 | 4 / 9 |
| `phrase_color` | 259 / 264 | 240 / 266 | 179 / 206 | 17 / 150 | 130 / 152 |
| `three_axes` | 2388 / 2388 | 472 / 477 | 34 / 37 | 0 / 3 | 0 / 2 |
| `all_p400` | 5880 / 5949 | 1654 / 1651 | 1159 / 1172 | 286 / 858 | 298 / 986 |
| `all_p100` | 5611 / 5721 | 1437 / 1435 | 1005 / 1007 | 168 / 647 | 223 / 807 |

`all` = cały katalog, `root` = największy dział, `leaf` = największy liść, `color2` = dwie wartości jednej osi,
`p400` = strona 400. Kolumna `plm (h10)` dla fraz pochodzi sprzed poprawki „fraza jednym żądaniem”.

### Koszt na klasę adresów mieszanki: CPU w ms na listing

| klasa | udział | cur | flat3 | pl | plm (`h10`) | plm, sort po głównej kategorii | model R (`r10`) |
|---|---:|---:|---:|---:|---:|---:|---:|
| cały katalog | 4,1% | 5699 | 1461 | 1000 | 582 | 599 | 695 |
| cały katalog z filtrami | 6,1% | 3393 | 2250 | 2386 | 848 | 781 | 832 |
| dział | 12,2% | 957 | 228 | 162 | 137 | 122 | 136 |
| dział z filtrami | 8,2% | 709 | 552 | 396 | 269 | 189 | 201 |
| kategoria środkowa | 10,2% | 313 | 72 | 62 | 68 | 50 | 53 |
| środkowa z filtrami | 6,1% | 306 | 243 | 155 | 125 | 82 | 88 |
| liść | 20,4% | 111 | 29 | 36 | 41 | 20 | 22 |
| liść z filtrami | 12,2% | 128 | 97 | 70 | 70 | 40 | 46 |
| fraza | 14,3% | 237 | 129 | 91 | 96 | 78 | 60 |
| fraza z filtrami | 4,1% | 190 | 170 | 109 | 137 | 69 | 67 |
| głęboka strona | 2,0% | 3527 | 1016 | 730 | 547 | 529 | 611 |
| **mieszanka** | 100% | **818** | **357** | **303** | **176** | **146** | **157** |

W modelu R klasy „cały katalog”, „cały katalog z filtrami” i „głęboka strona” (12% adresów) dają ok. 58% kosztu
mieszanki.

### Współbieżność (pętla zamknięta 30 s, mieszanka, 8 CPU)

| wariant | N | zapytań/s | p50 ms | p95 ms | p99 ms | CPU ms/zap. | użyte rdzenie |
|---|---:|---:|---:|---:|---:|---:|---:|
| cur | 1 | 1,6 | 235 | 3456 | 6578 | 635 | 1,0 |
| cur | 8 | 8,7 | 256 | 6028 | 6373 | 816 | 7,1 |
| cur | 100 | 7,8 | 12222 | 21549 | 24682 | 981 | 7,7 |
| flat3 | 1 | 2,5 | 99 | 1908 | 5582 | 401 | 1,0 |
| flat3 | 8 | 22,9 | 106 | 1520 | 2264 | 313 | 7,2 |
| flat3 | 100 | 18,7 | 4881 | 9467 | 10333 | 419 | 7,8 |
| pl | 1 | 3,0 | 79 | 2102 | 3157 | 343 | 1,0 |
| pl | 8 | 29,6 | 79 | 1097 | 2539 | 255 | 7,5 |
| pl | 100 | 26,7 | 3130 | 6249 | 7098 | 286 | 7,6 |
| plm (`h10`) | 1 | 21,1 | 24 | 184 | 293 | 181 | 3,8 |
| plm (`h10`) | 8 | 40,2 | 111 | 916 | 1089 | 192 | 7,7 |
| plm (`h10`) | 100 | 42,2 | 2198 | 3342 | 4179 | 188 | 7,9 |
| **model R (`r10`)** | 1 | 20,4 | 22 | 226 | 256 | 152 | 3,1 |
| **model R (`r10`)** | 8 | 48,3 | 84 | 878 | 1185 | 166 | 8,0 |
| **model R (`r10`)** | 100 | 47,4 | 2045 | 3120 | 3916 | 167 | 7,9 |

Bez błędów i odrzuceń we wszystkich przebiegach. Przy N=100 kolejka wynika z nasycenia CPU. Wiersze `plm (h10)`
pochodzą sprzed poprawki „fraza jednym żądaniem”; model R ją zawiera.

### Skok 100 jednoczesnych listingów z mieszanki (3 rundy, różne adresy)

| wariant | najwolniejsza odpowiedź, ms | p50, ms | CPU ms/listing |
|---|---|---|---|
| cur | 15961 / 13572 / 11485 | 9593 / 8711 / 7106 | 1101 / 885 / 748 |
| flat3 | 10294 / 5518 / 4367 | 5091 / 3397 / 2858 | 567 / 325 / 281 |
| pl | 6407 / 4024 / 3763 | 3455 / 2291 / 1926 | 406 / 274 / 221 |
| plm (`h10`) | 3545 / 2344 / 2241 | 2576 / 1883 / 1758 | 236 / 167 / 151 |
| **model R (`r10`)** | 3315 / 2255 / 1850 | 2222 / 1632 / 1318 | 214 / 145 / 124 |

Wszystkie 100 odpowiedzi pełne w każdej rundzie (trzecia runda ma 94 adresy).

### Stałe tempo (model otwarty, 60 s, model R)

| CPU kontenera | tempo | p50 ms | p95 ms | p99 ms | maks. ms | użyte rdzenie |
|---|---:|---:|---:|---:|---:|---:|
| 8 | 15/s | 32 | 241 | 308 | 561 | 2,4 |
| 8 | 30/s | 36 | 280 | 450 | 722 | 4,8 |
| 3 | 5/s | 34 | 444 | 676 | 1209 | 1,3 |
| 3 | 10/s | 45 | 934 | 1422 | 1839 | 2,2 |

### Pojemność zbliżona do serwera (`--cpus=3`)

| wariant | N=8: zapytań/s | N=8: p50 / p95 ms | skok 100: najwolniejsza, ms |
|---|---:|---|---|
| cur | 3,4 | 776 / 11002 | 42184 / 33581 / 27661 |
| model R | 16,2 | 296 / 1493 | 9223 / 6570 / 6131 |

### Zapisy równoległe (50 przepisań produktu na sekundę, N=8, bez cache)

| wariant | zapytań/s bez zapisów | z zapisami |
|---|---:|---:|
| cur | 8,7 | 10,8 |
| model R | 48,3 | 46,6 |

Zapisy w tym tempie nie obniżają przepustowości odczytu; różnice mieszczą się w rozrzucie pomiaru. Pierwsze zapytanie po zapisie
i odświeżeniu nie jest droższe od kolejnego (model R: 854 wobec 809 ms CPU; `cur`: 5838 wobec 5784) [POMIAR
`refresh.py`].

## 6. Ustawienia silnika (na modelu R)

| Ustawienie | Pomiar | Wniosek |
|---|---|---|
| równoległe przeszukiwanie segmentów (domyślne) | wyłączone: ten sam CPU (182 wobec 176 ms), ale p95 przy N=1 608 zamiast 184 ms i 36,6 zamiast 40,2 zapytań/s | zostawić włączone; daje krótki czas pojedynczego zapytania bez kosztu CPU |
| 4 shardy zamiast 1 | 312–332 zamiast 181–192 ms CPU na zapytanie, 24–25 zamiast 40–42 zapytań/s, skok 3,6–4,9 s zamiast 2,2–3,5 s | 1 shard; shardy na jednym węźle kosztują 1,6–1,75 razy więcej CPU, a czas skraca już równoległość segmentów |
| sort indeksu po kategorii | mieszanka 146–157 zamiast 176 ms; liść 20–22 zamiast 41 ms; dział z filtrami 189–201 zamiast 269 ms; przepustowość 48–53 zamiast 40 zapytań/s; cały katalog bez zmiany | włączyć; produkty kategorii leżą obok siebie |
| `filters` (jeden `term` na wartość) zamiast `terms` | 203 wobec 42 ms CPU na 1 mln (208 wartości) | odrzucone; ES nie liczy tego z postingów, tylko sprawdza każdy filtr na każdym dokumencie |
| jedno pole łączone zamiast czterech agregacji | 62–69 wobec 107 ms CPU na 1 mln | przyjęte (`facets`) |
| zakres cen przez dwa „top 1” | ok. 5 wobec 23 ms CPU na 1 mln | przyjęte |
| liczenie w stałym czasie ze statystyk termów | 0 segmentów policzonych w stałym czasie | niedostępne: wymaga zapytania `match_all`, braku dokumentów `nested` i braku skasowanych dokumentów |

Sort po `sort_path` (ścieżka głównej kategorii) i po samej głównej kategorii dały wynik w granicach rozrzutu
(157 i 146 ms na mieszance); wybór klucza można potwierdzić w etapie B.

## 7. Natywny request cache dla faset

Żądania faset modelu R mają `size: 0`, więc Elasticsearch buforuje je sam i unieważnia przy odświeżeniu sharda, na
którym coś się zmieniło [POMIAR `cachetest.py`, `r10`]:

- pierwsze zapytanie o fasety całego katalogu: 715 ms CPU; trafienie: 0,8 ms;
- zapis jednego dokumentu z odświeżeniem unieważnia wpis (kolejne zapytanie liczy od nowa);
- 20 identycznych zapytań naraz na pustym cache: 668 ms CPU łącznie, wszystkie odpowiedzi po 653–656 ms, czyli silnik
  liczy raz, a pozostałe czekają na wynik;
- mieszanka 294 adresów to 538 żądań faset, w tym 423 różne; jedno żądanie jest wspólne dla 25 adresów.

Mieszanka przy 50 zapisach na sekundę, N=8 (60 s):

| request cache | `refresh_interval` | zapytań/s | CPU ms/listing | p95 ms |
|---|---|---:|---:|---:|
| wyłączony (zimna ścieżka) | 1 s | 46,6 | 170 | 846 |
| tylko fasety listingów bez warunków | 1 s | 74,4 | 105 | 378 |
| tylko fasety listingów bez warunków | 5 s | 97,3 | 80 | 267 |
| tylko fasety listingów bez warunków | 30 s | 105,4 | 76 | 240 |
| wszystkie żądania faset | 1 s | 95,4 | 80 | 298 |
| wszystkie żądania faset | 5 s | 153,4 | 49 | 182 |
| wszystkie żądania faset | 30 s | 199,0 | 40 | 155 |

Wiersze „wszystkie żądania faset” zawyżają zysk: pętla wraca do tego samego adresu co kilka sekund, a w prawdziwym
ruchu kombinacji filtrów jest więcej. Wiersze „bez warunków” dotyczą zbioru ograniczonego (kategoria × dostępność,
362 żądania), więc są realistyczne. Obecny model nie może skorzystać z tego mechanizmu, bo liczy fasety w tym samym
żądaniu co stronę wyników.

## 8. Indeksowanie, rozmiar, gorące dane

| | obecny model | model R | model R z sortem indeksu |
|---|---:|---:|---:|
| zapis masowy, 1 zadanie `_reindex`, produktów/s | 16 766 | 16 595 | 15 592 |
| zapis masowy, CPU ms na produkt | 0,063 | 0,063 | 0,067 |
| przepisanie jednego produktu, CPU ms | 0,49 | 0,36 | 0,55 |
| dokumenty Lucene na produkt | 8,9 | 4,0 | 4,0 |
| rozmiar 10 mln | 9,5 GB | – | 8,5 GB |
| 1 mln: `_source` i pola przechowywane | 578 MB | – | 489 MB |
| 1 mln: doc values + indeks odwrócony + punkty + normy | 411 MB | – | 448 MB |

- Tempo zapisu po stronie Elasticsearcha jest takie samo; sort indeksu kosztuje ok. 7% przy zapisie masowym i ok. 12%
  CPU więcej niż dziś przy przepisaniu pojedynczego produktu (0,55 wobec 0,49 ms). Wąskim gardłem przebudowy pozostaje
  PHP i Postgres (audyt, E7), nie indeks.
- Gorące dane (wszystko poza polami przechowywanymi, górna granica): ok. 4,5 GB na 10 mln w modelu R, ok. 4,1 GB
  dziś [SZACUNEK ×10 z 1 mln]. Przy limicie 4 Gi z `prod` nie mieszczą się w cache plików w żadnym modelu; to wejście
  dla PERF-1.
- Budowa indeksów testowych: 10 mln w 197–232 s (43–51 tys. produktów/s, 10 równoległych zadań `_reindex`).

## 9. Odrzucone warianty

- **Wariant jako osobny dokument** (`scripts/mkv.py`, `vd.py`, 1 mln): produkty trzeba liczyć `cardinality`
  (HyperLogLog++), więc liczby są przybliżone. Przy progu dokładności 40 000 (maksimum) 18–96 liczb na listing było
  błędnych, do 0,38% (np. 105 079 zamiast 104 877 trafień). CPU: cały katalog z dostępnością 1202 ms, z kolorem
  508 ms, z ceną 861 ms na 1 mln; model R potrzebuje na to samo 73, 98 i 162 ms w przeliczeniu na 1 mln. Narusza
  wymaganie dokładnych liczb i jest droższy, dlatego nie budowałem go na 10 mln (odstępstwo od kryterium „każdy
  kandydat na 10 mln”).
- **Rozdział produktów o jednej cenie** (filtr ceny liczony z pól produktu, gdy wszystkie warianty kosztują tyle
  samo; 57% produktów do kupienia): 20% oszczędności w grupie faset przy filtrze ceny na całym katalogu (1377 zamiast
  1722 ms). Za mało wobec trzeciej ścieżki liczenia.
- **Transformacje Elasticsearcha jako natywne liczniki:** tryb ciągły przelicza tylko grupy, do których trafił
  zmieniony dokument, więc nie zmniejsza licznika wartości, którą produkt utracił. Odrzucone bez pomiaru, z opisu
  mechanizmu.
- **Więcej shardów, `filters`, liczenie ze statystyk termów:** sekcja 6.

## 10. Budżet W1, W8, W9 na obecnym serwerze

Wszystkie wartości serwera to [SZACUNEK]: pomiar lokalny × 2,9, do potwierdzenia na worf.pl. Dotyczą samego
Elasticsearcha; API, Postgres i sklep zużywają CPU dodatkowo.

| | obecny model | model R, bez cache | model R, cache faset bez warunków, odświeżanie 5 s |
|---|---:|---:|---:|
| CPU Maca na listing mieszanki | 818 ms | 157–166 ms | 80 ms |
| CPU serwera na listing | ok. 2,4 s | ok. 455–480 ms | ok. 230 ms |
| W8: budżet 160 ms CPU na listing we wszystkich usługach | 15 razy za dużo | 3 razy za dużo | 1,45 razy za dużo |
| W8: 100 jednoczesnych zimnych listingów do 2 s | min. 30 s (pomiar 3 CPU: 28–42 s) | min. 5,7 s (pomiar 3 CPU: 6,1–9,2 s) | nie mierzono skoku z cache |
| W9: 30 zimnych listingów/s (całe 8 vCPU dla ES) | ok. 3,4/s | ok. 17/s (pomiar 3 CPU: 16,2/s) | ok. 34/s |
| W1 przy 15 zimnych/s: potrzebne vCPU dla ES | ponad 8 | ok. 6,8–7,2 | ok. 3,5 |

- **W1:** pojedynczy zimny listing w modelu R mieści się w p50 (mediana 22–32 ms Maca, ok. 65–95 ms serwera).
  Najdroższa klasa, cały katalog, to 221 ms czasu na 8 wolnych rdzeniach Maca, czyli ok. 0,6 s na serwerze przy
  wolnych rdzeniach: powyżej p95 (500 ms), poniżej p99 (1 s). Przy tempie nominalnym 15/s bez cache Elasticsearch
  zająłby prawie cały serwer, więc W1 pod obciążeniem nie jest spełnione.
- **W8 i W9 nie są spełnione** w żadnym zbadanym modelu liczonym na żywo. Model R zmniejsza brak z ok. 15 razy do
  ok. 3 razy, a z natywnym cache faset listingów bez warunków do ok. 1,5 razy (dla samego ES).
- Źródło braku: fasety całego katalogu to przejście po 9,8 mln produktów (ok. 65 ns na produkt przy ok. 10 tokenach).
  Tego kosztu nie zmienia mapping, liczba shardów ani typ agregacji; zmienia go tylko to, jak często się go ponosi.

## 11. Decyzje dla właściciela

1. **Model R jako model etapu B.** Rekomenduję akceptację: najmniejszy koszt w każdej klasie adresów, wyniki
   identyczne, mniej dokumentów Lucene, mniejszy indeks, ten sam koszt zapisu. Każda dalsza opcja z punktów 2–4
   buduje na nim.
2. **Ustawienia:** 1 shard, równoległość segmentów włączona, sort indeksu po ścieżce kategorii. Dla worf.pl oznacza
   to powrót z 4 shardów do 1 przy przebudowie indeksu w etapie B.
3. **Natywny request cache faset i `refresh_interval`.** Cache działa bez kodu, ale przy odświeżaniu co 1 s i ciągłych
   zapisach żyje sekundę. Odświeżanie co 5 s daje 80 zamiast 105 ms CPU na listing i mieści się w W16 (p95 do 10 s);
   30 s daje niewiele więcej (76 ms). Rekomenduję 5 s, do potwierdzenia razem z PERF-6.
4. **W8 i W9 wobec pomiaru.** Samym modelem wyszukiwania nie da się ich spełnić na 8 vCPU przy 10 mln. Możliwości,
   żadna nie jest częścią tej rekomendacji bez Twojej decyzji:
   - materializowane liczniki faset listingów bez warunków (181 kategorii × dostępność): pełne przeliczenie to 7,1 s
     CPU Maca, ok. 20 s CPU serwera [SZACUNEK]; co 60 s zajmuje ok. 0,35 vCPU i usuwa zależność od odświeżania
     indeksu, kosztem świeżości liczników do 60 s i modelu odczytu utrzymywanego przez aplikację;
   - ponowna ocena wartości W8 i W9 po zdefiniowaniu mieszanki przez PERF-0: 12% adresów (cały katalog i głębokie
     strony) daje 58% kosztu, więc wynik silnie zależy od ich udziału;
   - listingi z filtrem ceny na całym katalogu i w dziale (1,1–1,6 s i 0,25–0,35 s CPU Maca) pozostają złączeniem
     `nested` w każdym dokładnym modelu; nie znalazłem natywnego sposobu, żeby je policzyć bez złączenia.

Poza zakresem modelu, a widoczne w pomiarach: strona 400 kosztuje ok. 290 ms CPU więcej niż strona 1 (sort 9600
pozycji), a dopasowanie frazy 60–150 ms niezależnie od modelu.

## 12. Odtworzenie

**Wymagania wstępne.**

- Python 3 na hoście (sama biblioteka standardowa) i polecenie `docker` (odczyt CPU z cgroup kontenera, `cpu.py`).
  Skrypty importują `esq.py`, `variants.py`, `mapping_orig.json`, `settings_orig.json` i `tree.json` z
  `docs/reports/performance-audit-2026-11-25/results/es/`.
- Kontener `es-audit` z audytu: obraz Elasticsearcha projektu (`infra/elasticsearch/Dockerfile`, ES 9.4.0 z wtyczką
  analizy), `--cpus=8`, pamięć 10 GiB, `ES_JAVA_OPTS=-Xms2g -Xmx2g`, `discovery.type=single-node`,
  `xpack.security.enabled=false`, port `127.0.0.1:19200`, wolumen `es-audit-data`. Inny adres: zmienna `ES`
  (zapytania przez `esq.py`) oraz stałe `HOST` i `PORT` w `bench.py`; inna nazwa kontenera: `ES_CONTAINER`.
- Dane źródłowe: alias `products` w tym kontenerze, indeks profilu `medium` (1 000 191 produktów) zbudowany przez
  aplikację na `2b4258c7`. Ten indeks jest w wolumenie `es-audit-data`; dane `medium` są też w wolumenach zatrzymanego
  stosu `shop-perf25`. Audyt nie zapisał polecenia, którym utworzył kontener i wczytał indeks, więc odtworzenie od
  zera (bez tego wolumenu) wymaga wygenerowania `medium` (`make seed-scale PROFILE=medium`, z dostępem do puli zdjęć),
  reindeksu i uruchomienia kontenera o parametrach jak wyżej na kopii wolumenu Elasticsearcha tego stosu. Tej ścieżki
  nie sprawdzałem.
- Mieszanka jest zapisana (`results/mix.json`, losowanie ze stałym ziarnem), więc pomiary nie wymagają jej ponownego
  generowania.

Wszystkie polecenia z katalogu `docs/reports/perf-2-search-model-2026-11-25/scripts`. `bench.py`, `classes.py`
i pozostałe skrypty pomiarowe dopisują wiersze do `results/bench.jsonl` z etykietą ze zmiennej `LABEL`.

| Krok | Polecenie | Wynik |
|---|---|---|
| indeksy 1 mln | `t_orig1` z audytu (`mk_indices.py`); `python3 mk.py h1 h 1 1 \| python3 wait.py`; `r1` buduje `batch3.sh` | |
| indeks odniesienia 10 mln | `python3 mk.py c10 cur 1 10 \| python3 wait.py` | `build_c10.json` |
| nadzbiór pól (`flat3`, `pl`, `plm`) | `python3 mk.py h10 h 1 10 \| python3 wait.py` | `build_h10.json` |
| indeks modelu R 10 mln | `python3 mk.py r10 r 1 10 products sort_path \| python3 wait.py` | `build_r10.json` |
| mieszanka | `python3 mix.py h1` | `mix.json` |
| zgodność kandydatów | `python3 check.py c10 h10 flat3,pl,pl-split ../results/equivalence_10m.json`; `MIX=1 python3 check.py t_orig1 h1 flat3,pl,plm ../results/equivalence_mix_1m.json` | `equivalence_*.json` |
| zgodność modelu R | `python3 check.py c10 r10 plm`, `MIX=1 python3 check.py c10 r10 plm` | `equivalence_r10.json`, `equivalence_mix_r10.json` |
| kandydaci: pojedyncze zapytania | `LABEL=10m python3 bench.py single c10 cur`; to samo dla `h10` z `flat3`, `pl`, `pl-split`, `plm` | etykieta `10m` |
| kandydaci: klasy adresów | `LABEL=10m-class python3 classes.py c10 cur`; to samo dla `h10` z `flat3`, `pl`, `plm` | `10m-class` |
| kandydaci: współbieżność i skok | `LABEL=10m-cpu8 python3 bench.py conc c10 cur 30 1,8,100 mix`, `LABEL=10m-cpu8 python3 bench.py burst c10 cur 100`; to samo dla `h10` z `flat3`, `pl`, `plm` | `10m-cpu8` |
| ustawienia silnika, cache na `h10` | `batch2.sh` (równoległość segmentów, 4 shardy, sort po głównej kategorii) | `10m-nopar`, `10m-s4`, `10m-sort`, `batch2.log` |
| model R: wszystkie pomiary | `batch3.sh` (pojedyncze, klasy, współbieżność, skok, stałe tempo, cache, odświeżenie, `--cpus=3`, indeksowanie, wariant jako dokument, zapisy równoległe) | `r10*`, `c10-*`, `ingest.jsonl`, `vd.jsonl`, `batch3.log` |
| pojedyncze kroki z `batch3.sh` | `LABEL=r10 python3 bench.py single r10 plm`, `bench.py conc r10 plm 30 1,8,100 mix`, `bench.py burst r10 plm 100`, `bench.py open r10 plm 15 60`, `cachetest.py r10`, `refresh.py r10 plm all_name`, `writes.py r10 50 75` równolegle z `CACHE=tok bench.py conc …`, `ingest.py r10 r sort_path`, `mkv.py v1 products`, `vd.py v1 r1` | |
| kalibracja i kontrola rozrzutu | `LABEL=1m-calib python3 bench.py single t_orig1 cur all_name,root_name,leaf_name`; `LABEL=recheck python3 bench.py single r10 plm all_name,root_name,leaf_name` | `1m-calib`, `recheck` |
| rozmiary pól | `python3 disk.py r1`, `python3 disk.py t_orig1` | `disk.jsonl` |
| pełne przeliczenie faset bez warunków | `python3 sweep.py r10` | wiersz `sweep` |
| operacje pierwotne, profil agregacji | `python3 prim.py r10`, `python3 prof.py h10 pl all_color` | na ekran |
| tabele raportu | `python3 tables.py` | `tables.md` |

Uwagi do odtwarzania:

- Serie kandydatów (`10m`, `10m-class`, `10m-cpu8`) uruchamiałem poleceniami z tabeli, bez skryptu serii. `sweep.py`
  to zapisany kod, który w eksperymencie wykonałem jednorazowo z wiersza poleceń; jako plik nie był uruchamiany.
- `batch3.sh` zmienia limit CPU kontenera (`docker update --cpus 3`, potem 8), a zapisy równoległe (`writes.py`)
  przepisują istniejące dokumenty `r10` i `c10`: treść się nie zmienia, układ segmentów tak.
- Liczby trafień i fasety są deterministyczne. Czasy i CPU zależą od maszyny, obciążenia w tle i układu segmentów
  (rozrzut do ok. 25%, sekcja 2), a przebiegi współbieżne były pojedyncze, więc powtórzenie da te same proporcje
  między modelami, nie te same wartości.

Stan po eksperymencie: kontener `es-audit` zatrzymany, limit pamięci 10 GiB; zostają indeksy `c10`, `r10`, `r1`, `h1`
i indeksy audytu (`products_*`, `t_orig*`, `t_flat*`); `h10`, `h10s4`, `h10sort` i `v1` usunięte (odtwarzalne
poleceniami z tabeli).
