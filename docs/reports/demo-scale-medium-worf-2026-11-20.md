# DEMO-SCALE: przebieg `medium` na worf.pl (2026-11-20)

Pierwszy przebieg `make -C infra/k8s generate PROFILE=medium` na serwerze demonstracyjnym worf.pl (OVH VPS, jeden węzeł
k3s, 8 vCPU, 22,9 GiB RAM, dysk 192,9 GiB). Wdrożona rewizja: `f33ac36a` (wdrożenie run 44737505037). Zgoda
użytkownika w rozmowie: „Tak, generate medium” (operacja `generate`, host worf.pl, profil `medium`, potem `open`).
Czasy w UTC. Liczby opisują ten serwer i ten profil; nie są pomiarem profilu `server`.

Źródła: wyjście `generate` i `open` na komputerze operatora, log Joba `demo-data-run` i metryki z Grafana Cloud
(Loki i Prometheus, retencja 14 dni), sonda HTTP z komputera operatora.

## Wynik

`generate` zakończył się kodem 0: dane wygenerowane, sprawdzone, sklep otwarty.

| Krok | Czas |
|---|---|
| `tak`, zamknięcie sklepu (reguła `shop-closed`, restart Traefika, sonda: oba hosty 502) | 13:25:08–13:25:49 |
| Job `demo-data-run` (od pierwszej linii logu do `exit 0`) | 13:25:49–14:33:12 (67 min) |
| `open`: sprawdzenia, Job `load`, rozmiary, usunięcie reguły, sonda „otwarty” | 14:33:12–14:34:26 |
| Sklep niedostępny łącznie | ok. 69 min |

Stan przed przebiegiem: 191 produktów i 0 zamówień (seed). Po przebiegu (`open`): 1 000 191 produktów i 1 000 001
zamówień (z zamówieniem checkoutu gościa Joba `load`).

## Kroki generatora (linie `[OK]` logu Joba)

| Czas | Krok |
|---|---|
| 13:25:50 | pula zdjęć: wersja 1, 4317 plików |
| 13:25:59–13:26:20 | reset do seeda: kategorie 44, produkty 191, ceny 1012, stany 1012, alias, unieważnienie Varnisha, backfill |
| 13:26:20 | kategorie: 136 zapisanych, 7 już było |
| 13:28:04–13:28:16 | produkty: 4 workery × 250 000 produktów, razem 1 000 000 produktów i 3 008 338 wariantów (ok. 2 min) |
| 13:33:49 | zdjęcia: 1 617 932 zdjęć dla 1 000 000 produktów (5,5 min) |
| 13:35:15–13:35:16 | ceny: 4 workery, 3 008 338 wariantów, 3 910 043 wpisów historii (1,5 min) |
| 13:35:18 | promocje: 40 promocji na 6705 wariantach, 14 kodów rabatowych |
| 13:42:35 | konta wygenerowane i uzgodnione (Keycloak: 100 000 kont sklepu, 50 kont panelu; 7 min) |
| 13:48:19–13:48:40 | zamówienia: 4 workery × 250 000, razem 1 000 000 zamówień i 1 899 745 linii (6 min) |
| 13:51:35 | stany: 3 008 338 rekordów, 121 861 rezerwacji, 3 241 571 ruchów; następny numer zamówienia po R-1100000 |
| 13:53:09 | sprzedaż: 26 279 godzin, 960 053 zamówień, 520 065 209,00 zł |
| 13:53:19 | `ANALYZE` tabel |
| 13:53:19–14:09:59 | indeks wyszukiwarki: alias `products` na nowym indeksie, 1 000 191 produktów (16,7 min) |
| 14:10:00 | unieważnienie Varnisha i stron sklepu |
| 14:10:00–14:33:12 | sprawdzenie spójności wygenerowanych danych (23 min) |
| 14:33:12 | `exit 0` |

Najdłuższe kroki to sprawdzenie spójności (23 min), indeksowanie (17 min) i zamówienia z kontami (13 min).

**Kompletność logu w Grafana Cloud** (warunek przed `server`): 2241 linii kontenera `demo-data` od pierwszej linii
(odczyt strażnika nr 1, 13:25:49) do końcowej `exit 0: bin/generate-demo … ended with 0` (14:33:12), z liniami `[OK]`
wszystkich kroków powyżej. Pełny log zostaje w Grafana Cloud (Explore, `{namespace="shop", container="demo-data"}`) do
końca retencji; do repozytorium nie trafia. Nie dowodzi to kompletności przy wielkości logu `server`.

## Sprawdzenie w `open`

- Pozostawiony sekret `demo-photos` usunięty (sekret puli zdjęć istniał tylko na czas przebiegu).
- Wszystkie indeksy bazy `shop` ważne i gotowe.
- Elasticsearch `green`, alias `products` na `products_20261120135319397_b9bde0` (8 891 973 dokumentów Lucene, w tym
  zagnieżdżone; 1,06 GB).
- Job `load-20261120143338` CronJoba `load` (ścieżki sklepu: strona główna, listing, produkt, wyszukiwarka, koszyk,
  checkout gościa) przeszedł.
- Sonda po usunięciu reguły: `https://worf.pl/` 200, `https://api.worf.pl/` 404 (otwarty).
- Blokada `demo-data-lock` zwolniona.

## Rozmiary

| Baza | Rozmiar |
|---|---|
| `shop` | 8295 MB |
| `keycloak` | 134 MB |

Największe tabele `shop` (dane + indeksy): `catalog_product` 1323 MB, `ordering_order` 1161 MB,
`inventory_stock_movement` 1085 MB, `pricing_price_history` 916 MB, `ordering_order_line` 808 MB,
`catalog_product_variant` 770 MB, `ordering_order_status_change` 760 MB, `payment_payment` 407 MB,
`pricing_variant_price` 386 MB, `inventory_stock_item` 381 MB. Indeks Elasticsearcha: 1,06 GB.

## Zasoby (Grafana Cloud, 13:25–14:35)

| Zasób | Szczyt | Limit / całość |
|---|---|---|
| Wolne miejsce `/` | 175,3 → 163,9 GiB (minimum 14:12; zajęte +11,4 GiB) | dysk 192,9 GiB, próg strażnika 32 GB |
| Pamięć dostępna węzła | minimum 14,6 GiB (13:46) | 22,9 GiB |
| CPU węzła | 64% (13:46) | 8 vCPU |
| Elasticsearch (working set) | 2882 MiB (14:35) | 4096 MiB |
| Postgres | 357 MiB (13:50); CPU 2,52 rdzenia (13:28) | 2048 MiB |
| Pod Joba generatora | 184 MiB (13:48); CPU 1,94 rdzenia (13:35) | 4 GiB |
| Sklep (`store`) | 479 MiB | 1536 MiB |
| API | 87 MiB | 2048 MiB |

Restarty kontenerów w przestrzeni `shop`: 0. OOMKill: brak.

## Sklep i panel po otwarciu

Sonda z komputera operatora (pojedyncze żądania przez Cloudflare): strona główna 200 w 0,6–1,0 s, strona produktu 200
w 0,5 s, wyszukiwarka 200 w 0,7 s, panel `admin.worf.pl` 302 (przekierowanie do logowania). Listing produktów API
`/api/v1/products` odpowiada w 2,0–2,3 s (5 pomiarów, także druga strona); to znany koszt liczenia przy milionie
produktów z audytu wydajności i należy do PERFORMANCE (#781). Ręcznego przeglądu sklepu i panelu po zalogowaniu
nie było.

## Kopie

Kopie restic w klastrze są w `prod` wyłączone. Stan automatycznej kopii VPS-a od OVH nie został potwierdzony (U3);
użytkownik 2026-11-19: kopie nie są warunkiem generowania. Dane generatora odtwarza ponowny `generate PROFILE=medium`.

## Projekcja przed `server` (do decyzji, nie weryfikacja)

- **Czas:** 67 min Joba × 10 ≈ 11 h wobec limitu 24 h (`activeDeadlineSeconds.server`). Sprawdzenie spójności
  i indeksowanie rosną z liczbą produktów; jeśli rosną szybciej niż liniowo, zapas się zmniejsza.
- **Dysk:** przyrost 11,4 GiB przy `medium`. Dane produktów × 10 i zamówień × 5 dają szacunkowo 80–95 GiB wobec
  163,9 GiB wolnego po `medium` (część miejsca `medium` zwolni `--force-reset`). Zgodne z `diskPeakGb` 96 GB
  i progiem 120 GB w specyfikacji.
- **Pamięć:** Elasticsearch ma 2,9 z 4 GiB przy indeksie 1,06 GB; przy indeksie ok. 10 GB to największe ryzyko
  (sterta i cache plików). Postgres 357 MiB z 2 GiB.
- **Sklep:** warunek z decyzji użytkownika: przed `server` naprawa nieograniczonego `tagsManifest` w PERFORMANCE (#781)
  z testem masowej rewalidacji tagów przy skali `medium`.
- **Restarty:** brak przy `medium`.
