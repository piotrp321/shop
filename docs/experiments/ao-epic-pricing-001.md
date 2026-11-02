# Eksperyment AO-EPIC-001: epik Pricing od `/brainstorm` do `main` przez AO

Pierwszy pełny przebieg procesu Agentic Software House na rzeczywistym epiku: `/brainstorm` → `/planner` →
AO (`ao submit-plan`, `ao run`) → `/delivery` na zadanie → integracja → PR do `main`. Raport opisuje przebieg
z 2026-11-01/02 i po zapisaniu się nie zmienia.

## 1. Warunki

- Epik: Pricing, punkt 5 MVP z `docs/vision.md`. Specyfikacja `docs/features/pricing/spec.md`, plan z manifestem
  8 zadań w 5 falach (usunięty po ukończeniu epika).
- Rewizja startowa: `adab2fa` (`main`). Wynik: `97a5e82` (#46, epik) i `a64f0ef` (#47, follow-up).
- AO: `piotrp321/agentic-orchestrator` `main` `9e58e02` (v0.1 z `submit-plan`), `AO_HOME` osobny dla epika,
  limit 40 USD i 4 h na próbę. Wykonawca: `claude -p --model opus --effort high`. Claude Code 2.1.308.
- Ograniczenia ustalone przez użytkownika: bez zmian AO, `/brainstorm`, `/planner`, `/delivery`; bez ADR; bez zmian
  w `docs/features/walking-skeleton/` i `README.md` (pracowała nad nimi inna sesja); merge do `main` tylko za zgodą.
- AO v0.1 zgłasza tylko pierwszą falę i nie ma etapu integracji, więc rolę integratora przejęła sesja Claude Code
  (Opus 5.5, effort medium) na gałęzi `epic/pricing`. Tryb zaakceptował użytkownik.

## 2. Przebieg i czas (CET)

| Etap | Czas | Uwagi |
|---|---|---|
| Discovery, `/brainstorm`, dwie rundy korekt projektu, spec | 01.11 od ok. 17:09 | korekty: klasa podatkowa zamiast kategorii, jednokierunkowa zależność Pricing → Catalog |
| `/planner`, PR #33 ze spec i planem, merge | do 17:47 | ok. 40 min razem z etapem wyżej |
| Fala 1: PRICING-1 | 17:49 – ok. 18:25 | PR do `main`, baza zmieniona ręcznie; ponowienie flaky CI |
| Fala 2: PRICING-2 | 18:28 – 19:35 | |
| Fala 3: PRICING-3, 4, 7 | 19:37 – 21:31 | kolejno (AO: jedna próba naraz) |
| Fala 4: PRICING-5, 6 | 21:32 – 00:22 | przerwa na limit Claude 22:05–23:25 |
| Fala 5: PRICING-8 (E2E) | 00:23 – 00:50 | |
| Review architektury i adversarial QA całego epika | ok. 00:50 – 01:20 | równolegle |
| PRICING-FIX-1 (poprawki z przeglądu) | 01:23 – 02:02 | |
| Bramki lokalne na czubku epika, PR #46 | ok. 02:05 – 02:35 | |
| Merge #46 do `main` | 02.11 09:38 | po zgodzie użytkownika |

Od merge'u specyfikacji do gotowego PR #46 minęło ok. 8 h 50 min. Same próby AO trwały łącznie 5 h 39 min.

## 3. Zadania i próby AO

| Zadanie | PR | Próby | Czas pracy | Wycena CLI |
|---|---|---|---|---|
| PRICING-1 Catalog `TaxClassCode` | #35 | 1 | 23,5 min | $2.92 |
| PRICING-2 model, persystencja, seed | #38 | 1 | 54,3 min | $14.85 |
| PRICING-3 ceny w sklepie | #39 | 1 | 31,5 min | $4.81 |
| PRICING-4 lista cen w panelu | #40 | 1 | 44,9 min | $7.14 |
| PRICING-7 reconcile | #41 | 1 | 32,4 min | $6.37 |
| PRICING-5 zmiana ceny | #42 | 3 (crash przy limicie, limit, sukces) | 32,6 + 0 + 26,1 min | $10.76 |
| PRICING-6 historia cen | #43 | 1 | 27,9 min | $4.48 |
| PRICING-8 E2E | #44 | 1 | 26,9 min | $2.36 |
| PRICING-FIX-1 poprawki z przeglądu | #45 | 1 | 38,8 min | $2.97 |
| **Razem** | | **11** | **5 h 39 min** | **$56.66** |

Wycena CLI to równowartość kosztu API. Przebieg szedł na subskrypcji Claude.

Każde zadanie przeszło `code-reviewer` w `/delivery`, TDD ze statusem `verified` (PRICING-8: `not_applicable`,
sam test E2E) i zielone CI (`checks`, `e2e`) przed integracją. Żadne zadanie nie zwróciło `BLOCKED`.

## 4. Tokeny, modele i efforty

Tokeny prób AO (z `stdout.jsonl`, pole `modelUsage`):

| Model | Wejście bez cache | Zapis cache | Odczyt cache | Wyjście | Wycena |
|---|---|---|---|---|---|
| Opus 5.5 | 1,6 tys. | 2,74 mln | 77,7 mln | 710 tys. | $46.08 |
| Sonnet 5.5 | 0,7 tys. | 1,43 mln | 38,1 mln | 318 tys. | $10.58 |

| Rola | Model | Effort | Zużycie |
|---|---|---|---|
| Sesja integratora (brainstorm, planner, integracja) | Opus 5.5 | medium | kontekst 372 tys. z 1 mln na końcu przebiegu |
| Wykonawca AO: `/delivery` (orkiestrator zadania) | Opus 5.5 | high | tabela wyżej |
| `developer` (implementacja w `/delivery`) | Sonnet 5.5 | high | tabela wyżej; w PRICING-2 i 7 brak użycia Sonneta |
| `code-reviewer` (review zadania) | Opus 5.5 | high | tabela wyżej |
| `architecture-reviewer` (przegląd epika) | Opus 5.5 | high | 146 tys. tokenów, 34 narzędzia, 7 min |
| `qa-adversarial` (QA epika) | Sonnet 5.5 | high | 198 tys. tokenów, 166 narzędzi, 28 min |
| subagent analizy luk AO (`general-purpose`) | Opus 5.5 | dziedziczony | 115 tys. tokenów, 14 narzędzi, 3 min |

## 5. Jakość

- Review architektury całego epika: KEEP, bez Critical. Important: usunąć plan po ukończeniu epika.
- Adversarial QA na pełnym stosie: PASS, bez Critical i Important. Między innymi 16 równoległych PUT z tą samą
  wersją dało 1×200 i 15×409 (5 rund), reconcile w trakcie 720 zapisów bez utraconych aktualizacji, 183 obliczenia
  HALF_UP zgodne z referencją, autoryzacja wszystkich ról i obu realmów. Minor: dwa sfałszowane kursory historii
  dawały 500 zamiast 400, wyścig ładowania listy z zapisem ceny w panelu.
- Wszystkie znaleziska Important i Minor z kodu naprawiło zadanie PRICING-FIX-1 (#45). Follow-up #47: polski
  komunikat 422 w złotych zamiast komunikatu walidatora po angielsku w groszach.
- Bramki na `7c0bcb5`: `make check` OK, `make test` (api 1057, store 265, admin 229), `make e2e` 8 passed.
- Zgłoszone ryzyka: wyszukiwanie po frazie z 2 znaków nie korzysta z indeksu trigramowego; indeksy bez
  `CONCURRENTLY`; wydajność bez testu obciążeniowego. Nieaktualne zostały `docs/features/walking-skeleton/spec.md`
  (`TaxCategory`, walidacja zawsze 400) i `README.md` (`make seed` bez cen), wyłączone z epika.

## 6. Czynności integratora poza AO

1. Utworzenie i wypchnięcie gałęzi `epic/pricing`.
2. Zgłaszanie fal 2–5 ręcznie (`submit-plan` zgłasza tylko pierwszą): worktree od czubka epika, `ao submit`.
3. Gałąź docelowa PR: AO jej nie przekazuje. W fali 1 PR poszedł do `main`, baza zmieniona ręcznie. Od fali 2
   brief poza repo (`--spec` z absolutną ścieżką) z gałęzią docelową `epic/pricing`.
4. Ograniczenia przebiegu: AO nie ma na nie kanału. `/delivery` zmienił wyłączony plik `walking-skeleton`
   (zgodnie z zasadą dokumentacji z `CLAUDE.md`), cofnięty commitem integratora.
5. Przenoszenie `integration_notes` do briefów kolejnych zadań.
6. Stos usług: przydział portów, `.env`, `make up`/`make down`, sprzątanie worktree i gałęzi po merge'u.
7. Obserwacja PR i CI, ponowienie flaky joba Elasticsearch.
8. Weryfikacja PR (baza, wyłączone pliki, CI, diff) i squash-merge z `--match-head-commit` do `epic/pricing`.
9. Wznowienie po limicie Claude: AO nie ma demona, opóźnione `ao run` uruchomione ręcznie.
10. Etap integracji: worktree i stos, review architektury i adversarial QA, brief zadania naprawczego z obu
    raportów, zgłoszenie go przez AO.
11. Końcowe bramki na czubku epika i PR `epic/pricing → main`.
12. Usunięcie planu po epiku (zlecone w zadaniu naprawczym; AO nie commituje dokumentacji).

## 7. Wnioski dla AO

Analiza kodu AO `9e58e02` potwierdziła luki: brak gałęzi epika, gałęzi docelowej w prompcie, odblokowywania fal
po merge'u, obserwacji PR i CI, merge'a do gałęzi epika, trybu poprawki przy konfliktach, przygotowania stosu,
etapu integracji i końcowego PR. Przebieg dodał trzy wymagania spoza tej analizy: ograniczenia na cały przebieg
epika (pliki wyłączone), przekazywanie wiedzy między zadaniami (`integration_notes` → brief kolejnych) i
wznowienie po limicie bez człowieka. Proponowana kolejność v0.2:

1. `shop`: gałąź docelowa w kontrakcie `/delivery`, CI na push do `epic/**`, ruleset `epic/*`.
2. AO: epik i gałąź docelowa (`ao epic start`, wszystkie zadania planu z `WAITING_DEPS`).
3. AO: obserwacja PR i CI bez modelu, `run --until-idle`.
4. AO: merge do epika, odblokowanie fal, tryb poprawki przy konflikcie i czerwonym CI.
5. AO: hooki stosu usług.
6. `shop`: skill `/integration` (review architektury, QA, usunięcie planu).
7. AO: etap integracji i końcowy PR, bez merge'a do `main`.

Przy jednej próbie naraz fale z wieloma zadaniami nie skracają czasu (fala 3: trzy zadania kolejno).
