# Propozycja drugiego PR: zlecenie poprawek i dowód RED

Osobna od poprawki metryk ([`spec.md`](spec.md)), po pierwszej rundzie review Codexa (zmieniona zasada dla
ustaleń niepotwierdzonych w P1). Trzy propozycje ocenione na przebiegu epiku STORE-UX-FIXES
(sesja `2791e97b…`, 2026-11-22): zadanie 2, zadanie poprawkowe QA1 i zadanie CX2 (uwaga N2). Wynik: dwie małe
zmiany tekstu (P1, P3) i jedna propozycja bez zmiany (P2), którą w międzyczasie zamknął #868. Żadna nie dodaje
agenta, narzędzia, rundy przeglądu ani testu i żadna nie zmienia doboru modeli.

Stan instrukcji sprawdzony na `main` = `480850f5`, czyli po retrospektywach STORE-UX-FIXES (#862)
i ADMIN-UX-FIXES (#864) oraz po #868 (sprawdzone odroczenia, CI dokładnego commita, czas CI po epiku). Retro #862
dopisało do kroku 4 „Przebiegu epiku”, co jest dowodem RED po stronie sesji i tabeli kryteriów, a #868 podstawę
oceny przed odroczeniem; tego nie powtarzam. #868 nie zmienia zdań o zleceniu poprawek i zleceniu przeglądu
w kroku 4 ani plików agentów `developer` i `code-reviewer`, więc zmiany 1–4 odnoszą się do aktualnego tekstu.

## Przebieg, na którym oceniam

| Czas (UTC) | Zdarzenie |
|---|---|
| 17:29 | Zadanie 2, przegląd FULL: W1 potwierdzone, W2 „niepotwierdzone” (powrót z kasy przy bfcache) |
| 17:29 | Zlecenie poprawek: „Decyzja sesji, jak naprawić W2”: przejście programowym `click()` na linku |
| 17:43 | DELTA: W1 i W2 naprawione, N1 `IMPORTANT`, „regresja tej rundy” (zamiar ginie, gdy linku nie ma w DOM); N2 `SUGGESTION` (pola odblokowane ok. 110 ms) |
| 17:43 | Zlecenie: „Decyzja sesji, jak naprawić N1 (i przy okazji N2)”: stan `opening` do odmontowania strony |
| 17:53 | DELTA: N1 i N2 naprawione, N3 `IMPORTANT`, regresja stanu `opening` (blokada zostaje na stałe) |
| 17:53 | Zlecenie: „Decyzja sesji (najprostszy wariant)”: bez blokady; „N2 odraczam z oceną skutku” |
| 18:03 | DELTA: `PASS` |
| 18:25 | QA1 po przeglądzie całości: „Decyzja sesji”: komunikat pozycji nie znika na starcie operacji |
| 19:06 | QA1, przegląd FULL: skrypt QA na czole poprawki nadal gubi kliknięcie 2 z 2; test E2E wykonawcy omijał ten przypadek |
| 19:26 | QA1, DELTA po drugiej rundzie: `PASS` |
| 19:49 | Review Codexa: uzasadnienie odroczenia N2 opiera się na założeniu, że stara kasa dostanie 409 |
| 19:51 | CX2: „Krok 1: próba”, poprawka tylko po potwierdzeniu, z gwarancją ze specyfikacji i warunkiem zatrzymania |
| 20:08 | CX2: przebieg potwierdzony (zamówienie ze starą ilością, nowy PATCH 404, bez 409), poprawka |
| 20:13 | Przegląd CX1 i CX2: `PASS` w pierwszej rundzie |

## P1. Zlecenie poprawek: dowód i gwarancja, sposób naprawy jako hipoteza

**Co już jest.**

- Krok 4: „Zlecenie poprawek przekazuje ustalenia reviewera; to, co sesja dodaje od siebie (własna reguła,
  zawężenie albo rozszerzenie poprawki), oznacza jako decyzję sesji w zleceniu poprawek i w zleceniu przeglądu.”
- Agent `developer`, punkt 7: „Gdy dostaniesz potwierdzone uwagi review/CI/integracji, napraw je we wskazanym
  zakresie, dodaj test regresyjny”. Punkt 6: istotne niezgodności zgłasza z opcjami, rekomendacją i dowodami,
  „nie zgaduj”.
- Agent `code-reviewer`, punkt 7: zgłoszenie opiera się o „sposób odtworzenia lub dowód”, a ustalenie bez
  potwierdzonego wpływu ma oznaczenie „niepotwierdzone” z tym, co by je rozstrzygnęło.
- Krok 4, „Ocena przed odroczeniem”: istotne podejrzenie sesja sprawdza „w kodzie albo eksperymentem”.

**Co się stało.**

- Zasada o oznaczaniu dodatków była wykonana: każde zlecenie nazywało sposób naprawy „Decyzją sesji”, a reviewer
  oceniał ją w DELTA.
- W zadaniu 2 dwie z trzech rund poprawek naprawiały regresję sposobu naprawy z poprzedniego zlecenia (N1 po
  `click()`, N3 po stanie `opening`). Wykonawca realizował podany mechanizm; żadne zlecenie nie mówiło, że może go
  podważyć, ani nie podawało gwarancji, którą mechanizm ma zachować na ścieżkach, które zmienia.
- W2 reviewer oznaczył „niepotwierdzone w silnikach Playwright” i uzasadnił ścieżką w kodzie (pełne wczytanie
  strony, odpowiedź `/cart` bez `no-store`, powrót z bfcache). Zlecenie kazało je naprawić. Sam brak reprodukcji
  nie był tu błędem: punkt 7 agenta `developer` mówi jednak tylko o uwagach potwierdzonych, więc wykonawca nie miał
  zasady, co zrobić z niepotwierdzoną.
- W QA1 zlecenie podało ścieżkę skryptu QA, ale test wykonawcy był „oparty na skrypcie” i omijał przypadek, na
  którym skrypt dalej nie przechodził. Wykonawca nie uruchomił skryptu QA na swoim czole; zrobił to reviewer.
- W CX2 zlecenie miało inny kształt (próba jako pierwszy krok, gwarancja ze specyfikacji, warunek zatrzymania)
  i przegląd przeszedł w pierwszej rundzie.

**Ocena.** Brakuje doprecyzowania w trzech miejscach: status sposobu naprawy podanego przez sesję, postępowanie
z ustaleniem niepotwierdzonym po stronie wykonawcy i uruchomienie sondy reviewera przed oddaniem. To obserwacja
z jednego epiku (dwa zadania z dodatkowymi rundami, jedno bez); związek kształtu zlecenia z liczbą rund jest
hipotezą. Zlecenia poprawek zadań 1 i 5, które przeszły w jednej rundzie, nie były czytane.

Zasada dla ustalenia niepotwierdzonego nie wymaga reprodukcji bezwzględnie. Błąd wyścigu, awarii albo zachowania
przeglądarki, którego narzędzie testowe nie odtwarza (W2), może potwierdzić konkretna ścieżka w kodzie. Brak
reprodukcji sam ustalenia nie obala; bez żadnego dowodu wykonawca zgłasza wynik sprawdzenia i niepewność.

**Proponowana zmiana 1** (`docs/adr/agentic-software-house.md`, krok 4, po zdaniu „Zlecenie poprawek przekazuje
ustalenia reviewera; …”):

> Dla każdego ustalenia zlecenie podaje jego dowód (reprodukcję albo ścieżkę w kodzie; sondę albo skrypt
> reviewera, jeśli jest) i gwarancję, którą poprawka ma przywrócić (specyfikacja, lista jakości, kontrakt). Sposób
> naprawy, który sesja proponuje, jest hipotezą do sprawdzenia, nie zakresem.

**Proponowana zmiana 2** (`.claude/agents/developer.md`, punkt 7, po pierwszym zdaniu):

> Punktem odniesienia jest dowód i gwarancja z ustalenia, nie sposób naprawy podany w zleceniu: sprawdź go na
> ścieżkach, które zmienia (przerwanie, błąd, powtórzenie), a gdy nie domyka gwarancji albo wprowadza stan, który
> może utknąć, zgłoś to z rekomendacją, zanim go zrealizujesz. Ustalenie oznaczone „niepotwierdzone” najpierw
> zweryfikuj reprodukcją albo dowodem w kodzie; brak reprodukcji sam go nie obala. Gdy nie masz ani jednego, ani
> drugiego, zgłoś, co sprawdziłeś, wynik i niepewność, zamiast zmieniać zachowanie na samo podejrzenie. Przed
> oddaniem uruchom na swoim czole sondę albo skrypt reviewera, jeśli zlecenie go podaje, i podaj wynik. Test
> regresyjny obejmuje naprawiane zachowanie, nie jego wariant.

Zgodność z istniejącymi zasadami: zmiana 1 nie powtarza „Oceny przed odroczeniem” (tam sesja sprawdza podejrzenie
przed własną decyzją; tu zlecenie przekazuje dowód dalej). Zmiana 2 nie powtarza obowiązku testu regresyjnego
z pierwszego zdania punktu 7, tylko mówi, co test ma obejmować, i rozszerza punkt o uwagi niepotwierdzone, których
dziś nie dotyczy. Zgłoszenie niepewności korzysta z istniejącej drogi z punktu 6.

## P2. Sprawdzenie istotnego podejrzenia przed odroczeniem: bez zmiany, zamknięte przez #868

**Co już jest.** Krok 4, „Ocena przed odroczeniem”, w brzmieniu po #868:

- ocena kończy się podstawą: „sprawdzone: <próba albo miejsce w kodzie i wynik>” albo „założenie niesprawdzone:
  <jakie>”;
- do niesprawdzonych założeń należy „własne założenie sesji o skutku”, z przykładem „stara kasa dostanie 409”;
- ocena z podstawą „założenie niesprawdzone” nie stwierdza braku skutku: uwaga zostaje do sprawdzenia i trafia do
  przeglądu epiku jako scenariusz do wykonania, bez oceny sesji i bez oczekiwanego wyniku (`qa-adversarial` podaje
  wynik każdego scenariusza);
- „Wąskie okno wyścigu nie obniża wagi” (bez zmian).

Te same zasady są w `docs/features/ash-process-fixes/spec.md` (O3, O6, O7).

**Co się stało.** N2 opisywało konkretną ścieżkę (pola aktywne między kliknięciem a zmianą strony). Sesja odroczyła
je w zleceniu wysłanym 19 s po raporcie z 17:53, więc bez próby. Ocena skutku w opisie PR brzmiała: wpis
zatwierdzony nie przepada, a kasa ze starą ilością dostaje 409 (cytat za zleceniem CX2 i review Codexa; opisu PR
nie czytałem). Zmierzone lokalnie 110 ms posłużyło za miarę okna. Próba z CX2 pokazała zamówienie ze starą ilością
bez 409.

**Ocena.** Nie proponuję zmiany. Przypadek N2 jest dokładnie tym, który #868 opisuje i zamyka: ocena musiałaby
dostać podstawę „założenie niesprawdzone: stara kasa dostanie 409”, a uwaga poszłaby do QA jako scenariusz.
W pierwszej wersji tego dokumentu oceniłem, że wystarczały zasady sprzed #868 i że był to wyłącznie problem
wykonania; #868 rozstrzygnął to inaczej, dodając podstawę oceny, i ta propozycja niczego do tego nie dokłada.
Skuteczność O6 i O7 pokaże kolejny epik (liczba znalezisk zewnętrznego review, które powtarzają odroczoną uwagę,
liczona w `/retro` po #868).

Uwaga do P1: zmiana 2 obejmuje sąsiedni przypadek, którego „Ocena przed odroczeniem” nie dotyczy, czyli pracę
wykonawcy nad ustaleniem niepotwierdzonym (W2). Jej sformułowanie „reprodukcją albo dowodem w kodzie” odpowiada
podstawie „sprawdzone: <próba albo miejsce w kodzie i wynik>” z #868.

## P3. Reviewer dostaje zapis uruchomienia RED; późniejsze odtworzenie to inny dowód

**Co już jest.** Krok 4 (po #862): „RED przed implementacją potwierdza tylko zapis uruchomienia: dziennik dowodów
albo transkrypt subagenta, w którym RED jest przed pierwszą zmianą pliku produkcyjnego. Bez takiego zapisu sesja
pisze w »Ujawnieniu TDD«, na czym oparła ocenę”; uruchomienie na kodzie bazowym i kolejność commitów nie są fazą
RED. Agent `code-reviewer`, punkt 4: kontrola „RED przed implementacją” opiera się na dzienniku dowodów, a „Bez
dziennika oceń przebieg TDD z kolejności commitów (…) i z raportu developera i napisz, że dziennika nie było”.
Zlecenie przeglądu podaje worktree, `head_sha` i `base_sha`.

**Co się stało.**

- Epik natywny nie ma dziennika dowodów, więc reviewer zawsze trafia w gałąź „bez dziennika”.
- Zadanie 2, FULL: „Oceniam z kolejności commitów i raportu. (…) `CartPage.tsx` jest równy bazie, więc RED jest
  prawdziwy.” Według kroku 4 kolejność commitów tego nie dowodzi.
- QA1, FULL: reviewer uruchomił testy na eksporcie commitu z samymi testami i dla dwóch grup napisał „RED jest
  prawdziwy”. To uruchomienie po fakcie na starej rewizji: potwierdza, że test wykrywa błąd.
- RED uzyskany w QA1 przez tymczasowe wyłączenie gotowego kodu (`if (false && …)`) był znany tylko z ujawnienia
  wykonawcy.
- Zapis uruchomień istniał. W transkrypcie wykonawcy zadania 2 w każdym z czterech zleceń niepowodzenie testów
  jest przed pierwszym poleceniem zmieniającym `CartPage.tsx`: 16:55:49 (13 testów) przed 17:00:43; 17:31:39
  i 17:32:19 (6 i 4) przed 17:33:19; 17:44:13 (2) przed 17:44:54; 17:54:25 (1) przed 17:54:58. Sprawdzone
  przeszukaniem poleceń transkryptu, nie pełną lekturą. Nikt nie wskazał tego zapisu reviewerowi.
- Wykonawca zmieniał plik produkcyjny skryptem uruchamianym przez Bash, więc szukanie samych wywołań `Edit` nie
  wystarcza.

**Ocena.** Brakuje doprecyzowania po stronie reviewera i zlecenia przeglądu. ADR i plik agenta są niespójne: sesja
ma rozróżniać trzy rodzaje dowodu, a reviewer dalej ocenia z kolejności commitów i nazywa wynik „prawdziwym RED”.
Podstawa to jeden epik; wcześniejszych epików pod tym kątem nie sprawdzałem. Czy reviewer może czytać
`~/.claude/projects` w swoim środowisku, jest założeniem do sprawdzenia w próbie (niżej).

**Proponowana zmiana 3** (`docs/adr/agentic-software-house.md`, krok 4, zdanie o zleceniu przeglądu):

> zlecenie podaje worktree zadania jako katalog przeglądu, `head_sha` i `base_sha` (`git merge-base task/<ID>
> <gałąź epiku>`) oraz ścieżkę transkryptu wykonawcy jako zapis uruchomień testów.

**Proponowana zmiana 4** (`.claude/agents/code-reviewer.md`, punkt 4, w miejsce zdania „Bez dziennika oceń …
i napisz, że dziennika nie było.”):

> Bez dziennika zapisem uruchomień jest transkrypt wykonawcy wskazany w zleceniu. Znajdź w nim uruchomienie RED
> z raportu (komenda i linia niepowodzenia) i sprawdź, że jest przed pierwszą zmianą pliku produkcyjnego tego
> zachowania, także zmianą skryptem albo `sed`. Wynik kontroli podaj jednym z trzech określeń: „RED potwierdzony
> zapisem uruchomienia”; „RED niepotwierdzony: tylko kolejność commitów i raport” (brak transkryptu albo brak
> takiego uruchomienia); „test wykrywa błąd na kodzie bazowym” (uruchomienie po fakcie na `base_sha` albo na
> commicie z samym testem, także Twoje). Dwa ostatnie nie są fazą RED i nie nazywaj ich prawdziwym RED. Zapis,
> w którym kod produkcyjny powstał przed testem, to RED odtworzony po fakcie.

Waga się nie zmienia: RED odtworzony po fakcie albo brak RED to dalej `IMPORTANT`, a „niepotwierdzony” jest
wynikiem kontroli, który sesja ujawnia w „Ujawnieniu TDD”.

## Zakres i weryfikacja PR 2

| Plik | Zmiana |
|---|---|
| `docs/adr/agentic-software-house.md` | zmiany 1 i 3 (krok 4 „Przebiegu epiku”) |
| `.claude/agents/developer.md` | zmiana 2 (punkt 7) |
| `.claude/agents/code-reviewer.md` | zmiana 4 (punkt 4) |

`docs/features/ash-process-fixes/spec.md` nie ma sekcji o zleceniu poprawek ani o dowodzie RED (zasady z #862 są
tylko w ADR), więc PR 2 go nie zmienia.

- `make check` (zakres dokumentacji i narzędzi repo).
- Próba zmiany 4 przed merge'em: jeden przebieg `code-reviewer` w świeżym kontekście, tylko kontrola „RED przed
  implementacją”, na dwóch przypadkach z historii: zadanie 2 (oczekiwane „potwierdzony zapisem uruchomienia”)
  i dwa testy „while a pointer is pressed” z QA1 (oczekiwane „odtworzony po fakcie”, bo kod powstał przed testem).
  Drugi przypadek sprawdza próbę obejścia. Próba pokaże też, czy reviewer ma dostęp do transkryptu.
- Zmian 1 i 2 nie da się sprawdzić testem. Ocena po kolejnym epiku w `/retro`: ile rund poprawek naprawiało
  regresję sposobu naprawy podanego w zleceniu i czy sonda reviewera była uruchomiona przed oddaniem. Bez progu
  z góry.
- PR mergowany między epikami. Po merge'u aktualizacja głównego checkoutu, bo sesje desktopowe ładują agentów
  stamtąd; `metrics.py process` pokaże rozbieżność.

## Czego nie proponuję

- Dziennika dowodów dla drogi natywnej (`delivery_state.py` należy do zamrożonego `/delivery`), hooków, nowego
  agenta ani skryptu sprawdzającego transkrypt.
- Zmiany budżetu rund, wag ustaleń i zasad odraczania (O1–O7 po #868 zostają bez zmian).
- Reguły do P2.
