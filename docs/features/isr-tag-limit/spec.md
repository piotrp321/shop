# ISR-TAG-LIMIT: ograniczona lista rewalidowanych tagów cache ISR sklepu

Sklep przestaje rewalidować strony produktów po nazwach tagów z identyfikatorem i slugiem produktu. Zamiast nich używa
stałej liczby tagów (kubełków), do których te nazwy są przypisywane skrótem. Lista rewalidowanych tagów w procesie
sklepu ma przez to stały górny rozmiar, niezależny od liczby produktów i liczby zmian. Zmiana jest warunkiem danych
w skali `server` na serwerze demo (`docs/features/demo-scale/spec.md`, „Wymagane przed startem”).

Działanie strony produktu i cache ISR opisuje `docs/features/product-page/spec.md`; ta specyfikacja opisuje tylko
zmianę. Twierdzenia o obecnym kodzie dotyczą `origin/main` z 2026-11-24 (`476d84c4`).

Issues: #781 (pozycja „Lista rewalidowanych tagów cache ISR sklepu”), #484
Zakres: `apps/store/src/catalog`, `apps/store/src/api/client.ts`, `apps/store/app/api/revalidate`, `apps/store/CLAUDE.md`, `docs/features/product-page/spec.md`, `docs/features/demo-scale/spec.md`, `docs/features/isr-tag-limit`

## Problem

- Next trzyma rewalidowane tagi w mapie w pamięci procesu (`tagsManifest`,
  `next/dist/server/lib/incremental-cache/tags-manifest.external.js`). `revalidateTag` dopisuje do niej wpis dla
  każdego nowego tagu; w Next 16.4.6 nic z niej nie usuwa (w kodzie serwera nie ma `delete` ani `clear` tej mapy).
  Mapa jest poza limitem `cacheMaxMemorySize` i znika dopiero przy restarcie poda.
- Sklep nadaje odczytom strony produktu tagi `product-<id>` i `product-slug-<slug>`
  (`apps/store/src/catalog/load-product.ts`), a worker API rewaliduje te same nazwy po każdej zmianie produktu, ceny,
  poziomu dostępności albo promocji (`POST /api/revalidate`). Liczba unikalnych tagów rośnie więc do dwóch na każdy
  produkt zmieniony od startu poda.
- Pomiar (`docs/reports/isr-tag-revalidation-2026-11-24.md`): na obrazie produkcyjnym z limitem pamięci
  `prod` (1536 MiB) lista kosztuje 181 B sterty na tag. Po rewalidacji tagów całego katalogu `medium` (2,0 mln) sterta
  po GC rośnie ze 144 do 506 MB i sklep działa dalej. Po 3 121 500 unikalnych tagach (ok. 1,56 mln zmienionych
  produktów) proces kończy się błędem braku sterty V8. Przy 10 mln produktów wystarcza do tego zmiana 16% katalogu od
  startu poda.

## Decyzje

Decyzje D1–D4 zaakceptował użytkownik 2026-11-24.

| # | Decyzja | Rekomendacja przyjęta w specyfikacji | Odrzucone warianty |
|---|---|---|---|
| D1 | Jak ograniczyć listę | Stała liczba tagów w sklepie: nazwa tagu produktu z API jest zamieniana skrótem na jeden z N kubełków. Lista ma najwyżej N + 1 wpisów z definicji, bez zależności od wnętrza Next | Własny `cacheHandler` z limitem wieku wpisów i czyszczeniem listy (własna implementacja całego cache ISR, utrzymywana przy każdym podbiciu Next); czyszczenie mapy Next z `instrumentation` (prywatny moduł Next, a usunięty tag mógłby przywrócić starą stronę jako świeżą); cykliczny restart poda sklepu (nie daje górnej granicy między restartami, gubi cały cache) |
| D2 | Liczba kubełków | N = 65 536 (16 bitów skrótu) | 4096 (lista 16 razy mniejsza, ale przy pełnym cache prawie każda zmiana produktu unieważnia dodatkowo cudzą stronę); 1 048 576 (lista rzędu setek MB w najgorszym przypadku) |
| D3 | Gdzie jest zamiana | Tylko w sklepie, w jednym module. Słownik tagów API (`CacheTags`), treść `POST /api/revalidate` i `xkey` Varnisha zostają bez zmian | Kubełki liczone w API (zmiana kontraktu rewalidacji i dwóch miejsc z tym samym skrótem; Varnish i tak potrzebuje dokładnych nazw) |
| D4 | Co znaczy „zweryfikowane na serwerze” przed `generate server` | Wdrożona rewizja zawiera zmianę; strona produktu jest najpierw rozgrzana w cache ISR ze starą ceną, a po zmianie ceny w panelu pierwsze wejście pokazuje nową; pamięć i restarty poda sklepu z Grafana Cloud są bez zmian. Masowej rewalidacji na serwerze nie wykonujemy: górna granica wynika z konstrukcji i z pomiaru na obrazie produkcyjnym z limitem pamięci `prod` | Masowa rewalidacja na serwerze (endpoint wymaga sekretu, którego agent nie obsługuje, więc potrzebny byłby nowy Job w klastrze tylko do tego jednego sprawdzenia) |

## Rozwiązanie

- Nowy moduł sklepu (`apps/store/src/catalog/isr-tags.ts`) jest jedynym miejscem, które tworzy nazwy tagów cache Next:
  - `catalog` zostaje tagiem `catalog`;
  - tag produktu z API (`product-<uuid>` albo `product-slug-<slug>`) dostaje nazwę `product-bucket-<4 cyfry szesnastkowe>`:
    pierwsze 16 bitów SHA-256 pełnej nazwy tagu z API. Identyfikator i slug mają wspólną przestrzeń kubełków.
- `loadProduct` nadaje odczytom tagi z tego modułu: szczegóły `catalog` i kubełek nazwy `product-slug-<slug>`, oferta
  `catalog` i kubełek nazwy `product-<id>`.
- `POST /api/revalidate` sprawdza treść jak dziś (1–100 tagów ze słownika API), zamienia każdy tag tym samym modułem
  i woła `revalidateTag(<tag Next>, { expire: 0 })` raz dla każdej różnej nazwy. Odpowiedź, kody błędów i limit treści
  się nie zmieniają; `revalidated` to nadal liczba tagów z żądania.
- `createApiClient` przyjmuje w `tags` wyłącznie typ tagu z tego modułu (typ oznaczony, którego wartości powstają tylko
  w `isr-tags.ts`), więc odczyt z nazwą tagu zbudowaną poza modułem nie przechodzi `tsc`.
- API, worker, Varnish, kontrakt `POST /api/revalidate`, OpenAPI i chart się nie zmieniają.

## Skutki

- **Górna granica listy:** 65 537 wpisów (65 536 kubełków i `catalog`). W pomiarze 131 072 różne tagi (dwa razy
  więcej, z dłuższymi nazwami) zajęły 32 MB sterty, a sterta i RSS nie rosły między 2 mln a 24 mln rewalidacji.
- **Zbędne unieważnienia.** Zmiana produktu unieważnia też te wpisy cache, których tag trafił do tego samego kubełka.
  Cache mieści ok. 500 stron produktu (po dwa kubełki) albo 1000–2000 stron 404 (po jednym kubełku), czyli najwyżej
  ok. 2000 przypisań do 65 536 kubełków. Prawdopodobieństwo ok. 3% dotyczy jednego rewalidowanego tagu: tyle wynosi
  szansa, że w jego kubełku jest tag co najmniej jednego cudzego wpisu. Każdy tag żądania liczy się osobno, więc
  zmiana produktu rewalidowana dwoma tagami (identyfikator i slug) trafia w cudzy wpis z prawdopodobieństwem ok. 6%.
  Kolizja unieważnia wszystkie wpisy cache, których tag jest w tym kubełku, więc może unieważnić kilka wpisów naraz
  (strony kilku produktów i strony 404), a razem ze stroną także odczyty `fetch` z tym tagiem. Skutek dla każdego
  unieważnionego wpisu to jeden dodatkowy render strony przy następnym wejściu (ok. 39 ms CPU, audyt wydajności).
  Strona nigdy nie jest przez to starsza niż dziś: zbiór unieważnianych wpisów zawiera cały obecny zbiór.
- **Rozkład.** 2 000 382 nazwy tagów katalogu `medium` (identyfikatory i slugi) zajmują wszystkie 65 536 kubełków:
  średnio 30,5 nazwy na kubełek, najmniej 9, najwięcej 58.
- **Masowa zmiana** (każdy produkt) unieważnia cały cache, tak jak dziś.
- **Wdrożenie:** nowy pod sklepu startuje z pustym cache i pustą listą, więc nie ma okresu przejściowego ani zgodności
  ze starymi nazwami tagów.

## Poza zakresem

- Koszt CPU renderu stron pod ruchem robotów i cache HTML listingów (STORE-CACHE w #781).
- Cache facetów i czas listingu przy dużym katalogu (SEARCH-FACETS w #781).
- Zmiana limitu cache ISR (128 MB) i limitu pamięci kontenera `store`.
- Wspólny cache ISR dla kilku podów sklepu (sklep ma jedną replikę).

## Kryteria akceptacji

1. Moduł tagów: ta sama nazwa z API daje zawsze ten sam tag Next; `catalog` daje `catalog`; każdy inny wynik ma postać
   `product-bucket-<4 cyfry szesnastkowe>`; dla 1 000 000 różnych nazw (identyfikatory i slugi) różnych wyników jest
   najwyżej 65 536, a najliczniejszy kubełek ma mniej niż 3 razy więcej nazw niż średni (rozkład bez skupień; przy
   równym rozkładzie najliczniejszy z 65 536 kubełków ma ok. 2 razy więcej niż średnie 15).
2. `loadProduct` wysyła odczyt szczegółów z tagami `catalog` i kubełkiem nazwy `product-slug-<slug>`, a odczyt oferty
   z tagami `catalog` i kubełkiem nazwy `product-<id>`; żaden odczyt nie ma tagu z identyfikatorem ani slugiem.
3. `POST /api/revalidate` z tagami `catalog`, `product-<id>` i `product-slug-<slug>` woła `revalidateTag` z `expire: 0`
   dokładnie dla ich tagów Next, raz dla każdej różnej nazwy; 100 tagów jednego kubełka to jedno wywołanie. Tag spoza
   słownika API, także `product-bucket-0000` podany wprost, daje 400 jak dziś. Pozostałe testy endpointu przechodzą
   bez zmiany asercji.
4. `createApiClient({ tags: ["product-..."] })` z nazwą zbudowaną poza modułem nie przechodzi `tsc` (test typów
   w `make -C apps/store check`).
5. E2E świeżości bez zmian asercji: `product-page-freshness.spec.ts`, `price-change.spec.ts`, `stock-receipt.spec.ts`,
   `promotions.spec.ts` i `catalog-admin.spec.ts` (publikacja produktu zastępuje stronę 404 jego sluga) przechodzą na
   buildzie produkcyjnym.
6. Pomiar na obrazie produkcyjnym sklepu z limitem pamięci `prod` (1536 MiB), powtórzony po zmianie tym samym
   narzędziem co pomiar w raporcie (`docs/reports/isr-tag-revalidation-2026-11-24/scripts`, tryb `exact`, czyli
   dzisiejsze nazwy z API): proces działa po rewalidacji tagów 10 mln produktów (20 mln nazw) bez restartu, a sterta
   po GC po 20 mln nazw nie jest wyższa niż po 2 mln nazw o więcej niż 10 MB. Wynik trafia do nowego raportu
   w `docs/reports/` i do opisu PR.
7. `make -C apps/store check` i `make -C apps/store test` przechodzą.

## Weryfikacja na serwerze (D4)

Po wdrożeniu rewizji ze zmianą, przed `generate PROFILE=server`:

1. `make -C infra/k8s status`: wdrożona rewizja zawiera zmianę, pod sklepu działa bez restartów.
2. Rozgrzanie cache i stara cena: dwa wejścia na stronę wybranego produktu. Drugie ma `x-nextjs-cache: HIT` (strona
   jest w cache ISR) i pokazuje dotychczasową cenę; cena jest zapisana jako punkt odniesienia. Bez tego kroku nowa
   cena po zmianie nie dowodzi rewalidacji: strona spoza cache wyrenderowałaby się świeżo także bez niej.
3. Zmiana ceny tego produktu w panelu (krok użytkownika albo sesji po zgodzie).
4. Pierwsze wejście na stronę produktu po obsłużeniu zdarzenia pokazuje nową cenę, a nie zapisaną w kroku 2.
5. Grafana Cloud, godzinę po kroku 4: `container_memory_working_set_bytes` kontenera `store` poniżej 1 GiB, liczba
   restartów poda sklepu bez zmian.

Wynik trafia do komentarza w #781.

## Dokumentacja

W tym samym PR co kod:

- `docs/features/product-page/spec.md`: „Rewalidacja” i „Cache ISR” (nazwy tagów Next, górna granica listy, pomiar),
  „Ryzyka i ograniczenia” (zdanie o nieograniczonej liście znika, dochodzi zdanie o zbędnych unieważnieniach).
- `apps/store/CLAUDE.md`: „Strona produktu” (zdanie „Tagi cache Next to te same nazwy, które API wystawia w `xkey`”
  przestaje być prawdziwe) i zasada: nazwy tagów cache Next powstają tylko w `isr-tags.ts`, a tag z identyfikatorem
  encji jest zabroniony.
- `docs/features/demo-scale/spec.md`: „Ryzyka” i „Wymagane przed startem” (warunek dla `server` wskazuje weryfikację
  z tej specyfikacji).
- Komentarze `load-product.ts`, `client.ts` i `route.ts`.

Po wdrożeniu i weryfikacji na serwerze ta specyfikacja jest usuwana; trwały opis zostaje
w `docs/features/product-page/spec.md`.

## Ryzyka

- Nowy rodzaj tagu z identyfikatorem encji (np. tag kategorii) dodany kiedyś obok modułu przywróciłby wzrost listy.
  Odpowiedź: typ tagu w `createApiClient` (kryterium 4) i zasada w `apps/store/CLAUDE.md`.
- Pomiar po zmianie emuluje masową zmianę wywołaniami `POST /api/revalidate`, nie zmianami produktów przez API.
  Ścieżka od zmiany produktu do wywołania endpointu się nie zmienia i ma testy E2E (kryterium 5).
- Przy podbiciu Next sposób przechowywania tagów może się zmienić; granica liczby różnych tagów nie zależy od tej
  implementacji.
