# Audyt natywnego workflow ASH: skuteczność, koszt i czas (2026-11-24)

Audyt pięciu epików natywnych z wersji procesu `ash-v1.1` i `ash-v1.2`: API-INPUT-CONTRACT etap 1 (PR #843),
ADMIN-UX-FIXES etap 1 (#855), STORE-UX-FIXES etap 1 (#858), CART-ORDER-CONSISTENCY (#894) i ADMIN-UX-FIXES etap 2
(#896). Rewizja: `origin/main` `c617e788`. Czasy w UTC. Raport niczego nie zmienia w procesie; decyzja należy do
właściciela. Obserwacje (z zapisów) są oddzielone od hipotez (co zmiana dałaby w przyszłości).

## 1. Werdykt

1. **Końcowy PR.** Pierwsze zewnętrzne review znalazło potwierdzony problem w każdym z pięciu epików: razem 8
   potwierdzonych na 10 zgłoszonych. Cztery z ośmiu powtarzają uwagę, którą wewnętrzny przegląd zauważył, a sesja
   prowadząca odroczyła. Trzy z czterech pozostałych to kolejne wystąpienie klasy błędu naprawionej wcześniej
   w jednym miejscu. Wykrywanie działa; zawodzi rozstrzyganie odroczeń i uogólnianie poprawki.
2. **Przygotowanie.** Zewnętrzne uwagi padły w każdym z pięciu przygotowań. Większość wskazywała lukę widoczną
   w dokumencie i kodzie w chwili przeglądu (obietnica szersza niż mechanizm, założenie niesprawdzone w kodzie),
   a nie nowe wymaganie. W czterech przygotowaniach projekt trafił do właściciela bez żadnego odczytu w świeżym
   kontekście. Kolejne rundy dotyczyły głównie treści dopisanej przez autora w odpowiedzi na poprzednią rundę.
3. **Czas.** Praca agentów to 2,4–6,0 h na epik. Czas od startu do gotowości (5,2–19,1 h) wyznaczają noce:
   czekanie na decyzję albo zewnętrzne review (API 13,3 h, CART 10,6 h) i uśpienie komputera (ADMIN-UX-2 10,2 h).
4. **Koszt.** 41–151 USD wartości tokenów na epik (cennik API, nie rachunek). Sesja prowadząca na Fable kosztowała
   48 USD przy liczbie tokenów zbliżonej do sesji na Opusie za 21 USD. Oba epiki v1.2 wystartowały przy 89–90%
   tygodniowego okna i doszły do 97–100%; w CART sesja sama wiąże z tym jedno błędne odroczenie.
5. **Rekomendacja do testu:** niezależna ocena odroczeń w świeżym kontekście przed przekazaniem PR do zewnętrznego
   review (sekcja 6, zmiana 1; plan oceny w sekcji 7).

## 2. Zakres, dane i braki

Źródła: rekordy METRICS (`~/.local/state/shop-metrics/epics/*.json`), pliki przebiegu, raporty retro
(`~/.local/state/shop-retro/<epik>/report.md`), transkrypty dziesięciu sesji (pięć przygotowań, pięć realizacji; CART
miał jedną sesję na oba etapy), opisy PR #894 i #896 przez bramkę pochodzenia, lokalna telemetria (Prometheus,
`claude_code_cost_usage_USD_total`), kod i specyfikacja na `64a134a4`.

Zewnętrzne review robił Codex (#843, #855, #858, #894 i przygotowania ADMIN-UX-FIXES 1 oraz STORE-UX-FIXES) albo
ChatGPT (#896 i przygotowania CART oraz ADMIN-UX-2). Liczę je razem jako „zewnętrzne review”.

Braki, które ograniczają wnioski:

- **Surowa treść zewnętrznych review końcowych PR.** W sesjach są tylko zlecenia poprawek wklejone przez
  właściciela, nie sam wynik review. Dotyczy: #843 (`edf5b2b5`), #855 (`78cc3ec0` i `78cc3ec0..8f953a9c`), #858
  (`98f6fd47` i zmiany do `ac876199`), #894 (`050e830f`), #896 (`64a134a4`). Nie wiem, czy review zawierały
  ustalenia pominięte przed zleceniem.
- **Źródło uwag w przygotowaniu API-INPUT-CONTRACT.** Dwie rundy (cztery warunki przy akceptacji kierunku; cztery
  doprecyzowania przekazane z innej sesji jako „wiadomość właściciela”) nie mają oznaczenia ChatGPT. Oznaczona jest
  tylko trzecia: jedna uwaga (TDD w planie), która przypomina obowiązującą regułę i nie jest luką. Tak samo
  nieoznaczona jest pierwsza runda w ADMIN-UX-FIXES 1 (5 punktów) i STORE-UX-FIXES (6 punktów). Rundy nieoznaczone
  opisuję, ale nie wliczam do punktu odniesienia; oznaczona runda API wchodzi do niego jako 1 runda i 0 luk.
- **Ponowne zewnętrzne review poprawek CX1** w #894 i #896: w sesjach go nie ma. Przeglądy bez nowych ustaleń mam
  tylko dla #855 i #858 (końcowy PR) oraz dwóch ostatnich rund przygotowania ADMIN-UX-FIXES 1.
- **Pomiary.** `ci_wait` i `limit_wait` są puste we wszystkich pięciu rekordach, `effort` subagentów nie jest
  zapisany. Odczyt limitów STORE-UX-FIXES jest nieczytelny, a w obu epikach v1.2 odczyt końcowy jest niższy od
  startowego (89→59%), więc zużycia okna w punktach procentowych nie da się policzyć. Przygotowanie epiku nie ma
  rekordu METRICS; czas biorę z transkryptów, koszt z telemetrii (cała sesja, także po przygotowaniu).
  `human_wait` zawiera postoje przez limit (#905). Werdykt `ux-reviewer` nie jest rozpoznawany.
- **Próba.** Pięć epików o różnym zakresie i ryzyku; liczby to obserwacje, nie rozkład.

## 3. Uwagi podczas przygotowania

Klasyfikacja jest moja, z treści uwagi i odpowiedzi autora. „Luka” znaczy: dało się ją wykryć z dokumentu i kodu
dostępnych w chwili przeglądu. „Nowa treść”: luka w mechanizmie dopisanym przez autora w odpowiedzi na wcześniejszą
uwagę. Autorem wszystkich pięciu przygotowań był Fable.

| Epik | Co oceniano | Odczyt w świeżym kontekście przed właścicielem | Rundy zewnętrzne z uwagami | Uwagi (luka / nowa treść / decyzja lub preferencja) |
|---|---|---|---|---|
| API-INPUT-CONTRACT | propozycja zakresu po 16 min, potem specyfikacja | brak | 2 nieoznaczone + 1 ChatGPT | nieoznaczone 6 / 0 / 2; ChatGPT 0 / 0 / 1 |
| ADMIN-UX-FIXES 1 | propozycja, potem spec i plan (`9e5e1cae`) | Sonnet po pierwszej rundzie: 2 blokujące, 7 ważnych | 1 nieoznaczona + 2 Codexa, potem 2 bez uwag | nieoznaczona 3 / 0 / 2; Codex 3 / 1 / 1 |
| STORE-UX-FIXES | propozycja D1–D8, potem projekt | brak przed akceptacją (przegląd spec i planu dopiero po niej) | 1 nieoznaczona + 2 Codexa | nieoznaczona 3 / 0 / 3; Codex 0 / 7 / 0 |
| CART-ORDER-CONSISTENCY | spec i plan po 10 min | brak; sesja nie przeczytała `brainstorm/SKILL.md` | 1 ChatGPT | 1 / 0 / 0 |
| ADMIN-UX-FIXES 2 | propozycja po 7 min | brak | 3 ChatGPT | 5 / 4 / 0 |

Najważniejsze uwagi od wersji ocenianej do decyzji:

- **CART, D6 (konto A → B).** Cel specyfikacji mówił „ten sam kupujący”, gwarancja już tylko „ten sam rodzaj
  kupującego”. Autor pokazał to jako decyzję D6 z rekomendacją „poza zakresem” i uzasadnieniem, że wykrycie wymaga
  dodatkowego odczytu `/api/v1/me`. Nie sprawdził tego: `sub` jest w sesji sklepu. Luka wykrywalna; właściciel
  przyjął poprawione D6.
- **ADMIN-UX-2, B4, runda 1 (5 uwag).** Autor sam zapisał utratę zmian po odblokowaniu jako „ryzyko, które
  przyjmuję”, a tezę „ponowienie nie tworzy duplikatu” jako „wniosek z opisów kontrolerów, nie z próby”. Obie
  informacje były w ocenianym tekście. Rundy 2 i 3 (4 uwagi) dotyczyły reguły zgodności szkicu, którą autor dopisał
  w odpowiedzi; runda 3 znalazła w niej błąd (porównanie z zapisem A zamiast z aktualnym szkicem).
- **STORE, R4.** Autor w odpowiedzi na uwagę dopisał blokadę Web Locks z obietnicą „zamówienie nigdy nie wychodzi
  przed zapisem… z dowolnej karty”. Kolejna runda wykazała, że mechanizm tego nie zapewnia. R4 odroczono; stało się
  osobnym epikiem (CART).
- **ADMIN-UX-FIXES 1, K2.** Wewnętrzny recenzent zgłosił brak górnej granicy blokady. Autor poprawił i napisał
  „poprawionej wersji nie przeglądałem drugi raz”. Codex znalazł, że poprawka ogranicza liczbę prób, a nie czas
  wiszącego `GET`.
- **API-INPUT-CONTRACT.** Obietnica „każda operacja” przy próbie, która nie objęła 12 operacji `/me`; sprzeczność
  „żadnego tekstu angielskiego” z pozostawionym angielskim `title`. Wymóg „test ma dojść do walidacji badanego pola”
  wrócił na końcowym PR jako jedyne ustalenie Codexa (test webhooka kończył się na podpisie).

Wzorce (obserwacje):

1. Dominująca klasa to obietnica szersza niż mechanizm albo teza o kodzie bez sprawdzenia. Reguły to już obejmują
   (`/brainstorm`, kroki 4, 10 i 11); nikt poza autorem nie sprawdza ich przed właścicielem.
2. Co najmniej w trzech z pięciu przygotowań autor sam oznaczył słabe miejsce („przyjmuję ryzyko”, „nie z próby”, „poza
   zakresem”) i zostawił je w rekomendacji. To nie brak wiedzy, tylko obrona własnego projektu; tak też ocenił to
   autor w ADMIN-UX-2.
3. Treść dopisana w odpowiedzi na uwagę idzie do właściciela bez żadnej kontroli i jest przedmiotem następnej
   rundy (12 z 23 uwag z review oznaczonych jako Codex albo ChatGPT).
4. Uwagi, które były nowym wymaganiem albo preferencją, to mniejszość: rozmiar rozwiązania testowego, zakaz ogólnej
   kolejki akcji, kolejność odroczeń, przypomnienia o TDD.
5. Luki specyfikacji wracają później. Według analiz sesji trzy z czterech potwierdzonych ustaleń na końcowych PR
   v1.2 miały udział specyfikacji: sztywny tekst Z3, brak wiersza w macierzy (a)–(l), sprzeczność B2 z listą „poza
   zakresem”. Trzy rundy ChatGPT nad specyfikacją ADMIN-UX-2 tych luk nie usunęły.

## 4. Uwagi po końcowym review ASH

W #894 i #896 zewnętrzne review oceniło tę samą rewizję, na której skończył się wewnętrzny przegląd z poprawkami
(`050e830f`, `64a134a4`); po nim nie było zmian. Dla trzech PR v1.1 przyjmuję to z treści zleceń, bez sprawdzenia.

| PR | Ustalenie | Skutek dla użytkownika | Czy istniało i kto je widział przed zewnętrznym review | Kategoria |
|---|---|---|---|---|
| #843 | test błędnego JSON webhooka nie dochodzi do dekodowania | brak (luka pokrycia testu) | uwaga T1 S3 z przeglądu zadania; odroczona | powtarza odroczoną |
| #855 | zmiany wpisane w trakcie ręcznego „Odśwież” promocji przepadają | utrata wpisanych danych | nikt; QA miało „Odśwież” w zleceniu i sprawdziło wynik po limicie, bez pisania w trakcie (z fragmentu raportu) | nowe (klasa znana z retro DELIVERY, reguła objęła tylko zapis) |
| #858 | fokus ginie po ręcznym sprawdzeniu płatności, gdy aktywny link znika | fokus na `body` | nikt; oba zachowania miały testy osobno | nowe |
| #858 | N2: odblokowany koszyk w trakcie wejścia do kasy | zamówienie starej ilości | zgłoszone jako SUGGESTION; odroczone na 110 ms i założeniu „stara kasa dostanie 409” | powtarza odroczoną |
| #894 | odrzucenie ponowienia zdejmuje ostrzeżenie o próbie w toku | „Nie złożyliśmy zamówienia” przy złożonym zamówieniu | ostatni przegląd różnicy („wymaga czterech zbiegów”); odroczone przy limicie 97% | powtarza odroczoną |
| #894 | `cart-changed` i `order-total-changed` ignorują niepewną próbę z innym kluczem | jak wyżej, zwykła ścieżka | nikt; klasa naprawiona wcześniej tylko dla zmiany kupującego | nowe (klasa) |
| #896 | wysyłka zdjęcia w trakcie odczytu stanu produktu | niżej | wykonawca, `code-reviewer` (S2), QA (M2, odtworzone); odroczone | powtarza odroczoną |
| #896 | zapis B kasuje nieznany wynik zapisu A | znika ostrzeżenie, choć A mógł się wykonać | nikt; `clearNotices()` poprawione wcześniej w jednym wywołaniu | nowe (klasa) |
| #896 | nieaktualny przycisk odrzucenia po „Sprawdź stan” | brak | nieosiągalne: wersja serwera rośnie monotonicznie | fałszywy alarm |
| #896 | ponowna publikacja po nieznanym wyniku | brak | QA sprawdziło wcześniej (409), było w opisie PR | duplikat sprawdzonej uwagi |

Suma: 10 zgłoszonych, 8 potwierdzonych (4 nowe, 4 powtarzają odroczoną uwagę), 2 bez poprawki: jedno nieosiągalne
i jeden duplikat uwagi sprawdzonej wcześniej przez QA. Ponowne review
poprawek w #855 i #858: bez nowych ustaleń. Żadne ustalenie nie wynikało ze zmiany wprowadzonej po wewnętrznym
przeglądzie.

**Wysyłka zdjęcia a odczyt stanu (#896).**

- *Trafność:* trafne. Na `64a134a4` przyciski „Sprawdź stan”, „Odrzuć moje zmiany…” i „Spróbuj ponownie” wyłączał
  tylko `reading` (`FormSafetyNotices.vue`, pięć miejsc), nie trwająca wysyłka. Specyfikacja B2 (wiersz 431) wymienia
  zdjęcia z „dodawaniem plików” wśród rzeczy zablokowanych na czas odczytu, a lista „Poza zakresem etapu 2” (wiersz
  579) zawiera „wysyłanie zdjęć”. Specyfikacja była więc wewnętrznie sprzeczna. QA odtworzyło przebieg w przeglądarce;
  przed poprawką nie przechodziło 12 nowych testów.
- *Waga:* P1 z review jest zawyżone co do skutku. Nic nie zapisuje się samo („Zapisz” jest nieaktywne w trakcie
  wysyłki). Zdjęcie ginie ze szkicu, który pracownik właśnie odrzuca, albo dopisuje się do przyjętego stanu
  (formularz znów pokazuje niezapisane zmiany). Po zgodnym „Sprawdź stan” komunikat „Stan na serwerze odpowiada
  formularzowi” przestaje być prawdą. Skutek jest widoczny i odwracalny. Według reguły projektu (złamana gwarancja
  specyfikacji) uwaga i tak szła do poprawki przed merge'em; etykiety SUGGESTION i Minor były za niskie, P1 za
  wysokie.
- *Dlaczego przeszło:* trzy osobne zapisy tej samej uwagi (wykonawca 19:50, reviewer 19:58, QA 21:26). Sesja
  dwukrotnie oceniła skutek i odroczyła z podstawą „poza zakresem: wysyłanie zdjęć”, bez zestawienia z B2. Reguła
  „etykieta zakresu nie jest podstawą odroczenia” weszła dopiero po epiku (#913).

**Przyczyny (obserwacje):**

- Odroczenie rozstrzyga sama sesja prowadząca, na końcu epiku, po ostatnim wewnętrznym przeglądzie. W trzech
  z czterech powtórzeń (#858, #894, #896) nikt tej decyzji już nie oglądał przed zewnętrznym review.
- Reviewerzy też zaniżali wagę (rzadkość przeplotu, etykieta zakresu), mimo definicji „waga według skutku”.
- Ta sama luka była głównym wnioskiem retrospektywy serii v1.0 (3 z 4 błędów Codexa). Od tego czasu regułę oceny
  przed odroczeniem dopisywano i rozszerzano czterokrotnie (#834, #868, #904, #913), a wzorzec wystąpił w czterech
  z pięciu kolejnych epików. Skuteczność #904 i #913 nie jest jeszcze sprawdzona żadnym epikiem.
- Klasa zamiast wystąpienia: reguła z #904 (poprawka obejmuje każde miejsce łamiące tę samą gwarancję) weszła po
  starcie obu epików v1.2 i też czeka na sprawdzenie.

## 5. Czas i limit

| Epik (proces) | Zadania | Start → gotowy | Praca | Czekanie na człowieka | Inne | Wartość (USD) | Prowadząca / wykonawcy / przeglądy |
|---|---|---|---|---|---|---|---|
| API-INPUT-CONTRACT (v1.1) | 5 + 1 | 19,1 h | 2,7 h | 14,2 h | 2,2 h nieprzypisane | 47,5 | 12,5 / 20,0 / 15,0 |
| ADMIN-UX-FIXES 1 (v1.1) | 7 | 5,5 h | 2,4 h | 1,1 h | 2,0 h nieprzypisane | 40,9 | 21,0 / 10,8 / 9,1 |
| STORE-UX-FIXES (v1.1) | 8 | 5,2 h | 4,2 h | 0,3 h | 0,7 h nieprzypisane | 85,9 | 20,9 / 40,5 / 24,4 |
| CART-ORDER-CONSISTENCY (v1.2) | 4 | 17,8 h | 4,6 h | 12,7 h | 0,5 h nieprzypisane | 151,3 | 48,2 / 73,2 / 29,9 |
| ADMIN-UX-FIXES 2 (v1.2) | 4 | 17,8 h | 6,0 h | 0,4 h | 10,2 h uśpienie, 1,3 h nieprzypisane | 98,7 | 27,8 / 26,4 / 44,6 |

„Nieprzypisane” to czas bez pracy agentów i bez czekania na właściciela; zawiera oczekiwanie na CI, którego rekord
nie wydziela. W końcowych przebiegach `checks` trwało 8–12 min (w API 23 min), `e2e` 13–17 min; PR miały 7–16
przebiegów CI.

Przygotowanie (z transkryptów; koszt całej sesji z telemetrii): API 38 min i 18,8 USD; ADMIN-UX-FIXES 1 74 min
i 28,3 USD; STORE 76 min i 42,2 USD; CART 15 min (koszt w sesji epiku); ADMIN-UX-2 28 min i 9,8 USD.

Co rzeczywiście opóźniło koniec epiku:

- **API:** 13,3 h nocnego czekania na decyzję o pominiętym przypadku `page=1.5` (niespełnione wymaganie; reguła
  dodana w retro #845). Od startu do tej chwili minęło 2,5 h.
- **ADMIN-UX-FIXES 1:** po przeglądzie UX (17:25) do przekazania PR ok. 1,7 h bez pracy subagentów (szacunek).
  Retro wskazuje czterokrotne przeliczanie wag części E2E, konflikt w `ci.yml` z #859 i #858 oraz zgubione zdarzenie
  push. Wagi E2E wyjęto z epików w #868.
- **STORE:** łańcuch zadania 2 (76 min, w tym trzy dodatkowe rundy: 34 min) i poprawka QA1 po BLOCK (61 min).
- **CART:** od startu do PR gotowego do zewnętrznego review 4,1 h. Potem 10,6 h nocy do wklejenia review, 1,5 h
  decyzji przy limicie 99% i nieudanym starcie (429), 41 min poprawki CX1. Retro przypisało noc limitowi; według
  transkryptu sesja o 21:46 skończyła pracę i czekała na zewnętrzne review.
- **ADMIN-UX-2:** zadania 2–4 poszły szeregowo decyzją właściciela, choć plan dawał im jedną falę (86 min zamiast
  ok. 33 min najdłuższego zadania; różnica to hipoteza). `ux-reviewer` wystartował o 21:27 i oddał raport o 08:12:
  komputer spał od 21:58 do 08:07. Poprawka FIX1 czekała na ten raport.

Przeglądy: co znalazły i ile kosztowały (wartość subagentów, bez sesji prowadzącej):

| Przegląd | Przebiegi | Koszt i czas jednego | Co znalazł |
|---|---|---|---|
| `code-reviewer` zadania (pierwszy FULL) | 23 zadania | 0,4–4,1 USD, 3–13 min | CHANGES_REQUIRED w 8 z 23 |
| dodatkowe rundy zadania | ADMIN-UX-2-1: 5; STORE-2: 3; CART-3: 2 | 6,3 USD i 20 min; 11,9 USD i 34 min; 7,7 USD i 17 min | każda runda miała potwierdzony błąd; przyczyny: specyfikacja bez zachowania w nowym stanie, klasa definiowana za wąsko |
| `qa-adversarial` | 5 | 2,9–6,4 USD, 18–51 min | BLOCK w STORE; potwierdzone F3 w CART; w ADMIN-UX-2 odtworzona wysyłka zdjęć (Minor) i „Zakończ teraz” (#897); PASS w API |
| `architecture-reviewer` | 2 | 1,5 USD, 3 min | KEEP, uwagi drobne |
| `ux-reviewer` | 4 | 1,8–2,6 USD, 10–28 min; w ADMIN-UX-2 18,9 USD | uwagi Minor, nic blokującego |
| poprawka po przeglądzie epiku (QA1, FIX1) | 3 | 6,4–15,7 USD, 47–75 min | STORE i CART: dwie rundy |
| poprawka po zewnętrznym review | 5 | 0,6 / 0,6 / 4,1 / 13,6 / 7,9 USD; 2–48 min pracy | CART: trzy rundy (klasa trzykrotnie za wąsko); ADMIN-UX-2: dwie |

Powtarzania wcześniejszej pracy nie stwierdzam w przeglądach zadań. Powtórzeniem było czwarte ustalenie ChatGPT
w #896 (sprawdzone już przez QA). `ux-reviewer` w ADMIN-UX-2 kosztował siedem do dziesięciu razy więcej niż
w pozostałych epikach; raport mówi o powtarzanych przebiegach po uśpieniu (związek z kosztem to hipoteza).

Limit i modele:

- Sesja prowadząca: CART na Fable 48,2 USD (wyjście 161 tys. tokenów, odczyt cache 58 mln); ADMIN-UX-FIXES 1 na
  Opusie 21,0 USD (163 tys., 71 mln); STORE na Opusie 20,9 USD (176 tys., 72 mln). CART jako jedyny prowadziła sesja
  przygotowania; pozostałe cztery epiki właściciel uruchomił w osobnej sesji „z opus high”.
- Liczba potwierdzonych zewnętrznych ustaleń na końcowym PR: sesje na Opusie 1, 1, 2; Fable 2; Opus z przejściem
  na Fable 2. Błędne odroczenia robiły sesje na obu modelach. Próba nie pokazuje różnicy jakości.
- Okno tygodniowe: API +2 pp, ADMIN-UX-FIXES 1 +6 pp (równolegle ze STORE). Oba epiki v1.2 wystartowały
  równolegle przy 89–90%. Skutki zapisane w sesjach: w CART odroczenie uwagi „przy limicie 97%”, ostatni przegląd
  różnicy na słabszym modelu, przerwany start CX1; w ADMIN-UX-2 100% okna w chwili zewnętrznego review.

## 6. Zmiany (najwyżej trzy)

**1. Niezależna ocena odroczeń przed zewnętrznym review** (jakość końcowego PR).

- *Przypadek:* #896 wysyłka zdjęć, #894 uwaga nr 5, #858 N2, #843 T1 S3: cztery z ośmiu potwierdzonych ustaleń.
- *Zmiana:* po poprawkach przeglądu epiku jeden `code-reviewer` w świeżym kontekście (ten sam model co przeglądy
  zadań) dostaje odroczone uwagi w brzmieniu reviewera, który je zgłosił, bez oceny sesji, oraz specyfikację
  i gałąź. Dla każdej podaje naruszoną gwarancję (sekcja specyfikacji), przeplot, skutek i werdykt „naprawić” albo
  „można odroczyć”, z dowodem. Werdyktu „naprawić” sesja nie uchyla sama: naprawia albo pyta właściciela.
- *Co zastępuje:* własne sprawdzenie odroczeń przez sesję w kroku 7 (z #904). To przeniesienie decyzji, nie nowa
  runda przeglądu całej zmiany. Bez kolejnego akapitu reguł: cztery dopisania tej reguły nie zatrzymały wzorca.
- *Jakość:* hipoteza: mniej ustaleń powtarzających odroczoną uwagę (dziś 0,8 na epik). Podstawa: zewnętrzny
  reviewer, który dostał tę samą listę z tym samym pytaniem, znalazł błędne odroczenie w czterech z pięciu epików.
  Zmiana nie dotyczy ustaleń nowych.
- *Koszt i czas:* szacunek 2–4 USD i 5–10 min (podobne zlecenia w tych epikach: 1,8–2,8 USD, 5–8 min). Dodatkowe
  poprawki przed zewnętrznym review zamiast po nim. Oszczędność cyklu po zewnętrznym review tylko wtedy, gdy nie
  zostanie żadne ustalenie nowe (w tych danych: #843).
- *Ryzyko:* werdykty „naprawić” bez błędu do naprawienia. Zewnętrzne review miało na 10 ustaleń jedno nieosiągalne
  i jeden duplikat uwagi, którą QA już sprawdziło jako bezpieczną.

**2. Kontrola obietnic w świeżym kontekście, zanim projekt zobaczy właściciel** (jakość przygotowania).

- *Przypadek:* CART D6, ADMIN-UX-2 B4, STORE R4, ADMIN-UX-FIXES 1 K2.
- *Zmiana:* przed przedstawieniem projektu, i ponownie po każdej odpowiedzi, która dopisuje mechanizm, jeden
  subagent (Sonnet albo Opus) dostaje dokument i kod z wąskim zleceniem: dla każdej gwarancji, każdego „nigdy/zawsze/
  najwyżej” i każdego miejsca oznaczonego przez autora jako ryzyko, założenie albo „poza zakresem” podać przeplot,
  który ją łamie, i tezy o kodzie bez wskazanego pliku. Autor przedstawia właścicielowi wynik razem z projektem.
- *Co zastępuje:* przegląd specyfikacji i planu po akceptacji (STORE) albo po pierwszej rundzie (ADMIN-UX-FIXES 1).
- *Jakość:* hipoteza: mniej rund i mniej luk w zewnętrznym review przygotowania. Jedyna obserwacja: w ADMIN-UX-FIXES
  1 taki odczyt znalazł 2 wady blokujące i 7 ważnych, a Codex potem jeszcze 5. Zera nie należy oczekiwać.
- *Koszt i czas:* ok. 10 min na odczyt; koszt rzędu 1–2 USD (pięć subagentów Sonnet w ADMIN-UX-FIXES 1 to razem
  4,0 USD). Każda runda to ręczne przeniesienie tekstu przez właściciela; między rundami mijało 2–18 min.
- *Ryzyko:* dłuższe przygotowanie bez spadku liczby uwag, jeśli zewnętrzne review szuka innych klas.

**3. Epik prowadzi osobna sesja na Opusie, nie sesja przygotowania na Fable** (koszt).

- *Przypadek:* CART: 48,2 USD sesji prowadzącej wobec 20,9–21,0 USD w epikach na Opusie o zbliżonej liczbie tokenów
  (12,5 USD w mniejszym API).
- *Zmiana:* realizacja zawsze w nowej sesji na Opusie, jak w czterech pozostałych epikach; Fable zostaje do
  przygotowania. To uproszczenie do jednego wariantu, bez nowego kroku.
- *Jakość:* w tej próbie bez widocznej różnicy (zastrzeżenie: pięć epików, różny zakres).
- *Koszt:* hipoteza z jednego porównania: 20–30 USD mniej na epik wielkości CART i mniejsze zużycie okna Fable.
- *Ryzyko:* decyzje sesji prowadzącej (odroczenia) są miejscem błędów; zmiana 1 odbiera jej tę decyzję.

Nie rekomenduję: mocniejszego modelu (przygotowania na Fable miały te same luki, błędne odroczenia robiły oba
modele), kolejnego przeglądu całej zmiany, usunięcia `architecture-reviewer` (1,5 USD i 3 min) ani QA (istotne
ustalenie w trzech z pięciu epików). Dwa największe postoje to nie kroki workflow: uśpienie komputera w trakcie
epiku i start dwóch epików przy 89–90% okna tygodniowego.

## 7. Test na najbliższym epiku

**Wybór: zmiana 1.** Ma najdłuższą historię (osiem epików z seriami v1.0–v1.2), najwęższy zakres i wynik, który da
się ocenić niezależnie od zewnętrznego review.

Punkt odniesienia (epiki v1.1–v1.2, które przeszły zewnętrzne review):

| Epik | Przygotowanie: rundy z uwagami / potwierdzone luki (oznaczone review) | Końcowy PR: potwierdzone / nowe / powtarza odroczoną | Wartość (USD) | Start → gotowy | Praca |
|---|---|---|---|---|---|
| API-INPUT-CONTRACT | 1 / 0 (oznaczona tylko trzecia runda; dwie wcześniejsze bez oznaczenia źródła) | 1 / 0 / 1 | 47,5 | 19,1 h | 2,7 h |
| ADMIN-UX-FIXES 1 | 2 / 4 | 1 / 1 / 0 | 40,9 | 5,5 h | 2,4 h |
| STORE-UX-FIXES | 2 / 7 | 2 / 1 / 1 | 85,9 | 5,2 h | 4,2 h |
| CART-ORDER-CONSISTENCY | 1 / 1 | 2 / 1 / 1 | 151,3 | 17,8 h | 4,6 h |
| ADMIN-UX-FIXES 2 | 3 / 9 | 2 / 1 / 1 | 98,7 | 17,8 h | 6,0 h |

Co zapisywać w każdym epiku testu (opis PR i przekazanie do retro):

- lista odroczonych uwag z decyzją sesji sprzed oceny i werdyktem oceniającego; liczba decyzji zmienionych;
- dla każdego werdyktu „naprawić”: czy powstał test RED (potwierdzenie) czy przeplot okazał się nieosiągalny;
- koszt i czas zlecenia oceny (z rekordu METRICS: osobne `ref`, np. `<EPIK>-DEF`);
- surowa treść zewnętrznego review wklejona w całości, z rewizją, także gdy nie ma ustaleń;
- wynik zewnętrznego review w podziale jak w tabeli; przygotowanie i końcowy PR osobno, obok wartości i czasu.

Ocena po trzech–czterech epikach z zewnętrznym review, bez progu liczbowego:

- ocena zmienia odroczenia, poprawki mają test RED, a zewnętrzne review nie zgłasza powtórzeń: zostaje;
- ocena niczego nie zmienia, a powtórzeń nie ma: wystarczyły reguły z #904 i #913, ocenę można usunąć;
- ocena niczego nie zmienia, a powtórzenia są: mechanizm nie działa w tej postaci, wraca do decyzji;
- większość werdyktów „naprawić” bez testu RED: koszt bez korzyści, zawęzić zlecenie albo usunąć.

Ograniczenie: najbliższy epik będzie też pierwszym na regułach z #904 i #913, więc sam spadek liczby powtórzeń nie
rozdzieli ich wpływu od oceny; rozdziela go liczba decyzji zmienionych przez oceniającego. Liczba ustaleń nowych
(dziś 0,8 na epik) pokaże osobno, czy działa reguła klasy z #904.
