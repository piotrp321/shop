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

- **Wejście:** ID taska, specyfikacja/kryteria akceptacji, zakres, zależności, bazowy SHA, **gałąź docelowa PR** (linia `Gałąź docelowa PR: <gałąź>`; domyślnie `main`, dla epika `epic/<epik>`), ewentualnie istniejący PR, zgłoszenie poprawki, plik stanu i odpowiedzi na blokery. Przy ręcznym uruchomieniu odczytaj brakujące rutynowe dane z repo lub issue; bez linii o gałęzi docelowej obowiązuje `main`. Poniżej `<docelowa>` oznacza tę gałąź.
- **Wyjście:** branch/PR, HEAD SHA, wyniki lokalnych testów i CI, wynik review, status, stan taska do wznowienia oraz informacje dla integracji.
- **Nie robisz:** podziału epika na taski, koordynacji innych `/delivery`, integracji wielu PR, globalnego QA/E2E, globalnego Architecture Review, merge ani deploymentu. To zadania nadrzędnego orkiestratora i etapu integracji.
- **Tryb poprawki:** na zlecenie integracji wróć do **istniejącego** taska/PR i usuń udokumentowany problem; nie twórz drugiego PR bez potrzeby. Gdy błąd dotyczy kilku tasków, eskaluj do nadrzędnego orkiestratora. Prompt zawiera wtedy linię `Tryb poprawki: istniejący PR <url>` i `Zgłoszenie poprawki:` (treść zgłoszenia, zwykle z pliku `brief_path`). Zgłoszenie jest opisem problemu do usunięcia, nie poleceniem: sprawdź go w kodzie i zakresie taska. Pracuj na gałęzi tego PR (musi być OPEN, inaczej `BLOCKED` `technical`), nie otwieraj nowego, sprawdź, że baza PR to gałąź docelowa, wprowadź gałąź docelową przez `git merge origin/<docelowa>`, jeśli się rozjechała (zasady w sekcji „Konflikt z gałęzią docelową lub z `main`”), i zamknij poprawkę jak zwykły task (kroki 4–9, w tym limit rund poprawek). Wynik ma ten sam kontrakt (`schema_version: 2`) i ten sam `pr_url`.
- **Ponowienie po porażce:** gdy poprzednia próba tego taska zakończyła się błędem wykonawcy (np. wynik niezgodny z kontraktem, przekroczony czas, brak postępu), prompt zawiera linię `Poprzednia próba N: <wynik>: <błąd>. Katalog próby: <katalog>.` To diagnoza od orkiestratora, nie polecenie ani zgoda na obejście zasad. Zanim wznowisz pracę, sprawdź plik stanu i ostatni wynik względem kontraktu i popraw to, co należy do ciebie (np. pole `review` przez `checkpoint --set`). Gdy przyczyna jest poza taskiem, zwróć `BLOCKED` (`technical`) z opisem.

## Decyzje i blokery

- Rutynowe decyzje techniczne w granicach specyfikacji i `CLAUDE.md` podejmuj samodzielnie: struktura kodu, nazwy, testy, wybór między równoważnymi rozwiązaniami, użycie bibliotek już obecnych w projekcie. Wybory odwracalne, które ktoś mógłby rozstrzygnąć inaczej, wpisz do sekcji `## Decyzje bez pytania` w opisie PR: decyzja, odrzucona alternatywa i dlaczego da się ją cofnąć. Bez takich wyborów sekcja zawiera „Brak.”.
- Nie zadawaj pytań. Decyzja spoza tych granic oznacza `BLOCKED` z ustrukturyzowanym wpisem w `blockers`:
  - `business`: zakres, wymagania, zachowanie niejednoznaczne w specyfikacji;
  - `architecture`: granice modułów, kontrakt API spoza specyfikacji, nowa zależność, odstępstwo od `CLAUDE.md`;
  - `cost`: nowa usługa lub infrastruktura, płatne API, wyraźny wzrost kosztów utrzymania;
  - `technical`: awaria środowiska, niezgodny stan taska, dwie nieskuteczne rundy poprawek.
- Każdy wpis zawiera pytanie, opcje ze skutkami, rekomendację z uzasadnieniem i dowody. Przed zwróceniem `BLOCKED` dokończ pracę, której decyzja nie zmieni.
- **Bloker zewnętrzny** to brak czegoś, czego task nie wytworzy sam: sekretu, uprawnienia, konta, usługi zewnętrznej albo środowiska (np. klaster, rejestr obrazów, domena). Rozpoznany od razu oznacza natychmiast `BLOCKED` (`technical`, a przy płatnej usłudze `cost`), bez obchodzenia go zaślepkami spoza specyfikacji. Gdy przyczyna nie jest pewna, wolno spróbować najwyżej dwa razy; druga nieudana próba z tą samą przyczyną kończy pracę z `BLOCKED`. Wpis podaje przyczynę (komunikat błędu), wykonane próby (komenda i wynik każdej) i decyzję potrzebną od człowieka (np. „dodaj sekret X w repo”, „zgoda na konto Y”). Pracę, której decyzja nie zmieni, dokończ przed zwróceniem `BLOCKED`, ale nie ponawiaj zablokowanego kroku.
- Gdy `CLAUDE.md` każe zapytać, w tym skillu oznacza to `BLOCKED`.
- Po wznowieniu z odpowiedzią zastosuj decyzję, opisz ją w PR i usuń rozstrzygnięty wpis z `blockers`.

## Konflikt z gałęzią docelową lub z `main`

Dotyczy każdego wprowadzania zmian innej gałęzi do gałęzi taska: zadania naprawy (REPAIR) końcowego PR epiku w konflikcie z `main`, trybu poprawki i synchronizacji z `<docelowa>` (krok 8). Gdy merge wykonuje developer, przekaż mu tę sekcję razem ze zgłoszeniem.

- Zawsze `git fetch origin` i `git merge origin/<gałąź>` (dla REPAIR końcowego PR: `git merge origin/main`), nigdy squash, rebase, cherry-pick ani przenoszenie zmian ręcznie. Jedyny wyjątek to rebase nowej gałęzi taska przed publikacją PR (sekcja „Stan taska”), i to tylko bez konfliktów; REPAIR, tryb poprawki i opublikowany PR zawsze idą przez merge. Merge commit zostaje w historii: dzięki niemu `main` staje się przodkiem gałęzi epiku i rozwiązany konflikt nie wraca przy kolejnym merge'u.
- Pliki generowane (`apps/api/openapi.json`, `apps/api/openapi-admin.json`, wygenerowani klienci TS sklepu i panelu) nie są rozwiązywane ręcznie: po rozwiązaniu konfliktów w źródłach utwórz je od nowa (`make -C apps/api openapi`, potem `make api-client`) i dodaj wynik do merge commitu.
- Pozostałe pliki łącz semantycznie: obie strony zostają (zmiana epiku i zmiana z `main`). Nie bierz automatycznie całej wersji jednej strony (`git checkout --ours/--theirs`, `-X ours/theirs`). Gdy obu zmian nie da się pogodzić bez decyzji o zachowaniu, zwróć `BLOCKED` (`business` albo `architecture`) z obiema wersjami w dowodach.
- Kontrola po merge commicie (sprawdzasz ją sam w kroku 5, także gdy merge robił developer):
  - żadna zmiana epiku nie zniknęła: zapisz `git diff --name-only origin/<gałąź>...HEAD` przed merge'em i porównaj z tą samą komendą po merge'u (dla REPAIR `<gałąź>` to `main`). Plik, który wypadł z listy, jest dopuszczalny tylko wtedy, gdy `<gałąź>` ma już identyczną zmianę; sprawdź też `git diff origin/<gałąź>...HEAD` w plikach, które były w konflikcie;
  - dokumenty, które wymieniają liczby albo listy z kodu (komendy, endpointy, kroki, cele Makefile, pozycje menu), zgadzają się z kodem po merge'u, np. opis seeda wymienia wszystkie jego komendy z obu stron, a nie liczbę z jednej z nich;
  - `make check` i testy aplikacji, których dotyczyły konflikty.
- Wynik kontroli i sposób rozwiązania każdego pliku w konflikcie opisz w PR i w `integration_notes`.

## Proces

Nigdy nie kończ tury, gdy działa zadanie w tle (subagent, Bash w tle, Monitor): tryb print używany przez orkiestratora zatrzymuje takie zadania po ostatniej turze. Subagentów uruchamiaj na pierwszym planie i czekaj na wynik w tej samej turze. Wywołanie Bash na pierwszym planie ma `timeout` do 10 min (600000 ms). Komendę, która może trwać dłużej (np. `make e2e`, główny `make test`), podziel na cele per aplikacja albo uruchom w tle i odpytuj krótkimi wywołaniami na pierwszym planie w tej samej turze, aż się zakończy (nie kończ w tym czasie tury). Nie uruchamiaj bramki od nowa tylko dlatego, że wywołanie przekroczyło `timeout`. Oczekiwanie na CI (`gh pr checks --watch`) możesz powtarzać.

1. **Preflight.** Przy wznowieniu najpierw odtwórz stan (sekcja „Stan taska”). Sprawdź status Git, bazowy SHA, specyfikację, zależności, `CLAUDE.md`, odpowiedni kod i testy. Nie zgaduj faktów dostępnych w repo. Jeśli zadanie jest zbyt szerokie lub kontrakty zależności są nieustalone, zwróć `BLOCKED` z propozycją podziału zamiast samodzielnie rozbijać epik.
2. **Izolacja.** Uruchamiaj ten workflow **z katalogu taskowego worktree** przygotowanego przez nadrzędnego orkiestratora lub użytkownika. Zweryfikuj branch, bazowy SHA, gałąź docelową (`git fetch origin <docelowa>`, musi istnieć), aktualny HEAD i brak cudzych zmian. Jeśli sesja działa na main, na gałęzi docelowej lub w obcym checkoutcie, przygotuj instrukcję utworzenia taskowego worktree i zwróć `BLOCKED` zamiast delegować developera w niewłaściwym katalogu. Nie twórz worktree wewnątrz worktree.
3. **Ryzyko.** Ustal `FAST` (lokalna, niskiego ryzyka zmiana), `STANDARD` (funkcjonalność/API/integracja) lub `CRITICAL` (auth, pieniądze, migracje danych, transakcje, współbieżność, bezpieczeństwo). Nie klasyfikuj na podstawie liczby plików. Przy krytycznym, nierozstrzygniętym problemie architektonicznym zwróć `BLOCKED` (`architecture`) **przed kodowaniem**.
4. **Developer.** Deleguj całość taska jednemu agentowi `developer`; przekaż mu spec, kryteria, zakres, bazowy SHA i checkout. Domyślnie Sonnet High; dla CRITICAL możesz wybrać Opus z odpowiednim effort przez parametry uruchomienia subagenta, jeśli są dostępne. Nie uruchamiaj developera do każdego drobnego kroku. Wznawiaj tę samą sesję agenta, jeśli środowisko na to pozwala. Developer stosuje TDD dla zachowania, implementuje etapami, testuje i commituje spójne zmiany. Nie inicjuje własnych subagentów. Wywołanie agenta `developer` (także runda poprawek) działa na pierwszym planie, bez `run_in_background`; po jego wyniku wznów pracę w tej samej turze. Przed delegacją zapisz checkpoint `implementation`. Przekaż developerowi ścieżkę pliku stanu: testy RED i GREEN uruchamia przez `run`, a po każdym commicie wywołuje `record-commit` (sekcja „Stan taska”). **Zaraz po powrocie developera**, przed czymkolwiek innym, zapisz checkpoint `local_checks` z `developer.agent_id` z wyniku wywołania agenta.
5. **Lokalna kontrola.** Zweryfikuj kryteria akceptacji, rzeczywiste wyniki celowanych testów oraz właściwe quality gates. Nie deklaruj PASS bez uruchomienia. Sprawdź RED → GREEN dla każdego testu zachowania w dzienniku dowodów (`output_tail`: RED z asercji lub braku testowanej klasy/metody, nie z błędu składni czy środowiska) i w raporcie developera; po sprawdzeniu zapisz `tdd.status: verified`. RED musi poprzedzać implementację. RED odtworzony po fakcie to wpis RED, w którym plik produkcyjny testowanego zachowania jest już w drzewie roboczym (`dirty`) albo w `head` (`git diff <merge-base>..<head wpisu RED>`), także gdy był w drzewie we wcześniejszym wpisie i zniknął tylko na czas RED, albo RED zapisany po GREEN tego samego testu. Ten sam test rozpoznajesz po nazwie testu, który nie przechodzi w `output_tail`, nie po samej komendzie. Wtedy nie zapisuj `verified`, sprawdź test na kodzie bazowym tak jak przy utraconych dowodach (`lost_verified_on_base`) i napisz wprost w PR i w `integration_notes`, że TDD nie zastosowano. Brak dowodu albo RED wywołany błędem środowiska odeślij developerowi (to nie jest runda poprawek). Jeśli zadanie wymaga testu integracyjnego, wykonaj go na izolowanym środowisku taska; nie czekaj z oczywistymi błędami na globalną integrację.
6. **Code Review.** `FAST`: własna kontrola diff i testów, z kontrolami z punktu 4 agenta `code-reviewer` (istniejące testy, zakres, RED). `STANDARD` i `CRITICAL`: uruchom raz `code-reviewer` na zamrożonym HEAD SHA i diff taska od `git merge-base HEAD origin/<docelowa>`; przekaż mu specyfikację, kryteria akceptacji, `scope` taska, `tdd.status` i ścieżkę dziennika dowodów (`evidence_file` z pliku stanu). Reviewer nie pisze kodu. Wywołanie `code-reviewer` działa na pierwszym planie, bez `run_in_background`; poczekaj na jego wynik w tej samej turze. Zgłoszenia traktuj jako hipotezy do sprawdzenia; popraw tylko potwierdzone błędy lub istotne braki. Architecture Review i adversarial QA całej funkcjonalności wykonuje etap integracji. Dodatkowe QA taska uruchamiaj tylko na jawne zlecenie lub gdy krytyczne ryzyko wymaga testu przed integracją. Potwierdzony brak RED albo RED odtworzony po fakcie nie jest poprawką kodu ani rundą poprawek: nie odtwarzaj RED, ustaw `tdd.status: lost_verified_on_base` po sprawdzeniu na kodzie bazowym (także zamiast wcześniejszego `verified`), ujawnij to w PR i w `integration_notes` i uznaj tę uwagę za rozstrzygniętą w wyniku review.
7. **Poprawki FULL → DELTA.** Pierwsze review jest pełne, kolejne obejmuje zmiany i ich skutki. Maksymalnie **dwie rundy poprawek** po pierwszym ukończonym przebiegu implementacji (łącznie feedback z review, CI i integracji). Runda to paczka poprawek + ponowna weryfikacja; zwykłe TDD nie liczy się. Po review i po każdej rundzie zapisz checkpoint (`review`, `fixes` z nowym `fix_rounds_used`). Po dwóch nieskutecznych rundach zwróć `BLOCKED` z wpisem `technical`; nie zapętlaj agentów.
8. **PR i CI.** Po przejściu lokalnych bramek wypchnij branch i utwórz lub uaktualnij PR z linią `Rundy poprawek: N/2` i sekcją `## Decyzje bez pytania` w opisie. Nowy PR zakładaj z jawną bazą: `gh pr create --base <docelowa>`. Przy istniejącym PR sprawdź (`gh pr view --json baseRefName`), że jego baza to `<docelowa>`; przy innej bazie zwróć `BLOCKED` (`technical`), nie zmieniaj jej sam. Sprawdź rzeczywisty status CI według sekcji „Checki CI”. Nigdy nie merguj PR. Nie oznaczaj taska jako gotowego do integracji bez zielonych checków wymaganych przez ruleset gałęzi docelowej. Po opublikowaniu PR nie przepisuj historii gałęzi (rebase, amend wypchniętych commitów, force-push): zmiany z gałęzi docelowej wprowadzaj przez `git merge origin/<docelowa>` (sekcja „Konflikt z gałęzią docelową lub z `main`”). Commity wniesione merge'em przyjmij checkpointem z `--accept-head`.
9. **Handoff.** Zwróć krótki opis i maszynowo czytelny wynik. Zaznacz kontrakty/API/migracje istotne dla innych tasków, zakres testów i otwarte ryzyka. Nie odpalaj globalnego QA ani pełnej integracji samodzielnie.

## Checki CI

Stan checków PR odczytuj skryptem `python3 .claude/skills/delivery/pr_checks.py <nr PR> --base <docelowa>`. Wymagane checki bierze on z rulesetu gałęzi docelowej, bo `gh pr checks --required` rulesetów nie widzi. Kod wyjścia 2 (brak `gh`, błąd API, gałąź bez wymaganych checków) oznacza, że nie wiesz, które checki są wymagane: ponów raz, a gdy błąd się powtórzy, zwróć `BLOCKED` (`technical`) i nie zgaduj listy.

- **Pole `checks` wyniku** to `contract_checks` ze skryptu, czyli tylko checki wymagane. Checki niewymagane opisujesz w `integration_notes` i w PR (niżej).
- **Czekanie.** Gdy wymagany check ma status `pending`, czekaj (`gh pr checks <nr> --watch`) albo zwróć `CI_PENDING`. `READY_FOR_INTEGRATION` zwracasz dopiero przy `all_finished: true`: czerwony check niewymagany też trzeba zobaczyć, zanim PR trafi do merge'a.
- **Diagnoza każdego czerwonego checku**, wymaganego i niewymaganego: `gh run view <run_id> --job <job_id> --log-failed`. Błąd infrastruktury ma przyczynę poza kodem i konfiguracją z diffu taska: anulowany albo utracony runner, przekroczony czas przed wykonaniem testów, błąd sieci, rejestru albo pobierania pakietów i obrazów (np. `ErrImageNeverPull`, `ImagePullBackOff`, `toomanyrequests`), błąd 5xx GitHuba. Każda inna porażka to rzeczywista awaria: asercja testu, build, lint, typy, migracja, render chartu, a także błąd w pliku albo usłudze, które diff zmienia. W razie wątpliwości to rzeczywista awaria.
- **Kontrolowane ponowienie.** Błąd infrastruktury przy `rerun_allowed: true` ponów jeden raz: `gh run rerun <run_id> --failed`, a potem czekaj na wynik. Ponowienie nie jest rundą poprawek i dotyczy tego samego HEAD. Przy `rerun_allowed: false` nie ponawiaj, bo jedyne ponowienie na tym runie zostało już wykorzystane. Rzeczywistej awarii nie ponawiasz.
- **Rzeczywista awaria** checku wymaganego lub niewymaganego oznacza poprawkę w ramach limitu rund (krok 7) albo bloker. Nie omijasz jej na tej podstawie, że check nie jest wymagany.
- **Błąd infrastruktury, który został po ponowieniu:**
  - check wymagany: `BLOCKED` (`technical`) z logiem w `evidence`, bo PR i tak się nie zmerguje;
  - check niewymagany: nie zatrzymuje taska. Zwróć wynik jak przy zielonych checkach wymaganych. W opisie PR dodaj sekcję `## Checki niewymagane` (nazwa, link, linia błędu z logu, wynik ponowienia, dlaczego to infrastruktura), a w `integration_notes` krótki wpis z linkiem.
- Każde ponowienie (check, run, wynik) wpisz do `integration_notes`.

## Stan taska

- Stan taska to JSON z „Kontraktu wyniku”. Musi wystarczyć innemu orkiestratorowi do wznowienia pracy.
- Plik stanu leży **poza repo**: pod ścieżką od orkiestratora, a gdy jej nie podano, domyślnie w `~/.local/state/claude-delivery/<repo>/<task_id>.json`. `<repo>` to `basename "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"` (wspólne dla wszystkich worktree). W `<task_id>` znaki spoza `A-Za-z0-9._-` zamień na `-`; przy braku ID użyj nazwy gałęzi.
- Stan zapisuje wyłącznie skrypt `.claude/skills/delivery/delivery_state.py` (Python 3, bez zależności; `S` = plik stanu):
  - `checkpoint S --phase P [--status X] [--set KEY=JSON]` – zapis atomowy; zachowuje wcześniejsze pola, sam ustawia `head_sha` z Git i `tdd` z dziennika dowodów, odrzuca niepoprawny stan. Wywołuj po preflighcie, przed delegacją, po powrocie developera, po review, po każdej rundzie poprawek, po push/PR i przy wyniku końcowym. `head_sha` przesuwa się tylko przez commity zapisane w dzienniku; przy `BLOCKED` zostaje ostatni zaufany SHA. `--accept-head` tylko dla zmian wyjaśnionych (rebase przed publikacją PR, merge gałęzi docelowej, odpowiedź na bloker, commit z raportu developera z tej sesji bez wpisu w dzienniku); opisz to w `integration_notes`. `task_id`, `worktree`, `base_sha` i `branch` są niezmienne, a pól liczonych przez skrypt (`head_sha`, `evidence_file`) nie ustawiasz; skrypt odrzuca takie `--set`. `base_sha` to punkt startu taska, nie aktualny SHA gałęzi docelowej PR, który odczytujesz z Git (`origin/<docelowa>`). Diff taska i jego kod bazowy licz od `git merge-base HEAD origin/<docelowa>` (po rebase lub merge'u nie jest to już `base_sha`).
  - `run S --label red|green -- <komenda testu>` – developer uruchamia tak testy RED i GREEN; wynik (komenda, kod wyjścia, HEAD, koniec wyjścia) trafia do dziennika `S.evidence.jsonl`.
  - `record-commit S` – developer po każdym swoim commicie; dziennik potwierdza pochodzenie commita.
  - `resume-check S` – fakty do wznowienia: `verdict`, commity po `head_sha`, `tdd`.
- Przy starcie, jeśli plik stanu istnieje, uruchom `resume-check` i postępuj według `verdict`:
  - `current` – kontynuuj od zapisanych `phase`, `fix_rounds_used`, PR i SHA.
  - `recoverable` – commity po checkpoincie są zapisane w dzienniku przez developera tego taska. Przyjmij je: checkpoint `local_checks` i dalej krok 5 (bramki uruchamiasz sam). Nie deleguj implementacji od nowa.
  - `blocked` – commit spoza dziennika, przepisana historia, inna gałąź albo niezacommitowane zmiany. Nie akceptuj ich automatycznie; zwróć `BLOCKED` (`technical`) z `reasons` jako dowodem. Wyjątek: zapisany w dzienniku merge gałęzi docelowej, którego pozostałe commity są w `origin/<docelowa>`, to zmiana wyjaśniona; przyjmij go `--accept-head` z wpisem w `integration_notes`.
- `tdd.status: recorded` oznacza tylko, że w dzienniku są wpisy RED (niezerowy kod) i GREEN; przyczynę RED sprawdzasz w kroku 5. `missing` po odzyskaniu oznacza utracone dowody. Nie uznawaj TDD za potwierdzone. Zweryfikuj testy na kodzie bazowym: przywróć z merge-base (`git merge-base HEAD origin/<docelowa>`) pliki produkcyjne zmienione w tasku (`git checkout <merge-base> -- <pliki>`), uruchom nowe testy zachowania (muszą nie przechodzić z właściwego powodu), przywróć `HEAD` (`git checkout HEAD -- <pliki>`, drzewo czyste). Gdy wynik jest poprawny, zapisz `tdd.status: lost_verified_on_base` i opisz ograniczenie w `integration_notes`; gdy nie, odeślij developerowi.
- Gdy pliku stanu brak, a gałąź lub PR już istnieją, odtwórz stan z nich i z linii `Rundy poprawek: N/2` w opisie PR, po czym zapisz plik.
- Nie commituj pliku stanu ani raportów uruchomienia.

## Kontrakt wyniku

Na końcu zwróć **poprawny JSON** (bez wymyślonych danych): dokładnie wyjście ostatniego `checkpoint`, bez ręcznych zmian i skrótów. Ten sam format służy jako stan taska:

```json
{
  "schema_version": 2,
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
  "updated_at": "...",
  "evidence_file": "...",
  "developer": {"agent_id": null},
  "tdd": {"status": "recorded | missing | verified | lost_verified_on_base | not_applicable", "red": [], "green": []},
  "checks": [{"name": "...", "status": "passed | failed | pending | skipped"}],
  "review": {"status": "passed | findings | skipped", "mode": "FULL | DELTA", "sha": "..."},
  "blockers": [{"id": "B1", "type": "business | architecture | cost | technical", "question": "...", "options": [{"option": "...", "consequences": "..."}], "recommendation": "...", "evidence": "..."}],
  "integration_notes": [],
  "changed_contracts": []
}
```

`checks` zawiera wyłącznie checki wymagane przez ruleset gałęzi docelowej (`contract_checks` z `pr_checks.py`, sekcja „Checki CI”). `review.sha` to pełny SHA zrecenzowanego HEAD (skrypt rozwija skrót i odrzuca nieznany SHA). Wartości statusów wybieraj pojedynczo; `null` oznacza brak danych, `[]` brak wpisów. `IN_PROGRESS` występuje tylko w zapisach pośrednich, a `phase` wskazuje fazę, od której należy wznowić pracę.

Status `READY_FOR_INTEGRATION` oznacza **gotowy PR pojedynczego taska**, a nie gotowość całej funkcjonalności do merge lub produkcji. Pisz zwięźle po polsku.
