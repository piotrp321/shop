# Weryfikacja ISR-TAG-LIMIT: masowa rewalidacja 20 mln nazw tagów (2026-11-24)

Pomiar kryterium 6 specyfikacji `docs/features/isr-tag-limit/spec.md`: pamięć procesu sklepu po zmianie, w której
sklep zamienia nazwy tagów produktów z API na 65 536 kubełków (`apps/store/src/catalog/isr-tags.ts`), przy rewalidacji
tagów 10 mln produktów (20 mln nazw). Punkt odniesienia sprzed zmiany: `docs/reports/isr-tag-revalidation-2026-11-24.md`
(wynik A).

Rewizja: gałąź `claude/isr-tag-limit-513717`, obraz zbudowany z drzewa `apps/store` commitu `745642ef` (baza
`origin/main` `110478db`; Next 16.4.6). Następny commit gałęzi (`a20b4be1`) zmienia tylko łamanie komentarza
w `isr-tags.ts`. Pomiar lokalny, bez serwera; liczby opisują ten obraz i ten komputer, nie worf.pl.

## Metoda

- **Narzędzie:** bez zmian, `docs/reports/isr-tag-revalidation-2026-11-24/scripts/` (`run.sh`, `driver.mjs`,
  `analyze.py`), tryb `exact`: driver wysyła dzisiejsze nazwy z API (`product-<id>`, `product-slug-<slug>`), a kubełki
  liczy już sklep w `POST /api/revalidate`. Uruchomienie z katalogu głównego repo:
  `STORE_IMAGE=isr-tag-store:745642ef PRODUCTS_TSV=<plik> PAUSE_AT=2000382 sh docs/reports/isr-tag-revalidation-2026-11-24/scripts/run.sh <wyniki> exact 20000000 isr-tag_default`,
  potem `python3 docs/reports/isr-tag-revalidation-2026-11-24/scripts/analyze.py <wyniki> 2000000`.
- **Sklep:** `git archive 745642ef:apps/store | docker build --target prod -`, `node --trace-gc server.js`, limit
  pamięci kontenera 1536 MiB bez swapu (jak `resources.store` w `prod`). Docker Desktop 29.9.3 na macOS (arm64), VM
  16 CPU i 31,3 GiB.
- **Reszta stosu:** compose tego worktree (projekt `isr-tag`, sieć `isr-tag_default`) z danymi `make seed` (191
  produktów); sklep czyta przez Varnish.
- **Tagi:** identyfikatory i slugi 1 000 191 produktów katalogu `medium` (plik `id<TAB>slug` z bazy stosu audytu
  wydajności, odczyt bez zmian); po wyczerpaniu katalogu driver tworzy kolejne produkty (losowy UUID, slug
  z przyrostkiem), więc każda z 20 mln nazw jest inna.
- **Przebieg:** jak w raporcie z 2026-11-24: rozgrzewka (191 stron produktów i 2500 adresów `/p/<slug>`, z czego 2309
  to strony 404; cache ISR dochodzi do limitu), potem `POST /api/revalidate` po 100 tagów, 4 żądania naraz, co 50 żądań
  dwa wejścia na strony; po tagach całego katalogu (2 000 382 nazwy) pauza 60 s z lekkim ruchem, potem dalej do
  20 mln nazw.
- **Odczyty:** jak w raporcie z 2026-11-24 (cgroup kontenera co 2 s, working set = `memory.current` minus
  `inactive_file`, sterta po GC z ostatniego Mark-Compact śladu `--trace-gc`).
- **Próba narzędzia:** `run.sh` w postaci przenośnej (ścieżki z `STORE_IMAGE`, `PRODUCTS_TSV`, `REPO`) uruchomiony
  najpierw krótko na obrazie sprzed zmiany (commit `2e267c0f`, `exact 20000`): proces działał (`running=true`,
  `restarts=0`), sterta po GC po rozgrzewce 144 MB, jak w raporcie z 2026-11-24. Skrypt nie wymagał poprawek.

Surowe wyniki: `docs/reports/isr-tag-limit-verification-2026-11-24/results/` (`exact/` pełny przebieg, `trial-base/`
próba; w `store-gc.log` log sklepu bez wierszy Scavenge, `summary.txt` to wynik `analyze.py`).

## Wynik

| Punkt | RSS (MiB) | Working set (MiB) | Sterta po GC (MB) |
|---|---|---|---|
| Start procesu | 106 | 67 | 36 |
| Po rozgrzewce (cache ISR pełny) | 485 | 488 | 145 |
| 2,0 mln nazw: cały katalog `medium`, koniec serii | 537 | 534 | 168 |
| 2,0 mln nazw, po 60 s spoczynku (koniec pauzy) | 462 | 480 | 161 |
| 4 mln | 524 | 539 | 168 |
| 8 mln | 527 | 533 | 169 |
| 12 mln | 529 | 535 | 169 |
| 16 mln | 529 | 534 | 169 |
| 20 mln (dwa tagi na każdy z 10 mln produktów), koniec | 528 | 526 | 169 |

- Po 20 mln nazw proces działa bez restartu: `state.txt` `running=true oomkilled=false exit=0 restarts=0`. Driver
  wysłał wszystkie tagi bez błędu (`failed: 0`: każda odpowiedź `POST /api/revalidate` to 200).
- Sterta po GC od 4 mln do 20 mln nazw stoi na 168–169 MB; najwyższa wartość w całym przebiegu 172 MB. Szczyt RSS
  544 MiB, working set 547 MiB przy limicie 1536 MiB.
- GC: 286 cykli Mark-Compact, najdłuższa pauza 36 ms, żadnej pauzy od 100 ms, razem 1,6 s pauz.
- Tempo: ok. 60 tys. nazw na sekundę do pauzy (w raporcie z 2026-11-24 ok. 114 tys.); komputer obsługiwał w tym
  czasie także inne stosy, a każde żądanie liczy do 100 skrótów SHA-256.
- Sterta po rozgrzewce i stan po 2 mln nazw są bliskie przebiegowi B z 2026-11-24 (145 i 170 MB): tamten przebieg
  emulował kubełki nazwami zastępczymi, ten mierzy kubełki sklepu.

Porównanie z wynikiem A z 2026-11-24 (te same nazwy z API, sklep bez kubełków):

| Punkt | Sterta po GC przed zmianą (MB) | Sterta po GC po zmianie (MB) |
|---|---|---|
| Po rozgrzewce | 144 | 145 |
| 2,0 mln nazw, po 60 s spoczynku | 506 | 161 |
| 3,1 mln nazw | 734, koniec procesu (brak sterty V8) | 168 |
| 20 mln nazw | nie osiągnięte | 169 |

## Kryterium 6

**Spełnione.** Proces działa po rewalidacji 20 mln nazw (10 mln produktów) bez restartu, a sterta po GC po 20 mln nazw
(169 MB) jest wyższa niż po 2 mln nazw na końcu pauzy (161 MB) o 8 MB, przy progu 10 MB.

Te 8 MB to różnica między stanem po 60 s spoczynku a stanem pod obciążeniem, nie przyrost z kolejnymi nazwami: przed
pauzą, przy tych samych 2 mln nazw, sterta miała 168 MB, a od 4 mln do 20 mln nazw stoi na 168–169 MB.

## Ograniczenia

- Jeden przebieg, na macOS arm64; serwer to amd64. Limit pamięci i obraz są te same co w `prod`, ale pamięć poda na
  serwerze nie była mierzona (weryfikacja na serwerze: specyfikacja, „Weryfikacja na serwerze (D4)”).
- Tagi trafiają do sklepu wprost przez `POST /api/revalidate`, gęściej niż od workera API. Ścieżka od zmiany produktu do
  wywołania endpointu nie była częścią pomiaru (sprawdzają ją testy E2E świeżości, kryterium 5).
- Strony renderowały się z danych `make seed`; stan po rozgrzewce przy danych `medium` może być inny (wpis strony 404
  rośnie z drzewem kategorii). Granica listy tagów nie zależy od danych stron.
- Pomiar nie sprawdza kosztu zbędnych unieważnień przy kolizji kubełka (specyfikacja, „Skutki”): w tym przebiegu każda
  seria nazw trafia we wszystkie kubełki, więc wpisy z rozgrzewki są unieważniane wielokrotnie, a liczba ponownych
  renderów nie była mierzona.
