# ASH: poprawki procesu po serii v1.0

Poprawki procesu natywnego i jego narzędzi po retrospektywie serii ASH v1.0
(`docs/reports/ash-v1-series-retrospective-2026-11-21.md`). Zakres i kierunek zaakceptował właściciel 2026-11-21.
Zasady obowiązują w miejscach wskazanych przy każdym wymaganiu (ADR „Przebieg epiku”, `CLAUDE.md`, pliki agentów
i skilli); ta specyfikacja mówi, co ma być spełnione i jak to sprawdzamy. Zachowanie narzędzi opisują
`docs/features/metrics/spec.md` i `docs/features/retro/spec.md`.

Issues: #832, #818 (F1), #822 (pozycje 1 i 2)

## Poza zakresem

- Archiwizacja AO i decyzja P6.
- Zmiana modeli, effortu i składu ról przeglądu.
- Nowe narzędzia orkiestracji, harmonogramy i automatyzacje.
- Zmiany aplikacji wynikające z zasady etapu demo (D): istniejącego kodu nie przebudowujemy.
- Pozostałe odroczone propozycje retro: #818 (F2–F5), #822 (pozycja 3), #813, #821, #645.

## W: wersja procesu

Sesja w worktree uruchomiona z aplikacji desktopowej czyta `CLAUDE.md` z worktree, a agentów i skille z głównego
checkoutu repozytorium. Zmieniony plik agenta albo skilla działa od następnego zlecenia, we wszystkich otwartych
sesjach.

Pomiar rozróżnia trzy poziomy i zapisuje je w jednym polu `process` pliku przebiegu, z którego korzysta i kontrola,
i raport (bez osobnego rejestru):

- **oczekiwana**: baza epiku (SHA, opis względem tagu `ash-v*`) i skróty plików instrukcji w bazie: każdy
  `CLAUDE.md`, pliki agentów, `SKILL.md` skilli i ADR z „Przebiegiem epiku”;
- **na dysku**: te same pliki w każdym miejscu, z którego sesja może je ładować (worktree, główny checkout),
  z pochodzeniem (ścieżka, commit, gałąź, niezacommitowane zmiany), skrótami i listą różnic względem oczekiwanej;
- **załadowana**: tylko to, co potwierdza transkrypt: treść `CLAUDE.md` wczytana przez sesję i treść definicji
  w prompcie każdego subagenta. Status: potwierdzona, różni się albo brak potwierdzenia (z powodem). Brak dowodu
  nigdy nie liczy się jako zgodność.

- **W1.** `metrics.py process` pokazuje poziomy „oczekiwana” i „na dysku” dla bieżącego worktree, agentów i skille
  użytkownika (`~/.claude`) o nazwie takiej jak w projekcie oraz epiki w toku z odciskiem zapisanym na ich starcie.
  Skróty liczy się z treści plików, nie z długości promptu ani z numeru commita głównego checkoutu.
- **W2.** `metrics.py start` zapisuje wszystkie trzy poziomy (załadowaną dla sesji prowadzącej; agenci i skille:
  brak potwierdzenia, bo jeszcze nie ruszyli) i odmawia startu, gdy definicje w głównym checkoucie różnią się od
  bazy, gdy worktree ma niezacommitowaną zmianę pliku instrukcji, gdy skill użytkownika przesłania skill projektu
  albo gdy sesja wczytała `CLAUDE.md` o treści spoza bazy i gałęzi epiku. Start mimo różnicy wymaga opcji
  z powodem; powód trafia do pliku przebiegu. Zmiana zacommitowana na gałęzi epiku w jego worktree jest zapisem
  informacyjnym, nie rozbieżnością.
- **W3.** `metrics.py process <epik>` powtarza kontrolę w trakcie epiku na danych pliku przebiegu: porównuje dysk ze
  stanem ze startu i potwierdza z transkryptów wersję załadowaną przez każdego uruchomionego subagenta. Zmianę albo
  różnicę zapisuje w pliku przebiegu i kończy się kodem niezgodności. Sesja uruchamia ją przed każdą falą zadań,
  przed przeglądem epiku i zaraz po starcie pierwszego subagenta danej roli; rozbieżność obsługuje przed zleceniem
  implementacji albo przeglądu (W5, W6): usuwa przyczynę albo akceptuje ją opcją z powodem. Akceptacja obejmuje
  tylko pozycje pokazane przez poprzednią kontrolę; zaakceptowany stan nie jest zgłaszany ponownie, także gdy
  kolejny subagent załaduje definicję z zaakceptowanego stanu głównego checkoutu. Zmiana procesu przyniesiona
  z `main` do gałęzi epiku jest rozbieżnością. Komendy zapisujące plik przebiegu biorą na niego blokadę, więc
  równoległe wywołania nie gubią zapisów. `start --resume` i `done` robią tę samą kontrolę; wznowienie mimo zmiany wymaga opcji
  z powodem, `done` zapisuje czas zawsze.
- **W4.** Rekord epiku ma pole `process` z trzema poziomami i podsumowaniem załadowanej wersji na rolę; różnica,
  zaakceptowany start mimo różnicy, zmiana w trakcie i brak potwierdzenia są w `gaps` i w raporcie (`render`).
  `/retro` podaje je analitykowi jako fakt.
- **W5.** Główny checkout aktualizuje się jawnym krokiem (`git pull --ff-only`, bez `reset`, `checkout` i `stash`),
  gdy jest czysty, stoi na `main` i `metrics.py process` nie pokazuje epiku w toku, który wystartował z bieżącymi
  definicjami. W pozostałych przypadkach decyduje właściciel. Sesje bez pliku przebiegu (retro, mała zmiana) nie są
  widoczne dla tej kontroli.
- **W6.** Epik, który startuje mimo różnicy, nie zmienia wspólnych definicji: jego zlecenia każą subagentowi
  przeczytać plik roli z worktree epiku i stosować go zamiast wbudowanej definicji, a skill sesja czyta z worktree.
- **W7.** `/retro` sprawdza wersję przed zbieraniem danych (`retro.py collect`, skrypt z worktree retro) i przy
  różnicy odmawia, dopóki użytkownik nie zaktualizuje głównego checkoutu albo nie zaakceptuje różnicy z powodem.

## E: jedna sesja, jeden epik

- **E1.** Sesja prowadząca realizuje jeden epik. `metrics.py start` odmawia, gdy identyfikator sesji jest już
  w pliku przebiegu innego epiku. Kontynuacja tego samego epiku w nowej sesji (`start --resume`) zostaje i dopisuje
  sesję do tego samego pliku przebiegu.
- **E2.** Po potwierdzonym merge'u końcowego PR sesja: zapisuje rekord (`metrics.py epic <epik>`), gasi swoje stosy
  i usuwa czyste worktree zadań, dodaje do końcowego PR komentarz „Przekazanie do retro” i kończy komunikatem
  „Epik zakończony. Uruchom /retro <epik> w nowej sesji. Tę sesję można zarchiwizować”. Nie archiwizuje sesji i nie
  zaczyna kolejnego epiku.

## P: przekazanie do retro

- **P1.** Komentarz „Przekazanie do retro” podaje: nazwę epiku i polecenie `/retro <epik>`, końcowy PR i commit
  merge'u, specyfikację, plan (ścieżka albo commit, w którym plan jeszcze istnieje), przeglądy i poprawki (także
  zewnętrzne review z commitami poprawek), plik przebiegu, rekord i sesje z transkryptami, wersję procesu
  z odstępstwami oraz uwagi właściciela, które padły po ostatniej edycji opisu PR.
- **P2.** Uwagę właściciela o procesie sesja zapisuje w całości, jako jeden punkt, w „Problemach i zastojach” albo
  „Lukach w instrukcjach” opisu końcowego PR, z początkiem „Uwaga właściciela:”.
- **P3.** `retro.py collect` przekazuje analitykowi: pełną treść komentarza „Przekazanie do retro” (brak komentarza to
  fakt, nie błąd), ścieżki specyfikacji i planu (plan usunięty: commit z ostatnią wersją), plik przebiegu, rekord
  i katalogi transkryptów sesji epiku.
- **P4.** `/retro` uruchamia użytkownik w nowej sesji. Bez harmonogramu i bez automatycznego startu.

## R: pełna treść uwag w `/retro`

- **R1.** Treść punktu opisu PR i jego dowód trafiają do `input.json` w całości. Skrót (do prezentacji) jest
  osobnym polem i nie zastępuje pełnej treści.
- **R2.** Jeden punkt to jeden incydent o tym samym identyfikatorze co dotąd, bez względu na długość.
- **R3.** Żaden dalszy etap (`validate`, raport, treść issue, opis PR) nie ucina treści po cichu: albo zachowuje
  całość, albo kończy się błędem z nazwą pola.
- **R4.** Test regresyjny: uwaga dłuższa niż 500 znaków z istotną informacją na końcu; sprawdza pełną treść,
  identyfikator i liczbę incydentów.

## O: ocena znaleziska przed odroczeniem

- **O1.** Uwagi, która opisuje konkretną ścieżkę błędu, nie odracza się na podstawie samej etykiety `Minor` albo
  `SUGGESTION`.
- **O2.** Przed odroczeniem sesja zapisuje przy uwadze w „Odroczonych uwagach” krótką ocenę: rzeczywisty skutek,
  naruszona gwarancja, odwracalność.
- **O3.** Istotne podejrzenie oparte na niesprawdzonym założeniu („nie odtwarzałem”, założenie o działaniu
  zależności, własne założenie sesji o skutku) sesja sprawdza w kodzie albo eksperymentem, zanim je odroczy.
- **O6.** Ocena kończy się podstawą: „sprawdzone: <próba albo miejsce w kodzie i wynik>” albo „założenie
  niesprawdzone: <jakie>”. Ocena z niesprawdzonym założeniem nie stwierdza braku skutku.
- **O7.** Uwagę z niesprawdzonym założeniem sesja przekazuje do przeglądu epiku jako scenariusz do wykonania (wejście
  albo przeplot i co zaobserwować), bez własnej oceny i bez oczekiwanego wyniku. `qa-adversarial` podaje wynik
  każdego scenariusza z dowodem; wynik zastępuje podstawę w opisie PR.
- **O4.** Wąskie okno wyścigu nie obniża wagi. Równoległe żądania, równoległe sesje i edycja formularza w trakcie
  zapisu to zwykłe użycie.
- **O5.** Styl, drobne sugestie i przypadki bez istotnego skutku nadal odracza się bez pytania; zasada nie jest
  obowiązkiem naprawy każdej uwagi i nie zmienia budżetu dwóch rund.
- **O8 (pilotaż).** O odroczeniu uwagi, która opisuje konkretny błąd (wejście albo przeplot i błędny wynik),
  rozstrzyga przed zewnętrznym review `code-reviewer` w świeżym kontekście. Dostaje uwagę w brzmieniu zgłaszającego
  i wyniki sprawdzeń jako fakty, bez oceny sesji, i daje werdykt „naprawić przed merge'em” albo „można odroczyć”.
  Werdyktu „naprawić” sesja sama nie uchyla: naprawia albo pyta właściciela. Sprawdzenie pozycji „założenie
  niesprawdzone” (O3) zostaje przy sesji, bo `code-reviewer` nie uruchamia prób. Bez odroczonej uwagi o konkretnym
  błędzie oceny nie ma.
- **O9 (pilotaż).** Opis PR i przekazanie do retro podają osobno: potwierdzone błędne odroczenia (werdykt „naprawić”,
  poprawka z testem RED potwierdzonym zapisem uruchomienia), werdykty „naprawić”, których błąd się nie potwierdził,
  nowe znaleziska zewnętrznego review oraz znaleziska powtarzające odroczoną uwagę z werdyktem, jaki dostała. Koszt
  i czas oceny są w rekordzie epiku przy zleceniu `<EPIK>-DEF1`.
- **O10 (pilotaż).** Pilotaż obejmuje trzy albo cztery epiki z zewnętrznym review, od pierwszego, którego sesja ma tę
  regułę przed przekazaniem końcowego PR. Potem właściciel ocenia, ile błędnych odroczeń wychwycił reviewer i ile to
  kosztowało, i decyduje, czy ocena zostaje. Podstawa: `docs/reports/ash-workflow-audit-2026-11-24.md` (cztery
  z ośmiu potwierdzonych znalezisk zewnętrznego review w pięciu epikach powtarzały uwagę odroczoną przez sesję).

## S: ryzykowne założenia w specyfikacji

- **S1.** Niepewne założenie o zachowaniu zależności, od którego zależy poprawność (transakcje, skutki zewnętrzne,
  ponowienia, limity czasu, współbieżność), `/brainstorm` sprawdza przed akceptacją specyfikacji: w dokumentacji
  właściwej wersji, w kodzie zależności albo celowanym eksperymentem, odpowiednio do ryzyka. Specyfikacja podaje
  źródło.
- **S2.** Zwykłe użycie frameworka nie wymaga eksperymentu.

## D: etap demo

- **D1.** Decyzja produktowa: rozwijamy demo. Przestój, zmiana niezgodna z poprzednią wersją i odtworzenie danych demo
  (reset i seed) są dopuszczalne. Nie projektujemy bezprzerwowych wdrożeń, migracji zachowujących dane ani zgodności
  ze starszą wersją wyłącznie dla ciągłości działania.
- **D2.** Poprawność zwykłego działania sklepu zostaje wymagana: zamówienia, płatności, bezpieczeństwo, formularze.
- **D3.** Zasada jest w `docs/vision.md` (źródło), głównym `CLAUDE.md`, `apps/api/CLAUDE.md`, `/brainstorm`,
  `/planner` i plikach reviewerów; żaden z tych plików nie nakazuje złożoności, którą D1 wyklucza.
- **D4.** Zasada nie jest poleceniem kasowania danych ani wdrożenia: operacje na serwerze dalej wymagają zgody
  z głównego `CLAUDE.md`.

## C: zewnętrzne review

- **C1.** Końcowy PR każdego epiku przechodzi niezależne review Codexa: po przeglądzie epiku, przed oznaczeniem PR
  jako gotowego. Review obejmuje całą zmianę i osobno podważa „Odroczone uwagi”.
- **C2.** Sesja nie uruchamia Codexa: przekazuje właścicielowi gotowe zlecenie i stan PR, a po poprawkach i zielonym
  wymaganym CI oznacza PR jako gotowy.
- **C3.** Każde potwierdzone znalezisko jest w opisie PR oznaczone jako „nowe” albo „powtarza odroczoną uwagę”
  (z podstawą odroczenia). `/retro` podaje obie grupy osobno w sekcji „Zewnętrzne review” raportu: pierwsza mierzy,
  co wykrywa tylko zewnętrzne review, druga, ile kosztuje odroczenie bez sprawdzenia. Trzy epiki od 2026-11-21 dały
  cztery potwierdzone znaleziska, w tym dwa powtarzające odroczoną uwagę, więc review zostaje bez ograniczenia.

## T: czas i pomiar

- **T1.** Po pushu, na którego wynik czeka, sesja śledzi w tle przebieg CI wypchniętego commita
  (`.github/scripts/ci-wait.sh <sha>`) i w tym czasie robi pracę niezależną od wyniku. Polecenie czeka na
  zarejestrowanie przebiegu dokładnie tego SHA (kod 3, gdy się nie pojawi), kończy się razem z nim i podaje wynik
  z SHA i numerem przebiegu; wynik starszego commita nie potwierdza bieżącego czoła.
- **T4.** Po merge'u sesja czeka na koniec przebiegu CI commita merge'u na `main` i dopiero wtedy zapisuje rekord
  i przekazanie do retro. Przebieg w toku albo jego brak to w rekordzie wynik nieznany (`regressions.ci_red: null`,
  otwarte okno), nie zero.
- **T5.** Na końcu epiku `metrics.py ci-time <epik>` porównuje czas CI ze stanem sprzed epiku
  (`docs/features/metrics/spec.md`, „Czas CI po epiku”): baza z przebiegów `main` sprzed startu, końcowy SHA osobno,
  pomiar na commicie merge'u po merge'u, `main` w trakcie epiku jako możliwy wpływ zewnętrzny. Progi są startowe i do
  oceny po trzech kolejnych epikach. Przekroczenie sesja wyjaśnia z dowodem i kończy rekomendacją; kontrola nie
  blokuje PR, nie uruchamia CI dla pomiaru i brak danych podaje jako brak danych.
- **T6.** Wag części E2E (`E2E_SHARD_WEIGHTS`) epik nie zmienia: dodane testy biegną bez zmiany wag, a wyrównanie to
  osobny PR do `main` z czasów testów (`e2e/CLAUDE.md`, „CI”). Jedną dodatnią wagę na część macierzy sprawdza test
  skilla `integration`; powód do wyrównania podaje rozrzut części E2E z T5.
- **T2.** `qa-adversarial` i `ux-reviewer` nie pracują naraz na jednym stosie; `AGENT_DIR` leży w katalogu
  udostępnionym Dockerowi; przed falą sesja gasi stosy zakończonych zadań. Reviewer, który zmienia commit
  w worktree z istniejącym cache testów API, czyści ten cache przed testami (#645 zostaje do naprawy w `Makefile`).
- **T3.** `metrics.py pause` zapisuje przerwę komputera z podanych czasów. Rekord liczy ją jako `host_pause`, nie
  jako `human_wait`. Bez zapisanej przerwy `host_pause` zostaje `null`: narzędzie niczego nie zgaduje (#832).

## Kryteria akceptacji

1. `metrics.py process` w worktree, którego główny checkout ma inne definicje, wypisuje różniące się pliki i kończy
   się kodem różnym od zera; przy zgodnych definicjach kodem 0.
2. `metrics.py start` przy różnicy definicji odmawia i nie zapisuje pliku; z opcją akceptacji zapisuje plik
   z powodem; przy zgodności zapisuje `process` bez odstępstw.
3. `metrics.py process <epik>`, `start --resume` i `done` po zmianie pliku agenta w miejscu ładowania zapisują zmianę
   w pliku przebiegu; rekord (`metrics.py epic`) ma ją w `process` i `gaps`. Subagent z definicją inną niż w bazie ma
   w rekordzie status „różni się”, a subagent bez dowodu w transkrypcie „brak potwierdzenia”; rekord prawdziwego
   epiku serii (np. DELIVERY, na kopii stanu) pokazuje reviewerów z definicją inną niż baza.
4. `metrics.py start` z sesją zapisaną w pliku przebiegu innego epiku odmawia; `start --resume` tego samego epiku
   w nowej sesji przechodzi.
5. Rekord DELIVERY z zapisaną przerwą 15:25–17:43Z ma ją w `host_pause`, `human_wait` jej nie zawiera,
   a `decide delivery` nie zgłasza z jej powodu `D4:human_wait`. Bez zapisanej przerwy `host_pause` jest `null`.
6. `retro.py collect` dla punktu opisu PR o długości ponad 500 znaków z informacją na końcu zapisuje w `input.json`
   pełną treść w danych i w dowodzie, jeden incydent o identyfikatorze `…#<pozycja>`; liczba incydentów nie zależy od
   długości punktów.
7. `retro.py collect` zapisuje w `input.json` treść komentarza „Przekazanie do retro” i wskazania materiałów; dla
   usuniętego planu podaje commit z jego ostatnią wersją; brak komentarza daje fakt.
8. `retro.py collect` przy różnicy definicji odmawia z listą plików; z akceptacją przechodzi, a fakt o różnicy jest
   w `input.json`.
9. ADR „Przebieg epiku” zawiera kroki W, E, P, O, C i T; `CLAUDE.md`, pliki agentów, `/brainstorm` i `/planner` nie
   zawierają zdań sprzecznych z O i D (sprawdzenie wyszukiwaniem).
10. CI: testy skryptów (`.github/scripts/script-tests.sh`) i `make check` zielone na czole gałęzi.
11. `ci-wait.sh` z podstawionym `gh`: przebieg zarejestrowany z opóźnieniem jest śledzony do końca; brak przebiegu
    w limicie daje kod 3; przebieg innego commita nie potwierdza podanego (kod 4); przebieg czerwony albo anulowany
    daje kod 1 z nazwami i wynikami nieudanych jobów (także `timed_out`); błąd `gh` kod 4; błędne użycie kod 2.
12. `metrics.py epic` tuż po merge'u, gdy przebieg CI commita merge'u trwa albo nie istnieje, zapisuje
    `regressions.ci_red: null` z luką i otwartym oknem; kolejne uruchomienie po końcu przebiegu zapisuje wynik.
13. `metrics.py ci-time`: kryterium 18 specyfikacji `docs/features/metrics/spec.md`.
14. Test skilla `integration` przechodzi po zmianie wag w kopii `ci.yml` i nie przechodzi, gdy liczba wag różni się
    od liczby części macierzy `e2e-shard`; `e2e/CLAUDE.md` nie każe zmieniać wag w epiku.

## Ograniczenia

- Kontrola przed pracą porównuje pliki na dysku we wszystkich znanych miejscach ładowania
  (`https://code.claude.com/docs/en/worktrees.md`). Wersję załadowaną przez subagenta da się potwierdzić dopiero po
  jego starcie, z wpisu promptu w transkrypcie; gdy wersja CLI takiego wpisu nie zapisuje, status to „brak
  potwierdzenia”.
- Zmianę definicji w trakcie epiku narzędzie wykrywa przy kolejnej kontroli (`process <epik>`, `start --resume`,
  `done`), nie w chwili zmiany.
- Kontrola wersji w `/retro` obejmuje pliki na dysku; wersji załadowanej przez `retro-analyst` nie potwierdza.
- `start --at` (zapis po fakcie) nie sprawdza wersji, ale podlega E1: plik przebiegu drugiego epiku tej samej sesji
  historycznej nie powstanie tą komendą.
- Inna gałąź zmergowana do gałęzi epiku, zanim trafi na `main`, liczy się jako własna zmiana epiku.
- Reguły O, S i D to instrukcje: ich skuteczność pokażą kolejne epiki, nie ta zmiana.
