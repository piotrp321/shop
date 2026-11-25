# Indeks katalogu na worf.pl: 1 shard kontra 4 shardy (2026-11-25)

Pomiar z #928 na serwerze demonstracyjnym worf.pl (OVH VPS, jeden węzeł k3s, 8 vCPU, 22,9 GiB RAM), na danych `medium`
(1 000 191 produktów, 1 000 001 zamówień). Wdrożona rewizja: `a5056dbc` (wdrożenie run 45596728726 z 2026-11-25),
`elasticsearch.shards: 4` w `prod`. Pomiar A na indeksie z 1 shardem (wygenerowany 2026-11-20), potem
`make -C infra/k8s reindex` za zgodą właściciela w rozmowie, potem pomiar B na nowym indeksie z 4 shardami. Czasy w UTC.
Liczby opisują ten serwer, te dane i tę chwilę; nie są pomiarem profilu `server`.

## Metoda

- Wyłącznie żądania GET z komputera operatora przez Cloudflare (`curl`, czas do pierwszego bajtu), skrypty w
  `docs/reports/search-shards-worf-2026-11-25/scripts/`: `measure.py` (plan z #928), `measure-shallow.py` (płytkie
  strony i głębokość strony), `facets.py` (zgodność facetów, skrypt z #928).
- A: 12:19–12:33, B: 14:14–14:25, na tych samych zapytaniach; kolejne zimne strony to inne numery `?page=K` (osobne
  obiekty Varnisha).
- Zasoby z Grafana Cloud (szczyty w oknie pomiaru). Narzut sieci: mediana `/api/v1/categories` 0,22 s (bez wyszukiwarki).

## Przebudowa indeksu

`make -C infra/k8s reindex`: Job `reindex-20261125135332`, 13:53:23–14:13:26 (20 min), 1 000 191 produktów,
849 dokumentów na sekundę. Alias `products` przeszedł z `products_20261120135319397_b9bde0` (1 shard) na
`products_20261125135336053_21043c` (4 shardy), 8 891 973 dokumentów Lucene w obu. Sklep był w tym czasie otwarty.
Szczyt w trakcie: Elasticsearch 0,26 rdzenia i 3248 MiB, Postgres 0,45 rdzenia.

**Fasety:** 36 zapytań (cały katalog, sortowanie, dostępność, zakres cen, działy, podkategorie, frazy, wartości osi):
pliki sprzed i po przebudowie są identyczne bajt po bajcie (`cmp`, `results/facets-*.json`).

## Wynik: zapytania pojedyncze (mediana TTFB, statusy)

| Zapytanie | A: 1 shard | B: 4 shardy |
|---|---|---|
| `/api/v1/categories` (bez wyszukiwarki) | 0,22 s | 0,22 s |
| `/api/v1/products`, cały katalog (980 083 trafień) | 1,86 s | 0,69 s |
| dział „Bieganie” | 0,54 s | 0,34 s |
| fraza „buty biegowe” | 0,35 s | 0,27 s |
| liść „Akcesoria biegowe” | 0,25 s | 0,23 s |
| API, strona 20 / 100 / 200 | 1,74 / 2,00 / 2,10 s | 0,72 / 0,95 / 1,05 s |
| API, strona 300 / 400 | 503 / 503 | 1,31 / 1,37 s |
| API, strona 417 (ostatnia) | 503 | 200, 1,49 s |
| sklep `/?page=417` (ostatnia) | 500 | 200, 1,60 s |
| sklep, zimne `/?page=2…11` | 1,98 s | 0,94 s |
| sklep, zimne `/?page=201…210` | 2,39 s, 4 × 500 | 1,39 s, 10 × 200 |
| sklep `/`, po 90 s (5 prób) | 2,02 s | 1,08 s |
| sklep `/`, ciepły Varnish | 0,34 s | 0,34 s |

## Wynik: równoległe wejścia (N jednoczesnych, 3 rundy)

| N | API A | API B | Sklep A | Sklep B |
|---|---|---|---|---|
| 1 | 1,76 s, 200 | 0,72 s, 200 | 2,07 s, 200 | 1,05 s, 200 |
| 2 | 1,83 s, 200 | 0,88 s, 200 | 2,19 s, 200 | 1,16 s, 200 |
| 4 | 1,89 s, 200 | 1,38 s, 200 | 2,22 s, 200 | 1,69 s, 200 |
| 8 | 2,19 s, 8 z 24 × 503 | 2,25 s, 8 z 24 × 503 | 2,43 s, 18 z 24 × 500 | 2,53 s, 24 z 24 × 500 |

Wiersze dotyczą płytkich stron (API 12–35, sklep 60–83). Na stronach 220–270 przy 1 shardzie już pojedyncze wejście
dawało 503 i 500; przy 4 shardach pojedyncze i podwójne przechodzą, przy 4 jednoczesnych 1 z 12 to 503
(`results/measure-*.json`).

## Zasoby (szczyty w oknie pomiaru)

| | A: 1 shard | B: 4 shardy |
|---|---|---|
| Elasticsearch, CPU | 2,15 rdzenia | 3,31 rdzenia |
| Elasticsearch, working set (limit 4096 MiB) | 2867 MiB | 3589 MiB |
| API, CPU | 0,02 rdzenia | 0,06 rdzenia |
| Postgres, CPU | 0,03 rdzenia | 0,01 rdzenia |

Restartów kontenerów w przestrzeni `shop` w trakcie: 0.

## Wnioski

- 4 shardy skracają zapytanie szerokiego zbioru ok. 2,5–2,7 raza (1,86 → 0,69 s dla całego katalogu) i usuwają
  błędy przy pojedynczych wejściach na głębokie strony: ostatnia strona listingu (`/?page=417`) otwiera się
  (kryterium 4 z #794, #954).
- Nie zwiększają przepustowości: przy 8 jednoczesnych zimnych wejściach wynik jest taki sam jak przy 1 shardzie
  (wszystkie strony sklepu 500, jedna trzecia odpowiedzi API 503). Elasticsearch zużywa więcej CPU (3,31 wobec
  2,15 rdzenia), bo każde zapytanie liczy agregacje na czterech shardach równolegle.
- Working set Elasticsearcha wzrósł do 88% limitu kontenera (3589 z 4096 MiB) przy indeksie 1 GB i stercie 2 GiB.
- Fasety i liczba trafień są takie same jak przy 1 shardzie dla wszystkich 36 zapytań porównania.
- Dla profilu `server` (10 mln produktów) koszt zapytania szerokiego zbioru rośnie z katalogiem. Liniowa
  ekstrapolacja z B daje ok. 5–7 s dla całego katalogu, czyli 503 na zimnej stronie głównej; to szacunek, nie pomiar.
  Shardy same nie wystarczą przed `server` (R4 w #781: osobny cache facetów).

## Ograniczenia

- Jedna seria pomiarów każdej konfiguracji, w różnych godzinach tego samego dnia; ruch innych odwiedzających nie był
  kontrolowany. Pomiar sam generował błędy 503 i 500 widoczne w logach i Sentry.
- Czasy zawierają sieć i Cloudflare (ok. 0,2 s). Bezpośredniego `took` Elasticsearcha nie mierzono (wymaga polecenia
  na serwerze).
- Working set Elasticsearcha obejmuje cache plików; zajętości sterty JVM nie zbieramy.
