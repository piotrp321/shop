# Agent tester i agent UX

Krok 9 planu [Agentic Software House](../agentic-software-house/plan.md): testerów i projektantów UX zastępują agenci.
Agent tester sprawdza sklep po merge'u epiku, agent UX ocenia użyteczność i dostępność po merge'u oraz jako trzeci
reviewer w `/integration`. Zgłoszenia agentów trafiają do backlogu dopiero po triage użytkownika.

## Cel i kryteria sukcesu

- Po merge'u epiku `/sweep [epik]` (uruchomiony przez `/ao-run` albo ręcznie) daje raport z co najwyżej pięcioma
  propozycjami zgłoszeń. Triage zajmuje jedną odpowiedź w sesji.
- Każda propozycja jest odtworzonym problemem z kompletem pól formularza `bug` albo `ux`, więc nigdy nie dostaje
  `needs-info`. Hipotez agenci nie zgłaszają jako błędów.
- `/integration` przy epiku zmieniającym `apps/store` lub `apps/admin` dostaje ocenę UX zmienionych ekranów na kodzie
  epiku, zanim powstanie końcowy PR.
- Po merge'u epiku `/sweep <epik>` uruchamia sesja `/ao-run` (krok 12 planu, sekcja „Przegląd po merge'u”
  w `docs/features/ao-run/spec.md`). Użytkownik może go też uruchomić ręcznie. Triage zawsze robi użytkownik.

## Dwa zakresy

| | `/sweep` po merge'u | agent UX w `/integration` |
|---|---|---|
| Kod | świeży stos z `origin/main` po merge'u epiku | stos AO z worktree epiku na przeglądanym HEAD gałęzi `epic/<epik>` |
| Kto | agent tester, potem agent UX | tylko agent UX |
| Co | pełne ścieżki użytkownika po integracji i nowa funkcja | ekrany i zachowania, które zmienia diff epiku |
| Wynik | propozycje zgłoszeń do triage | znaleziska i uwagi w raporcie `/integration` |

## Narzędzie: Playwright z obrazu `e2e`

Agenci nie używają przeglądarki aplikacji Claude ani serwera MCP. Piszą jednorazowe skrypty Playwright poza repo i
uruchamiają je w kontenerze `e2e` nowym celem `make e2e-agent`. Działa to tak samo w sesji interaktywnej i w
`/integration`, które AO uruchamia bez sesji.

- **`make e2e-agent AGENT_DIR=<ścieżka bezwzględna>`**: montuje katalog agenta jako `/app/agent` (dzięki temu skrypty
  widzą `node_modules` obrazu i `support/`) i uruchamia Playwright z konfiguracją `e2e/playwright.agent.config.ts`.
  Testy czyta z `<AGENT_DIR>/tests`, wyniki (zrzuty ekranu, trace, raport JSON) zapisuje w `<AGENT_DIR>/results`.
  Pusty `AGENT_DIR`, ścieżka względna albo nieistniejący katalog kończą cel błędem przed startem kontenera.
  Niedziałający stos kończy go błędem z `e2e/entrypoint.sh` („Start the stack first”). Flagi `E2E_FLAGS` działają jak
  w `make e2e`.
- **Konfiguracja agentów:** dwa projekty, `desktop` (Desktop Chrome) i `mobile` (Chromium z emulacją telefonu, 375 × 812 px, dotyk), zrzut ekranu
  i trace przy każdym teście, `workers: 1`, raport `list` i JSON. Testy wybierają projekt przez `--project`
  (`make e2e-agent ... E2E_ARGS="--project mobile"`).
- **Pomocnik `e2e/support/agent.ts`** (wspólny dla obu agentów, sprawdzany przez `make e2e-check`):
  - fixture `test`, który dla każdej strony zbiera błędy konsoli, `pageerror`, nieudane żądania (`requestfailed`)
    i odpowiedzi API ze statusem ≥ 500 oraz dołącza je do wyniku testu;
  - `checkA11y(page, name)`: axe-core (`@axe-core/playwright`, reguły WCAG 2.1 A i AA), wynik dołączony do testu;
  - `snap(page, name)`: zrzut całej strony dołączony do testu.
- **Nowa zależność:** `@axe-core/playwright` jako zależność dev w `e2e/package.json` (licencja MPL-2.0), zatwierdzona
  przez użytkownika. Wersja przypięta dokładnie, jak pozostałe.
- Logowanie: istniejące `e2e/support/staff-login.ts`, `totp.ts` i `accounts.ts`. Obowiązują zasady z `/integration`:
  jedno logowanie na konto, bez celowo błędnych logowań, przy nieudanym logowaniu bez ponawiania.
- Dane: agenci zmieniają tylko to, co odtwarza `make seed` (koszyki, ceny, stany), nie zakładają kont poza rejestracją
  przez formularz sklepu, nie zmieniają Keycloaka ani konfiguracji usług.

## Agent tester (`.claude/agents/tester.md`)

Model Sonnet, wysiłek wysoki, narzędzia: Read, Grep, Glob, Bash, Write (tylko w swoim `AGENT_DIR`). Nie zmienia repo.

1. **Krytyczne ścieżki** z sekcji „Testy” w `docs/vision.md`, w zakresie, w jakim już istnieją w kodzie. Krok, którego
   jeszcze nie ma (dziś: wyszukiwarka, strona produktu, checkout, płatność, historia zamówień), tester pomija
   i wpisuje do raportu jako „jeszcze nie ma”. To nie jest błąd.
2. **Nowa funkcja:** każde kryterium akceptacji ze specyfikacji epiku (`docs/features/<epik>/spec.md`), które da się
   sprawdzić przez sklep, panel albo publiczne API.
3. **Role:** gość, klient demo (`e2e/support/accounts.ts`) i pracownik w rolach, których dotyczy epik, plus jedna
   rola bez uprawnień do funkcji epiku (dostęp ma być zablokowany w API, nie tylko ukryty w UI).
4. **Eksploracja:** po punktach 1–3 do 8 scenariuszy przypadków brzegowych w obrębie funkcji epiku (np. wartości
   graniczne, podwójne kliknięcie, dwie karty, powrót przyciskiem wstecz, nieaktualny widok). Bez epiku nie eksploruje.
   Nie przechodzi pozostałych części sklepu.
5. W każdym scenariuszu sprawdza też błędy JS, nieudane żądania i odpowiedzi 5xx zebrane przez fixture z
   `e2e/support/agent.ts`.

## Agent UX (`.claude/agents/ux-reviewer.md`)

Model Sonnet, wysiłek wysoki, narzędzia jak tester. Do czasu makiet z punktu 3 „Zakresu MVP” ocenia wyłącznie
użyteczność i dostępność, nie zgodność z projektem wizualnym, paletą ani typografią.

- **Ekrany:** w `/sweep` ekrany ścieżek i nowej funkcji z zakresu testera, w `/integration` ekrany i stany, które
  zmienia diff epiku (także stany błędu, pusty stan i ładowanie). Sklep na desktopie i na telefonie, panel tylko na
  desktopie.
- **Obowiązkowo ogląda zrzuty ekranu** z `snap` (czyta pliki PNG) dla każdego ekranu i każdego rozmiaru. Drzewo
  dostępności (`ariaSnapshot`) i axe uzupełniają tę ocenę, nie zastępują jej.
- **Sprawdza:** czy zadanie da się wykonać i czy ekran nie myli (etykiety, komunikaty błędów, potwierdzenia, stan
  ładowania), obsługę klawiaturą i widoczny fokus, nazwy dostępne i role, kontrast, układ na telefonie (nic nie wystaje,
  cele dotyku nie są za małe, brak poziomego przewijania strony), wynik axe.
- **Ocena dostępności według rzeczywistego wpływu:** naruszenie axe jest dowodem, nie wyrokiem. Agent potwierdza wpływ
  (np. elementu nie da się obsłużyć klawiaturą, czytnik ekranu nie dostaje nazwy przycisku, tekst potrzebny do zadania
  jest nieczytelny) albo opisuje go jako niski.

## Zasady zgłoszeń (obaj agenci)

- **Tylko odtworzone problemy.** Propozycja wymaga odtworzenia skryptem co najmniej dwa razy, z czego raz od czystego
  stanu (nowy kontekst przeglądarki). Pole „Odtworzono: tak (2/2)” jest częścią propozycji. Problem odtworzony raz albo
  wcale trafia do sekcji raportu „Hipotezy” i nigdy nie jest propozycją.
- **Pola propozycji** to pola formularza (`.github/ISSUE_TEMPLATE/bug.yml` albo `ux.yml`): środowisko (commit,
  przeglądarka i rozmiar, konto i rola), kroki odtworzenia (jeden krok w linii), wynik oczekiwany i faktyczny,
  kryterium akceptacji (najlepiej jako test), niewiadome. Do tego dowód i odtworzenie:
  - dowód tekstowy w treści zgłoszenia: wpis konsoli, nieudane żądanie z kodem, fragment `ariaSnapshot`, reguła axe
    z selektorem, fragment skryptu odtwarzającego;
  - zrzut ekranu i trace zostają w katalogu przebiegu (ścieżka w raporcie), bo `gh issue create` nie przesyła plików.
- **Rodzaj:** `bug`, gdy działanie jest niezgodne ze specyfikacją albo z oczekiwaniem (także 5xx i błąd JS), `ux`, gdy
  działa, ale jest trudne w użyciu albo niedostępne.
- **Waga** (do kolejności i limitu): `Critical` utrata danych albo luka bezpieczeństwa, `Important` zadanie nie do
  wykonania albo mylące w realnym scenariuszu, `Minor` reszta.
- **Duplikaty:** przed propozycją agent porównuje ją z otwartymi issues (`gh issue list --state open`, wszystkie
  etykiety) i z plikiem odrzuconych propozycji. Duplikat otwartego issue trafia do raportu jako „już zgłoszone: #N”.

## `/sweep [epik]` (`.claude/skills/sweep/SKILL.md`)

Sesja interaktywna użytkownika w aplikacji desktop: ręcznie albo z `/ao-run` po merge'u epiku, który wykonuje
kroki 1–6 tego skilla i zostawia triage użytkownikowi. `epik` to katalog w `docs/features/`; bez niego tester przechodzi
tylko krytyczne ścieżki, a UX ich ekrany.

1. **Warunki startu:** działa Docker i `gh`, `git fetch origin main`, przy podanym epiku `docs/features/<epik>/spec.md`
   jest na `origin/main`. Niespełniony warunek: odmowa z powodem, nic nie startuje.
2. **Stos:** osobny worktree `git worktree add --detach` z `origin/main` w katalogu przebiegu
   `~/.local/state/shop-sweep/<data>-<sha7>[-<epik>]/` i projekt Compose `sweep-<sha7>[-<epik>]` (z epikiem, żeby przeglądy dwóch epików na
   tym samym commicie się nie wykluczały), porty od `AO_PORT_BASE` (domyślnie
   19100), uruchomiony przez `.ao/setup` z worktree. Błąd setupu: raport z końcówką wyjścia, sprzątanie, koniec.
3. **Agenci po kolei:** tester, potem UX (te same konta demo i kody TOTP, więc nie równolegle). Każdy dostaje adresy
   stosu, commit, ścieżkę specyfikacji epiku, swój `AGENT_DIR` w katalogu przebiegu, listę otwartych issues
   i odrzuconych propozycji. Zwraca raport i propozycje.
4. **Selekcja:** skill scala duplikaty między agentami, ponownie sprawdza duplikaty z otwartymi issues i odrzuconymi,
   układa propozycje według wagi i zostawia **co najwyżej pięć na przebieg, łącznie**. Nadmiar trafia do raportu po
   jednej linii.
5. **Raport** w katalogu przebiegu (`report.md`, poza repo): commit, zakres, wynik każdej ścieżki i kryterium
   (OK, błąd, jeszcze nie ma), propozycje z pełnymi polami, hipotezy, nadmiar ponad limit, duplikaty, ścieżki dowodów.
   Skrót w rozmowie i powiadomienie push „Przegląd po merge'u <sha7><-epik>: N propozycji”.
6. **Sprzątanie, zawsze, także po błędzie:** `.ao/teardown` z tym samym projektem i `git worktree remove`. Raport
   i dowody zostają.
7. **Triage:** użytkownik odpowiada w sesji, np. „załóż 1 i 3, odrzuć 2: celowe”. Skill zakłada wybrane przez
   `gh issue create` z etykietami `bug` albo `ux` i `agent`, z sekcjami formularza, dowodem i sekcją „Źródło” (przebieg
   `/sweep`, commit, agent). Odrzucone dopisuje do pliku odrzuconych. Propozycja bez decyzji przepada i może wrócić
   w następnym przebiegu. Bez odpowiedzi użytkownika skill niczego nie zakłada.

### Odrzucone propozycje

Plik `~/.local/state/shop-sweep/rejected.md`, jeden wpis na odrzuconą propozycję: data, tytuł, rodzaj, ekran lub
endpoint, pliki kodu, których dotyczy, commit z przebiegu, powód od użytkownika (albo „bez powodu”). Agent nie zgłasza
ponownie odrzuconego problemu, chyba że od zapisanego commitu zmieniły się pliki z wpisu
(`git log <commit>..HEAD -- <pliki>`) i ma nowy dowód. Wtedy propozycja ma dopisek „wcześniej odrzucone <data>:
<powód>” i opis, co się zmieniło.

## Agent UX w `/integration`

Zmiana w `.claude/skills/integration/SKILL.md`, bez zmian w AO:

- Uruchamiany, gdy `git diff --name-only <zakres>` z kroku 2 dotyka `apps/store/` albo `apps/admin/`. Działa po
  zakończeniu `qa-adversarial` (te same konta TOTP), przed bramkami, w worktree epiku, przeciw stosowi AO z `.env`
  tego worktree. Wyniki pisze tylko do katalogu poza repo obok `<report_path>`, więc `git status --porcelain`
  się nie zmienia.
- Dostaje HEAD, zakres (FULL albo DELTA) i listę zmienionych plików frontów, bez wniosków innych reviewerów. W DELTA
  dostaje też swoje znaleziska z poprzedniego raportu do sprawdzenia.
- **Waga w `/integration`:** `Important` tylko dla problemu odtworzonego (zasady zgłoszeń), na ekranie lub w zachowaniu
  zmienionym przez epik, który uniemożliwia albo myli wykonanie zadania, także osobie korzystającej z klawiatury lub
  czytnika ekranu. Pozostałe problemy na zmienionych ekranach to `Minor`. Problemy na niezmienionych ekranach i
  hipotezy to uwagi w raporcie, nie znaleziska. Weryfikacja z kroku 5 `/integration` obowiązuje także dla UX.
- Raport `/integration` dostaje sekcję wyników UX, a `checks` wpis `ux-review` (`passed`, `failed` albo `skipped`, gdy
  diff nie dotyka frontów). Kontrakt wyniku i walidator się nie zmieniają: dodatkowe wpisy `checks` są dozwolone.
- Niedziałający `make e2e-agent` przy działającym stosie to uwaga w raporcie i `ux-review: skipped`, nie bloker.

## Kryteria akceptacji

1. `make e2e-agent` uruchamia skrypt z `AGENT_DIR` na projekcie `desktop` i `mobile`, zapisuje zrzuty i raport JSON
   w `<AGENT_DIR>/results`, a fixture zbiera błędy konsoli, nieudane żądania i odpowiedzi 5xx. Pokazane na stosie.
2. `make e2e-agent` bez `AGENT_DIR`, ze ścieżką względną albo nieistniejącą kończy się błędem i kodem różnym od zera
   bez startu kontenera; przy zatrzymanym stosie błędem z `entrypoint.sh`. Pokazane.
3. `make e2e-check` przechodzi z `e2e/support/agent.ts` i `@axe-core/playwright`; `make e2e` przechodzi bez zmian
   w wynikach.
4. Prawdziwy przebieg `/sweep cart-fixes` na `main`: świeży stos, raport z wynikiem każdej istniejącej ścieżki
   krytycznej i każdego kryterium specyfikacji, co najwyżej pięć propozycji z kompletnymi polami i „Odtworzono”,
   sprzątnięty stos i worktree (`docker compose ls`, `git worktree list`). Triage robi użytkownik.
5. Ścieżka błędu `/sweep`: zajęty port bazowy albo nieistniejący epik kończą się odmową bez pozostawionego stosu.
6. Agent UX uruchomiony według zasad `/integration` na stosie z kodem epiku zwraca wyniki z wagami według tej
   specyfikacji. Sprawdzone ręcznie na gałęzi z prawdziwą zmianą frontu, bez AO.

## Zmiany w dokumentacji (w PR implementacji)

- `docs/features/agentic-software-house/plan.md`: status kroku 9, stan ról „UX” i „Tester całego sklepu”.
- `docs/adr/agentic-software-house.md`: agent tester i agent UX w tabeli komponentów z `/sweep` i rolą w `/integration`.
- `CLAUDE.md`: `/sweep` w sekcji o skillach, agent UX w opisie `/integration`, cel `make e2e-agent` w środowisku.

### Kryteria akceptacji kroku 12

7. Po merge'u końcowego PR epiku sesja `/ao-run` uruchamia `/sweep <epik>` bez polecenia użytkownika, wysyła push
   z liczbą propozycji i zostawia triage w rozmowie. Pokazane na pierwszym prawdziwym merge'u po wdrożeniu.
8. „Tak” na „Zacząć epik?” w trakcie przeglądu uruchamia epik dopiero po końcu kolejki przeglądów; dwa merge'e
   po nocy dają dwa przeglądy po kolei, także gdy oba startują na tym samym commicie `main`.
9. Pominięcie z pushem i podpowiedzią `/sweep <epik>`: zasilanie z baterii, okno limitu ≥ 80% (`night_queue.py room`
   z testami) i odmowa `/sweep` (np. zajęty port). Sprawdzone testami `room` i ręcznie dla baterii albo portu.

## Ryzyka i ograniczenia

- Ocena UX i eksploracja zależą od modelu. Wymóg odtworzenia i limit pięciu propozycji ograniczają szum, nie trafność.
- Koszt przebiegu `/sweep` jest zmierzony: `/sweep cart-fixes` trwał około 15 min, tester zużył około 111 tys.,
  a UX około 142 tys. tokenów (Sonnet).
- Agent UX wydłuża nocne `/integration` epików z frontem (działa po QA).
- Zrzutów ekranu nie ma w issue; zostają lokalnie w katalogu przebiegu.
- Przegląd uruchamiany po każdym merge'u zużywa limit (ostatnie przebiegi: 250–540 tys. tokenów Sonnet, 15–60 min)
  i opóźnia start następnego epiku o czas przeglądu. Pominięcie przy limicie ≥ 80% chroni limit na następny epik.
