# Audyt: Elasticsearch i wyszukiwarka katalogu (2026-11-25, rewizja 2b4258c7)

Stan: GOTOWE. Najpierw odpowiedzi i znaleziska, potem załączniki z surowymi pomiarami.

Skrypty i surowe wyniki: `docs/reports/performance-audit-2026-11-25/results/es/` (`esq.py` – wierny port `ProductSearchQuery.php` do Pythona i scenariusze).

## Środowisko pomiaru

- Kontener `es-audit`: ES 9.4.0, 8 CPU (`--cpus=8`), heap 2 GiB, limit pamięci kontenera 4 GiB, `search` thread pool 13
  wątków / kolejka 13 000.
- Indeks `products_20261118164738354_b3e480` (alias `products`): 1 shard, 0 replik, 1 000 191 produktów (980 083
  `published`, 20 108 `draft`), 3 009 350 dokumentów wariantów, 4 882 432 dokumentów par oś–wartość = 8 891 973
  dokumentów Lucene, 1013 MB, 21 segmentów. 180 kategorii (7 korzeni, 133 liście), 21 osi, najwyżej 36 wartości osi.
- Mapping indeksu jest identyczny z `ProductIndexDefinition::mappings()` na 2b4258c7 (ostatnia zmiana pliku 476d84c4
  dodała tylko walidację liczby shardów; hash definicji nie obejmuje shardów). [KOD]+[POMIAR `_mapping`]
- Drugi indeks `products_20261118164446661_244a25` (2,39 mln dok., bez aliasu) to przerwana wcześniejsza przebudowa
  z 2026-11-18; nie używam go i nie usuwam.
- Maszyna współdzielona: w tle działają inne stosy (Postgres innej sesji ~100% CPU, ES stosu shop-perf25), load average
  5–7 na 16 rdzeniach. Wszystkie czasy to mediana z 9 po 3 rozgrzewkach, `request_cache=false`; rozrzut min–max podany.

## Odpowiedzi wprost

### (1) Co kosztuje w zapytaniu listingu i dlaczego

- [POMIAR] Cały katalog bez filtrów (`all_name`): 815 ms (767–1098) na oryginalnym indeksie, 543–591 ms na świeżej
  kopii 1 shardu. Lista 24 produktów z sortem i `track_total_hits:true` to 2–31 ms; **~96% czasu to fasety**.
  Ablacja: `free` (oś→wartość: nested `variants` → filtr → nested `variants.pairs` → `terms` osi z sortowaniem po
  `reverse_nested` → `terms` wartości z sortowaniem po `reverse_nested`) 456 ms, `categories` (`terms` z `include`)
  145 ms, `price` 78 ms, `available` 45 ms.
- [POMIAR Profile API] W `free` najdroższe jest zbieranie (`collect`) po 4,9 mln dokumentów par i dwukrotne
  `reverse_nested` dla każdej pary (do produktu), plus ~184 ms `build_aggregation`, bo `terms` sortowane po
  pod-agregacji muszą policzyć `reverse_nested` dla wszystkich kubełków. `price` i `available` przechodzą osobno po
  3 mln dokumentów wariantów.
- Przyczyna źródłowa [KOD `ProductSearchQuery.php:117-186`]: każda faseta jest liczona przez złączenie blokowe
  (nested) na poziomie wariantu albo pary, nawet gdy zapytanie NIE ma warunku na wariant (wejście na katalog, dział,
  liść, frazę). Wtedy wynik jest z definicji właściwością produktu („produkt ma jakiś wariant z wartością X”), a
  liczymy go przez iterację po 4–9 dokumentach Lucene na produkt. Koszt jest liniowy w liczbie produktów pasujących do
  zapytania (pomiar 1 mln → 3 mln: ×2,96–3,06).
- [POMIAR] Faseta kategorii jako `terms` z listą `include` kosztuje 60 ms na 1 mln, ta sama informacja jako agregacja
  `filters` (jeden `term` na kategorię) 10 ms, `terms` bez `include` 14 ms.

### (2) Dlaczego 8 równoległych zimnych wejść daje błędy; przepustowość

- Łańcuch [KOD]: `ElasticsearchClientFactory` – `timeout` 2 s (w Symfony HttpClient to limit bezczynności: ES nie
  wysyła nic, dopóki nie skończy liczyć, więc to praktycznie limit czasu zapytania; „Idle timeout reached”) →
  `SearchUnavailable` → `ListProductsController` → 503 → sklep 500. Klient nie ponawia (retries 0).
- To **nie jest** kolejka, odrzucenia ani GC [POMIAR]: pula `search` 13 wątków, kolejka maks. 19–118, 0 odrzuceń
  we wszystkich przebiegach; young GC < 1% czasu, 0 old GC, heap 20–66% z 2 GB, 0 zadziałań breakerów. Pierwsze
  zapytanie po zapisie + odświeżeniu nie jest wolniejsze (globalne ordinals: niemierzalne przy 1 mln) – hipoteza
  „zimne ES” odrzucona.
- To **CPU** [POMIAR]: przy N=8 kontener ES ma 780–806% CPU; czas CPU procesu ES na zapytanie `all_name` to
  687–864 ms (1 shard) i 851–1252 ms (4 shardy) na rdzeniu Maca. Serwer ma rdzeń 2,5–3,5× wolniejszy (dane ze
  zlecenia) => ~1,7–4,4 CPU‑s na jedno zimne wejście [SZACUNEK]. 8 takich wejść naraz = 14–35 CPU‑s na 8 vCPU,
  dzielonych z Postgresem, API i sklepem (ES ma w `prod` request 1 CPU i brak limitu) => każde kończy się po ~2–4 s,
  czyli po limicie 2 s klienta. Przy `--cpus=2` (przybliżenie pojemności serwera) `all_name` przy N=8: 100% (1 shard)
  i 88% (4 shardy) odpowiedzi > 2 s.
- Przepustowość ES, zmierzona (q/s, plateau):

| zapytanie | 8 CPU Mac, 1 shard | 8 CPU Mac, 4 shardy | 2 CPU, 1 shard | 2 CPU, 4 shardy |
|---|---|---|---|---|
| bieżące, cały katalog | 4,2–5,3 | 4,1–5,2 | 2,4–2,5 | 1,8–2,4 |
| bieżące, mix 10 scenariuszy | 11–14 | 12–13 | 4,8–6,6 | 5,1–6,6 |
| flat2, cały katalog | 15–22 | 20–48 (rozrzut hosta) | 11–12 | 12–13 |
| flat2, mix 10 scenariuszy | 20–26 | 29–31 | 18–20 | 17–18 |

  4 shardy skracają pojedyncze zapytanie 2–4×, ale zużywają 1,7–1,8× więcej CPU na zapytanie, więc w nasyceniu nie
  zwiększają przepustowości [POMIAR] (zgadza się z notą w specyfikacji SEARCH: czas CPU się nie zmniejsza).

### (3) Czy nested→nested z reverse_nested jest właściwy; prostsza alternatywa i zmierzony zysk

- Nested na wariancie jest **potrzebny** do filtrów: `Kolor=czarne` i `Rozmiar=M` w tym samym wariancie = 8 614
  produktów, w dowolnych wariantach = 20 214 [POMIAR `same_variant.txt`]. Tego nie wolno spłaszczyć.
- Nested do **liczenia faset bez warunków na wariant** jest zbędny i to on kosztuje. Drugi poziom (`variants.pairs`)
  nic nie wnosi semantycznie (to rozbicie `variants.options`) i podwaja liczbę dokumentów Lucene (8,9 mln vs 4,0 mln),
  ale sam jego brak daje mało (wariant B: 568 vs 591 ms) – zysk daje dopiero przeniesienie faset na produkt.
- Alternatywa natywna (bez nowych usług), zmierzona na kopii 1 mln: pola produktu `facet_axes`, `facet_options`
  (keyword, różne pary wszystkich wariantów), `purchasable`, `max_gross` (+ istniejące `from_gross`); przy braku
  warunków na wariant fasety z tych pól (`terms`/`filter`/`min`/`max`), faseta kategorii jako `filters`; przy
  warunkach – nested jednego poziomu, a agregacje z pustym własnym zbiorem warunków też z pól produktu. Wyniki
  identyczne z bieżącymi w 10/10 scenariuszy (total, kategorie, available, zakres cen, 10 osi × 30 wartości, wybrane
  wartości; liczby to produkty) i ta sama pierwsza strona wyników [POMIAR `compare.py`, `semantics_flat2.txt`].
  Zysk (took, 1 shard / 4 shardy): cały katalog 591 → 143 / 38 ms (4,1× / 15×); dział 129 → 33 / 10 ms; liść 21 →
  5 / 3 ms; fraza 33 → 9 / 6 ms; jedna wybrana oś 87 → 53 / 17 ms; zapytania z ceną lub 2 osiami bez zmian
  (~110 / ~42 ms; tam koszt to nested po wariantach w dziale). Czas CPU na zapytanie: 687 → 160 ms (cały katalog).
  Przepustowość przy 2 CPU: cały katalog 2,5 → 11–13 q/s, mix 5–6,6 → 17–20 q/s, odsetek > 2 s przy N=8 w mix:
  25% → 0%.
- Koszt zmiany [SZACUNEK]: M. `ProductIndexDefinition` (4 pola, usunięcie `pairs`), `ProductSearchQuery` (dwie ścieżki
  agregacji), `ElasticsearchProductSearch::facets` (odczyt dwóch kształtów), przebudowa indeksu. Ryzyko: dwie ścieżki
  liczenia tej samej fasety – wymagają testu równoważności (jak `compare.py`) na danych z wieloma wariantami.

### (4) Skalowanie do 10 mln produktów

- [POMIAR] 1 mln → 3 mln (powielony indeks): wszystkie zapytania ×2,6–3,1, czyli liniowo w liczbie pasujących
  produktów; same trafienia praktycznie stałe (2–11 ms).
- [SZACUNEK, ekstrapolacja liniowa ×10, jeden wątek Maca → serwer ×2,5–3,5]:

| zapytanie | Mac 1 mln | Mac 10 mln (ekstr.) | serwer 10 mln, 1 shard | serwer 10 mln, 4 shardy (÷~3,5 czasu, ×1,7 CPU) |
|---|---|---|---|---|
| bieżące, cały katalog | 543–591 ms | ~5,5 s | ~14–19 s | ~4–6 s |
| bieżące, dział 20% katalogu | 122–129 ms | ~1,2 s | ~3–4,5 s | ~1–1,3 s |
| flat2, cały katalog | 143–146 ms | ~1,4 s | ~3,6–5 s | ~1–1,5 s |
| flat2, dział | 32–33 ms | ~0,33 s | ~0,8–1,2 s | ~0,25–0,35 s |
| liść / fraza (oba modele) | 5–33 ms | 50–330 ms | 0,1–1,2 s | – |

- Wniosek: przy 10 mln nawet model spłaszczony nie policzy faset całego katalogu w budżecie 2 s na serwerze 8 vCPU
  przy równoległym ruchu (~5 CPU‑s na zapytanie [SZACUNEK]). Fasety bez filtrów dla katalogu i działów są wspólne dla
  wszystkich użytkowników i nie zależą od strony ani sortu – trzeba je liczyć rzadko (patrz 8), a nie przy każdym
  żądaniu.

### (5) Shardy i zasoby dla 10 mln

- Rozmiar: ~1 GB / 1 mln produktów (oba modele) => ~10 GB przy 10 mln; zalecenie Elastic to shardy 10–50 GB
  (https://www.elastic.co/guide/en/elasticsearch/reference/current/size-your-shards.html), więc rozmiar
  uzasadnia 1 shard. Więcej shardów na jednym węźle kupuje tylko krótszy czas pojedynczego zapytania kosztem
  1,7–1,8× CPU [POMIAR]; przy pełnym obciążeniu nie zwiększa przepustowości. Rekomendacja: po rozdzieleniu faset
  i ich buforowaniu 1–2 shardy; 4 tylko jeśli celem jest czas pojedynczego zimnego zapytania przy niskim ruchu
  [HIPOTEZA – do pomiaru 2 shardów].
- Heap: 2 GB wystarcza [POMIAR: 710 MB użyte przy 12 GB indeksów; bitsety nested 16 MB/1 mln → ~160 MB przy 10 mln
  w bieżącym modelu]. Nie zwiększać.
- Page cache: gorący zbiór (doc values + indeks odwrócony) ≈ 0,4 GB / 1 mln => ~4 GB przy 10 mln, plus część
  `_source` (5,6 GB) dla trafień. `prod`: limit 4 Gi przy heapie 2 GB zostawia ~1,5–1,8 GB na page cache (w cgroup
  liczy się do limitu) => przy 10 mln odczyty z dysku w każdej fasecie [SZACUNEK; potwierdzić `node_stats`
  io/`docker stats` przy indeksie > limitu]. Zalecenie: limit ≥ heap + 5 GB (np. 7–8 Gi) albo mniejszy gorący zbiór
  (usunięcie `pairs`, `doc_values:false` na `slug`).
- CPU: request 1 CPU bez limitu, na węźle 8 vCPU współdzielonym z Postgresem i API – to budżet, nie ES, decyduje.
  Szczegóły w znaleziskach.

### (6) Głęboka paginacja

- [KOD] `max_result_window` 10 000 respektowane (`ElasticsearchProductSearch.php:30-31`), strona za oknem ma `size:0`
  – ale nadal liczy wszystkie fasety (pełny koszt zapytania bez żadnego wyniku).
- [POMIAR] Strona 400 kosztuje ~100 ms więcej niż strona 1 (kolejka 9 600 pozycji na shardzie + scalanie);
  `track_total_hits:10000` tego nie zmienia; `search_after` na tej samej pozycji 1 ms. Przy bieżących fasetach to
  +15%, po spłaszczeniu +70–280%.
- Rekomendacja: zostawić okno 10 000 (problem jest ograniczony), nie liczyć faset dla strony za oknem; jeśli sklep
  ma „pokaż więcej”, użyć `search_after` (już stosowane w `ElasticsearchSubtreeProductIds`).

### (7) Indeksowanie

- Pełna przebudowa [KOD `ProductSearchIndexRebuilder::fill`]: jeden proces, szeregowo: strona z Postgresa (keyset po
  `id`, CTE z 3 LATERAL), potem fakty ofert przez `query.bus` w paczkach po 48 (`GetProductOffers::MAX_PRODUCTS`, ~11
  wywołań na paczkę 500), budowa dokumentów, `_bulk`; `refresh_interval:-1` w trakcie, 0 replik, zamiana aliasu – to
  jest zrobione dobrze. Według komentarza w `values.yaml` 1 mln trwał 17 min na serwerze (~980 prod./s) =>
  10 mln ≈ 2,8 h, gdy stronicowanie keyset jest stałe na stronę [SZACUNEK]; limit Joba 6 h.
- Wąskie gardło to PHP/Postgres w jednym wątku, nie ES [POMIAR po stronie ES]: `_reindex` w kontenerze zapisał ≥55
  tys. produktów/s (38,7 mln dok. Lucene w ≤108 s) na 8 CPU Maca; nawet ÷3 dla serwera to ~18 tys./s, ~18× więcej niż
  przebudowa. Rekomendacja: N równoległych procesów po zakresach UUID (rebuilder już ma kursor po `id`), fakty ofert
  jednym zapytaniem na paczkę; szacowany efekt ~N× do nasycenia Postgresa [HIPOTEZA].
- Przyrostowo [KOD `ProductIndexing::reindex`, `ElasticsearchProductIndexer`]: jeden dokument na wiadomość, bez łączenia;
  każda zmiana ceny/stanu = pełny odczyt produktu + oferta + zapis całego dokumentu z wszystkimi wariantami
  (w bieżącym modelu 1 + w + p dokumentów Lucene). Każdy zapis unieważnia request cache shardu przy następnym
  odświeżeniu (1 s). Przebudowa kategorii [KOD `ReindexCategoryBatchHandler`] reindeksuje produkty poddrzewa po
  jednym – dział 20% z 10 mln = 2 mln pojedynczych zapisów.

### (8) Czy cache maskuje problem; rozdzielenie faset od wyników

- Varnish (`max-age` 60 s, klucz = pełny URL) [KOD `ListProductsController.php:135-141`] maskuje: każda kombinacja
  kategoria × strona × sort × filtry to osobny obiekt, więc fasety identyczne dla stron 1..n i 4 sortów są liczone
  od nowa n×4 razy co minutę; zimne wejście (lub wygaśnięcie TTL) płaci pełne ~0,6 s CPU‑Maca. Cache nie naprawia
  kosztu, tylko go rozkłada.
- Rozdzielenie jest uzasadnione pomiarem: wyniki (sort, strona, total) kosztują 2–31 ms i zależą od strony/sortu;
  fasety kosztują 96% i od nich nie zależą. Rekomendacja: (a) najpierw tańsze fasety (model spłaszczony), (b) osobne
  zapytanie faset `size:0` (lub `_msearch` z dwoma zapytaniami w jednym żądaniu) z kluczem bez strony i sortu;
  (c) dla faset bez warunków na wariant (katalog, dział, liść) dłuższy czas życia: ES request cache (`size:0` jest
  domyślnie buforowane: 208 → 3–7 ms [POMIAR]) działa tylko, gdy shard się nie zmienia – zapis 1 dokumentu go
  unieważnia (141 ms po zapisie [POMIAR]), a przy ruchu zamówień zmiana stanu reindeksuje produkty co chwilę. Stąd
  `refresh_interval` 30–60 s (spójność ewentualna do 60 s już przyjęta w specyfikacji SEARCH) albo bufor aplikacji
  (Redis jest w stosie) z TTL 60 s i kluczem kryteriów bez strony/sortu. Bez (a) bufor tylko przesuwa problem
  na zimne wejścia i wygaśnięcia.

## Znaleziska

| # | Znalezisko | Waga | Dowód | Rodzaj | Wzrost z danymi / współbieżnością | Rekomendacja | Efekt / koszt |
|---|---|---|---|---|---|---|---|
| E1 | Fasety liczone zawsze przez nested→nested + 2× reverse_nested, także bez warunków na wariant; 96% czasu listingu | Krytyczna | [POMIAR] ablacja + profil, [KOD] `ProductSearchQuery.php:117-186` | architektura (model indeksu) | liniowo z pasującymi produktami (×~9 dok. Lucene/produkt); CPU-bound, więc latencja rośnie liniowo z N | pola faset na produkcie + dwie ścieżki agregacji; nested tylko gdy agregacja ma warunek na wariant | 4–15× krócej, 4,3× mniej CPU, przepustowość ×3–5 [POMIAR]; M |
| E2 | Nasycenie CPU przy kilku równoległych zimnych listingach => > 2 s => 503/500 | Krytyczna (objaw E1+E5) | [POMIAR] conc 8 CPU/2 CPU, 0 odrzuceń, GC pomijalny | architektura + budżet sprzętu | liniowo z N i z danymi | E1, E3; nie podnosić timeoutu jako lekarstwa | – |
| E3 | Fasety liczone razem z wynikami przy każdym żądaniu, choć nie zależą od strony ani sortu; Varnish trzyma je per URL 60 s | Wysoka | [KOD] `ProductSearchQuery::body`, `ListProductsController:135-141`; [POMIAR] trafienia 2–31 ms vs fasety | architektura | liczba obliczeń ∝ strony × sorty × filtry | osobne zapytanie faset `size:0` z kluczem bez strony/sortu; dla katalogu/działów długi bufor (request cache + `refresh_interval` 30–60 s albo Redis) | do n×4 mniej obliczeń faset; S–M |
| E4 | Faseta kategorii jako `terms` z `include` | Średnia | [POMIAR] 60 vs 10 ms (`filters`) na 1 mln, 145 ms w pełnym zapytaniu | kod | liniowo | `filters` z `term` na każdą kategorię fasety | −50…−135 ms przy całym katalogu; S |
| E5 | ES na węźle 8 vCPU: request 1 CPU, bez limitu, współdzielony z Postgresem/API/sklepem; limit pamięci 4 Gi przy heapie 2 GB | Wysoka przy 10 mln | [KOD] `environments/prod/values.yaml:102-116`; [POMIAR] gorący zbiór 0,4 GB/1 mln | konfiguracja / sprzęt | page cache: ~4 GB przy 10 mln > ~1,8 GB dostępne | limit ≥ heap+5 GB; ustalony budżet CPU dla ES; heap 2 GB zostaje | uniknięcie I/O na każdej fasecie [SZACUNEK]; S |
| E6 | 4 shardy na jednym węźle: krótszy pojedynczy czas, 1,7–1,8× CPU, ta sama przepustowość w nasyceniu | Średnia | [POMIAR] cpu_ms/q 687 vs 1252 (cur), 160 vs 269 (flat2) | konfiguracja | – | po E1/E3: 1–2 shardy; zmierzyć 2 | do −40% CPU na zapytanie; S |
| E7 | Pełna przebudowa szeregowa w PHP (~1 tys. prod./s); ES przyjmuje ≥18× więcej | Średnia (10 mln ≈ 2,8 h) | [KOD] `ProductSearchIndexRebuilder::fill`; [POMIAR] `_reindex` ≥55 tys./s | kod | liniowo | równoległe zakresy UUID, oferty jednym zapytaniem na paczkę, większa paczka | ~N× [HIPOTEZA]; M |
| E8 | Reindeksacja przyrostowa dokument po dokumencie, bez łączenia; zapis unieważnia request cache; przebudowa kategorii po jednym produkcie | Średnia | [KOD] `ProductIndexing`, `ReindexCategoryBatchHandler`; [POMIAR] request cache 3 → 141 ms po zapisie | architektura | liniowo z ruchem zamówień / rozmiarem poddrzewa | deduplikacja/łączenie zmian per produkt w oknie, `_bulk`; `refresh_interval` 30 s | S–M |
| E9 | Strona za oknem (`from ≥ 10 000`) nadal liczy wszystkie fasety; strona 400 +100 ms | Niska | [KOD] `ElasticsearchProductSearch.php:30-33`; [POMIAR] `deep.log` | kod | liniowo (sort) | bez agregacji, gdy `size==0`; `search_after` dla „pokaż więcej” | S |
| E10 | `variants.pairs` (drugi poziom nested) dubluje `variants.options`, ×2,2 dok. Lucene | Niska samodzielnie | [POMIAR] 8,9 vs 4,0 mln dok.; B ≈ bieżące | model | liniowo | usunąć razem z E1 | upraszcza; – |

## Co jest dobrze i czego nie ruszać

- Zapis przez alias z atomową zamianą, `refresh_interval:-1` w trakcie przebudowy, wersje `external_gte`,
  klasyfikacja błędów, brak ponowień klienta przy odczycie – poprawne.
- Filtry wariantu w `post_filter` z nested (ten sam wariant) i fasety bez własnego warunku – semantyka poprawna,
  zachować.
- `track_total_hits:true`, sort po `name.sort` + `id`, `max_result_window` 10 000 – tanie (2–31 ms), nie ruszać;
  `index.sort` i `eager_global_ordinals` niepotrzebne [POMIAR].
- `search_after` w `ElasticsearchSubtreeProductIds` – właściwe.
- Heap 2 GB – wystarcza; nie zwiększać.
- Nie podnosić limitu 2 s klienta jako rozwiązania: przy nasyceniu CPU wydłuża kolejkę, nie przepustowość.

## Pomiary do potwierdzenia na stosie medium (najważniejsze najpierw)

1. Czas CPU ES na jedno zimne `GET /api/v1/products` (cały katalog) na serwerze: `_nodes/stats/process` przed/po 20
   zapytaniach z `request_cache=false` bezpośrednio do ES z treścią z `esq.py` – potwierdza mnożnik serwera (oczek.
   1,7–4,4 CPU‑s).
2. k6 / 8–16 równoległych zimnych listingów (różne kategorie, strony, sorty) z równoczesnym `kubectl top pod` ES,
   Postgres, API – czy ES osiąga 100% dostępnego CPU, a odpowiedzi > 2 s pokrywają się z 503.
3. Ten sam test po wdrożeniu modelu spłaszczonego (E1) i faset `filters` (E4): oczek. odsetek > 2 s przy N=8 → ~0.
4. 2 shardy vs 1 vs 4 na kopii 1 mln: `conc.py` z `--cpus=2` (E6).
5. Indeks > limitu pamięci poda (np. 10 mln albo kopia ×5 przy limicie 4 Gi): pierwszy i mediana `all_name`,
   `docker stats`/`node_stats` fs io – potwierdza E5.
6. Przebudowa z N=4 procesami po zakresach UUID na medium: produkty/s i obciążenie Postgresa (E7).

## Załącznik 1: koszt zapytania listingu

Scenariusze odtworzone z `ProductSearchQuery::body()` (port 1:1 w `esq.py`): `all_name` = cały katalog bez filtrów
(sort po nazwie, faseta kategorii = 7 korzeni), `root_name` = dział „Turystyka” (203 tys. produktów), `leaf_name` =
największy liść (23 tys.), `phrase_*` = fraza, `root_color` / `root_color_size` = filtr opcji (1 / 2 osie),
`root_price` = zakres cen, `all_color_avail` = cały katalog + kolor + „dostępne”, `deep_p400` = strona 400.

Indeks oryginalny (1 shard), took ms, mediana (min–max), `baseline_products.log`:

| scenariusz | took | total |
|---|---|---|
| all_name | 815 (767–1098) | 980 083 |
| all_price_asc | 790 (751–1012) | 980 083 |
| root_name | 185 (179–208) | 202 733 |
| leaf_name | 39 (35–42) | 23 340 |
| phrase_kurtka | 105 (81–198) | 19 319 |
| phrase_typo | 35 (19–62) | 3 |
| root_color | 193 (160–236) | 21 830 |
| root_color_size | 119 (113–132) | 2 422 |
| root_price | 174 (150–196) | 76 477 |
| all_color_avail | 449 (419–484) | 98 673 |
| deep_p400 (from 9576) | 903 (768–958) | 980 083 |

Ablacja `all_name` (indeks oryginalny, `ablation_products_all_name.json`): pełne 803 ms; same trafienia +
`track_total_hits:true` 31 ms; trafienia bez total 7 ms; sam count 4 ms; tylko agregacja `categories` 145 ms,
`available` 45 ms, `price` 78 ms, `free` (nested→nested + 2× reverse_nested) 456 ms; wszystkie agregacje size:0 844 ms.
=> ~96% kosztu to fasety; sama lista produktów to ~30 ms.

## Załącznik 2: warianty modelu na kopiach indeksu

Indeksy testowe w `es-audit` (`_reindex` z oryginału, `mk_indices.py`, `flat_script.painless`; czas: 4 kopie
równolegle po 1 mln produktów w ~7 min):

- `t_orig1` / `t_orig4` – mapping bieżący, 1 / 4 shardy (8,89 mln dok. Lucene, ~1 GB).
- `t_flat1` / `t_flat4` – bez `variants.pairs` (4,01 mln dok. Lucene), w wariancie dodatkowo `variants.axes`
  (keyword), a na produkcie: `facet_axes`, `facet_options` (keyword, różne pary „oś␟wartość” wszystkich wariantów),
  `purchasable` (bool: jest wariant do kupienia), `max_gross` (najwyższa cena wariantu do kupienia; `from_gross` już
  jest).

Warianty zapytania (`variants.py`):
- `cur` – bieżące zapytanie;
- `B` – jeden poziom nested: fasety z `variants.options` / `variants.axes` + `reverse_nested`;
- `Bp` – B + każda agregacja nested z warunkami najpierw zawęża produkty zapytaniem nested tych warunków;
- `flat` – hybryda: bez warunków na wariant (cały katalog, dział, liść, fraza – typowe „zimne wejście”) wszystkie
  fasety z pól produktu, zero agregacji nested; z warunkami na wariant `Bp` (post_filter nested bez zmian);
- `flat2` – `flat` + faseta kategorii jako agregacja `filters` (jeden `term` na kategorię fasety) zamiast `terms`
  z `include` (ablacja: `terms`+`include` 60 ms, `filters` 10 ms, `terms` bez `include` 14 ms na 1 mln produktów).

Poprawność semantyczna (`compare.py`, `semantics_flat2.txt`): dla wszystkich 10 scenariuszy fasety (total,
kategorie, available, zakres cen, 10 osi, top 30 wartości, wybrane wartości) i pierwsza strona wyników są
IDENTYCZNE z bieżącym zapytaniem dla B, Bp, flat (1 shard) i flat2 (4 shardy). Liczby to produkty, nie warianty
(terms po polu produktu liczy dokument produktu raz). Warunek „ten sam wariant” zostaje w nested:
`Kolor=czarne` i `Rozmiar=M` w tym samym wariancie = 8 614 produktów, w dowolnych wariantach (gdyby filtr szedł po
polu płaskim) = 20 214 (`same_variant.txt`) – dlatego pola płaskie służą tylko fasetom bez warunków na wariant.

Took ms, mediana (min–max), 9 powtórzeń, obciążenie maszyny load 11–14 w trakcie (`bench_variants.json`):

| scenariusz | cur 1 shard | cur 4 shardy | B 1 | Bp 1 | flat 1 | flat 4 |
|---|---|---|---|---|---|---|
| all_name | 591 (579–598) | 154 (152–169) | 568 | 606 | 272 | 146 |
| all_price_asc | 1703 (1480–10267) | 1466 (156–3838) | 568 | 599 | 201 | 56 |
| root_name | 129 | 36 | 121 | 126 | 43 | 13 |
| leaf_name | 21 | 7 | 18 | 20 | 5 | 3 |
| phrase_kurtka | 33 | 12 | 27 | 29 | 14 | 7 |
| root_color | 89 | 26 | 97 | 86 | 86 | 27 |
| root_color_size | 70 | 22 | 65 | 42 | 41 | 15 |
| root_price | 119 | 33 | 109 | 112 | 111 | 32 |
| all_color_avail | 357 | 94 | 424 | 370 | 369 | 106 |

`flat2` (faseta kategorii przez `filters`): all_name 143 ms (1 shard) / 38 ms (4 shardy); all_price_asc 149 / 40;
root_name 33 / 10; phrase_kurtka 9 / 6.

Ablacja `flat` all_name (1 shard, `ablation_flat_all_name.txt`): kategorie (`terms`+`include`) 60 ms, available 4,
price 13, axes 12, opts 17 ms.

## Załącznik 3: współbieżność, 8 CPU

`conc.py`: N wątków w zamkniętej pętli przez 15 s, `request_cache=false`; „>2s” = odsetek odpowiedzi wolniejszych niż
limit klienta API (2 s); `rej` = odrzucenia puli `search`; `act/q` = maks. aktywne wątki / kolejka (próbki co 250 ms);
`gc` = ms młodego GC w czasie przebiegu (old GC: 0 we wszystkich przebiegach). `mix` = 10 scenariuszy na zmianę.
Surowe: `conc_cpu8.log`.

| CPU | indeks | zapytanie | N | q/s | p50 | p90 | p99 | >2s | rej | act/q | gc ms |
|---|---|---|---|---|---|---|---|---|---|---|---|
| cpu8 | t_orig1 | all_name | 1 | 1.72 | 579 | 587 | 594 | 0% | 0 | 1/0 | 3 |
| cpu8 | t_orig1 | all_name | 2 | 3.44 | 580 | 588 | 601 | 0% | 0 | 2/0 | 13 |
| cpu8 | t_orig1 | all_name | 4 | 4.57 | 608 | 1411 | 1792 | 0% | 0 | 4/1 | 19 |
| cpu8 | t_orig1 | all_name | 8 | 4.86 | 1673 | 1801 | 1963 | 0% | 0 | 8/1 | 31 |
| cpu8 | t_orig1 | all_name | 16 | 5.28 | 2502 | 4641 | 5078 | 100% | 0 | 13/3 | 35 |
| cpu8 | t_orig1 | all_name | 32 | 4.46 | 6500 | 11223 | 11302 | 100% | 0 | 13/19 | 32 |
| cpu8 | t_orig1 | mix | 1 | 3.97 | 139 | 737 | 859 | 0% | 0 | 1/0 | 4 |
| cpu8 | t_orig1 | mix | 4 | 9.96 | 200 | 1145 | 1456 | 0% | 0 | 4/0 | 13 |
| cpu8 | t_orig1 | mix | 8 | 11.09 | 353 | 2068 | 2434 | 11% | 0 | 8/1 | 30 |
| cpu8 | t_orig1 | mix | 16 | 13.96 | 676 | 2811 | 3667 | 21% | 0 | 13/3 | 27 |
| cpu8 | t_orig1 | mix | 32 | 13.05 | 2255 | 3744 | 5660 | 55% | 0 | 13/19 | 65 |
| cpu8 | t_orig4 | all_name | 1 | 3.39 | 269 | 363 | 555 | 0% | 0 | 4/0 | 16 |
| cpu8 | t_orig4 | all_name | 2 | 4.90 | 390 | 472 | 579 | 0% | 0 | 8/0 | 32 |
| cpu8 | t_orig4 | all_name | 4 | 4.07 | 986 | 1306 | 1485 | 0% | 0 | 13/3 | 28 |
| cpu8 | t_orig4 | all_name | 8 | 5.07 | 1649 | 2095 | 2569 | 23% | 0 | 13/19 | 36 |
| cpu8 | t_orig4 | all_name | 16 | 5.16 | 2333 | 4600 | 5973 | 100% | 0 | 13/51 | 34 |
| cpu8 | t_orig4 | all_name | 32 | 4.44 | 6333 | 8240 | 14542 | 100% | 0 | 13/115 | 47 |
| cpu8 | t_orig4 | mix | 1 | 8.61 | 63 | 291 | 586 | 0% | 0 | 4/0 | 25 |
| cpu8 | t_orig4 | mix | 4 | 11.88 | 213 | 788 | 1095 | 0% | 0 | 13/4 | 43 |
| cpu8 | t_orig4 | mix | 8 | 12.60 | 548 | 1228 | 1508 | 0% | 0 | 13/20 | 50 |
| cpu8 | t_orig4 | mix | 16 | 13.35 | 1093 | 1761 | 2865 | 8% | 0 | 13/49 | 90 |
| cpu8 | t_orig4 | mix | 32 | 8.76 | 3200 | 4262 | 7891 | 100% | 0 | 13/115 | 53 |
| cpu8 | t_flat1 | flat2 all_name | 1 | 4.23 | 224 | 287 | 324 | 0% | 0 | 1/0 | 0 |
| cpu8 | t_flat1 | flat2 all_name | 2 | 7.99 | 246 | 278 | 311 | 0% | 0 | 2/0 | 3 |
| cpu8 | t_flat1 | flat2 all_name | 4 | 10.20 | 356 | 435 | 1112 | 0% | 0 | 4/2 | 3 |
| cpu8 | t_flat1 | flat2 all_name | 8 | 16.04 | 479 | 591 | 680 | 0% | 0 | 8/4 | 4 |
| cpu8 | t_flat1 | flat2 all_name | 16 | 15.09 | 1047 | 1509 | 2097 | 1% | 0 | 13/4 | 3 |
| cpu8 | t_flat1 | flat2 all_name | 32 | 14.50 | 1890 | 3091 | 3596 | 45% | 0 | 13/19 | 7 |
| cpu8 | t_flat1 | mix | 1 | 7.28 | 93 | 391 | 656 | 0% | 0 | 1/0 | 3 |
| cpu8 | t_flat1 | mix | 4 | 18.18 | 121 | 746 | 916 | 0% | 0 | 8/1 | 9 |
| cpu8 | t_flat1 | mix | 8 | 19.98 | 220 | 1130 | 1831 | 0% | 0 | 10/1 | 12 |
| cpu8 | t_flat1 | mix | 16 | 20.52 | 486 | 1677 | 3141 | 10% | 0 | 13/15 | 13 |
| cpu8 | t_flat1 | mix | 32 | 25.70 | 477 | 1666 | 7222 | 8% | 0 | 13/35 | 33 |
| cpu8 | t_flat4 | flat2 all_name | 1 | 3.11 | 268 | 514 | 1061 | 0% | 0 | 4/2 | 5 |
| cpu8 | t_flat4 | flat2 all_name | 2 | 49.06 | 40 | 42 | 47 | 0% | 0 | 8/1 | 36 |
| cpu8 | t_flat4 | flat2 all_name | 4 | 48.06 | 88 | 96 | 109 | 0% | 0 | 13/3 | 38 |
| cpu8 | t_flat4 | flat2 all_name | 8 | 47.51 | 192 | 208 | 257 | 0% | 0 | 13/16 | 42 |
| cpu8 | t_flat4 | flat2 all_name | 16 | 32.36 | 421 | 820 | 1225 | 0% | 0 | 13/51 | 39 |
| cpu8 | t_flat4 | flat2 all_name | 32 | 21.25 | 1397 | 1546 | 2885 | 9% | 0 | 13/115 | 37 |
| cpu8 | t_flat4 | mix | 1 | 19.66 | 38 | 85 | 205 | 0% | 0 | 8/1 | 34 |
| cpu8 | t_flat4 | mix | 4 | 29.32 | 104 | 299 | 510 | 0% | 0 | 13/29 | 66 |
| cpu8 | t_flat4 | mix | 8 | 28.70 | 226 | 581 | 786 | 0% | 0 | 13/44 | 104 |
| cpu8 | t_flat4 | mix | 16 | 30.75 | 459 | 913 | 1195 | 0% | 0 | 13/75 | 119 |
| cpu8 | t_flat4 | mix | 32 | 31.18 | 942 | 1319 | 2004 | 1% | 0 | 13/118 | 104 |

Czas CPU procesu ES na zapytanie (`_nodes/stats` process.cpu, przebieg 12 s, `conc_results.jsonl` label cpu8-cpu):

| indeks / zapytanie | N=1: q/s, p50, CPU ms/zapytanie | N=8: q/s, p50, CPU ms/zapytanie |
|---|---|---|
| t_orig1 cur all_name | 1,46; 691; 687 | 4,18; 1672; 1872 |
| t_orig4 cur all_name | 3,21; 314; 1252 | 5,13; 1566; 1572 |
| t_flat1 flat2 all_name | 6,12; 157; 160 | 21,6; 368; 361 |
| t_flat4 flat2 all_name | 14,3; 66; 269 | 20,0; 391; 402 |

Obserwacje: (a) nasycenie to CPU (es-audit 780–806% CPU w `docker stats` przy N=8), bez odrzuceń, kolejka
najwyżej 19–118, GC pomijalny (≤120 ms na 15 s, 0 old GC, heap 20–66%); (b) jedno zapytanie na jednym shardzie
wykonuje jeden wątek (2 zadania puli `search` na żądanie: query + fetch; 4 shardy: 9 zadań; `intra_shard_parallel.txt`);
(c) 4 shardy skracają czas pojedynczego zapytania 2–4×, ale zużywają 1,7–1,8× więcej CPU na zapytanie (N=1), więc
przy nasyceniu przepustowość 1 i 4 shardów jest praktycznie taka sama; (d) czas CPU na zapytanie rośnie przy N=8
1,3–2,7× (rywalizacja o pamięć/cache CPU na współdzielonej maszynie; nie rozdzielam przyczyn).

## Załącznik 4: symulacja przepustowości serwera, `docker update --cpus=2`

Serwer: 8 vCPU, rdzeń 2,5–3,5× wolniejszy od tego Maca => pojemność ≈ 2,3–3,2 rdzenia Maca. `--cpus=2` przybliża
POJEMNOŚĆ serwera (lekko pesymistycznie), ale nie czas pojedynczego zapytania (ten trzeba mnożyć ×2,5–3,5).
Surowe: `conc_cpu2.log` (przebieg `t_orig1 mix N=1` nieudany – 1 błąd w rozgrzewce, pominięty).

| CPU | indeks | zapytanie | N | q/s | p50 | p90 | p99 | >2s | rej | act/q | gc ms |
|---|---|---|---|---|---|---|---|---|---|---|---|
| cpu2 | t_orig1 | all_name | 1 | 1.39 | 654 | 927 | 994 | 0% | 0 | 1/0 | 3 |
| cpu2 | t_orig1 | all_name | 2 | 2.44 | 812 | 928 | 961 | 0% | 0 | 2/0 | 6 |
| cpu2 | t_orig1 | all_name | 4 | 2.38 | 1651 | 1897 | 1970 | 0% | 0 | 4/0 | 7 |
| cpu2 | t_orig1 | all_name | 8 | 2.51 | 3192 | 3292 | 3377 | 100% | 0 | 8/0 | 79 |
| cpu2 | t_orig4 | all_name | 1 | 2.42 | 403 | 472 | 510 | 0% | 0 | 4/0 | 9 |
| cpu2 | t_orig4 | all_name | 2 | 2.10 | 909 | 1209 | 1595 | 0% | 0 | 8/0 | 8 |
| cpu2 | t_orig4 | all_name | 4 | 1.84 | 1993 | 3001 | 3593 | 45% | 0 | 13/3 | 10 |
| cpu2 | t_orig4 | all_name | 8 | 1.99 | 3606 | 5382 | 5890 | 88% | 0 | 13/19 | 8 |
| cpu2 | t_orig1 | mix | 4 | 4.79 | 416 | 2114 | 3766 | 13% | 0 | 4/0 | 108 |
| cpu2 | t_orig1 | mix | 8 | 6.58 | 601 | 3778 | 4288 | 25% | 0 | 8/0 | 14 |
| cpu2 | t_orig1 | mix | 16 | 5.46 | 1703 | 6791 | 8045 | 41% | 0 | 13/4 | 14 |
| cpu2 | t_orig4 | mix | 1 | 5.30 | 97 | 500 | 689 | 0% | 0 | 4/0 | 61 |
| cpu2 | t_orig4 | mix | 4 | 5.08 | 492 | 1901 | 2525 | 10% | 0 | 13/3 | 90 |
| cpu2 | t_orig4 | mix | 8 | 5.72 | 1106 | 2496 | 4308 | 25% | 0 | 13/19 | 14 |
| cpu2 | t_orig4 | mix | 16 | 6.55 | 1990 | 3494 | 4910 | 43% | 0 | 13/51 | 18 |
| cpu2 | t_flat1 | flat2 all_name | 1 | 5.22 | 187 | 223 | 276 | 0% | 0 | 1/0 | 0 |
| cpu2 | t_flat1 | flat2 all_name | 2 | 10.85 | 181 | 200 | 216 | 0% | 0 | 2/0 | 2 |
| cpu2 | t_flat1 | flat2 all_name | 4 | 11.04 | 373 | 394 | 400 | 0% | 0 | 4/2 | 8 |
| cpu2 | t_flat1 | flat2 all_name | 8 | 11.87 | 696 | 785 | 813 | 0% | 0 | 8/0 | 2 |
| cpu2 | t_flat4 | flat2 all_name | 1 | 12.91 | 87 | 90 | 93 | 0% | 0 | 4/0 | 55 |
| cpu2 | t_flat4 | flat2 all_name | 2 | 13.11 | 182 | 191 | 197 | 0% | 0 | 8/0 | 10 |
| cpu2 | t_flat4 | flat2 all_name | 4 | 12.20 | 303 | 398 | 495 | 0% | 0 | 13/4 | 11 |
| cpu2 | t_flat4 | flat2 all_name | 8 | 12.46 | 703 | 805 | 909 | 0% | 0 | 13/19 | 90 |
| cpu2 | t_flat1 | mix | 1 | 10.00 | 86 | 163 | 395 | 0% | 0 | 2/0 | 3 |
| cpu2 | t_flat1 | mix | 4 | 20.44 | 98 | 359 | 790 | 0% | 0 | 4/0 | 4 |
| cpu2 | t_flat1 | mix | 8 | 19.24 | 309 | 1416 | 1695 | 0% | 0 | 9/1 | 6 |
| cpu2 | t_flat1 | mix | 16 | 18.10 | 694 | 2591 | 3206 | 10% | 0 | 13/15 | 5 |
| cpu2 | t_flat4 | mix | 1 | 17.67 | 33 | 93 | 249 | 0% | 0 | 4/0 | 20 |
| cpu2 | t_flat4 | mix | 4 | 17.07 | 200 | 502 | 903 | 0% | 0 | 13/29 | 20 |
| cpu2 | t_flat4 | mix | 8 | 16.74 | 401 | 901 | 1302 | 0% | 0 | 13/36 | 104 |
| cpu2 | t_flat4 | mix | 16 | 16.77 | 895 | 1700 | 2291 | 3% | 0 | 13/58 | 21 |

Czas CPU na zapytanie przy 2 CPU jest stały względem N (cur all_name 1 shard 719–864 ms, 4 shardy 851–1120 ms; flat2
all_name 1 shard 170–196 ms, 4 shardy 152–164 ms; cur mix 304–415 ms, flat2 mix 98–124 ms) – inflacja z przebiegu
8 CPU to rywalizacja na współdzielonym hoście.

## Załącznik 5: pierwsze zapytanie po zapisie i odświeżeniu (globalne ordinals)

`refresh_test.py`: zapis 1 dokumentu + `_refresh` + natychmiastowe all_name, 5 razy (`refresh_test.log`):
t_orig1 cur: rozgrzane 571 ms, pierwsze po odświeżeniu 577 ms (refresh 8 ms); t_flat1 flat2: 142 / 141 ms
(refresh 7 ms). Przebudowa globalnych ordinals pól faset (kilkaset wartości) jest niemierzalna przy 1 mln =>
`eager_global_ordinals` nic nie da; hipoteza „zimne ES po zapisie” odrzucona dla tej skali. [POMIAR]

## Załącznik 6: skalowanie, strona głęboka, request cache, rozmiar pól

Skalowanie (`scale.py`, `scale.json`): kopie ×3 (`t_orig1_x3`: 3 000 573 produktów, 26,7 mln dok. Lucene, 3,8 GB;
`t_flat1_x3`: 12,0 mln dok., 3,8 GB; produkty powielone z nowymi `_id`, ten sam rozkład). Took ms (mediana z 9,
w nawiasie pierwsze zapytanie po zbudowaniu):

| scenariusz | cur 1M | cur 3M | ×  | flat2 1M | flat2 3M | × |
|---|---|---|---|---|---|---|
| all_name | 543 (870) | 1619 (1889) | 2,98 | 146 (299) | 424 (703) | 2,90 |
| root_name | 122 | 361 | 2,96 | 32 | 93 | 2,91 |
| leaf_name | 21 | 58 | 2,76 | 5 | 13 | 2,6 |
| phrase_kurtka | 33 | 90 | 2,73 | 10 | 11 | 1,1 |
| root_color | 87 | 266 | 3,06 | 80 | 238 | 2,98 |
| all_color_avail | 350 | 1038 | 2,97 | 332 | 1033 | 3,11 |

=> koszt faset rośnie liniowo z liczbą produktów pasujących do zapytania (dokładnie: z liczbą dokumentów wariantów
i par tych produktów). Same trafienia (sort po nazwie, total) 2–11 ms przy 1 i 3 mln (`hits_scale.txt`).

Strona głęboka (`deep.py`, `deep.log`, all_name; took ms): 

| indeks | p1 | p100 | p400 | p417 | same trafienia p1 / p400 | search_after na pozycji 9576 |
|---|---|---|---|---|---|---|
| t_orig1 cur | 548 | 582 | 673 | 670 | 13 / 109 | 1 (bez total), 14 (z total) |
| t_flat1 flat2 | 140 | 171 | 241 | 240 | 10 / 99 | 1 / 11 |
| t_flat4 flat2 | 38 | 70 | 143 | 146 | 1 / 105 | 1 / 1 |

`track_total_hits: 10000` zamiast `true` nie zmienia kosztu głębokiej strony (110 vs 109 ms) – kosztem jest kolejka
sortowania 9 600 pozycji (na każdym shardzie) i jej scalanie, nie liczenie.

Request cache (`request_cache.txt`, t_orig4, fasety all_name `size:0`, `request_cache=true`): pierwsze 208 ms, potem
7 i 3 ms; po zapisie 1 dokumentu z odświeżeniem: 141 i 139 ms (pamięć podręczna shardu unieważniona).

Rozmiar pól (`_disk_usage`, `disk_usage_t_orig1.json`): na 1 mln produktów `_source` 563 MB (czytany tylko dla 24
trafień), doc values razem 127 MB, indeks odwrócony 259 MB. Gorący zbiór zapytania listingu (doc values pól faset
i sortu + postings filtrów i frazy) ≈ 0,4 GB / 1 mln produktów. Heap: 710 MB z 2048 MB przy 12 GB indeksów na węźle,
`fixed_bit_set` (bitsety rodziców nested) 16 MB, 0 old GC, 0 zadziałań breakerów (`nodestats_end.json`).

Hybryda per agregacja (`flat3`, `ablation_flat3_all_color_avail.txt`): agregacja, której własny zbiór warunków jest
pusty (np. wartości wybranej osi przy jednej wybranej osi), liczona z pola produktu – wynik identyczny z bieżącym;
root_color 87 → 53 ms (1 shard) / 17 ms (4 shardy). W `all_color_avail` 241 z 331 ms to agregacja `selected_0`
z jedynym warunkiem `purchasable` (nested po ~3 mln wariantów); pole produktu z opcjami wariantów do kupienia
(np. `facet_options_buyable`) zrobiłoby z niej dokładny `terms` za ~15–20 ms (jak `opts` w ablacji) – [SZACUNEK],
nie zbudowałem tego pola.

Throughput przebudowy po stronie ES (`_reindex` wewnątrz kontenera, bez PHP): 4 kopie po 1 mln produktów (w tym jedna
ze skryptem painless) równolegle w ~7 min przy obciążonej maszynie; kopie ×3 (3 mln + 3 mln produktów, 38,7 mln dok.
Lucene) w ≤108 s, tj. ≥55 tys. produktów/s łącznie na 8 CPU Maca.


Indeksy testowe zostawione w `es-audit` (kontener zatrzymany): `t_orig1`, `t_orig4`, `t_flat1`, `t_flat4` (kopie ×3 usunięte). Oryginał i jego alias nietknięte.
