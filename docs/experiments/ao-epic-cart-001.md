# Eksperyment AO-EPIC-003: epik Cart przez AO v0.3.1

Pierwszy rzeczywisty przebieg AO v0.3.1 na epiku `shop`. Od zaakceptowanego planu AO samo prowadziło zadania przez
`/delivery`, mergowało PR do `epic/cart`, uruchomiło `/integration` i zadanie domknięcia, a na końcu otworzyło końcowy
PR do `main`. Sesja Claude Code tylko obserwowała. Raport opisuje przebieg z 2026-11-03/04 i po zapisaniu się nie zmienia.

## 1. Warunki

- **Epik:** Cart, punkt 6 MVP z `docs/vision.md`, bez checkoutu. Obejmuje koszyk gościa i zalogowanego klienta oraz
  scalanie przy logowaniu. Specyfikacja jest w `docs/features/cart/spec.md`. Plan miał manifest 5 zadań w 3 falach
  i został usunięty przez zadanie domknięcia (PR #78).
- **Rewizje:** start z `8c51468` (`main`, PR #71 ze specyfikacją i planem). Wynik to `epic/cart` na `df4484e`
  i końcowy PR #79 z zielonym CI. PR #79 zmergował użytkownik 04.11 o 08:33 jako `b89fa1b`.
- **AO:** `piotrp321/agentic-orchestrator`, `main` `03231c2` (v0.3.1). Przed startem lokalny checkout AO był na v0.2
  i został przewinięty (`git pull --ff-only`). Przebieg używał świeżego `AO_HOME` (`~/.local/state/ao-cart`), więc nie
  było potrzeby `migrate`.
- **Wykonawca:** `claude -p --model opus --effort high`, Claude Code 2.1.308, subskrypcja Max.
- **Limity:** takie same jak w AO-EPIC-002: `--epic-max-attempts 24`, `--epic-timeout 57600` (16 h),
  `--max-budget-usd 30` i `--timeout 14400` (4 h) na próbę, `--max-qa-rounds 3`.
- **Środowisko:** `ao run --until-idle` działał pod `caffeinate -dims`, z `--notify-cmd` (powiadomienie macOS).
  Na starcie AO zgłosiło zasilanie z baterii (`host_on_battery`, 91%). Uśpienia hosta nie było.
- **Protokół:** taki sam jak w AO-EPIC-002. Obserwator nie uruchamia `/delivery` ani `/integration` i nie zmienia
  kodu, AO, skilli, specyfikacji ani planu. Tym razem bez kontrolowanego restartu i bez wstrzykiwania awarii.
- **Brainstorm i planner:** `/brainstorm` i `/planner` przeprowadziła sesja obserwatora według ich `SKILL.md`.
  Była jedna runda 10 decyzji z rekomendacjami i dwie rundy doprecyzowań użytkownika:
  - odporność scalania na równoległe zmiany koszyka gościa;
  - czyszczenie wygasłego koszyka klienta przed scaleniem;
  - opis `store_cart`, w którym podpis nie oznacza szyfrowania.

## 2. Przebieg i czas (CET)

| Etap | Czas | Uwagi |
|---|---|---|
| Discovery, `/brainstorm`, projekt, spec, `/planner` | 03.11 21:36 – 22:18 | 10 decyzji, 2 doprecyzowania użytkownika |
| PR #71 (spec i plan), merge przez użytkownika | 22:18 – 22:20 | |
| `ao epic start`, CI startowe | 22:22 – 23:01 | AO przyjęło CI z `main` dla tego samego SHA; pierwsze podejście było czerwone (sekcja 7), więc AO je ponowiło o 22:43 |
| Fala 1: CART-1 | 23:02 – 00:02 | 1 próba |
| Fala 2: CART-2, CART-3, CART-4 | 00:03 – 02:51 | wykonane po kolei, każde w 1 próbie |
| Fala 3: CART-5 | 02:52 – 03:28 | ostatni merge zadania planu o 03:28, 5 h 06 min od startu |
| `/integration` runda 1 (FULL) | 03:29 – 03:52 | PASSED: 0 Critical, 0 Important, 6 Minor |
| CART-FINALIZE | 03:53 – 04:03 | usunięcie planu i uzgodnienie spec z kodem (PR #78) |
| Końcowy PR #79, CI | 04:03 – 04:28 | jedno ponowienie CI (sekcja 7), epik `DONE` |

Od startu epiku do zielonego końcowego PR minęło **6 h 06 min**, w całości aktywnie (bez przerw hosta i bez
cooldownu). Same próby wykonawcy trwały 4 h 51 min. W AO-EPIC-002 było to odpowiednio 21 h 59 min, ok. 10 h 41 min
aktywnie i 7 h 54 min prób.

## 3. Zadania i próby AO

| Zadanie | Ryzyko | PR | Diff | Próby | Czas pracy | Wycena CLI |
|---|---|---|---|---|---|---|
| CART-1 API koszyków gościa i klienta | CRITICAL | #73 | +8366, 102 pliki | 1 | 59,6 min | $15.97 |
| CART-2 scalanie w API | CRITICAL | #74 | +1635, 19 plików | 1 | 60,4 min | $7.61 |
| CART-3 `cart:purge-expired` | STANDARD | #75 | +585, 12 plików | 1 | 60,1 min | $4.56 |
| CART-4 koszyk w sklepie (BFF, strona, licznik) | STANDARD | #76 | +2701, 35 plików | 1 | 43,6 min | $6.26 |
| CART-5 scalanie przy logowaniu w sklepie | CRITICAL | #77 | +684, 19 plików | 1 | 36,0 min | $5.25 |
| CART-INTEGRATION-1 (FULL) | – | – | – | 1 (PASSED) | 22,1 min | $4.63 |
| CART-FINALIZE | FAST | #78 | +184/−254, 2 pliki | 1 | 9,3 min | $2.70 |
| **Razem** | | | **163 pliki, +14 099/−287** | **7 (0 nieudanych)** | **4 h 51 min** | **$46.97** |

Wycena CLI to równowartość kosztu API, a przebieg szedł na subskrypcji. Żadna próba nie skończyła się bez wyniku.
Limitów epiku nie przekroczono: 7 z 24 prób, 1 z 3 rund QA.

Każde zadanie planu przeszło przed merge'em:
- `code-reviewer` w `/delivery`;
- TDD ze statusem `verified` w pliku stanu (FINALIZE: `not_applicable`);
- zielone CI PR (`scope`, `checks`, `e2e`).

Rundy poprawek w `/delivery` po review:

| Zadanie | Rundy | Co poprawiono |
|---|---|---|
| CART-1 | 1 | brakujące testy HTTP |
| CART-2 | 1 | możliwe zakleszczenie przy wzajemnym scalaniu, test K8 |
| CART-3 | 2 | **Important:** wyścig purge z `lockOrCreateForCustomer` (500); w rundzie DELTA nieaktualny docblock |
| CART-4 | 1 | M1–M3 |
| CART-5 | 0 | |

Żadne zadanie nie wymagało rundy poprawki AO po czerwonym CI PR ani po konflikcie.

## 4. Tokeny, modele i efforty

Tokeny prób AO pochodzą z pola `modelUsage` wyniku w `stdout.jsonl`:

| Model | Wejście bez cache | Zapis cache | Odczyt cache | Wyjście | Wycena |
|---|---|---|---|---|---|
| Opus 5.5 | 1,1 tys. | 2,75 mln | 62,4 mln | 588 tys. | $40.04 |
| Sonnet 5.5 | 0,5 tys. | 0,83 mln | 22,6 mln | 259 tys. | $6.94 |

| Rola | Model | Effort |
|---|---|---|
| Sesja obserwatora (brainstorm, planner, obserwacja, raport) | Opus 5.5 | sesyjny |
| Wykonawca AO: `/delivery`, `/integration` | Opus 5.5 | high |
| `developer` przy CRITICAL (CART-1, CART-2, CART-5) | Opus 5.5 (wybór `/delivery`) | high |
| `developer` przy STANDARD i FAST, runda poprawek CART-1 | Sonnet 5.5 | high |
| `code-reviewer`, `architecture-reviewer` | Opus 5.5 | high |
| `qa-adversarial` (w `/integration`) | Sonnet 5.5 | high |

`/delivery` sam wybrał Opusa dla developera przy zadaniach CRITICAL, tak jak pozwala skill („dla CRITICAL możesz
wybrać Opus”). Stąd najwyższy koszt CART-1. W AO-EPIC-002 developer działał wszędzie na Sonnecie.

Limit tygodniowy (`seven_day`) wzrósł w trakcie przebiegu z 0,08 do 0,09 (reset 04.11 19:00). Zdarzeń
`usage_warning` i odrzuceń nie było.

## 5. Jakość

### `/integration` runda 1

Runda FULL na `1391eb4`: 162 pliki, +13 920/−38. Wynik PASSED, bez zadania FIX.

Bramki:
- `make check`: OK.
- `make test`: api 1864, store 484, admin 342.
- `make seed`: 454 stany.
- `make e2e`: 11 passed, w tym nowe `cart-guest.spec.ts` i `cart-merge.spec.ts`.

Architekt: APPROVE_WITH_FINDINGS. Potwierdził zgodność DDD, portów, Deptrac, blokad i kolejności gość → klient
ze specyfikacją. Sekcja „Dokumentacja do aktualizacji” jest wykonana w całości.

QA pracował na żywym stosie AO przez HTTP (API, sklep, Keycloak), więc jest to pierwszy przebieg zmiany S3 z AO v0.3.
Potwierdził:
- **Walidację:** macierz 422, 400 i 415; fuzz nie dał żadnej odpowiedzi 5xx.
- **`no-store`:** obecne na wszystkich odpowiedziach koszyka.
- **Współbieżność API:**
  - 16 równoległych dodań daje ilość 16;
  - limit 50 linii trzyma się przy 60 wątkach;
  - 600 mieszanych operacji zachowuje niezmienniki.
- **Scalanie:**
  - pozycje przenoszą się dokładnie raz przy 16 równoległych żądaniach z tą samą sesją;
  - scalanie równolegle z 20 dodaniami do koszyka gościa daje poprawny wynik (K2);
  - `already_merged` przy powtórce;
  - `merge_truncated` dokładnie raz.
- **BFF:** CSRF przez Origin i Content-Type, podpisane ciasteczko.
- **Izolacja:** koszyka klienta od endpointów gościa i tokenu `backoffice` od `/me/cart`.

### Minor z integracji

Trafiły do opisu końcowego PR jako „tylko w raporcie, bez napraw”:

| Id | Zadanie | Zgłoszenie |
|---|---|---|
| F1 | CART-4 | dwa równoległe pierwsze dodania gościa w dwóch kartach bez `store_cart` zakładają dwa koszyki, a pozycja z jednego ginie |
| F2 | CART-4 | przy 404 produktu dla linii `unavailable_product` strona koszyka mówi „Pozycja została już usunięta” |
| F3 | CART-1 | część komunikatów walidacji 422 (`NotNull`, `Type`) jest po angielsku |
| F4 | CART-4 | spec nie opisuje trzech zachowań BFF: `summary` po 404, kolejki zapisów, 404 dla `productId` spoza UUID; domknięcie tego nie uzupełniło |
| F5 | CART-1 | reguła „da się kupić” jest zdublowana w `Cart` i w `CartViewBuilder` |
| F6 | CART-1 | indeks `cart_line (cart_id)` dubluje prefiks indeksu unikalnego |

### Uwagi z review zadań, które zostały w kodzie

- **CART-4, I1:** dla `CartBadge` nie było prawdziwej fazy RED. Test i komponent powstały razem, a RED odtworzono po
  fakcie, czego `CLAUDE.md` zabrania. PR mówi to wprost. Plik stanu mimo to ma `tdd.status: verified`, bo dowody RED
  dotyczą pozostałych zachowań zadania.
- **CART-5:** trzy Minory:
  - callback logowania czeka na scalenie bez limitu czasu;
  - spec nie opisuje 401 scalenia;
  - komunikat `merge_truncated` może się spóźnić przy nieudanym scaleniu w callbacku.

### Rozbieżność specyfikacji z CART-1

W Doctrine ORM 3 kaskada `refresh` czyta linie przed zablokowaniem wiersza koszyka. CART-1 zaimplementował blokadę
bez kaskady i zgłosił to w PR do decyzji użytkownika. Nikt nie zatrzymał przebiegu: zadanie domknięcia poprawiło
zdanie w spec.

### Kryteria współbieżności

Kryteria K1–K8 działają na PostgreSQL w CI (procesy `pcntl_fork`, bariera na advisory locku). K8 nie było
przypisane do żadnego zadania planu. Dopisał je CART-2 jako dowód poprawki zakleszczenia.

## 6. Interwencje

Cel osiągnięty: końcowy PR z zielonym CI, a AO liczy 0 interwencji człowieka i 0 operacyjnych. Obserwator nie
wykonał w trakcie przebiegu żadnej czynności na AO ani na stosie.

Przed startem obserwator:
- przewinął checkout AO do v0.3.1;
- utworzył `AO_HOME` z wrapperem `ao.sh`, skryptem `ao-run.sh` (limity, `caffeinate`, `--notify-cmd`)
  i `ao-notify`.

Merge PR #71 (spec i plan) i PR #79 (epik do `main`) wykonał użytkownik zgodnie z zasadami repo. Nie są to
interwencje w przebieg.

## 7. Awarie i ponowienia

Żadna próba wykonawcy nie zawiodła. Trzy przebiegi CI były czerwone z przyczyn infrastruktury GitHub Actions,
niezwiązanych z kodem. Za każdym razem AO samo ponowiło przebieg (`epic_ci_rerun`) i drugie podejście było zielone.

| Czas | Przebieg | Objaw | Koszt czasu |
|---|---|---|---|
| 22:43 | `push` na `main` `8c51468` (CI startowe epiku) | `checks`: `Elasticsearch is unreachable (create index): Idle timeout reached` w `init-test` | ok. 18 min przed startem CART-1; przebieg `push` na `epic/cart` dla tego SHA był wtedy już zielony |
| 02:18 | `push` na `epic/cart` `cd26cac` (po CART-3) | `e2e`: ten sam timeout Elasticsearch w kroku „Start the stack” | 0: CART-4 już pracował |
| 04:19 | `pull_request` końcowego PR `df4484e` | `e2e`: `No space left on device` na runnerze | ok. 9 min do `DONE` |

Timeout Elasticsearch wystąpił dwa razy w jednej nocy, w `checks` i w `e2e`. Pojedyncze ponowienie wystarczyło.

## 8. Ścieżki AO sprawdzone pierwszy raz na żywo

| Ścieżka | Wynik |
|---|---|
| start epiku z CI z `main` dla tego samego SHA | działa (`source_branch: main`). AO ponowiło czerwony przebieg z `main` i czekało na niego do 23:01, choć przebieg `push` na `epic/cart` dla tego SHA był zielony już o 22:43 |
| CI PR zadania jako CI epiku przy równych drzewach | działa: 6 z 6 merge'ów, merge w 11–14 s po `READY` |
| ponowienie czerwonego CI (`epic_ci_rerun`, `epic_ci_inherited_red`) | działa, 3 razy, za każdym razem zielone po ponowieniu |
| krok schedulera w trakcie próby (CI epiku zielone podczas pracy następnego zadania) | działa: 5 razy `epic_ci_green` w trakcie próby |
| QA `/integration` na żywym stosie AO przez HTTP | działa: 13 skryptów czarnej skrzynki |
| zadanie domknięcia przy PASSED bez rundy FIX | działa |
| ostrzeżenie o baterii na starcie (`host_on_battery`) | działa |
| ograniczenie czasu do okresów bez uśpienia | **nie sprawdzone**: uśpienia nie było (0 s przerw) |
| czerwone CI PR zadania → tryb poprawki AO | **nie wystąpiło** |
| konflikt przy merge'u → tryb poprawki AO | **nie wystąpił** |
| czerwone CI gałęzi epiku z winy kodu → `REPAIRING` | **nie wystąpiło** (czerwone przebiegi były infrastrukturalne i przeszły po ponowieniu) |
| cooldown limitu Claude, `BLOCKED repeated_failure`, ponowienie z linią „Poprzednia próba” | **nie wystąpiły** |
| `--notify-cmd` przy `BLOCKED`/`FAILED` | **nie wystąpiło**: nie było takiego zdarzenia |

## 9. Czas i wąskie gardła

| Składnik | Pomiar |
|---|---|
| CI PR zadania (`pull_request`), czekanie wewnątrz próby `/delivery` | 11,5–18 min na zadanie; dla FINALIZE ok. 1 min (zmiana tylko w dokumentacji) |
| CI gałęzi epiku (`push`) po merge'u | 10,3–20 min; nie blokowało merge'y ani promocji (CI dziedziczone z PR) |
| CI startowe epiku z ponowieniem | 38 min bezczynności wykonawcy |
| ponowienie CI końcowego PR | 8,5 min bezczynności wykonawcy |
| bezczynność wykonawcy łącznie | 1 h 15 min z 6 h 06 min: 47 min to CI epiku, 28 min to inne (start, zakładanie zadań, rozruch prób) |
| kolejka (jedna próba naraz) | CART-3 czekał 1 h 03 min, CART-4 2 h 05 min, mając zielone zależności |

## 10. Wnioski

- AO v0.3.1 przeprowadziło cały epik bez interwencji i bez nieudanej próby. Nie było potrzeby zmieniać AO.
- Czas skrócił się względem AO-EPIC-002 z ok. 10 h 41 min do 6 h 06 min czasu aktywnego. Złożyły się na to: brak
  nieudanych prób, CI dziedziczone z PR zamiast czekania na `push` i mniej zadań (5 zamiast 8).
- Największy pozostały koszt czasu to szeregowa kolejka prób (3 h 08 min czekania zadań fali 2) i CI wewnątrz prób.
- Niestabilność infrastruktury CI (timeout Elasticsearch, brak miejsca na runnerze) pokrywa ponowienie w AO.
  W `shop` jest to ryzyko dla każdego przebiegu CI, nie tylko w AO.
- Otwarte dla `shop`:
  - Minory F1–F6;
  - TDD bez prawdziwego RED dla `CartBadge` w CART-4;
  - Minory CART-5 (brak limitu czasu scalania w callbacku, opis 401 w spec).
- Operacyjnie: długi przebieg pod `caffeinate`, na zasilaniu, bez zamykania klapy.
