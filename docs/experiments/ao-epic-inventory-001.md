# Eksperyment AO-EPIC-002: epik Inventory przez AO v0.2 bez integratora

Pierwszy rzeczywisty przebieg AO v0.2 na epiku `shop`: od zaakceptowanego planu AO samo prowadzi zadania przez
`/delivery`, merguje PR do `epic/inventory`, uruchamia `/integration` z naprawami i otwiera końcowy PR do `main`.
Sesja Claude Code była wyłącznie obserwatorem. Raport opisuje przebieg z 2026-11-02/03 i po zapisaniu się nie zmienia.

## 1. Warunki

- Epik: Inventory MVP, punkt 9 MVP z `docs/vision.md` bez Ordering. Specyfikacja `docs/features/inventory/spec.md`,
  plan z manifestem 8 zadań w 4 falach (usunięty przez zadanie domknięcia, PR #63).
- Rewizja startowa: `05fcf55` (`main`, PR #53 ze specyfikacją i planem). Wynik: `epic/inventory` na `2750253`,
  końcowy PR #64 z zielonym CI, niezmergowany (merge do `main` należy do użytkownika).
- AO: `piotrp321/agentic-orchestrator` `main` `fd2f0d5` (v0.2), osobny `AO_HOME`
  (`~/.local/state/ao-inventory`). Wykonawca: `claude -p --model opus --effort high`, Claude Code 2.1.308, Max.
- Limity uzgodnione przed startem: `--epic-max-attempts 24`, `--epic-timeout 57600` (16 h), `--max-budget-usd 30`
  i `--timeout 14400` (4 h) na próbę, `--max-qa-rounds 3`.
- Protokół: obserwator nie uruchamia `/delivery` ani `/integration`, nie zmienia kodu, AO, skilli, specyfikacji ani
  planu. Dozwolone interwencje operacyjne (ponowne `ao run`, restart Dockera lub hosta) i decyzyjne (`ao answer`,
  `ao epic resume`, `ao resume`), każda w dzienniku. Jeden kontrolowany restart AO w trakcie próby.
- Różnice względem AO-EPIC-001:
  - `/brainstorm` i `/planner` przeprowadziła sesja obserwatora według ich `SKILL.md`, nie wywołania slash command
    przez użytkownika (skille mają `disable-model-invocation`).
  - Przed startem zatrzymano 5 starych stosów `shop` z wcześniejszych eksperymentów (`stop`, bez usuwania wolumenów),
    za zgodą użytkownika: pamięć Dockera spadła z 26,4 do 13,6 GiB z 31,3 GiB.
  - Od ok. 09:50 drugiego dnia równolegle działała sesja planu AO v0.3 na tej samej subskrypcji.
- Dziennik obserwatora (czasy, interwencje, decyzje): `~/.local/state/ao-inventory/OBSERVER.md`, poza repo.

## 2. Przebieg i czas (CET)

| Etap | Czas | Uwagi |
|---|---|---|
| Discovery, `/brainstorm`, jedna runda pytań, projekt, spec | 02.11 do ok. 12:20 | 14 pytań, dwa doprecyzowania użytkownika (wybór blokady, idempotencja przyjęć) |
| `/planner`, PR #53, merge | do ok. 12:38 | |
| `ao epic start`, CI gałęzi epika na `05fcf55` | 12:40 – 12:58 | pełny przebieg `push`, choć ten SHA był zielony na `main` |
| Fala 1: INVENTORY-1 | 13:05 – 14:43 | 3 próby, `BLOCKED` na decyzji B1 (14:06–14:10) |
| Fala 2: INVENTORY-2, 4, 5 | 14:54 – 19:06 | kontrolowany restart AO o 15:00; INVENTORY-4 `FAILED`, ręczna poprawka i `resume` (17:51–18:22) |
| Fala 3: INVENTORY-3, 6, 7 | 17:19 – 20:59 | INVENTORY-3 zależało tylko od 2, więc ruszyło w fali 2 |
| Fala 4: INVENTORY-8 (E2E) | 20:35 – 21:14 | ostatni merge zadania planu o 21:14 (8 h 34 min od startu) |
| `/integration` runda 1 (FULL) | 21:29 – 21:50 | FINDINGS: 1 Important, 1 Minor |
| INVENTORY-FIX-1 | 21:51 – 09:34 | host uśpiony 22:13 – 09:23; próba zabita po wybudzeniu, epik `BLOCKED (epic_timeout)` |
| Wznowienie | 03.11 09:31 | upgrade planu Claude przez użytkownika, `epic resume`, `ao run` pod `caffeinate` |
| `/integration` runda 2 (DELTA) | 09:51 – 10:03 | PASSED, 2 Minor |
| INVENTORY-FINALIZE | 10:05 – 10:12 | usunięcie planu, uzgodnienie spec z kodem (PR #63) |
| Końcowy PR #64, CI | 10:28 – 10:39 | epik `DONE` |

Od startu epiku do zielonego końcowego PR: 21 h 59 min, z czego 11 h 10 min uśpienia hosta i 8 min oczekiwania na
upgrade planu. Czas aktywny: ok. 10 h 41 min. Czas prób wykonawcy bez uśpienia: ok. 7 h 54 min.

## 3. Zadania i próby AO

| Zadanie | PR | Próby (wynik) | Czas pracy | Wycena CLI |
|---|---|---|---|---|
| INVENTORY-1 model, persystencja, port rezerwacji | #54 | 3 (invalid_output, BLOCKED, READY) | 52,0 + 7,3 + 30,7 min | $20.81 |
| INVENTORY-2 przyjęcia i korekty w API | #55 | 1 | 64,3 min | $16.07 |
| INVENTORY-3 dane demo stanów | #57 | 1 | 30,6 min | $4.80 |
| INVENTORY-4 dostępność w sklepie | #56 | 3 (invalid_output, invalid_output → FAILED, READY) | 75,7 + 0,8 + 0,7 min | $5.13 |
| INVENTORY-5 lista stanów w panelu | #58 | 1 | 56,5 min | $5.34 |
| INVENTORY-6 przyjęcie i korekta w panelu | #59 | 1 | 27,2 min | $3.50 |
| INVENTORY-7 historia stanów | #60 | 1 | 42,9 min | $3.89 |
| INVENTORY-8 E2E | #61 | 1 | 23,6 min | $2.04 |
| INVENTORY-INTEGRATION-1 (FULL) | – | 1 (FINDINGS) | 20,6 min | $5.06 |
| INVENTORY-FIX-1 (F1) | #62 | 2 (timeout, READY) | ok. 22 min aktywnie + 0,7 min | $2.03 |
| INVENTORY-INTEGRATION-2 (DELTA) | – | 1 (PASSED) | 12,0 min | $1.97 |
| INVENTORY-FINALIZE | #63 | 1 | 6,7 min | $2.09 |
| **Razem** | | **17 (4 nieudane)** | **ok. 7 h 54 min** | **$72.74** |

Wycena CLI to równowartość kosztu API. Przebieg szedł na subskrypcji Claude Max. Próby bez wyniku kosztowały
$22.35 (INVENTORY-1/1, INVENTORY-4/1 i 2, FIX-1/1). Limity epiku nie zostały przekroczone poza `--epic-timeout`
(17 z 24 prób, 2 z 3 rund QA).

Każde zadanie planu przeszło `code-reviewer` w `/delivery`, TDD ze statusem `verified` (INVENTORY-8 i FINALIZE bez
TDD: sam test E2E i dokumentacja) i zielone CI (`checks`, `e2e`) przed merge'em. Żadne zadanie nie wymagało rundy
poprawki AO po czerwonym CI ani po konflikcie.

## 4. Tokeny, modele i efforty

Tokeny prób AO (z `stdout.jsonl`, pole `modelUsage` wyniku):

| Model | Wejście bez cache | Zapis cache | Odczyt cache | Wyjście | Wycena |
|---|---|---|---|---|---|
| Opus 5.5 | 1,8 tys. | 3,96 mln | 98,2 mln | 817 tys. | $59.58 |
| Sonnet 5.5 | 0,9 tys. | 2,30 mln | 38,1 mln | 359 tys. | $13.16 |

| Rola | Model | Effort |
|---|---|---|
| Sesja obserwatora (brainstorm, planner, obserwacja, raport) | Opus 5.5 | sesyjny |
| Wykonawca AO: `/delivery`, `/integration` | Opus 5.5 | high |
| `developer` (implementacja w `/delivery`) | Sonnet 5.5 | high |
| `code-reviewer`, `architecture-reviewer` | Opus 5.5 | high |
| `qa-adversarial` (w `/integration`) | Sonnet 5.5 | high |

Limit tygodniowy subskrypcji: `rate_limit_event` `allowed_warning`, `seven_day` od 0,92 w pierwszej próbie do 0,99
od INVENTORY-7, reset 04.11 19:00. Odrzucenia nie było. Po upgradzie planu (03.11 09:31) wykorzystanie 0.
Okno 5 h doszło najwyżej do 0,65.

## 5. Jakość

- `/integration` runda 1 (FULL na `da2c6bf`, 226 plików, +18 566/−45):
  - F1 [Important]: korekta bez zmiany na pustym rekordzie z seeda zwracała `version: null`, co dawało w panelu „—”
    i fałszywe 409 przy następnej korekcie (INVENTORY-2). Zgłoszone niezależnie przez architekta i QA.
  - F2 [Minor]: panel zgaduje przyczynę 409 korekty, a `StockModifiedConcurrently` ma angielski komunikat (INVENTORY-6).
  - Architekt: APPROVE_WITH_CHANGES. Poza epikiem: duplikacja z Pricing (composable list i historii, rozpakowanie
    `HandlerFailedException`), kierunek indeksu historii.
  - QA: PASS, ale **statycznie, bez stosu** (`qa-adversarial` ma `isolation: worktree`, a `/integration` zabrania mu
    `make`). W AO-EPIC-001 QA wykonywał scenariusze na żywym stosie.
- Runda 2 (DELTA na `2abccb8`): PASSED, F1 naprawione (test regresji w handlerze i w endpoincie), F2 bez zmian,
  F3 [Minor] nieaktualny opis fixture'a stanów w testach. Minor trafiły do opisu końcowego PR („tylko w raporcie,
  bez napraw”), nie do zadania naprawczego.
- Bramki rundy 1: `make check` OK, `make test` (api 1592, store 278, admin 342), `make seed` (454 stany),
  `make e2e` 9 passed, w tym nowy test przyjęcia w panelu widocznego w sklepie.
- Regresja wykryta przez CI zadania: etykieta dostępności na kafelku (INVENTORY-4) złamała lokator testu E2E cen
  (`strict mode violation`, dwa `<p>`). `/delivery` naprawił to w tej samej próbie (`data-testid`).
- Kryteria współbieżności C1–C6 ze specyfikacji działają na PostgreSQL w CI (procesy `pcntl_fork`, bariera advisory
  lock, 5 rund). Wszystkie przebiegi `push` na `epic/inventory` (11) były zielone.

## 6. Interwencje

Sukces funkcjonalny osiągnięty (końcowy PR z zielonym CI). Autonomia nie: 3 interwencje decyzyjne i 2 nieplanowane
operacyjne. AO zlicza 3 (`answer`, `resume`, `epic resume`). Zmiany środowiska i ręcznej poprawki pliku stanu nie widzi.

Zaplanowane:

| Czas | Czynność | Wynik |
|---|---|---|
| 02.11 15:00 | kontrolowany restart `ao run` w trakcie INVENTORY-2/1 (sekcja 8) | bez wpływu na próbę |

Operacyjne (nieplanowane):

| Czas | Przyczyna | Czynność | Koszt czasu |
|---|---|---|---|
| 02.11 14:10 | `claude -p` ucina zadania w tle 10 min po ostatniej turze (INVENTORY-1/1) | `ao run` ze zmienną `CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS=0` (decyzja użytkownika A) | razem z B1 |
| 03.11 09:31 | host uśpiony w nocy (klapa, bateria), `ao run` zakończony po wybudzeniu | `ao run` pod `caffeinate -dims`, na zasilaniu | 11 h 18 min przestoju |

Decyzyjne:

| Czas | Przyczyna | Czynność | Koszt czasu |
|---|---|---|---|
| 02.11 14:10 | INVENTORY-1 `BLOCKED` B1: zapis przez DBAL wbrew regule rekordów ORM w `apps/api/CLAUDE.md`; luka specyfikacji (`FOR UPDATE` i `ON CONFLICT` obok „rekordy i mapowanie w repozytoriach”) | `ao answer`: wyjątek dla Inventory z dokumentacją | 4 min |
| 02.11 18:22 | INVENTORY-4 `FAILED`: skrócony `review.sha` w pliku stanu `/delivery`, odrzucany przez walidator AO | poprawka jednego pola do pełnego SHA, `ao resume` | 31 min |
| 03.11 09:31 | epik `BLOCKED (epic_timeout)` po nocy, limit tygodniowy na 99% | `ao epic resume --extend-timeout 32400` po upgradzie planu przez użytkownika | 8 min |

## 7. Awarie i ich klasy

| Próba | Klasa | Objaw | Skutek |
|---|---|---|---|
| INVENTORY-1/1 | środowisko wykonawcy (CLI) | `/delivery` zlecił rundę poprawek subagentowi w tle i zakończył turę; CLI zatrzymał subagenta po 10 min | `invalid_output`, retry; praca zachowana w worktree |
| INVENTORY-1/2 | luka specyfikacji | `/delivery` zwrócił `BLOCKED` z decyzją architektoniczną | interwencja decyzyjna |
| INVENTORY-4/1, 2 | kontrakt `/delivery` ↔ AO | `review.sha` skrócony (`fd016b7`); `delivery_state.py` go przyjmuje, AO nie | `FAILED`, ponowienie deterministycznie bezużyteczne |
| FIX-1/1 | host | uśpienie 22:13–09:23 liczone do `--timeout` i `--epic-timeout` (czas zegarowy) | próba zabita po wybudzeniu (dokończyłaby się sama), epik `BLOCKED` |
| – | limit subskrypcji | `seven_day` 0,99 bez odrzucenia | bez awarii; odrzucenie dałoby ok. 45 h cooldownu bez możliwości zdjęcia |

Wznowienia: AO samo ponowiło INVENTORY-1 (po `invalid_output`), INVENTORY-4 (raz) i FIX-1 (po timeout). Ręczne:
`ao answer` (INVENTORY-1), `ao resume` (INVENTORY-4), `ao epic resume` (epik).

## 8. Ścieżki AO sprawdzone pierwszy raz na żywo

| Ścieżka | Wynik |
|---|---|
| `ao epic start` z gałęzią epika i całym grafem | działa |
| promocja fal po merge'u i zielonym CI epika | działa; zadania już wypromowane startują w trakcie CI epika |
| squash-merge PR zadania do `epic/inventory` (9 razy) | działa, za każdym razem jeden PR i jeden merge |
| `/integration` FULL, zadanie FIX, runda DELTA | działa; Minor przechodzą do raportu |
| zadanie domknięcia (`FINALIZE`) | działa (usunięcie planu przez PR) |
| końcowy PR do `main` i `DONE` po zielonym CI | działa; AO nie merguje |
| kontrolowany restart w trakcie próby | działa (sekcja 9) |
| `epic resume` po `epic_timeout` | działa |
| czerwone CI PR zadania → tryb poprawki AO | **nie sprawdzone**: jedyne czerwone CI naprawił `/delivery` w tej samej próbie |
| konflikt przy merge'u → tryb poprawki AO | **nie wystąpił** |
| czerwone CI gałęzi epika → `REPAIRING` | **nie wystąpiło** |
| cooldown limitu Claude | **nie wystąpił** |

## 9. Kontrolowany restart AO

02.11 15:00:16, 5 min po starcie INVENTORY-2/1: SIGTERM do `ao run`, nowy `ao run` z tymi samymi limitami
o 15:00:39 (23 s bez orkiestratora).

- Runner próby i `claude` przeżyły (osobna sesja procesów, blokady trzymane przez runner).
- Rekoncyliacja: `attempt 1 left by a previous run is running`, jedno nowe zdarzenie `reconciled {probe: running}`.
- Brak podwójnych operacji: liczba prób bez zmian (4 → 4), brak nowego PR. Po końcu próby zadanie miało dokładnie
  jeden PR (#55) i jeden merge (`6b29bec`).
- Termin próby liczony od `started_at` z bazy, więc watchdog po restarcie zachował ten sam termin. Podczas 23 s
  przerwy nikt nie pilnował terminu.

## 10. Czas i wąskie gardła

| Składnik | Pomiar |
|---|---|
| CI PR zadania (`pull_request`) | 7,4–17,3 min; `/delivery` czekał na nie wewnątrz próby, łącznie ok. 101 min |
| CI gałęzi epika (`push`) po merge'u | 10,7–15,2 min; bramka merge'a i promocji, ok. 92 min bezczynności wykonawcy |
| CI na starcie epika | 17 min na SHA, który był już zielony na `main` |
| merge gotowego PR po CI epika | INVENTORY-5 czekało 17 min; INVENTORY-7 25 min, bo krok merge'a wykonał się przed krokiem CI epika w tym samym obrocie |
| kolejka (jedna próba naraz) | 275 min czekania zadań z zielonymi zależnościami przy pierwszych próbach |
| status w trakcie próby | krok schedulera tylko między próbami: zielone CI epika widoczne z opóźnieniem 29–63 min |

## 11. Wnioski dla AO v0.3

Szczegółowy plan z dowodami jest w repozytorium AO (`docs/plan-v0.3.md` na gałęzi `claude/ao-v0-3-plan`). W skrócie:

1. Czas próby i epika bez uśpienia hosta i bez cooldownu limitu (wykrywanie przerw zegara zapisywane w bazie).
2. Skrócony `review.sha`: rozwinięcie w `delivery_state.py` (`shop`) i obrona w AO przez `git rev-parse`.
3. `/delivery` nie kończy tury w oczekiwaniu na subagenta (`shop`); AO domyślnie ustawia
   `CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS=0` jako bezpiecznik, plus wykrywanie braku postępu.
4. `run --until-idle` czeka na koniec cooldownu (z górnym limitem), a `release --cooldown` zdejmuje go po upgradzie planu.
5. Krok schedulera w trakcie próby; CI epika przed merge'em w kroku; promocja od ostatniego zielonego HEAD.
6. CI PR jako CI epika przy równych drzewach; start epika bez CI dla SHA już zielonego na `main`.
7. Ostrzeżenie o zużyciu limitu (`allowed_warning`) w `status` i raporcie.
8. `shop`: QA w `/integration` z dostępem do stosu; `/brainstorm` sprawdza konflikty projektu z regułami `CLAUDE.md`.
9. Równoległe próby dopiero po zmianach szeregowych i pomiarze zasobów stosu (szacowany zysk ok. −3 h w tym epiku).

Operacyjnie: długi przebieg tylko na zasilaniu, z wyłączonym usypianiem (`caffeinate`) i bez zamykania klapy.
