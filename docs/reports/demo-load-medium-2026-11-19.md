# Pomiar obciążeniowy na danych `medium` 2026-11-19

Raport z `make -C infra/k8s load LOAD_DATA=medium` (DEMO-LOAD-6; spec: `docs/features/demo-data/spec.md`, „k3d”, „Pomiar k6”,
„Wyniki przebiegu `medium`”). Po zapisaniu się nie zmienia: kolejny pomiar to nowy plik. Wszystkie liczby pochodzą z plików wyników
przebiegu (`environment.txt`, `demo-data-steps.txt`, `sizes.txt`, `space.log`, `top.log`, `node-stats.log`, `ramp.txt`, `steady.txt`,
`steady-rate`); wyliczenia własne są oznaczone. Raport opisuje dane `medium` (1 mln produktów, 1 mln zamówień, 100 tys. klientów) na tej
maszynie, nie skalę docelową: pomiar `full` (10 mln produktów, 50 mln zamówień) się nie odbył (W3 w specyfikacji).

## Nagłówek

| | |
|---|---|
| Data pomiaru | 2026-11-19, start 03:32 UTC (pierwszy wiersz `environment.txt` 03:32:09Z) |
| Rewizja | `aceeab48a899999475f26dbc3c355f147712a426` (obrazy zbudowane lokalnie z niej i otagowane `r1`) |
| Polecenie | `make -C infra/k8s load LOAD_DATA=medium LOAD_OUT=<katalog poza repo>`, ustawienia domyślne |
| Kod wyjścia | 0 (pomiar wykonany; skrypt nie ma progów zaliczenia) |
| Wynik surowy | poza repo (katalog `LOAD_OUT`, przebieg r5); do repo trafia tylko ten raport |
| Przebieg kontrolny | przebieg z 2026-11-18 (r3, rewizja wcześniejsza, bez `space.log` i próbkowania generatora) dał podobne czasy kroków (Job 1706 s wobec 1731 s) |
| Przebieg odrzucony | r4: komputer w jego trakcie zasypiał, więc jego czasy są bezwartościowe i nie są tu użyte |

## Host i środowisko

| | |
|---|---|
| Host | Darwin 27.2.0, arm64, obciążenie przed startem (`uptime`): 1,60 / 2,30 / 3,68 |
| Docker | Docker Desktop, Docker 29.9.3, VM: 16 CPU, 32041 MiB (31,3 GiB) |
| Klaster | k3d, `rancher/k3s:v1.36.6-k3s1`, profil chartu `load` |
| Generator obciążenia | k6 `grafana/k6:2.3.0@sha256:2dd4756e…0fba0b8`, w sieci klastra |
| Wątki API | 32 (FrankenPHP); wirtualni użytkownicy checkoutu: 8 |
| Inne kontenery na hoście | stos obserwowalności agentów (Grafana, kolektor OTel, Prometheus, Loki) działał w tle |
| Limity | Postgres 2 GiB, Elasticsearch 4 GiB, Job generatora 4 GiB (`resources.demoData`), api 2 GiB |

Obrazy usług bez naszego kodu jak w `environment.txt` (m.in. `postgres:18.6`, `keycloak:26.6.0`, `redis:8.10.3`, `rabbitmq:4.4.0-management`).

## Generator (`bin/generate-demo --profile=medium`)

Job: 1731 s (`environment.txt`); od pierwszego do ostatniego wiersza logu 1699 s (`demo-data-steps.txt`). Kroki (czas od końca poprzedniego do jego
ostatniego wiersza `[OK]`):

| Krok | Czas | Tabele kroku (wiersze z `sizes.txt`; przypisanie do kroku z nazw tabel i kroków, nie z kodu) | Tempo |
|---|---|---|---|
| produkty | 49 s | `catalog_product` 1 001 615 | około 20 tys. produktów/s |
| zdjęcia | 139 s | pula 4317 plików do Garage, klucze zdjęć produktów | |
| ceny | 40 s | `pricing_price_history` 3 911 223, `pricing_variant_price` 3 009 268 | około 98 tys. wierszy historii/s |
| konta (zgodność kont z Keycloak) | 162 s | `ordering_customer` 98 578 i konta w Keycloak | około 600 kont/s |
| zamówienia | 138 s | `ordering_order` 1 000 072, `ordering_order_line` 1 899 924, `ordering_order_status_change` 3 931 317, `payment_payment` 960 053 | około 7 tys. zamówień/s |
| stany | 75 s | `inventory_stock_item` 3 009 332, `inventory_stock_movement` 3 244 637 | około 40 tys. pozycji/s |
| sprzedaż godzinowa | 35 s | `ordering_sales_hour` 315 660 | |
| przebudowa indeksu | 447 s | 1 mln produktów, 8 891 973 dokumentów | około 2,2 tys. produktów (20 tys. dokumentów)/s |
| zgodność danych (`demo-data:verify`) | 596 s | | |

Przebieg kontrolny r3 (s): produkty 68, zdjęcia 178, ceny 52, konta 172, zamówienia 215, stany 79, sprzedaż 31, przebudowa indeksu 454,
`demo-data:verify` 412; razem 1686 (od pierwszego do ostatniego wiersza). Suma jest zbliżona do r5 (1699), ale podział inny: zamówienia i zdjęcia
wolniejsze, `verify` szybsze o 184 s.

## Rozmiary (`sizes.txt`)

Bazy: `shop` 9 004 512 959 B (8587 MB), `keycloak` 147 396 287 B (141 MB). Indeks Elasticsearcha `products_20261119030714601_203343`: 1 060 608 175 B
(1,06 GB), 8 891 973 dokumentów, 1 shard, 0 replik.

Największe tabele z indeksami:

| Tabela | Razem | Z tego indeksy | Wiersze (szac.) |
|---|---|---|---|
| `catalog_product` | 1586 MB | 606 MB | 1 001 615 |
| `ordering_order` | 1163 MB | 748 MB | 1 000 072 |
| `inventory_stock_movement` | 1084 MB | 600 MB | 3 244 637 |
| `pricing_price_history` | 915 MB | 435 MB | 3 911 223 |
| `ordering_order_line` | 816 MB | 411 MB | 1 899 924 |
| `catalog_product_variant` | 770 MB | 471 MB | 3 009 381 |
| `ordering_order_status_change` | 767 MB | 329 MB | 3 931 317 |
| `payment_payment` | 408 MB | 187 MB | 960 053 |
| `inventory_stock_item` | 391 MB | 142 MB | 3 009 332 |
| `pricing_variant_price` | 387 MB | 119 MB | 3 009 268 |

Wyliczenia własne (z `sizes.txt`): tabele produktowe (produkty, warianty, stany, ruchy stanów, ceny, historia cen, kategorie produktów)
5,418 GB; tabele zamówieniowe (zamówienia, linie, zmiany statusu, płatności, rezerwacje i ich linie, sprzedaż godzinowa, użycia kodów)
3,435 GB; klienci 0,140 GB.

## Dysk (`space.log`)

Pierwszy odczyt (przed startem): VM 872 861 396 KiB wolnych, host 181 071 464 KiB. Najmniej wolnego w VM: 852 826 364 KiB, czyli szczyt zużycia
**20,52 GB** względem pierwszego odczytu (na hoście 20,69 GB); na końcu przebiegu 20,08 GB. Wyliczenie własne: w chwili startu generatora
zużycie wynosiło około 9,2 GB (obrazy, klaster), dane (Postgres, Keycloak, Elasticsearch) 10,21 GB, reszta około 1,15 GB (m.in. pula zdjęć
w Garage, WAL). Pomiar wykonano przy obrazach z cache: zimna budowa dodaje warstwy obrazów.

## Pamięć i CPU

Szczyty z `top.log` (próbki co 10 s, więc krótszy szczyt mógł zostać pominięty) i `node-stats.log`:

| Pod | Pamięć | Limit | CPU |
|---|---|---|---|
| Postgres | 466 MiB | 2 GiB | 4009 m |
| Job generatora (`demo-data-load`) | 187 MiB | 4 GiB | 1537 m |
| Elasticsearch | 3204 MiB | 4 GiB | 848 m |
| Keycloak | 1117 MiB | 2 GiB | 3936 m |
| api | 221 MiB | 2 GiB | 3870 m |
| store | 503 MiB | 1536 MiB | 1759 m |
| RabbitMQ | 416 MiB | 1 GiB | 239 m |
| Varnish | 171 MiB | 512 MiB | 39 m |
| Garage | 57 MiB | 1 GiB | 682 m |
| worker | 50 MiB | 1 GiB | 120 m |

Cały węzeł (`node-stats.log`): szczyt pamięci 7,47 GiB z 31,29 GiB, szczyt CPU 915% (z 1600%).

## Wyniki k6

Dane do k6: 60 produktów z 3 porcji mapy strony (`DISCOVER_CHUNKS`), stan wariantów tych porcji podniesiony o 100 000 (83 933 wiersze).

### Rampa (`ramp.txt`)

Nasycenie w kroku 18 (p95 powyżej 2 s; `abortOnFail`), najwyżej **177,67 iteracji/s**, opuszczone iteracje 192. Brak błędów 5xx i sieci,
brak 409 i 429 w żadnym kroku.

| Krok (docelowo/s) | żądań/s | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|
| 0 (10) | 11,97 | 20,5 | 93 | 177,6 |
| 5 (60) | 73,5 | 10 | 61,7 | 94,9 |
| 10 (110) | 133,03 | 12,3 | 63,9 | 116,7 |
| 13 (140) | 165,23 | 15,1 | 77,4 | 157,8 |
| 14 (150) | 179,77 | 18,7 | 116,8 | 250,1 |
| 15 (160) | 193,43 | 22,7 | 166,6 | 336,9 |
| 16 (170) | 202,9 | 77,1 | 420 | 497 |
| 17 (180) | 212,73 | 360,7 | 1163,4 | 1345,7 |
| 18 (190) | 157,73 | 664,3 | 2052,4 | 2312,1 |

Zbiorcze p95 w krokach 0–15 nie przekracza 167 ms; opóźnienia rosną w krokach 16–18.

Wyniki według typu żądania dla całej rampy, razem z krokami nasycenia 16–18 (p95 / p99, ms): odczyt 1143,6 / 1896,3; koszyk 424,1 / 679,5;
home 1511,7 / 1938; category 1603,7 / 2105,6; product 219,2 / 300,6; search 1428,7 / 1861,3; search_filtered 1487,8 / 2078,5; cart_add 583,7 / 788,1;
cart_read 487,5 / 644,2; cart_page 181,7 / 252,2. Rozbicia typów na kroki ten plik nie zawiera, więc nie wiadomo, czy wysokie p95 home,
category i wyszukiwarki pochodzi z samych kroków 16–18 (bardzo prawdopodobne wobec zbiorczego p95 poniżej 167 ms w krokach 0–15).

### Bieg ciągły na 124 iteracji/s (70% nasycenia; `steady.txt`)

Odczyt i koszyk (strona maksymalnej przepustowości), 148,51 żądań/s: odczyt p95 177,9 ms (p99 355,3), koszyk p95 70,4 ms (p99 106); home 171,5, category 179,9,
product 28,6, search 189,9, search_filtered 249,9 (p95, ms); 0% błędów 5xx i sieci, brak 409 i 429.

Checkout przy ograniczonej równoległości 8 (nie benchmark): 146,01 żądań/s, p95 98,9 ms; `checkout_cart` p95 72,4 ms, `checkout` p95 114,3 ms (p99 141,5);
złożonych zamówień 14 601, rozpoczętych płatności 14 601, 0% błędów 5xx, opuszczonych iteracji scenariusza odczytu 0.

## Uwagi i ograniczenia

- Pomiar jest jeden (r5) z jednym przebiegiem kontrolnym czasów generatora (r3); powtarzalność k6 nie jest zmierzona.
- Wysokie p95 strony głównej, kategorii i wyszukiwarki tuż przed nasyceniem jest zgodne z hipotezą H1 (`docs/features/demo-data/spec.md`, „Ryzyka”: dokładna liczba
  wyników i agregacje przy każdym chybieniu cache), ale ten pomiar jej nie rozstrzyga: nie badano przyczyny (profil Elasticsearcha, CPU, kolejki).
- Wszystko dotyczy `medium`. Liczby `server` i `full` w specyfikacji (rozmiary, dysk, czasy Joba) są ekstrapolacją z tego przebiegu, niezweryfikowaną pomiarem.
