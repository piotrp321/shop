---
name: delivery
description: Realizacja jednego zaakceptowanego taska do zweryfikowanego PR, ręcznie lub na zlecenie nadrzędnego orkiestratora.
argument-hint: "[issue / task / specyfikacja]"
model: opus
effort: high
disable-model-invocation: true
---

Realizuj **jeden spójny, zaakceptowany task** opisany w: $ARGUMENTS.
Jesteś orkiestratorem pojedynczego taska, nie całego projektu. Implementację deleguj agentowi `developer`.
Stosuj `CLAUDE.md` (także lokalne pliki aplikacji). Nie uruchamiaj implementacyjnych workflow Superpowers równolegle z tym skillem.

## Granice odpowiedzialności

- **Wejście:** ID taska, specyfikacja/kryteria akceptacji, zakres, zależności, bazowy SHA, ewentualnie istniejący PR, zgłoszenie poprawki, plik stanu i odpowiedzi na blokery. Przy ręcznym uruchomieniu odczytaj brakujące rutynowe dane z repo lub issue.
- **Wyjście:** branch/PR, HEAD SHA, wyniki lokalnych testów i CI, wynik review, status, stan taska do wznowienia oraz informacje dla integracji.
- **Nie robisz:** podziału epika na taski, koordynacji innych `/delivery`, integracji wielu PR, globalnego QA/E2E, globalnego Architecture Review, merge ani deploymentu. To zadania nadrzędnego orkiestratora i etapu integracji.
- **Tryb poprawki:** na zlecenie integracji wróć do **istniejącego** taska/PR i usuń udokumentowany problem; nie twórz drugiego PR bez potrzeby. Gdy błąd dotyczy kilku tasków, eskaluj do nadrzędnego orkiestratora.

## Decyzje i blokery

- Rutynowe decyzje techniczne w granicach specyfikacji i `CLAUDE.md` podejmuj samodzielnie: struktura kodu, nazwy, testy, wybór między równoważnymi rozwiązaniami, użycie bibliotek już obecnych w projekcie. Istotne z nich opisz w PR.
- Nie zadawaj pytań. Decyzja spoza tych granic oznacza `BLOCKED` z ustrukturyzowanym wpisem w `blockers`:
  - `business`: zakres, wymagania, zachowanie niejednoznaczne w specyfikacji;
  - `architecture`: granice modułów, kontrakt API spoza specyfikacji, nowa zależność, odstępstwo od `CLAUDE.md`;
  - `cost`: nowa usługa lub infrastruktura, płatne API, wyraźny wzrost kosztów utrzymania;
  - `technical`: awaria środowiska, niezgodny stan taska, dwie nieskuteczne rundy poprawek.
- Każdy wpis zawiera pytanie, opcje ze skutkami, rekomendację z uzasadnieniem i dowody. Przed zwróceniem `BLOCKED` dokończ pracę, której decyzja nie zmieni.
- Gdy `CLAUDE.md` każe zapytać, w tym skillu oznacza to `BLOCKED`.
- Po wznowieniu z odpowiedzią zastosuj decyzję, opisz ją w PR i usuń rozstrzygnięty wpis z `blockers`.

## Proces

1. **Preflight.** Przy wznowieniu najpierw odtwórz stan (sekcja „Stan taska”). Sprawdź status Git, bazowy SHA, specyfikację, zależności, `CLAUDE.md`, odpowiedni kod i testy. Nie zgaduj faktów dostępnych w repo. Jeśli zadanie jest zbyt szerokie lub kontrakty zależności są nieustalone, zwróć `BLOCKED` z propozycją podziału zamiast samodzielnie rozbijać epik.
2. **Izolacja.** Uruchamiaj ten workflow **z katalogu taskowego worktree** przygotowanego przez nadrzędnego orkiestratora lub użytkownika. Zweryfikuj branch, bazowy SHA, aktualny HEAD i brak cudzych zmian. Jeśli sesja działa na main lub w obcym checkoutcie, przygotuj instrukcję utworzenia taskowego worktree i zwróć `BLOCKED` zamiast delegować developera w niewłaściwym katalogu. Nie twórz worktree wewnątrz worktree.
3. **Ryzyko.** Ustal `FAST` (lokalna, niskiego ryzyka zmiana), `STANDARD` (funkcjonalność/API/integracja) lub `CRITICAL` (auth, pieniądze, migracje danych, transakcje, współbieżność, bezpieczeństwo). Nie klasyfikuj na podstawie liczby plików. Przy krytycznym, nierozstrzygniętym problemie architektonicznym zwróć `BLOCKED` (`architecture`) **przed kodowaniem**.
4. **Developer.** Deleguj całość taska jednemu agentowi `developer`; przekaż mu spec, kryteria, zakres, bazowy SHA i checkout. Domyślnie Sonnet High; dla CRITICAL możesz wybrać Opus z odpowiednim effort przez parametry uruchomienia subagenta, jeśli są dostępne. Nie uruchamiaj developera do każdego drobnego kroku. Wznawiaj tę samą sesję agenta, jeśli środowisko na to pozwala. Developer stosuje TDD dla zachowania, implementuje etapami, testuje i commituje spójne zmiany. Nie inicjuje własnych subagentów.
5. **Lokalna kontrola.** Zweryfikuj kryteria akceptacji, rzeczywiste wyniki celowanych testów oraz właściwe quality gates. Nie deklaruj PASS bez uruchomienia. Sprawdź w raporcie developera RED → GREEN dla każdego testu zachowania; brak dowodu albo RED wywołany błędem środowiska odeślij developerowi (to nie jest runda poprawek). Jeśli zadanie wymaga testu integracyjnego, wykonaj go na izolowanym środowisku taska; nie czekaj z oczywistymi błędami na globalną integrację.
6. **Code Review.** `FAST`: własna kontrola diff i testów. `STANDARD` i `CRITICAL`: uruchom raz `code-reviewer` na zamrożonym HEAD SHA i diff od bazy. Reviewer nie pisze kodu. Zgłoszenia traktuj jako hipotezy do sprawdzenia; popraw tylko potwierdzone błędy lub istotne braki. Architecture Review i adversarial QA całej funkcjonalności wykonuje etap integracji. Dodatkowe QA taska uruchamiaj tylko na jawne zlecenie lub gdy krytyczne ryzyko wymaga testu przed integracją.
7. **Poprawki FULL → DELTA.** Pierwsze review jest pełne, kolejne obejmuje zmiany i ich skutki. Maksymalnie **dwie rundy poprawek** po pierwszym ukończonym przebiegu implementacji (łącznie feedback z review, CI i integracji). Runda to paczka poprawek + ponowna weryfikacja; zwykłe TDD nie liczy się. Licznik zapisuj w stanie taska. Po dwóch nieskutecznych rundach zwróć `BLOCKED` z wpisem `technical`; nie zapętlaj agentów.
8. **PR i CI.** Po przejściu lokalnych bramek wypchnij branch i utwórz lub uaktualnij PR z linią `Rundy poprawek: N/2` w opisie. Sprawdź rzeczywisty status CI. Gdy status jest jeszcze nieznany, zwróć `CI_PENDING`; gdy CI jest czerwone, popraw w ramach limitu lub zgłoś bloker. Nigdy nie merguj PR. Nie oznaczaj taska jako gotowego do integracji bez wymaganych zielonych kontroli.
9. **Handoff.** Zwróć krótki opis i maszynowo czytelny wynik. Zaznacz kontrakty/API/migracje istotne dla innych tasków, zakres testów i otwarte ryzyka. Nie odpalaj globalnego QA ani pełnej integracji samodzielnie.

## Stan taska

- Stan taska to JSON z „Kontraktu wyniku”. Musi wystarczyć innemu orkiestratorowi do wznowienia pracy.
- Plik stanu leży **poza repo**: pod ścieżką od orkiestratora, a gdy jej nie podano, domyślnie w `~/.local/state/claude-delivery/<repo>/<task_id>.json`. `<repo>` to `basename "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"` (wspólne dla wszystkich worktree). W `<task_id>` znaki spoza `A-Za-z0-9._-` zamień na `-`; przy braku ID użyj nazwy gałęzi.
- Przy starcie wczytaj plik stanu, jeśli istnieje, i kontynuuj od zapisanych `phase`, `fix_rounds_used`, PR i SHA. Zapisuj go od preflightu atomowo (plik tymczasowy i `mv`) po każdej zmianie fazy, HEAD SHA, PR, licznika rund lub blokerów.
- Przed kontynuacją porównaj stan z repo i PR. Gdy HEAD różni się od `head_sha`, ustal przyczynę w historii Git: rebase lub znane commity zapisz w stanie, niewyjaśnione zmiany zwróć jako `BLOCKED` (`technical`).
- Gdy pliku stanu brak, a gałąź lub PR już istnieją, odtwórz stan z nich i z linii `Rundy poprawek: N/2` w opisie PR, po czym zapisz plik.
- Nie commituj pliku stanu ani raportów uruchomienia.

## Kontrakt wyniku

Na końcu zwróć **poprawny JSON** (bez wymyślonych danych). Ten sam format służy jako stan taska:

```json
{
  "schema_version": 1,
  "task_id": "...",
  "status": "IN_PROGRESS | READY_FOR_INTEGRATION | CI_PENDING | BLOCKED | FAILED",
  "phase": "preflight | implementation | local_checks | review | fixes | pr_ci | handoff",
  "risk": "FAST | STANDARD | CRITICAL",
  "worktree": "...",
  "state_file": "...",
  "base_sha": "...",
  "head_sha": "...",
  "branch": "...",
  "pr_url": null,
  "fix_rounds_used": 0,
  "checks": [{"name": "...", "status": "passed | failed | pending | skipped"}],
  "review": {"status": "passed | findings | skipped", "mode": "FULL | DELTA", "sha": "..."},
  "blockers": [{"id": "B1", "type": "business | architecture | cost | technical", "question": "...", "options": [{"option": "...", "consequences": "..."}], "recommendation": "...", "evidence": "..."}],
  "integration_notes": [],
  "changed_contracts": []
}
```

Wartości statusów wybieraj pojedynczo; `null` oznacza brak danych, `[]` brak wpisów. `IN_PROGRESS` występuje tylko w zapisach pośrednich, a `phase` wskazuje fazę, od której należy wznowić pracę.

Status `READY_FOR_INTEGRATION` oznacza **gotowy PR pojedynczego taska**, a nie gotowość całej funkcjonalności do merge lub produkcji. Pisz zwięźle po polsku.
