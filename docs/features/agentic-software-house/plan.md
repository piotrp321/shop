# Plan: Agentic Software House

Docelowa architektura i zasady: [ADR](../../adr/agentic-software-house.md). Ten plan ustala role, proces pracy
i kroki wdrożenia. AO jest zamrożone po v0.3.1: zmienia się tylko wtedy, gdy prawdziwy przebieg epiku stoi,
najmniejszą poprawką i za zgodą użytkownika. Nowe role powstają jako skille i agenci w `shop`, bez kodu AO.

Cel: użytkownik poświęca około godziny dziennie na decyzje, a agenci budują sklep według „Zakresu MVP”
z [wizji](../../vision.md). Godzina dziennie to cel do zmierzenia (krok 7), nie potwierdzony fakt.

## Status

W toku: DEPLOY (#175) przez `/ao-run`. Następne epiki „Zakresu MVP”: PROMOTIONS (#96), DISCOUNT-CODES (#225)
i VISUAL-DESIGN (#97), kolejność na tablicy „Backlog sklepu”. Blokery: brak. Krok 7: CART-FIXES (#88) przeszedł cały proces, od `/prepare-epic` do odbioru (końcowy
PR #112): 30 minut użytkownika (szacunek użytkownika, nie pomiar), 0 interwencji, 0 ręcznych poprawek, 4 h przebiegu AO (5 zadań), koszt 14,45 USD
według Claude CLI.

| Krok | Zakres | Status |
|---|---|---|
| 1 | AO v0.3.1 i zamrożenie AO | zrobione |
| 2 | Ten plan, zasada backlogu w `CLAUDE.md` i ADR | zrobione |
| 3 | Poprawki procesu w `shop` | zrobione |
| 4 | Backlog w GitHub Issues | zrobione |
| 5 | Powiadomienia i kolejka epików przez aplikację Claude | zrobione (sprawdzenie w kroku 7) |
| 6 | Przygotowanie epiku jednym skillem `/prepare-epic` | zrobione |
| 7 | Pierwszy epik przez cały proces | zrobione |
| 8 | Nocna kolejka kilku epików | zrobione (sprawdzenie: pierwsza noc) |
| 9 | Agent tester i agent UX | zrobione |
| 10 | Przed publicznym demo: bezpieczeństwo i wydajność | do zrobienia |
| 11 | Po MVP: release i monitoring | do zrobienia |

## Role

Testerów i projektantów UX nie zatrudniamy: obie role pełnią agenci. Człowiekiem jest tylko właściciel produktu.

| Dział | Rola | Kto | Stan |
|---|---|---|---|
| Produkt | Właściciel produktu: co budujemy, kolejność, triage, odbiór | użytkownik | działa |
| Analiza i projekt | Analityk: specyfikacja | `/brainstorm` z użytkownikiem, w `/prepare-epic` | działa |
| | Krytyk specyfikacji: braki, sprzeczności, przypadki brzegowe | `spec-critic` w `/prepare-epic` | działa |
| | Architekt: review specyfikacji | `architecture-reviewer` w `/prepare-epic` | działa |
| | Architekt: ADR, review architektury epiku | `architecture-reviewer` w `/integration` | działa |
| | UX: ekrany, wersja mobilna, dostępność | `ux-reviewer` w `/sweep` i w `/integration` | działa |
| Planowanie | Plan i zadania | `/planner`, w `/prepare-epic` | działa |
| | Koordynacja: przebieg nocny, stan, PR | AO | działa |
| | Kolejka epików: następny epik po merge'u i lista na noc | `/ao-run` w sesji z Remote Control | działa, lista na noc do sprawdzenia w pierwszej nocy |
| Wytwarzanie | Developer: zadanie z TDD | `developer` w `/delivery` | działa |
| | Code review zadania | `code-reviewer` w `/delivery` | działa |
| Jakość | QA epiku | `qa-adversarial` w `/integration` | działa |
| | Tester całego sklepu | `tester` w `/sweep` | działa |
| | Bezpieczeństwo i wydajność | ustalane w kroku 10 | krok 10 |
| Wydanie i utrzymanie | Release na publiczne demo, rollback | k3s i Argo CD z `docs/vision.md` | krok 11 |
| | Monitoring → issues | Sentry i Grafana Cloud z `docs/vision.md` | krok 11 |

## Proces

Stały proces każdego epiku. Elementy, które jeszcze nie działają, mają w nawiasie numer kroku wdrożenia.

```mermaid
flowchart TB
    issues[GitHub Issues<br/>epic, bug, ux, tech-debt]
    triage{Triage i kolejność<br/>użytkownik}
    prep["/prepare-epic<br/>analityk, krytyk, architekt, planner<br/>epik albo epik poprawek"]
    accept{"Akceptacja specyfikacji<br/>końcowy PR do przejrzenia w jednym posiedzeniu?"}
    onmain[main: spec.md i plan.md]
    start{"Zacząć epik? albo lista na noc<br/>push w aplikacji Claude"}
    subgraph night[AO w nocy]
        delivery["/delivery × N"] --> integration["/integration<br/>architektura, QA, UX"] --> finalpr[Końcowy PR do main]
    end
    blocker[Bloker: push w aplikacji Claude i ao answer]
    review{Odbiór według ryzyka<br/>użytkownik}
    merge[Merge i zamknięcie issue]
    sweep[Agent tester i agent UX<br/>cały sklep na main]

    issues --> triage --> prep --> accept
    accept -- za duży, podział --> prep
    accept -- tak --> onmain --> start
    start -- tak --> delivery
    delivery -. bloker .-> blocker -. odpowiedź .-> delivery
    finalpr --> review
    review -- błąd w zakresie epiku: PR do gałęzi epiku --> integration
    review -- odebrany --> merge --> sweep
    merge -. następny przygotowany epik .-> start
    finalpr -. epic_done: następny epik listy na noc .-> start
    review -. uwagi i problemy spoza zakresu .-> issues
    sweep -. zgłoszenia z etykietą agent .-> issues
```

1. **Backlog: GitHub Issues** w `piotrp321/shop`.
   - Etykieta `epic` to funkcja z „Zakresu MVP”, `bug` to błąd, `ux` to problem użyteczności albo wyglądu,
     `tech-debt` to porządki w kodzie, bazie albo dokumentacji bez zmiany zachowania.
   - Zgłaszają: użytkownik, agent tester i agent UX. Zgłoszenia agentów mają etykietę `agent` i limit
     na jeden przebieg. Do backlogu trafiają dopiero po triage użytkownika.
   - Zgłoszenia `bug`, `ux` i `tech-debt` mają pola: środowisko, kroki odtworzenia (jeden krok w linii), wynik
     oczekiwany i faktyczny, kryterium akceptacji, niewiadome. Dla `bug` i `ux` jest formularz
     w `.github/ISSUE_TEMPLATE/`, agenci wypełniają te same pola. Zgłoszenie z brakami dostaje etykietę
     `needs-info` i nie idzie do przygotowania.
   - Kolejność ustala użytkownik na tablicy GitHub Projects
     [„Backlog sklepu”](https://github.com/users/piotrp321/projects/1): epik na górze idzie pierwszy.
2. **Przygotowanie (użytkownik, w dzień).**
   - Issue z góry listy → `/prepare-epic` → `spec.md` i `plan.md`.
   - `/prepare-epic` prowadzi kolejno:
     - analityka według zasad `/brainstorm`: discovery i szkic specyfikacji;
     - krytyka i `architecture-reviewer`: sprawdzają szkic, nie użytkownika;
     - poprawki techniczne w szkicu;
     - `/planner`.
   - Decyzje produktowe (zakres, reguły, limity, zachowanie klienta) podejmuje wyłącznie użytkownik. Agenci ich
     nie rozstrzygają między sobą. Zbierają je w jedną listę pytań, każde z rekomendacją. Użytkownik dostaje
     pytania raz, przed akceptacją.
   - Akceptacja jest jedna: specyfikacja z pozycjami użytkownika z „Wymaganych przed startem” i jedno pytanie, czy
     końcowy PR da się przejrzeć w jednym posiedzeniu.
     Skill podaje do tego liczbę zadań i dotknięte aplikacje. Jeśli PR jest za duży, epik dzielimy na dwa,
     mergowane po kolei. Rozmiar pojedynczych zadań zostaje bez zmian. Podziału na zadania użytkownik nie
     akceptuje: `plan.md` musi przejść walidator manifestu.
   - Przygotowujemy tyle epików do przodu, ile mieści noc: aż `night_queue.py ahead` pokaże pełną noc (suma
     szacunków z pomiaru; bez pomiaru jeden epik do przodu). Nie przygotowujemy wszystkich epików naraz, bo
     specyfikacja opisuje kod, którego jeszcze nie ma.
   - Specyfikacja ma linię `Zakres:`: ścieżki, które epik zmienia. Z niej lista na noc sprawdza rozłączność epików.
   - Specyfikacja ma sekcję „Wymagane przed startem”: sekrety, konta, usługi zewnętrzne, uprawnienia i decyzje
     infrastrukturalne potrzebne zadaniom. Przy akceptacji użytkownik potwierdza, że jego pozycje są gotowe albo
     będą przed `/ao-run`, żeby taki bloker nie wyszedł dopiero w trakcie zadania.
   - Zgłoszenie `ux` zawsze idzie przez `/prepare-epic`. Drobne zgłoszenia `bug`
     i `tech-debt` trafiają razem do jednego epiku poprawek: krótka specyfikacja, jedno zadanie na issue.
   - Zaakceptowane `spec.md` i `plan.md` trafiają do `main` przez PR, który `/prepare-epic` merguje po zielonym CI.
3. **Wykonanie (AO, w nocy).**
   - Start epiku: sesja z Remote Control bierze następny epik z tablicy, który ma zaakceptowane `spec.md`
     i `plan.md` na `main`, i pyta w powiadomieniu push „Zacząć epik X?”. Pyta po merge'u poprzedniego epiku albo na
     prośbę użytkownika.
   - Lista na noc: przed snem `/ao-run --noc` proponuje przygotowane epiki w kolejności tablicy, o rozłącznym
     zakresie, bez zależności od otwartych epików i, przy pomiarze czasu, mieszczące się do rana. Użytkownik
     zatwierdza ją jednym „tak”. Pierwszy epik rusza po „tak”, a każdy kolejny po `epic_done` poprzedniego, bez
     czekania na merge, tylko gdy jego szacunek mieści się do rana i w limitach subskrypcji. Inaczej noc się kończy.
   - Bez zgody sesja niczego nie uruchamia. Na raz trwa jeden epik z jednej sesji. Gdy działa epik innej sesji,
     `/ao-run` przed startem opisuje pliki wspólne obu epików (CI, `CLAUDE.md`, kontrakt OpenAPI, klienci TS, menu
     panelu) jako ryzyko konfliktu, bez blokowania startu. Na zasilaniu z baterii sesja pyta, zanim uruchomi epik.
   - Sesja z `/ao-run <epik>` uruchamia `ao epic start` i `ao run --until-idle`. AO prowadzi zadania przez
     `/delivery` po jednym naraz, squash-merguje je do `epic/<epik>`, uruchamia `/integration` i otwiera końcowy
     PR `epic/<epik>` → `main` z raportem `ao report`. Czerwone CI gałęzi epiku i konflikt końcowego PR z `main`
     naprawia zadanie REPAIR (konflikt: prawdziwy `git merge origin/main`, zasady w `/delivery`), mergowane do
     `epic/<epik>` merge commitem.
   - `code-reviewer` w `/delivery` sprawdza każde zadanie, a `/integration` cały epik. Usunięty, wyłączony albo
     osłabiony istniejący test lub zmieniona wartość oczekiwana bez podstawy w specyfikacji to `BLOCKER` w zadaniu
     i `Important` w epiku. Plik albo zmiana spoza `scope` zadania to zgłoszenie. `code-reviewer` sprawdza też,
     czy RED poprzedził implementację.
   - Przy epikach, które zmieniają `apps/store` albo `apps/admin`, `/integration` uruchamia też agenta UX
     (`ux-reviewer`). Problem z zakresu epiku jest znaleziskiem do naprawy, pozostałe zostają w raporcie jako uwagi.
4. **Blokery.**
   - `BLOCKED` i powiadomienie w aplikacji Claude na telefonie tylko wtedy, gdy zadanie nie może iść dalej bez
     decyzji. Użytkownik odpowiada w sesji, która prowadzi przebieg, a ta wykonuje `ao answer`.
   - Wybory odwracalne agent rozstrzyga sam i wpisuje do sekcji „Decyzje bez pytania” w opisie PR zadania, a
     `/integration` przenosi je do swojego raportu.
   - Powtarzający się bloker naprawiamy w `/brainstorm` albo w `CLAUDE.md`, nie w AO.
5. **Odbiór (użytkownik).**
   - Odbiór to przeczytanie raportu odbioru z `/ao-run` i merge. Raport ma rekomendację „merge” albo „najpierw
     sprawdź: …”, zbudowaną z „Listy odbioru” raportu `/integration` (ścieżka raportu jest w opisie końcowego PR):
     dla każdej pozycji „OK” albo „Sprawdź”, czyli zmiany istniejących testów, migracje, pliki spoza zakresów zadań,
     odstępstwa od specyfikacji i „Decyzje bez pytania”.
   - Błąd w zakresie epiku: poprawka przez PR do `epic/<epik>` (np. `/delivery` uruchomione ręcznie z linią
     `Gałąź docelowa PR: epic/<epik>`), zmergowany przez użytkownika po zielonym CI. AO traktuje nowy HEAD jako
     kod po przeglądzie: robi rundę `DELTA` `/integration`, a końcowy PR czeka na jej wynik. Potem odbiór od nowa.
     Taka poprawka liczy się jako ręczna.
   - Konflikt końcowego PR z `main`, którego AO nie usunie (epik `DONE` przed zmianą `main`, `BLOCKED` po naprawach):
     `/ao-run` po zgodzie użytkownika robi PR `sync/<epik>-main` z prawdziwym merge'em `main` do `epic/<epik>`
     i merguje go merge commitem po zielonym CI. Końcowy PR zostaje ten sam. To też ręczna poprawka.
   - Problem spoza zakresu albo większy: nowe issue `bug` lub `ux`. Epik mergujemy bez tej poprawki, o ile
     niczego na `main` nie psuje. Uwagi `Minor` z raportu `/integration`, które warto zrobić, też stają się
     issues.
   - `/ao-run` dopisuje do opisu końcowego PR `Closes #N` każdego issue z linii `Issues:` specyfikacji. Użytkownik
     sprawdza je i merguje PR (squash). Issue zamykają się same. Merge do `main` zostaje wyłącznie u użytkownika.
   - Po każdym epiku trzy liczby w komentarzu do issue epiku: liczba interwencji (z `ao report`), czas czekania na
     decyzje użytkownika (z `events.jsonl`, `ao_events.py summary`) i liczba ręcznych poprawek. Minut pracy
     użytkownika nie szacujemy.
6. **Po merge'u (`/sweep [epik]`).** Użytkownik uruchamia komendę po merge'u epiku.
   Agent tester i agent UX przechodzą cały sklep na świeżym stosie z `main`: krytyczne ścieżki z sekcji „Testy”
   w `docs/vision.md` i nową funkcję ze specyfikacji, jako klient i jako pracownik. Przed zaproponowaniem zgłoszenia
   sprawdzają otwarte issues, żeby nie dublować. Propozycje trafiają do triage (punkt 1).

## Kroki wdrożenia

1. **AO v0.3.1 i zamrożenie.** v0.3.1 zamknięte (piotrp321/agentic-orchestrator#32). Od teraz AO jest zamrożone.
2. **Plan i zasada backlogu.** Ten plan zastępuje poprzedni. `CLAUDE.md` dostaje zasadę backlogu, ADR wskazuje
   GitHub Issues jako intake i opisuje role agenta testera i agenta UX. Jeden PR.
3. **Poprawki procesu w `shop`.** Formularze `bug` i `ux` w `.github/ISSUE_TEMPLATE/`, kontrole istniejących
   testów, zakresu i RED w `code-reviewer`, sekcja „Decyzje bez pytania” w PR zadań i lista odbioru w raporcie
   `/integration`.
4. **Backlog.** Etykiety `epic`, `bug`, `ux`, `tech-debt`, `needs-info` i `agent` są założone. Epiki z niezrobionych
   punktów „Zakresu MVP” to issues #88–#97, w kolejności na tablicy „Backlog sklepu”.
5. **Powiadomienia i kolejka epików przez aplikację Claude.** Przebieg AO prowadzi sesja Claude Code z Remote
   Control (włączone globalnie w aplikacji). Sesja śledzi zdarzenia AO (bloker, awaria, koniec epiku) i wysyła
   powiadomienie push do aplikacji Claude na telefonie. Odpowiedź na bloker użytkownik pisze w tej sesji, a sesja
   wykonuje `ao answer`. Po merge'u końcowego PR ta sama sesja bierze następny przygotowany epik z tablicy i pyta
   „Zacząć epik X?” (punkt 3 procesu). Instrukcja dla sesji jest w `shop`, bez zmian w kodzie AO. Mechanizm sprawdza
   pierwszy przebieg (krok 7). Przebieg epiku prowadzi skill `/ao-run <epik>` (`docs/features/ao-run/spec.md`).
   Kolejkę prowadzi ten sam skill (sekcja „Następny epik”, `next_epic.py`): `/ao-run` bez argumentu wybiera
   następny przygotowany epik z tablicy.
6. **Przygotowanie epiku jednym skillem.** Skill `.claude/skills/prepare-epic/` i agent krytyka `spec-critic`
   w `shop`, bez zmian w AO (specyfikacja: `docs/features/prepare-epic/spec.md`):
   - kolejność i podział ról jak w punkcie 2 procesu, zasady analityka i podziału na zadania brane z `/brainstorm`
     i `/planner`; oba skille zostają do pracy poza epikami;
   - jedna lista pytań produktowych do użytkownika i jedna akceptacja specyfikacji z pytaniem o rozmiar PR;
   - PR ze `spec.md` i `plan.md` merguje skill po akceptacji i zielonym CI; wyjątki merge'ów w `CLAUDE.md` i ADR.
7. **Pierwszy epik.** Issue z góry backlogu przechodzi cały proces, od `/prepare-epic` do odbioru, razem z pomiarem
   z punktu 5 procesu. Każdy kolejny epik idzie tym samym procesem.
8. **Nocna kolejka kilku epików.** Przed snem użytkownik jedną zgodą zatwierdza listę przygotowanych epików na noc
   (`/ao-run --noc`, punkt 3 procesu). Po `epic_done` jednego epiku sesja z `/ao-run` startuje następny z listy, bez
   czekania na merge. Rano użytkownik odbiera kilka raportów. Opis jest w `docs/features/ao-run/spec.md` (sekcja
   „Noc”), kod w `.claude/skills/ao-run/night_queue.py`, bez zmian w AO:
   - na jedną noc trafiają tylko epiki o rozłącznym zakresie, bo każdy startuje z `main` bez poprzednich;
     `/prepare-epic` zapisuje zakres epiku w linii `Zakres:` specyfikacji, a lista sprawdza rozłączność;
   - `/prepare-epic` przygotowuje tyle epików do przodu, ile mieści noc (`night_queue.py ahead`, punkt 2 procesu);
   - następny epik nie rusza, gdy jego szacunek nie mieści się do rana albo limit subskrypcji nie wystarczy na cały
     jego przebieg;
   - szacunki i liczba epików na noc wynikają z pomiaru każdego epiku prowadzonego przez `/ao-run`, nie z założenia.
   Mechanizm sprawdza pierwsza noc.
9. **Agent tester i agent UX.** Gotowe: agent tester i agent UX (`.claude/agents/`), skill `/sweep` uruchamiany
   przez użytkownika po merge'u epiku, agent UX jako trzeci reviewer w `/integration` dla epików z frontem,
   etykieta `agent`, limit pięciu propozycji na przebieg i sprawdzanie duplikatów. Do czasu makiet z punktu 3
   „Zakresu MVP” agent UX ocenia tylko użyteczność i dostępność. Automatyczne uruchamianie dopiero wtedy, gdy
   zgłoszenia okażą się warte czasu triage. Opis: `docs/features/agent-tester-ux/spec.md`.
10. **Przed publicznym demo: bezpieczeństwo i wydajność.** Przegląd bezpieczeństwa całego sklepu i testy
   obciążeniowe względem „Wymagań niefunkcjonalnych” z `docs/vision.md`. Zakres i narzędzia ustala specyfikacja
   przed demo.
11. **Po MVP: release i monitoring.** Publiczne demo zgodnie z `docs/vision.md` (k3s, Argo CD, rollback). Błędy
   z Sentry i alerty z Grafana Cloud stają się issues `bug` z etykietą `agent` i przechodzą triage.

Proces i kontrakt trafiają do ADR, gdy krok 7 potwierdzi, że działają. Plan usuwamy po ostatnim kroku.

## Świadomie poza planem

Skrzynka decyzji (zamiast niej powiadomienia w aplikacji Claude i `ao answer`), polityka autonomii jako plik, AO jako demon launchd, kilka
zadań naraz i pula środowisk, budżet na funkcję, merge queue, automatyczne zamienianie issue w zadania, metryki
i evale skilli, agent decydujący o produkcie, automatyczny merge kodu do `main`, rój agentów pracujących równolegle,
`/planner` wewnątrz `/delivery` (jeden PR na epik bez review zadań), przygotowanie wszystkich epików naraz.
Wracają tylko wtedy, gdy przebiegi pokażą, że czegoś z tego brakuje, i użytkownik o tym zdecyduje.

## Kontrakt planu i backlogu

Styk backlog → `/planner` → AO → `/delivery`. Format manifestu i jego walidator są w `.claude/skills/planner/`
(`schema_version: 1`). Szczegóły po stronie AO opisuje README `piotrp321/agentic-orchestrator`.

1. **Jedno źródło na rodzaj danych.** Co budujemy i w jakiej kolejności: issue. Definicja zadania (ID, tytuł,
   zakres, kryteria akceptacji, ryzyko, zależności, specyfikacja): tylko manifest. Stan zadania (kolejka, próby,
   PR, blokery): tylko AO. AO nie zapisuje niczego w repozytorium, `/planner` nie zgłasza zadań do AO, issue nie
   zawiera definicji zadań.
2. **Plan wiążący = plan na `origin/main`.** Zaakceptowany plan i jego specyfikacja trafiają do `main` przed
   `ao submit-plan`, więc każda gałąź zadania zawiera specyfikację. AO czyta plan z commita, nie z dysku, i
   zapamiętuje jego SHA. Plan z innej gałęzi na `origin` AO przyjmuje tylko na jawne wskazanie (`--base`).
3. **Zmiana planu po imporcie.** Ponowne `ao submit-plan` z tym samym planem niczego nie zmienia. Epik
   zarejestrowany z innego commita planu albo z inną konfiguracją kończy się odmową. Plan zmieniony po akceptacji
   wymaga ponownej akceptacji (zasada `/planner`).
4. **Gotowość zadania zależnego.** Zadanie rusza, gdy wszystkie jego zależności są `MERGED` do `epic/<epik>`. AO
   sprawdza to w GitHubie (`gh pr view`) i promuje zadanie sam, od aktualnego `origin/epic/<epik>`.
5. **Wejście `/delivery`.** AO przekazuje ID zadania, ścieżkę `plan.md` i gałąź docelową PR (`epic/<epik>`).
   `/delivery` czyta swoje zadanie z manifestu na commicie bazowym, a specyfikację z pola `spec`. `risk`
   z manifestu to podpowiedź, wiąże klasyfikacja `/delivery`.
6. **Wersjonowanie.** Każda zmiana pól manifestu podnosi `schema_version` i wymaga zmiany walidatora `/planner`
   i AO w tej samej fali prac. AO odrzuca nieznaną wersję. AO sprawdza tylko pola, których używa, pełny format
   sprawdza walidator w `shop`.

## Punkty zatrzymania

- **Każdy epik:** przed `ao submit-plan` użytkownik akceptuje w `/prepare-epic` specyfikację i rozmiar końcowego PR,
  a `plan.md` przechodzi walidator manifestu.
- **Start epiku:** AO rusza z epikiem dopiero po „tak” użytkownika na pytanie „Zacząć epik X?” albo na listę nocy.
  Kolejny epik listy rusza bez pytania tylko przy spełnionych warunkach sekcji „Noc” z `docs/features/ao-run/spec.md`.
- **Merge do `main`:** końcowy PR epiku merguje wyłącznie użytkownik. Wyjątek: PR ze `spec.md` i `plan.md`
  z `/prepare-epic`, który skill merguje po akceptacji użytkownika i zielonym CI. Każde rozszerzenie
  autonomii wymaga jego jawnej zgody.
- **Zmiana AO:** tylko przy zablokowanym prawdziwym przebiegu, za zgodą użytkownika.
- **Krok 4:** issues epików powstają dopiero po akceptacji listy.
- **Kroki 8–11:** specyfikacja każdego kroku zaakceptowana przed implementacją.
- **Zgłoszenia agentów:** trafiają do backlogu dopiero po triage użytkownika.
