# Plan: Agentic Software House 24/7

Docelowa architektura i zasady: [ADR](../../adr/agentic-software-house.md). Ten plan ustala kolejność etapów.
Każdy etap przed implementacją dostaje własną specyfikację (`/brainstorm`) i plan zadań, akceptowane przez
użytkownika. Kod AO powstaje w repozytorium `piotrp321/agentic-orchestrator`, skille i agenci w `.claude/` tego
repozytorium.

## Status

W toku: `/planner` i `ao submit-plan` (etap 2) w osobnej sesji. Następny krok: **pozostałe decyzje z etapu 0**
(kanał skrzynki decyzji, host, polityka autonomii). Blokery: brak.

| Etap | Zakres | Repozytorium | Status |
|---|---|---|---|
| 0 | Decyzje wstępne | – | kontrakt i backlog ustalone |
| 1 | Skrzynka decyzji i polityka autonomii | shop, AO | do zrobienia |
| 2 | Backlog i `/planner` | shop, AO | w toku |
| 3 | AO jako demon 24/7 | AO | do zrobienia |
| 4 | `/integration` | shop | do zrobienia |
| 5 | Pula środowisk i budżet | AO, shop (`infra`) | do zrobienia |
| 6 | Merge queue i release | shop | do zrobienia |
| 7 | Monitoring → intake | shop, AO | do zrobienia |
| 8 | Metryki, evale i pętla uczenia | AO, shop | do zrobienia |

Stan wyjściowy: `/brainstorm` i `/delivery` v2.1 działają, AO v0.1 prowadzi jedno zadanie naraz bez trybu demona.

## Etapy

### 0. Decyzje wstępne

Decyzje użytkownika, od których zależą kolejne etapy. Dla każdej rekomendacja:

- **Kontrakt manifestu zadań (ustalony).** Sekcja „Kontrakt planu i backlogu”.
- **Gdzie żyje backlog (ustalone).** Definicje zadań w manifeście `plan.md` w Gicie, stan zadań w bazie AO.
  Bez GitHub Issues i bez własnej bazy zadań w `shop`.
- **Kanał skrzynki decyzji.** Rekomendacja: issue z etykietą `decision` w GitHubie (odpowiedź komentarzem)
  i powiadomienie push na telefon.
- **Host 24/7.** Rekomendacja na start: obecny Mac z wyłączonym usypianiem i launchd. Serwer dopiero, gdy limit
  zasobów jednej maszyny stanie się realny.
- **Polityka autonomii na start.** Rekomendacja: żadnego automatycznego merge'a. Pierwszy krok autonomii to
  automatyczny merge zadań `FAST` z zielonym CI i czystym review do gałęzi funkcji, nie do `main`.

Koniec etapu: decyzje zapisane w ADR (zmiana obecnego pliku albo nowe ADR-y).

### 1. Skrzynka decyzji i polityka autonomii

Cel: kolejka nie stoi, gdy czeka na człowieka.

- AO publikuje bloker `/delivery` (`business`, `architecture`, `cost`, `technical`) jako decyzję w skrzynce:
  pytanie, opcje, rekomendacja, dowody. Odpowiedź wznawia zadanie (`answer` w AO już istnieje).
- Akceptacja specyfikacji i planu też przechodzi przez skrzynkę.
- Polityka autonomii jako plik w repozytorium: które decyzje i jakie ryzyko (`FAST`, `STANDARD`, `CRITICAL`)
  wymagają człowieka.
- Powiadomienie o nowej decyzji.

Koniec etapu: bloker zgłoszony w nocy czeka w skrzynce, AO w tym czasie robi inne zadania bez blokera,
odpowiedź rano wznawia zablokowane zadanie.

### 2. Backlog i `/planner`

Cel: zaakceptowana specyfikacja zamienia się w zadania, które AO bierze bez udziału człowieka.

- Skill `/planner`: ze `spec.md` tworzy `plan.md` z manifestem zadań.
- `ao submit-plan` importuje plan z `origin/main` i zgłasza pierwszą falę.
- AO sam zgłasza kolejne fale, gdy zależności są zmergowane (punkt 4 kontraktu).

Koniec etapu: funkcja od zaakceptowanej specyfikacji do PR-ów wszystkich zadań bez ręcznego `submit`.

### 3. AO jako demon 24/7

Cel: AO działa bez nadzoru. Zakres z sekcji „Ograniczenia” README AO:

- tryb demona pod launchd, samodzielne wznowienie po limicie subskrypcji;
- sprawdzanie PR i CI w GitHubie, tryb poprawki `/delivery` na czerwonym CI;
- wykrywanie braku postępu i limit czasu, anulowanie;
- kilka zadań naraz, każde we własnym worktree (po etapie 5).

Koniec etapu: przebieg nocny z kilkoma zadaniami i jednym limitem subskrypcji kończy się bez interwencji.

### 4. `/integration`

Cel: weryfikacja całej funkcji, nie pojedynczych PR-ów.

- Skill `/integration`: łączy PR-y funkcji, uruchamia `make check`, testy i `make e2e` na pełnym stosie,
  review architektury (`architecture-reviewer`) i adwersarialne QA (`qa-adversarial`).
- Problem w jednym zadaniu wraca do jego PR przez tryb poprawki `/delivery`. Problem kilku zadań to decyzja
  w skrzynce.
- Wynik: raport integracji i prośba o akceptację w skrzynce.

Koniec etapu: funkcja z kilku zadań przechodzi integrację z co najmniej jedną automatyczną poprawką.

### 5. Pula środowisk i budżet

- Worktree i stos Docker na zadanie z wolnymi portami (mechanizm `.env` worktree), sprzątanie po zadaniu.
- Limit zadań naraz wynikający z zasobów hosta.
- Budżet na funkcję i priorytety kolejki. Rezerwa limitu subskrypcji na pracę interaktywną w dzień.

Koniec etapu: kilka zadań naraz na jednym hoście bez kolizji portów i bez osieroconych kontenerów.

### 6. Merge queue i release

- Merge zatwierdzonej funkcji (squash, usunięcie gałęzi) w kolejce z ponownym CI.
- Wdrożenie zgodne z `docs/vision.md` (k3s + Argo CD): staging automatycznie, produkcja po akceptacji,
  rollback.

Koniec etapu: zatwierdzona funkcja trafia na staging bez ręcznych kroków.

### 7. Monitoring → intake

- Alerty z obserwowalności z `docs/vision.md` (Grafana Cloud, Sentry) tworzą zgłoszenia w intake z kontekstem
  (logi, trace, wersja).
- Zgłoszenie błędu przechodzi zwykłą drogą: `/brainstorm` dla zmian zachowania, `/delivery` dla poprawki.

Koniec etapu: błąd ze stagingu kończy się PR z poprawką bez ręcznego zakładania zgłoszenia.

### 8. Metryki, evale i pętla uczenia

- Metryki z AO: czas od zgłoszenia do merge'a, rundy poprawek, odsetek blokerów, zużycie modelu.
- Benchmark skilli na bazie `docs/experiments/` (`delivery-001`, `brainstorm-00x`): zmiana skilla przechodzi
  benchmark przed użyciem.
- Retro po funkcji: propozycje zmian w skillach i `CLAUDE.md`, wdrażane po evalach i akceptacji użytkownika.

Koniec etapu: pierwsza zmiana skilla zaproponowana przez retro i potwierdzona benchmarkiem.

## Kontrakt planu i backlogu

Styk `/planner` → backlog → AO → `/delivery`. Format manifestu i jego walidator są w
`.claude/skills/planner/` (`schema_version: 1`), import pierwszej fali to `ao submit-plan`.

1. **Jedno źródło na rodzaj danych.** Definicja zadania (ID, tytuł, zakres, kryteria akceptacji, ryzyko,
   zależności, specyfikacja) jest tylko w manifeście. Stan zadania (kolejka, próby, PR, blokery) jest tylko w AO.
   AO nie zapisuje niczego w repozytorium, `/planner` nie zgłasza zadań do AO.
2. **Plan wiążący = plan na `origin/main`.** Zaakceptowany plan i jego specyfikacja trafiają do `main` przed
   zgłoszeniem zadań, więc każda gałąź zadania zawiera specyfikację. AO zapamiętuje SHA commita, z którego
   zaimportował plan. Plan z innej gałęzi na `origin` AO przyjmuje tylko na jawne wskazanie (testy
   akceptacyjne bez merge'a do `main`).
3. **Zmiana planu po imporcie.** Dziś import epica z innego commita, w którym zadanie już zgłoszone ma inną bazę,
   kończy się odmową całego importu. Docelowo, razem z punktem 4: zadania dodane są przyjmowane, zadania
   niezgłoszone mogą się zmienić, a zmiana albo usunięcie zadania już zgłoszonego kończy się odmową i decyzją
   w skrzynce. Plan zmieniony po akceptacji wymaga ponownej akceptacji (zasada `/planner`).
4. **Gotowość zadania zależnego.** Zależność jest spełniona, gdy PR z gałęzi `task/<ID>` jest zmergowany do `main`.
   AO sprawdza to w GitHubie (`gh pr view`), nie historią Gita, bo squash merge nie zostawia gałęzi w historii.
   Bazą zadania zależnego jest aktualny `origin/main`. Gdy zależności są spełnione, AO zgłasza zadanie samo.
5. **Wejście `/delivery`.** AO przekazuje ID zadania i ścieżkę `plan.md`. `/delivery` czyta swoje zadanie
   z manifestu na commicie bazowym, a specyfikację z pola `spec`. `risk` z manifestu to podpowiedź, wiąże
   klasyfikacja `/delivery`.
6. **Wersjonowanie.** Każda zmiana pól manifestu podnosi `schema_version` i wymaga zmiany walidatora `/planner`
   i AO w tej samej fali prac. AO odrzuca nieznaną wersję. AO sprawdza tylko pola, których używa, pełny format
   sprawdza walidator w `shop`.

## Punkty zatrzymania

- **Etap 0:** wszystkie decyzje użytkownika przed rozpoczęciem etapu 1.
- **Każdy etap:** specyfikacja i plan zadań zaakceptowane przed implementacją.
- **Etapy 1 i 6:** każde rozszerzenie polityki autonomii (automatyczny merge, wdrożenie bez akceptacji) wymaga
  jawnej zgody użytkownika.
