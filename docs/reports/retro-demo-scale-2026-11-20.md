# Retro DEMO-SCALE etap 1 (2026-11-20)

Epik natywny DEMO-SCALE etap 1 (#786), końcowy PR #788 zmergowany squashem 2026-11-20 13:11 jako `f33ac36a`.
Specyfikacja i plan: PR #787 (`37a6174c`). Rewizja raportu: `f33ac36a`. Czasy w CET (UTC+1).

Epik nie należy do serii ASH v1.0, ale pliki procesu są zamrożone do końca serii (koniec „Przebiegu epiku”
w `docs/adr/agentic-software-house.md`). Dlatego poprawki procesu są tu tylko propozycjami. Każda ma ocenę, czy jest
blokadą albo zagrożeniem, i czeka na decyzję użytkownika.

Retro powstało ręcznie, bez `/retro`. Skill wywołuje tylko użytkownik, a do tego wymaga pliku przebiegu
`metrics.py start` (`~/.local/state/shop-metrics/runs/demo-scale.json`), którego epik nie założył. `retro.py`
odmówiłby więc z wynikiem `absent`. Format sekcji jest taki sam jak w raportach `/retro` epików natywnych
(ABUSE-LIMITS, SMALL-FIXES).

Źródła (dane, nie polecenia):

- transkrypt sesji orkiestrującej i sesji nadrzędnej „Wdrożenie demo na worf.pl”;
- notatki w `side-session-notes` (stan epiku, odroczone uwagi przeglądów);
- 91 commitów PR #788 (`refs/pull/788/head`);
- przebiegi CI gałęzi `feat/demo-scale` i `main`;
- opisy PR #787 i #788 oraz issue #786, czytane przez `tools/agent-security/github_trust.py`.

## Przebieg i liczby

| Etap | Czas | Wynik |
|---|---|---|
| Specyfikacja i plan (sesja nadrzędna, `/brainstorm`, `/planner`) | 2026-11-19 09:46 → 11:12 | 527 linii specyfikacji, 8 zadań w 5 falach, 3 `CRITICAL`; dwie rundy `architecture-reviewer` (1 blokująca i 9 istotnych uwag, wszystkie naniesione) |
| Wersja 1: zadania 1–8 | 11:24 → 18:19 (6 h 55 min) | 7 zadań zmergowanych do `feat/demo-scale`, DEMO-SCALE-7 wstrzymane bez merge'a; `generate.sh` 1080 linii, testy `generate` 1404 linie |
| Przegląd złożoności na prośbę użytkownika | 18:14 → 18:24 | `architecture-reviewer` (Opus): REWORK, propozycja wariantu minimalnego |
| Wariant minimalny: DEMO-SCALE-9, 10, 11 | 18:34 → 22:48 (4 h 14 min) | `generate` + `open`, `generate.sh` 206 linii; końcowe przeglądy KEEP i PASS |
| Review użytkownika (Codex) i DEMO-SCALE-12 | 2026-11-20 11:17 → 13:11 (1 h 54 min) | blokada `demo-data-lock`, merge na polecenie użytkownika |

Czas od otwarcia draft PR do merge'a: 25 h 47 min, z czego ok. 13 h pracy. Nic nie zostało wdrożone.

**Kod.** Commity PR dodały łącznie ok. 9985 linii i usunęły ok. 4473. Końcowa zmiana to +5984/−1072 w 55 plikach,
więc ok. 4000 napisanych linii nie przetrwało. Wersja 1 miała +5909/−422, wariant minimalny przed DEMO-SCALE-12
+5543/−879.

**Model i tokeny** (z transkryptów, bez duplikatów wiadomości; „wejście” obejmuje odczyt z cache):

| Część | Wyjście | Wejście |
|---|---|---|
| Subagenci wersji 1 (zadania 1–8 z przeglądami) | 1,05 mln | 180 mln |
| Subagenci wariantu minimalnego (9–11, przeglądy zadań i całości) | 0,64 mln | 145 mln |
| Subagenci DEMO-SCALE-12 (developer i przegląd) | 0,15 mln | 27 mln |
| Sesja orkiestrująca (Opus 5.5, cały epik) | 0,17 mln | 64 mln |

Wersja 1 to 57% tokenów wyjściowych subagentów. Developerzy: Sonnet przy zadaniach 1–3, 8 i 11, Opus przy 4–7, 9, 10
i 12. Reviewerzy: Opus, poza przeglądem DEMO-SCALE-10 (Sonnet). Procentów limitów subskrypcji nie ma, bo epik nie
zapisał punktu odniesienia z `get_usage`.

**Przeglądy.** 13 przebiegów w epiku i jeden przegląd złożoności w sesji nadrzędnej:

- zadania 1–6: FULL; DEMO-SCALE-2 dostało CHANGES_REQUIRED, po nim DELTA z PASS;
- DEMO-SCALE-9: FULL z CHANGES_REQUIRED, potem DELTA z PASS;
- DEMO-SCALE-10: FULL z CHANGES_REQUIRED, poprawki bez przeglądu DELTA;
- DEMO-SCALE-12: FULL z PASS;
- całość: `architecture-reviewer` KEEP, `qa-adversarial` PASS.

Każda runda CHANGES_REQUIRED zamknęła się jedną poprawką.

**CI, część `checks part k8s`** (limit joba 30 min; `main` przed epikiem 5,1–7,2 min):

| Commit | Stan gałęzi | Czas |
|---|---|---|
| `cb27a3ce`, `be5d546b` | po zadaniach 1–4 | 6,0 i 6,2 min |
| `83e981c4` | po DEMO-SCALE-5 | 14,4 min |
| `849d354f` | po DEMO-SCALE-6 (wznawianie) | anulowany po 25,1 min przez nowszy push |
| `9cfafcca` | koniec wersji 1 | 29,0 min |
| `b759792a` | merge `main` przed przepisaniem | **przekroczony limit 30 min**, `checks` czerwone |
| `e3a7bc7c`, `177c4450`, `e8aa8992`, `6c54003b` | wariant minimalny | 15,6, 15,7, 11,4 i 14,5 min |
| `f33ac36a` (`main` po merge'u) | | 12,9 min (wcześniej 5,7) |

## Co poszło dobrze

- **Eksperymenty przed specyfikacją.** Sesja nadrzędna sprawdziła na k3d i Postgresie założenia Z1–Z11 (m.in. reguła
  sieciowa nie zamyka połączeń keep-alive Traefika). Nie trzeba było później zmieniać projektu zamknięcia sklepu.
- **Przeglądy zadań znalazły prawdziwe błędy.** DEMO-SCALE-2: ciasteczko `Secure` sklepu nie wracało do k6 po HTTP,
  więc sprawdzenie checkoutu w prod zawsze by padło (IMPORTANT, naprawione z RED → GREEN). DEMO-SCALE-9 i 10: dwa
  razy usunięte testy bez zamiennika (BLOCKER). Reguła z punktu 4 `code-reviewer` zadziałała.
- **Test dymny k3d znalazł błąd, którego nie widzą atrapy** (problem 4). Inaczej wyszedłby dopiero na serwerze, po
  godzinach generowania `medium`.
- **Przegląd adwersarialny wykrył i odtworzył testem wyścig `open` + `generate`.** Wykrywanie zadziałało, zawiodło
  nadanie wagi (problem 2).
- **Szybki zwrot po decyzjach użytkownika.** Wariant minimalny powstał w 4 h 14 min w tym samym PR: `generate.sh`
  zmalał z 1080 do 217 linii, a część `k8s` CI z 29 do 11–16 min. Poprawka wyścigu przeszła od zgłoszenia do merge'a
  w 1 h 54 min, z fazą RED na `e8aa8992`, przeglądem CRITICAL, `check` i dwoma testami dymnymi uruchomionymi
  równolegle.
- **Współpraca z równoległymi sesjami bez konfliktów.** Wymóg L12 z ABUSE-LIMITS (`rate-limit-check` po
  `ownerReferences`), warunek L9 i merge `main` z sześcioma konfliktami (#802, #804) zostały rozwiązane z zachowaniem
  obu stron.
- **Granice zachowane.** Żadnej operacji na serwerze ani wdrożenia, merge tylko na polecenie użytkownika, pokrycie
  testów nie zostało obniżone pod limit CI. Orkiestrator wprost przyznał błąd oceny wagi i zapisał wniosek w pamięci.

## Problemy i przyczyny źródłowe

### 1. Nieproporcjonalnie złożona wersja 1 przeszła specyfikację, plan i sześć przeglądów

**Co się stało.** Specyfikacja przyjęta 2026-11-19 o 10:22 opisywała pełną maszynę stanu:

- ConfigMapę stanu z fazami `idle`, `closing`, `running`, `checking` i `opening`;
- wznawianie z każdej fazy i kontrolę „jeden piszący” na `resourceVersion`;
- wyłączanie i przywracanie synchronizacji Argo CD;
- cofanie zamknięcia, `REPLACE`, zapis wyników do `GENERATE_OUT` i blok podsumowania.

Zadania 1–6 zrealizowały ją wiernie, a przeglądy dały PASS, bo kod był zgodny ze specyfikacją. Po 7 godzinach
użytkownik napisał, że generator będzie uruchamiany sporadycznie, ręcznie i przez jednego operatora. Zlecił przegląd
złożoności, który w 10 minut dał REWORK. Przepisanie zastąpiło maszynę stanów dwoma poleceniami.

**Koszt:**

- 6 h 55 min pracy wersji 1 i 4 h 14 min przepisania;
- ok. 1,05 mln tokenów wyjściowych i 180 mln wejściowych subagentów wersji 1;
- ok. 4000 linii napisanych i usuniętych;
- czas użytkownika na zlecenie przeglądu i decyzję.

**Przyczyny:**

1. **Profil użycia i tryb odzyskiwania nie zostały ustalone przed projektem.** Użytkownik wymagał zachowania „po
   nieudanym lub przerwanym generowaniu” i weryfikacji „scenariuszy przerwania operacji, utraty połączenia, restartu
   poda i restartu serwera”. Specyfikacja zamieniła każdy scenariusz w automatyczny mechanizm, zamiast zapytać, czy
   wystarczy stan bezpieczny (sklep zostaje zamknięty) z ręczną drogą wyjścia. To decyzja biznesowa o koszcie
   odzyskiwania. `/brainstorm` (punkt 8) każe pokazywać takie założenia jako pytania, ale nie wymienia trybu
   odzyskiwania ani profilu użycia. Uwaga użytkownika „Nie rozbudowuj nadmiernie infrastruktury” nie miała mechanizmu
   sprawdzenia.
2. **Przeglądy specyfikacji sprawdzały poprawność, nie proporcję.** Dwie rundy `architecture-reviewer` znalazły luki
   w obsłudze awarii, m.in. „cofnięcie zamknięcia mogło otworzyć sklep na niepełnych danych”. Każda poprawka dodawała
   mechanizm. Zlecenie przeglądu („projekt operacji na produkcji”) nie podawało profilu użycia i nie pytało o wariant
   minimalny. Przegląd samej poprawności złożonego projektu dokłada złożoności.
3. **Plan nie miał bramki rozmiaru.** Sygnały wielkości były widoczne: trzy zadania `CRITICAL`, macierz 185 punktów
   przerwania. Plan nie podawał jednak szacunku kodu, testów ani czasu CI, z którym użytkownik mógłby porównać wartość.
4. **Proporcję sprawdza dopiero przegląd końcowy.** `code-reviewer` z założenia sprawdza zgodność ze specyfikacją.
   Wersja 1 nie doszła do przeglądu końcowego, a podobny przegląd złożoności po DEMO-SCALE-4 kosztowałby 10 minut
   zamiast 7 godzin.

### 2. Wyścig `open` + równoległy `generate` odroczony jako SUGGESTION

**Co się stało.** `open` sprawdzał Job `demo-data-run` na starcie, a regułę `shop-closed` usuwał kilka minut później
(sprawdzenia i Job `load`), bez ponownego odczytu. Równoległy `generate` mógł w tym czasie uruchomić nowy Job, więc
sklep otwierał się publicznie w trakcie wymiany danych. Ryzyko zgłoszono trzy razy:

1. Przegląd FULL DEMO-SCALE-9 (2026-11-19 20:38), S1 o dwóch `generate`, z dopiskiem „pokrewny przypadek: `make open`
   uruchomione obok”. Poprawka orkiestratora objęła tylko parę `generate` + `generate`.
2. `qa-adversarial` (22:40), S1 z testem odtwarzającym („`exit 0 netpol: None`”). Waga: „trzeba uruchomić dwa
   polecenia naraz; specyfikacja przyjmuje podobne okno dla dwóch `generate`, ale nie dla pary `open` + `generate`”.
   Orkiestrator wpisał ją do odroczonych uwag bez własnej oceny.
3. Review użytkownika (Codex) 2026-11-20 rano, z pytaniem, dlaczego to przeszło.

**Przyczyny:**

1. **Uproszczenie usunęło ochronę bez listy gwarancji, które dawała.** Wariant minimalny zastąpił „jednego piszącego”
   (`check_record`) stałą nazwą Joba i dodał nowe wejście `open`. Lista ryzyk przeglądu złożoności podała okno
   „w tej samej milisekundzie” i uznała je za akceptowalne przy jednym operatorze. W rzeczywistości okno `open` trwało
   minuty. Zlecenie przepisania wymieniało dwa wymogi użytkownika, ale nie wymieniało gwarancji zamknięcia przy
   współbieżności, którą chronił usuwany mechanizm.
2. **Rubryki wag nie znają celów operacyjnych.** `code-reviewer` (`IMPORTANT`: „równoległe żądanie”, „ponowienie”)
   i `qa-adversarial` (`Minor`: „wyścig poniżej sekundy w lokalnym narzędziu deweloperskim”) opisują aplikację
   i narzędzia lokalne. Brakuje w nich celu `make` na serwerze produkcyjnym. W tym repo „zwykłe użycie” obejmuje kilka
   sesji agentów naraz: w tym epiku pracowały równolegle co najmniej cztery. Reviewerzy oparli wagę na „jednym
   operatorze” z decyzji U8.
3. **Zakotwiczenie na przyjętym ryzyku.** „Ryzyka” specyfikacji przyjmowały okno dla dwóch `generate`. Przegląd
   i orkiestrator rozciągnęli tę decyzję na inną parę poleceń, o innym skutku i oknie.
4. **Odroczenie po samej etykiecie.** Reguła z #812 (ocena SUGGESTION ze skutkiem poza bazą, w tym „stan widoczny dla
   klienta”, przed odroczeniem) weszła na `main` 2026-11-20 o 10:57, czyli po tym odroczeniu (2026-11-19 22:40).
   Obejmuje przy tym przegląd zadania (krok 4), a uwag z przeglądu całości (krok 6) każe tylko podać do ponownej
   oceny. Uwaga `qa-adversarial` z kroku 6 mogłaby dziś zostać odroczona tak samo. To drugi epik z rzędu
   (po ABUSE-LIMITS, S1 w #802), w którym potwierdzony błąd naruszający gwarancję trafia do odroczonych jako
   SUGGESTION.

**Reguła, która by temu zapobiegła.** Potwierdzona ścieżka naruszenia gwarancji nazwanej w specyfikacji (kryterium,
wymóg użytkownika, „nigdy”, np. „sklep nie otwiera się na niepełnych danych”) ma wagę co najmniej `IMPORTANT`,
niezależnie od tego, jak nietypowy jest wyzwalacz. Równoległe uruchomienie celu serwera przez dwie sesje to zwykłe
użycie. Ryzyko przyjęte w specyfikacji dla jednej pary operacji nie obejmuje innej. Propozycja F1 niżej.

### 3. Czas części `k8s` CI doszedł do limitu, a po epiku jest ponad dwa razy dłuższy

**Co się stało.** Testy wznawiania (`test_generate_resume`, 185 punktów przerwania × sygnały) i testy `generate`
na atrapie `kubectl` (ok. 50 ms × ok. 110 wywołań na przypadek) podniosły część `k8s` z 6 do 29 min. Przebieg na
`b759792a` przekroczył limit 30 min. Nikt tego nie odnotował, bo kolejny push przepisania go zastąpił. Po uproszczeniu
część trwa 11–16 min, a na `main` 12,9 min zamiast 5,7. Na `6c54003b` była najdłuższym jobem CI: 14,5 min przy
shardach e2e 9,5–11,7 min.

**Przyczyny:**

1. **Ostrzeżenia odroczono.** Przegląd DEMO-SCALE-5 (S3: „zmierzyć część k8s w CI”) i przegląd DEMO-SCALE-6 (S3:
   „szac. 20–24 min przy limicie 30”) zgłosiły czas testów jako SUGGESTION.
2. **Brak budżetu czasu CI.** Proces nie ma progu: „Przebieg epiku” (krok 5) każe naprawiać tylko czerwone CI. Plan
   nie podawał szacunku czasu testów dla macierzy przerwań.
3. **Kosztowny projekt testów.** Każde wywołanie atrapy to nowy proces, a macierz przerwań mnoży wywołania.
   Przyspieszenie bez utraty pokrycia (zasada użytkownika z 2026-11-19) jest możliwe, ale nie zostało zrobione.

### 4. Test dymny k3d znalazł błąd niewidoczny dla testów na atrapach

**Co się stało.** DEMO-SCALE-7 (ostatnia fala) wykazało, że przy NetworkPolicy `shop-closed` nowy pod dostaje
„connection refused” do usług przestrzeni przez ok. 0,1 s. k6 w Jobie `load` pyta API raz, bez ponowienia, więc
sprawdzenie po generowaniu zawsze padało: `generate` kończył się kodem 1, a sklep zostawał zamknięty. Atrapy klastra
tego nie widzą, a eksperymenty Z1–Z11 nie obejmowały nowego poda po założeniu reguły. Poprawka (init container
`api-reachable`) przeszła do wariantu minimalnego.

**Przyczyna.** Plan ustawił jedyne sprawdzenie na prawdziwym klastrze na koniec (fala 5), po trzech zadaniach
`CRITICAL`, które budowały orkiestrację na założeniach z atrap. Błąd wyszedł po 4 godzinach pracy na tych założeniach.
Na serwerze wyszedłby po godzinach generowania `medium`.

### 5. Pozostałe

- **Usuwanie testów bez zamiennika (dwa razy BLOCKER).** DEMO-SCALE-9 usunęło 7 testów zachowań, które zostały
  w kodzie (4 w `test_load_script.py` wbrew kryterium 10 i 3 w `test_generate_script.py`). DEMO-SCALE-10 usunęło test
  „nieczytelny odczyt klastra to nie gotowy”. Reviewer złapał oba przypadki. Przyczyna: zlecenia przepisania mówiły
  „usuwasz kod” i „preferuj usuwanie”, a `developer.md` nie mówi, że test usuwa się tylko razem z zachowaniem.
- **Zadania zależne ruszyły od gałęzi poprzednika w trakcie jego przeglądu.** DEMO-SCALE-10 i 11 wystartowały
  z `task/DEMO-SCALE-9` (20:18), zanim DEMO-SCALE-9 przeszło przegląd FULL (CHANGES_REQUIRED) i drugą rundę. Test
  dymny DEMO-SCALE-10 trzeba było powtórzyć na nowej wersji, a ok. 10 miejsc dokumentacji ze starą kolejnością
  orkiestrator poprawiał ręcznie. „Przebieg epiku” (krok 2) każe zaczynać po merge'u zależności. Ten sam wzorzec
  wystąpił w ABUSE-LIMITS (obserwacja `serial-chain-start-from-predecessor`), więc to drugi epik.
- **Odrzucony SubagentHandback.** Developer DEMO-SCALE-9, wznowiony wiadomością na rundę poprawek, wysłał drugi
  SubagentHandback. Narzędzie go odrzuciło („one report per result”), więc raport rundy nie dotarł. Orkiestrator
  odczytał stan z gita i sam uruchomił `check`.
- **Brak punktu odniesienia i stałych sekcji opisu PR.** Nie wywołano `metrics.py start` ani `done`. Opis #788 nie
  ma sekcji z kroku 7 „Przebiegu epiku”: tabeli kryteriów akceptacji, „Ujawnienia TDD”, „Decyzji bez pytania”,
  „Problemów i zastojów”, „Luk w instrukcjach”. Zlecenie z sesji nadrzędnej odsyłało do „Przebiegu epiku”, ale
  wymieniało tylko część kroków. Skutek: brak rekordu metryk, `/retro` nie działa, a kryteria 1–12 nie są pokazane
  w jednym miejscu.
- **Poprawki DEMO-SCALE-10 bez przeglądu DELTA.** Orkiestrator sprawdził je sam, choć krok 4 wymaga DELTA po
  poprawkach. Zmiana dotyczyła testów, więc ryzyko było małe.
- **Odstępstwo od specyfikacji bez pytania.** DEMO-SCALE-4: `seed` bez progu wolnego miejsca, bo to jedyna droga
  naprawy po zatrzymaniu przez strażnika. Decyzja jest rozsądna i trafiła do specyfikacji. Główny `CLAUDE.md` każe
  jednak przedstawiać zmiany specyfikacji użytkownikowi. Sesja nadrzędna uznała ją za decyzję orkiestratora.
- **Pytania o postęp.** Użytkownik czterokrotnie pytał, jak idzie, i raz poprosił o przyspieszenie. Orkiestrator
  przyspieszył końcówkę DEMO-SCALE-12, uruchamiając `check`, przegląd i oba testy dymne równolegle.

## Odroczone do końca serii (propozycje poprawek procesu)

Żadna z tych zmian nie została wprowadzona. Wprowadzenie wymaga „tak” użytkownika wskazującego pozycję. Bez niego
czekają na retro trzeciego epiku serii.

| Nr | Poprawka | Pliki | Koszt | Ocena |
|---|---|---|---|---|
| F1 | Waga według gwarancji i współbieżność celów serwera | `code-reviewer.md`, `qa-adversarial.md`, ADR (kroki 4 i 6) | 4–6 linii | **zagrożenie** |
| F2 | Profil użycia, tryb odzyskiwania i proporcja w specyfikacji | `brainstorm/SKILL.md`, `architecture-reviewer.md` | 3–4 linie | nie |
| F3 | Wczesny test na prawdziwym klastrze w planach `infra/k8s` | `planner/SKILL.md` | 2 linie | nie |
| F4 | Budżet czasu CI | ADR (krok 5), `code-reviewer.md` | 2 linie | nie |
| F5 | Usuwanie testów tylko razem z zachowaniem | `developer.md` | 1–2 linie | nie |

**F1. Waga według naruszonej gwarancji; współbieżność celów serwera to zwykłe użycie.**

- **Zmiana:**
  - `code-reviewer.md` (punkt 7) i `qa-adversarial.md`: potwierdzona ścieżka naruszenia gwarancji nazwanej
    w specyfikacji ma wagę co najmniej `IMPORTANT`/`Important`, niezależnie od wyzwalacza;
  - te same pliki: dwa cele serwera albo dwie sesje agentów naraz to zwykłe użycie;
  - ryzyko przyjęte w specyfikacji dla jednej pary operacji nie obejmuje innej pary.
- **ADR:**
  - krok 6: uwagi `SUGGESTION`/`Minor` z przeglądu całości sesja ocenia przed odroczeniem tak samo jak w kroku 4;
  - zlecenie uproszczenia, które usuwa mechanizm ochronny, wymienia gwarancje, które ten mechanizm chronił, a reviewer
    sprawdza każdą.
- **Dlaczego zapobiegnie:** każda z trzech dróg odroczenia z problemu 2 (rubryka, zakotwiczenie, odroczenie po
  etykiecie) dostaje regułę, a usunięcie `check_record` wymagałoby sprawdzenia gwarancji zamknięcia.
- **Ocena: zagrożenie.** To drugi epik z rzędu z błędem łamiącym gwarancję, odroczonym jako SUGGESTION. Następny epik
  serii (ASYNC-RELIABILITY: kolejki, ponowienia, współbieżne workery) jest dokładnie w tym obszarze. Rozszerza #812 na
  krok 6 i na cele operacyjne.

**F2. Profil użycia, tryb odzyskiwania i proporcja w specyfikacji.**

- **Zmiana:**
  - `/brainstorm` (punkt 8): dla narzędzi operacyjnych i infrastruktury pyta wprost o profil użycia (kto, jak często,
    ilu operatorów) i tryb odzyskiwania (stan bezpieczny z ręczną drogą wyjścia albo automatyczne wznawianie),
    z rekomendacją „stan bezpieczny i ręczne odzyskiwanie”, gdy profil to rzadkie, ręczne uruchomienia;
  - zlecenie przeglądu specyfikacji podaje ten profil, a `architecture-reviewer` przy maszynie stanów, wznawianiu albo
    własnej blokadzie podaje szacunek rozmiaru (kod, testy, czas CI) i wariant minimalny.
- **Dlaczego zapobiegnie:** pytanie zadane 2026-11-19 o 09:46 zamiast o 18:14 dałoby od razu wariant minimalny.
  Przegląd złożoności z profilem użycia trwał 10 minut.
- **Ocena:** nie blokuje kolejnego epiku serii i nie jest zagrożeniem bezpieczeństwa ani danych. To największa strata
  czasu w tym epiku.

**F3. Wczesny test na prawdziwym klastrze w planach `infra/k8s`.**

- **Zmiana:** `/planner`: gdy epik buduje orkiestrację na prymitywach klastra (NetworkPolicy, Job z CronJoba, restart
  podów), pierwsza fala zawiera minimalny przebieg tych prymitywów na k3d, przed zadaniami `CRITICAL`, które na nich
  budują.
- **Dlaczego zapobiegnie:** „connection refused” wyszłoby w fali 1 zamiast w fali 5.
- **Ocena:** nie (seria nie ma epiku `infra/k8s`).

**F4. Budżet czasu CI.**

- **Zmiana:**
  - ADR (krok 5): część CI powyżej 2/3 limitu swojego joba traktuje się jak czerwone CI, czyli naprawia przed kolejną
    falą, bez obniżania pokrycia;
  - `code-reviewer`: wzrost czasu testów zadania o ponad 50% w części, której zadanie dotyczy, to `IMPORTANT`.
- **Dlaczego zapobiegnie:** ostrzeżenia z DEMO-SCALE-5 i 6 stałyby się pracą do zrobienia, a nie odroczoną uwagą.
- **Ocena:** nie.

**F5. Usuwanie testów tylko razem z zachowaniem.**

- **Zmiana:** `developer.md`: test usuwa się tylko razem z zachowaniem, które sprawdza; raport wymienia usunięte
  testy z przypisaniem „zachowanie usunięte” albo „zachowanie zostaje → test zastępczy”.
- **Dlaczego zapobiegnie:** dwie rundy BLOCKER z DEMO-SCALE-9 i 10 nie byłyby potrzebne.
- **Ocena:** nie (reviewer to łapie, koszt to jedna runda).

Bez propozycji poprawki, do decyzji użytkownika albo do oceny serii:

- **Zadania zależne przed merge'em poprzednika** (drugi epik z rzędu). Do wyboru:
  - egzekwować regułę z kroku 2;
  - albo wpisać wyjątek: start z gałęzi poprzednika w trakcie jego przeglądu tylko przy rozłącznych plikach
    i z obowiązkiem powtórzenia weryfikacji po zmianach poprzednika.
- **Raport rundy poprawek.** W zleceniu rundy poprawek wznowionego subagenta: „raport wyślij zwykłą końcową
  odpowiedzią”, bo drugi SubagentHandback jest odrzucany. Jedno zdanie w ADR (krok 4) albo w `developer.md`.
  Jedno wystąpienie.
- **Epik natywny spoza serii a pomiar.** Do rozstrzygnięcia, czy każdy epik natywny ma `metrics.py start` i stałe
  sekcje PR (wtedy zlecenie sesji nadrzędnej musi to wymieniać), czy tylko epiki serii. Dziś ADR mówi o każdym epiku,
  a DEMO-SCALE tego nie zrobił.

## Propozycje issues

Nie zostały założone. Czekają na „tak” użytkownika.

1. **[shop] nowe, `tech-debt`, `agent`:** „CI: część k8s trwa 13 min zamiast 6 po DEMO-SCALE (atrapa kubectl
   w test_generate_script)”.
   - Dowody: czasy z tabeli wyżej; na `6c54003b` część `k8s` (14,5 min) była najdłuższym jobem CI.
   - Kierunek: tańsza atrapa (jeden proces zamiast procesu na wywołanie), równoległe moduły testów albo osobny job,
     bez zmniejszania pokrycia.
   - Sprawdzone duplikaty: #578 (shard e2e), #646 (zakres CI): inne problemy.
2. **[shop] nowe albo komentarz w #484, `tech-debt`, `agent`:** „DEMO-SCALE: odroczone uwagi #788 do rewizji po
   przebiegu `medium`”. Zakres (lista „Odroczone drobne uwagi” w #788):
   - `wait_job` skraca czekanie do 3 h po nieudanym pierwszym odczycie;
   - komunikat przy zgubionej odpowiedzi na utworzenie Joba;
   - sonda liczy 403 z wyzwania Cloudflare jako „otwarty”;
   - przyczyna błędu `kc create` blokady jest ukryta;
   - funkcje tylko dla `load.sh` w `demo-data-lib.sh`;
   - podwójny warunek „klaster gotowy”;
   - kody wyjścia opisane w czterech miejscach;
   - rozjazd `deploy/spec.md` (restic `enabled: true`) z `prod/values.yaml` (`false`).

## Obserwacje

- `native-complexity-not-gated-before-implementation`: problem 1, propozycja F2.
- `severity-by-trigger-not-by-guarantee`: problem 2, propozycja F1. Drugi epik po ABUSE-LIMITS.
- `ci-time-near-limit-deferred`: problem 3, propozycja F4. Przekroczony limit na `b759792a` nie został odnotowany.
- `stub-only-verification-of-cluster-primitives`: problem 4, propozycja F3.
- `rewrite-deletes-tests-of-kept-behaviour`: dwa BLOCKER, propozycja F5.
- `dependent-task-started-before-predecessor-review`: drugi epik po ABUSE-LIMITS.
- `handback-refused-after-resume`: jedno wystąpienie.
- `native-epic-without-baseline`: brak `metrics.py start`/`done` i stałych sekcji opisu PR.
- `delta-review-skipped-after-fix-round`: DEMO-SCALE-10.
- Pamięć sesji użytkownika ma już wniosek z problemu 2 (`safety-findings-not-deferred`), ale pamięć nie trafia do
  subagentów `code-reviewer` i `qa-adversarial`. Bez F1 rubryka reviewerów się nie zmienia.

## Status

Commit `docs(agentic-software-house): update the status` w tym PR:

- przygotowane: DELIVERY etap 1 (#120) zamiast DEMO-SCALE (#484);
- ostatni zmergowany epik: DEMO-SCALE etap 1 (#786, PR #788), bez danych pomiaru;
- retro do zrobienia: brak;
- issues z etykietą `agent`: 123 otwarte, 75 zamkniętych.

Tabela serii bez zmian.

## Zużycie

Sesja retro: 2026-11-20 ok. 13:44 → 14:00 CET (ok. 16 min), Opus 5.5. Bez odczytu `get_usage` na starcie, więc bez przyrostu
procentu limitów.

## Decyzje optymalizacyjne

Brak: nie ma incydentów `optimization` ani danych pomiaru DEMO-SCALE.
