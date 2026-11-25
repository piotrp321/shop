# Masowa rewalidacja tagów cache ISR sklepu przy katalogu `medium` (2026-11-24)

Pomiar pamięci procesu sklepu, gdy worker API rewaliduje tagi każdego produktu katalogu `medium` (1 000 191 produktów,
po dwa tagi), i dalej w stronę skali `server`. Wykonuje warunek z #781: test masowej rewalidacji tagów przy skali
`medium` z pomiarem sterty Node, RSS, working set i zachowania GC. Drugi przebieg sprawdza, czy stała liczba tagów
ogranicza listę rewalidowanych tagów (podstawa `docs/features/isr-tag-limit/spec.md`).

Rewizja: `origin/main` `476d84c4` (Next 16.4.6, Node 24.22.3). Pomiar lokalny, bez serwera. Liczby opisują ten obraz
i ten komputer; nie są pomiarem worf.pl.

## Metoda

- **Sklep:** obraz produkcyjny (`docker build --target prod apps/store`, `node server.js` z `--trace-gc`), limit pamięci
  kontenera 1536 MiB bez swapu, jak `resources.store` w `prod`. Docker Desktop 29.9.3 na macOS (arm64), VM 16 CPU
  i 31,3 GiB.
- **Reszta stosu:** compose tego worktree z danymi `make seed` (191 produktów); sklep czyta przez Varnish jak
  w `prod`.
- **Tagi:** identyfikatory i slugi 1 000 191 produktów z bazy stosu audytu wydajności (dane `medium`, odczyt bez
  zmian); slug ma średnio 43 znaki (19–64). Po wyczerpaniu katalogu narzędzie tworzy kolejne produkty: losowy UUID
  i ten sam slug z przyrostkiem.
- **Przebieg:** rozgrzewka (191 stron produktów i 2500 adresów `/p/<slug z katalogu>`, z czego 2309 to strony 404;
  cache ISR dochodzi do limitu), potem `POST /api/revalidate` po 100 tagów (`product-<id>` i `product-slug-<slug>`
  kolejnych produktów), 4 żądania naraz, co 50 żądań dwa wejścia na strony. Po tagach całego katalogu (2 000 382)
  pauza 60 s z lekkim ruchem, potem dalej do 24 mln tagów albo do końca procesu.
- **Odczyty:** co 2 s `memory.current`, `inactive_file` i `anon` z cgroup kontenera oraz `VmRSS` procesu; working set
  to `memory.current` minus `inactive_file` (definicja kubeleta). Sterta po GC to wartość po ostatnim Mark-Compact ze
  śladu `--trace-gc` (jednostka V8, MB).
- **Przebieg B** różni się tylko nazwami tagów: każda nazwa jest zamieniana skrótem SHA-256 na jedną z 65 536 nazw na
  format, które endpoint przyjmuje dziś (`product-slug-b-<hex>`, `product-00000000-0000-0000-0000-00000000<hex>`),
  razem najwyżej 131 072 różne tagi. Kod sklepu jest ten sam.

Narzędzie i surowe wyniki: `docs/reports/isr-tag-revalidation-2026-11-24/` (`scripts/run.sh`, `scripts/driver.mjs`,
`scripts/analyze.py`, `results/`).

## Wynik A: obecne nazwy tagów (unikalny tag na identyfikator i slug)

| Punkt | RSS (MiB) | Working set (MiB) | Sterta po GC (MB) |
|---|---|---|---|
| Start procesu | 128 | 77 | 33 |
| Po rozgrzewce (cache ISR pełny) | 547 | 501 | 144 |
| 0,5 mln tagów | 626 | 580 | 239 |
| 1,0 mln tagów | 764 | 722 | 323 |
| 1,5 mln tagów | 880 | 838 | 447 |
| 2,0 mln tagów: cały katalog `medium`, koniec serii | 972 | 933 | 524 |
| 2,0 mln tagów, po 60 s spoczynku | 841 | 804 | 506 |
| 2,5 mln tagów | 1008 | 971 | 655 |
| 3,0 mln tagów | 1075 | 1038 | 723 |
| 3 121 500 tagów | proces zakończony | | 734 |

- Proces sklepu zakończył się po 3 121 500 tagach (ok. 1,56 mln zmienionych produktów) błędem V8
  `FATAL ERROR: Ineffective mark-compacts near heap limit Allocation failed - JavaScript heap out of memory`, kod 139.
  To limit sterty Node (776 MB przydzielone stercie w chwili błędu), nie limit kontenera: szczyt RSS 1083 MiB, working
  set 1051 MiB przy limicie 1536 MiB, `OOMKilled` fałsz.
- Lista kosztuje 181 B sterty na tag: (506 − 144) MB na 2 000 600 tagów, po GC i spoczynku. Po spoczynku sterta nie
  wraca do stanu sprzed rewalidacji.
- Przy tagach całego katalogu `medium` sklep działa: sterta po GC 506 MB, czyli 69% wartości, przy której proces padł
  (734 MB); do tej wartości zostaje ok. 1,1 mln kolejnych unikalnych tagów.
- GC: 101 cykli Mark-Compact w całym przebiegu, najdłuższa pauza 322 ms, 17 pauz po co najmniej 100 ms (wszystkie
  w fazie rewalidacji), razem 4,6 s pauz.

## Wynik B: stała liczba tagów (131 072 nazwy)

| Punkt | RSS (MiB) | Working set (MiB) | Sterta po GC (MB) |
|---|---|---|---|
| Po rozgrzewce | 553 | 509 | 145 |
| 2,0 mln rewalidacji, koniec serii | 590 | 550 | 177 |
| 2,0 mln rewalidacji, po 60 s spoczynku | 463 | 417 | 170 |
| 4 mln | 567 | 526 | 176 |
| 8 mln | 571 | 531 | 176 |
| 12 mln | 573 | 531 | 176 |
| 16 mln | 573 | 533 | 175 |
| 20 mln (dwa tagi na każdy z 10 mln produktów) | 574 | 534 | 177 |
| 24 mln, koniec | 574 | 530 | 177 |

- Po 24 mln rewalidacji proces działa, bez restartu. Sterta po GC stoi na 175–177 MB od 2 mln do 24 mln rewalidacji:
  32 MB ponad stan po rozgrzewce przy 131 072 różnych tagach. Szczyt RSS 590 MiB, working set 550 MiB.
- GC: 245 cykli Mark-Compact, najdłuższa pauza 25 ms, żadnej pauzy od 100 ms.

## Wnioski

- Przy danych `medium` nieograniczona lista mieści się w stercie także w najgorszym przypadku (zmiana każdego
  produktu od startu poda): 506 MB sterty po GC. Zapas do końca procesu to ok. 1,1 mln unikalnych tagów, czyli
  kolejne ok. 560 tys. zmian produktów o innych identyfikatorach albo slugach.
- Przy danych `server` (10 mln produktów) sklep z obecnymi nazwami tagów kończy pracę po zmianie ok. 1,56 mln
  produktów od startu poda, czyli 16% katalogu. Po restarcie lista jest pusta, więc pod wraca do pracy do następnych
  ok. 3,1 mln unikalnych tagów.
- Pamięć zależy od liczby różnych tagów, nie od liczby rewalidacji: przy 131 072 nazwach sterta i RSS nie rosną
  między 2 mln a 24 mln rewalidacji.

## Ograniczenia

- Jeden przebieg każdego wariantu, na macOS arm64; serwer to amd64. Limit pamięci i obraz są te same co w `prod`,
  ale próg sterty na serwerze nie był mierzony.
- Tagi trafiają do sklepu wprost przez `POST /api/revalidate`, ok. 114–124 tys. tagów na sekundę. Worker API wysyła je
  w tempie obsługi zdarzeń, wielokrotnie wolniej; pauzy GC z tego pomiaru opisują więc serię gęstszą niż rzeczywista.
  Ścieżka od zmiany produktu do endpointu nie była częścią pomiaru.
- Strony renderowały się z danych `make seed`; rozmiar wpisu strony 404 rośnie z drzewem kategorii, więc stan „po
  rozgrzewce” przy danych `medium` może być inny. Przyrost na tag nie zależy od danych stron.
- W przebiegu B strony w cache mają dzisiejsze tagi, więc rewalidacja nazw zastępczych ich nie unieważnia; w przebiegu
  A unieważnia strony 404 z rozgrzewki. Obciążenie renderem w obu przebiegach nie jest więc takie samo.
- Nazwy zastępcze w przebiegu B są dłuższe niż nazwy z projektu w specyfikacji i jest ich dwa razy więcej (dwa
  formaty); 32 MB to górne oszacowanie dla tego wariantu, nie pomiar wdrożonej zmiany.
