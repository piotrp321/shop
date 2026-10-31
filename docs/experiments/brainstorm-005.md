# Eksperyment brainstorm-005: `/brainstorm` v0.7 vs `superpowers:brainstorming`

Data: 2026-10-30. A = nasz `/brainstorm` v0.7 (`8d6a865`), B = `superpowers:brainstorming` (plugin z
`.claude/settings.json`). Pięć nowych zadań, po jednej sesji A i B na zadanie. Metodologia jak w 004, z poprawkami
ograniczeń wykrytych w 004 (sekcja „Zmiany względem 004”).

## Werdykt

**v0.7 utrzymuje przewagę pierwszego projektu i trzyma decyzje przy zapisie specyfikacji, ale nie wyeliminowała
zawężania potwierdzonych wymagań.**

- **Pierwsze projekty: A lepsza w 5 z 5 par.** Średnia z 10 ocen: A 3,92, B 3,38. W 9 z 10 ocen A miała wyższą
  średnią (wyjątek: t4 w ocenie oryginalnej, gdzie liczby dały B 3,8 vs 3,6, a werdykt tego samego recenzenta
  wskazał A).
- **Specyfikacje końcowe: remis.** A 4,06, B 4,05. A wygrywa w t1, t3 i t5, B w t2 i t4.
- **Cel 1 v0.7 (bez zawężania bez zgody): nie spełniony.** s07 (t4) usunęła powód przy przywróceniu produktu
  (K11), choć PO podał go w odpowiedzi na pytanie. Zapisała to jako „założenie” i tak trafiło do specyfikacji.
  Obaj recenzenci uznali to za błąd krytyczny, a PO go przeoczył. Pozostałe zawężenia A zgłosiła jawnie jako
  decyzje do akceptacji.
- **Cel 2 v0.7 (zapis spec bez zmiany decyzji): spełniony w tej próbie.** 0/5 sesji A zmieniło zaakceptowaną
  decyzję biznesową przy zapisie (004: 1/5). B: 2 sesje ze zmianą względem zaakceptowanego projektu, każda wskazana
  przez jednego z dwóch recenzentów.
- **Cel 3 (kompletność i architektura):** w pierwszych projektach A pokryła 77/85 punktów klucza, B 59/85. Błędy
  krytyczne w końcowych specyfikacjach: A 2 potwierdzone i 1 sporny, B 1 potwierdzony i 3 sporne (szczegóły niżej).
- **Cel 4 (mniej interakcji): spełniony.** Na sesję: A 6,2 rundy, B 14,2. A miała 0 pustych zatwierdzeń, B 20
  łącznie. Korekty PO: A 6, B 8.
- **Koszt i czas bez przewagi A.** Koszt: A $3,36, B $3,14 na sesję (+7%). Czas modelu: A 807 s, B 738 s.
  Do akceptacji: A 678 s, B 533 s.

## Metoda

- **Kod.**
  - `git archive 8d6a865` bez `docs/experiments` w 10 katalogach `b005-runs/sNN/shop`, `git init` i commit z ustaloną
    datą i autorem. Wszystkie mają commit `b8b189f` i to samo drzewo.
  - Auto-memory pusta (nowe ścieżki projektów).
- **Sesje.**
  - `claude -p` (2.1.308), `--model claude-opus-5-5 --effort high --strict-mcp-config --permission-mode acceptEdits`,
    każda runda przez `--resume`. Narzędzia i blokady jak w 004 (zablokowane `docker`, `make`, `rm`, `git push`).
  - Prompt: `/brainstorm <brief>` albo `/superpowers:brainstorming <brief>`, brief identyczny w parze.
- **Przydział.** Losowy w parze (`mapping.json`): t1 s01/s02, t2 s03/s04, t3 s06/s05, t4 s07/s08, t5 s09/s10 (A/B).
  Ślepe etykiety X/Y losowane osobno (`blind-mapping.json`); każda para oceniona też po zamianie X↔Y.
- **Zadania** (nowe, żadne nie powtarza 001–004; brief = zadanie + jedno zdanie o brakujących zależnościach):
  1. Kody rabatowe w panelu: reguły, limity użyć, uprawnienia (reguły biznesowe i uprawnienia).
  2. Powiadomienie o dostępności produktu: brak magazynu, mailera, persystencji Customer, karty produktu
     (brakująca infrastruktura).
  3. Karty podarunkowe: saldo, blokady, zwroty, audyt (współbieżność i integralność danych).
  4. Wycofanie produktu ze sprzedaży: katalog, ES, cache, sklep, dashboard (integracja modułów).
  5. Konta pracowników w panelu: Keycloak Admin API, role, ostatni admin (backend + frontend).
- **Weryfikacja Skilli.** W transkrypcie każdej sesji jest „Base directory for this skill”: A →
  `.claude/skills/brainstorm`, B → `plugins/.../skills/brainstorming`. Żadna sesja nie wywołała drugiego Skilla.
- **Klucz i PO.**
  - `answer-key.md`: 17 punktów na zadanie, tylko decyzje biznesowe. Spisany przed startem (19:27 UTC), hash
    SHA-256 w `answer-key.sha256` razem z briefami, regułami PO, przydziałem i driverem. Niezmieniany.
  - Osobno, zapieczętowane tym samym hashem: `pressure-points.md` – 18 punktów klucza, które w obecnym repo
    napotykają realne ograniczenie (np. brak `symfony/rate-limiter`, brak SMTP w realmie `backoffice`, lokalna
    walidacja JWT z tokenem 5 min, brak Varnisha, brak zamówień). Nie trafiły do PO, sesji ani recenzentów.
  - PO: osobny proces Opus medium, bez narzędzi, ślepy na przydział. Reguły z 004 z jedną zmianą (niżej).
  - Dosłowność: 177/180 przekazań punktów zawiera dokładny tekst klucza; w 3 (s03 ×2, s08) brakowało tylko etykiety
    „Zakres i kiedy budować:”. Równoważne pytania dostały więc identyczne odpowiedzi.
- **Ocena.** 10 sesji Opus high, tylko odczyt, eksport repo bez `.claude` i `docs/experiments`. Recenzent dostał
  pierwszy projekt, wszystkie wiadomości do akceptacji (`accepted`), końcową specyfikację z diffem, odpowiedzi PO,
  klucz i brief. Z materiałów usunięto wzmianki o Skillach (słowa „superpowers”, „brainstorm” i jedno zdanie
  „zamiast domyślnego `docs/superpowers/specs/`”, s02).
- **Orkiestracja.** Główna sesja (Opus). Zarzuty recenzentów dotyczące repo i plików sprawdzałem w kodzie
  (sekcja „Weryfikacja ocen”).

### Zmiany względem 004

| Ograniczenie 004 | Zmiana w 005 |
|---|---|
| PO oceniał spec ze streszczenia agenta (przeoczona sprzeczność s10) | Driver dołącza do wiadomości pełną treść zmienionych plików `docs/**/*.md`, reguła 12 każe oceniać plik |
| 10 sesji równolegle, konkurencja o API | Pary kolejno, A i B tej samej pary równolegle (to samo okno czasowe) |
| Brak oceny zgodności spec z zaakceptowanym projektem | Recenzent dostaje `*-accepted.md` i osobno wypisuje niezatwierdzone zmiany zakresu, różnice spec↔projekt i błędy krytyczne |
| Weryfikacja zarzutów przerwana limitem | Weryfikacja po każdej parze, zapis do `verification.md` |
| Postęp tylko w pamięci procesu | Stan drivera (`sNN.state.json`) zapisywany po każdej turze agenta i PO, wznawialny; `progress.log` po każdej sesji i ocenie |
| Brak kontrolowanych ograniczeń technicznych | Klucze z punktami nacisku spisanymi przed startem |

### Odstępstwa i ograniczenia

1. **Równoległy benchmark `/delivery`.** W chwili startu działał w innej sesji (`shop-bench/delivery-001`).
   Nie ingerowałem w niego. Oceny pary N biegły równolegle z sesjami pary N+1. Obie strony pary miały te same
   warunki, ale czasy bezwzględne nie są porównywalne z 004.
2. **Niejednoznaczność klucza (moja).**
   - T2: K04 („zalogowany jednym kliknięciem”) koliduje z K05 („zapis wymaga zaznaczenia zgody”). A zgłosiła to jako
     decyzję O2, nie liczę tego jako błędu.
   - T1-K02 nie rozstrzyga, czy sprawdzenie kodu ma być publiczne. A zapytała (R2-1) i wybrała endpoint panelu.
3. **Lapsus PO na korzyść A (t4).** K11 w s07 nie wykryty w trzech weryfikacjach, mimo dołączonego pliku spec.
   Dołączenie pliku nie zastąpiło ludzkiej uważności, więc niezależni recenzenci pozostają potrzebni.
4. **„Zgoda przez akceptację całości”.** PO według reguły 10 akceptuje projekt, gdy nie jest sprzeczny z kluczem.
   Decyzje zawężające, które A wypisała w projekcie (np. s06 D2/D3, s03 O4/O5), przeszły więc razem z całym
   projektem. Realny użytkownik mógł je przeoczyć tak samo.
5. **n = 1 na zadanie.** Wariancji wewnątrz zadania nie znamy. Rozrzut ocen po zamianie X↔Y wynosił 0–0,8.
6. **Brak stosu.** Twierdzenia o repo sprawdzane statycznie.
7. **Orkiestrator.** Prowadziłem eksperyment z głównej sesji; jej własnego zużycia nie mierzyłem.

## Wyniki zbiorcze

Czas = suma `wall` rund agenta. Koszt = `total_cost_usd` ostatniej rundy (narasta przy `--resume`), ekwiwalent cen
API; sesje szły w subskrypcji.

| Zadanie | Skill | Rundy | Czas | Do 1. projektu | Do akceptacji | Wyjście (tok.) | Odczyt cache | Koszt |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| 1 Kody rabatowe | A | 6 | 729 s | 507 s | 582 s | 72 586 | 2,29 M | $3,16 |
| | B | 14 | 618 s | 395 s | 462 s | 58 394 | 2,51 M | $2,66 |
| 2 Dostępność | A | 6 | 654 s | 414 s | 526 s | 64 488 | 1,75 M | $2,65 |
| | B | 15 | 866 s | 490 s | 633 s | 81 092 | 3,26 M | $3,50 |
| 3 Karty podarunkowe | A | 6 | 878 s | 626 s | 759 s | 86 610 | 1,95 M | $3,43 |
| | B | 16 | 664 s | 384 s | 479 s | 64 851 | 2,89 M | $2,86 |
| 4 Wycofanie produktu | A | 7 | 800 s | 452 s | 681 s | 82 561 | 2,77 M | $3,73 |
| | B | 15 | 796 s | 376 s | 570 s | 77 013 | 3,06 M | $3,51 |
| 5 Konta pracowników | A | 6 | 973 s | 632 s | 843 s | 96 727 | 2,39 M | $3,85 |
| | B | 11 | 746 s | 351 s | 520 s | 69 411 | 3,24 M | $3,19 |
| **Średnia** | A | 6,2 | 807 s | 526 s | 678 s | 80 594 | 2,23 M | $3,36 |
| | B | 14,2 | 738 s | 399 s | 533 s | 70 152 | 2,99 M | $3,14 |

- PO na sesję: A $0,60 i 97 s, B $0,72 i 180 s.
- Oceny: $26,42 łącznie (10 sesji po $2,3–3,2, 5–7 min każda).
- Łącznie z PO: A $3,96, B $3,86 na sesję.

### Rozkład czasu i tokenów

Fazy wyznaczone z typu wiadomości agenta w rundzie (według PO): discovery = rundy z pytaniami (w A zawiera też
całą pierwszą analizę repo), projekt = części i pełne projekty z korektami, spec = zapis, self-review i zamknięcie.
Analiza repo to osobny przekrój: wywołania API, które użyły narzędzi odczytu (`Read`, `Grep`, `Glob`, `Bash`),
z czasem do następnego wywołania (transkrypt sesji).

| Udział (wall / koszt / tokeny wyjścia) | A | B |
|---|---|---|
| Discovery | 36% / 44% / 32% | 23% / 33% / 20% |
| Projekt | 48% / 37% / 50% | 49% / 37% / 48% |
| Spec i kontrola po zapisie | 16% / 19% / 18% | 28% / 30% / 32% |
| Analiza repo (przekrój) | 34% wall (271 s/sesję), 37% wyjścia | 28% wall (204 s/sesję), 15% wyjścia |

Wiarygodność: podział na fazy jest pewny, bo granice rund są twarde. Kontroli jakości przed pokazaniem projektu
(kroki 10–11 Skilla A) nie da się oddzielić od projektowania, bo dzieje się w tej samej turze. Udział analizy repo
jest przybliżony. A więcej czyta i myśli przed pierwszymi pytaniami, B przenosi koszt na zapis spec i self-review.

## Interakcja i discovery

| Miara (suma 5 sesji) | A | B |
|---|---:|---:|
| Pytania biznesowe | **127** (25, 31, 25, 25, 21) | 41 (6, 12, 10, 10, 3) |
| Pytania techniczne | 1 | 10 |
| Rundy z pytaniami | 10 | 29 |
| Puste zatwierdzenia sekcji | **0** | 20 |
| Punkty klucza przekazane na pytanie (z 85) | **69** (14, 16, 13, 13, 13) | 43 (11, 10, 9, 8, 5) |
| Punkty ujawnione dopiero w korekcie | **6** | 23 |
| Pierwszy projekt bez uwag PO | 0 | 0 |
| Rundy korekty projektu | 6 | 7 (+1 korekta sekcji, s08) |
| Korekty zapisanej specyfikacji | 0 | 0 |
| Pokrycie w 1. projekcie wg PO (spełnione/brak/sprzeczne) | **77 / 5 / 3** | 59 / 18 / 8 |
| Zmiany plików poza specyfikacją | 0 | 0 |
| Zmiany plików przed akceptacją projektu | 0 | 0 |
| Branch i commit | brak (zostaje na `main`) | 5/5 sesji |

Uwagi PO do pierwszych projektów:

| Para | A | B |
|---|---|---|
| 1 Kody | brak K12 | K04 sprzeczny; brak K12, K15, K16 |
| 2 Dostępność | K11 sprzeczny (strona z przyciskiem), brak K10 | brak K05, K06, K13; sprzeczne K07, K15, K16 |
| 3 Karty | brak K13, K15 | brak K05, K12, K14, K15 |
| 4 Wycofanie | brak K16 | brak K07, K12, K13, K14, K16; sprzeczne K05, K15 |
| 5 Konta | sprzeczne K12, K14 | brak K10, K11, K14; sprzeczne K13, K16 |

## Oceny jakości (średnia z oceny oryginalnej i zamienionej)

| Para | 1. projekt A | 1. projekt B | Spec. A | Spec. B |
|---|---:|---:|---:|---:|
| 1 Kody rabatowe | **4,2** | 3,6 | **4,4** | 4,1 |
| 2 Dostępność | **3,7** | 3,1 | 3,4 | **4,3** |
| 3 Karty podarunkowe | **4,2** | 3,5 | **4,6** | 4,1 |
| 4 Wycofanie produktu | **3,8** | 3,5 | 3,8 | **4,05** |
| 5 Konta pracowników | **3,7** | 3,2 | **4,1** | 3,7 |
| **Średnia** | **3,92** | 3,38 | 4,06 | 4,05 |

Zamiana etykiet zmieniała pojedyncze średnie o 0–0,8. Największe rozrzuty: spec A w t2 (3,0 vs 3,8) i 1. projekt B
w t4 (3,8 vs 3,2). W t4 recenzent oryginalny dał liczbowo B 3,8 i A 3,6, a w werdykcie wskazał pierwszy projekt A.

## Błędy krytyczne w końcowych specyfikacjach

Liczone osobno, bez uśredniania. „Potwierdzony” = obaj recenzenci i moja weryfikacja w kodzie lub plikach.
„Sporny” = jeden z dwóch recenzentów, treść potwierdzona przeze mnie.

| Sesja | Błąd | Typ | Status |
|---|---|---|---|
| s03 (A, t2) | Zmiana dostępności reindeksowana „jak `IndexProduct`”, ale ES przyjmuje tylko wyższą wersję produktu (`ElasticsearchProductIndexer.php:15,30-31`, stale = obsłużone w `IndexProduct.php:45`), a dostępność ma osobną wersję. Sklep nie pokaże „Niedostępny” ani przycisku, więc główny przepływ nie działa. | (b/c) | **potwierdzony** (r2: krytyczny; r2s: krytyczny) |
| s03 (A, t2) | `DispatchBatch` przenosi prośby w `sending` i publikuje `SendNotification` wprost do RabbitMQ, bez outboxa (`final.md:142-146`). Awaria między commitem a publikacją zostawia prośby w `sending`: blokują ponowny zapis (indeks `:132`), retencja ich nie obejmuje (`:164`), panel ich nie liczy (`:190`). Narusza K16. | (b) | sporny (r2) |
| s07 (A, t4) | „Przywrócenie nie ma powodu” (`final.md:46,49`), choć PO na pytanie podał K11 „…i powód”. W projekcie było tylko w „Założeniach”, nie na liście do potwierdzenia. | (a) | **potwierdzony** (obaj), PO przeoczył |
| s08 (B, t4) | Seed trzyma wycofania i historię tylko w pamięci, a reset robi `TRUNCATE` przed resetem ES i Garage (`DbalCatalogReset.php:27-34`). Awaria w trakcie gubi ręczne wycofania (K16). | (b) | **potwierdzony** (obaj) |
| s08 (B, t4) | Nieoczekiwany wyjątek przerywa akcję zbiorczą błędem 500, wbrew zaakceptowanemu „wyjątki → wynik pozycji” (K09). | (a) | sporny (r4s) |
| s05 (B, t3) | Zwrot na wygasłą kartę zapisuje przepadek (`final.md:97-98`), a przepadek czeka na koniec blokad (`:102-103`). Saldo może „ożyć” wbrew K04. | (b) | sporny (r3s) |
| s10 (B, t5) | Usunięcie hasła i koniec sesji przy resecie to „skutki” ponawiane ok. godziny (`final.md:136-147,162`), sprzeczne z „usuwa stare hasło od razu” (`:167`). Przy awarii stare hasło działa. | (b) | sporny (r5) |

Razem: A 2 potwierdzone i 1 sporny (s03: ES i stan `sending`; s07: K11); B 1 potwierdzony i 3 sporne. Sesje bez błędu krytycznego: A s01, s06, s09;
B s02, s04.

## Cele v0.7

### 1. Nie odkładać, nie usuwać ani nie ograniczać potwierdzonego wymagania bez zgody PO

| Zawężenie w A | Jak przedstawione | Ocena |
|---|---|---|
| s07 K11: przywrócenie bez powodu | „Założenia i interpretacje”, punkt przekazany wcześniej na pytanie | **naruszenie** (w spec) |
| s07 K14: przywrócenie w trakcie `catalog:reindex` niewidoczne do kolejnej zmiany | ryzyko, bez pytania | słabsza gwarancja, drobne |
| s01 K02: sprawdzenie kodu tylko w panelu | pytanie R2-1 z rekomendacją, potem „potwierdź przy akceptacji” | zgodne |
| s03 K13, część K12: odłożone do funkcji wycofania i usuwania konta | decyzje O4/O5 z alternatywą w projekcie | zgodne z literą, zgoda przez akceptację całości |
| s06 K04, K06: D2 (bez przedłużenia wygasłej), D3 (blokada do końca ważności) | sekcja „Decyzje do Twojej akceptacji” | jak wyżej |
| s07 K05: wyszukiwarka, podpowiedzi, mapa strony, cache | „wymaga twojego potwierdzenia” (tych elementów rzeczywiście nie ma: `openapi.json` `/api/v1/products` ma tylko `category`, `page`, `perPage`) | zgodne |

- Punkty nacisku (18) A ostatecznie spełniła albo zgłosiła jako decyzję. Naruszenie wystąpiło na punkcie bez
  ograniczenia technicznego (K11), przez „założenie” w projekcie. Mechanizm jest ten sam co w 004 (s01 K12 jako
  założenie). Reguła z v0.7 („potwierdzonego wymagania nie usuwaj…”) jest w kroku 8, a krok 10 (kontrola, czy każde
  ustalenie ma odzwierciedlenie) tego nie zatrzymał.
- Nowy wzorzec A: zawężenia trafiają do projektu jako lista decyzji do akceptacji, a nie jako pytania przed
  projektem (s03, s06, s01). Zgoda jest formalna, ale łatwo ją przeoczyć w długim projekcie.
- B: w pierwszych projektach 8 sprzeczności z kluczem, w tym ciche osłabienia punktów nacisku (s04 K07, K15, K16;
  s08 K05). Wszystkie poprawił PO w korekcie. W specyfikacjach B nie ma niezatwierdzonego zawężenia poza spornym
  K09 (s08) i K09 (s10).

### 2. Nie zmieniać zaakceptowanych decyzji przy zapisie specyfikacji

- A: 0/5. Obaj recenzenci w każdej parze uznali spec A za zgodną z zaakceptowanym projektem. Różnice to
  doprecyzowania. Zarzut r2 wobec s03 („`sent` bez wysyłki”) realizuje zaakceptowaną decyzję O1
  (`accepted.md:497`), więc nie jest zmianą decyzji.
- B: s08 (K09, r4s) i s10 (porzucenie skutków po godzinie, r5) – po jednej różnicy względem zaakceptowanego projektu.
- PO z pełnym plikiem spec nie zgłosił korekty spec w żadnej sesji (w 004 A miała jedną).

### 3. Kompletność wymagań i jakość architektury

- Pokrycie w 1. projekcie: A 77/85 (90%), B 59/85 (69%). 004: A 82/86, B 64/86.
- Oceny architektury (kryterium 2) w pierwszych projektach były bliskie u obu stron (3–4,5). Przewaga A wynika
  głównie z kompletności i zgodności z repo.
- Spec A ma 2 potwierdzone błędy krytyczne na 5 (004: nie mierzone w tej formie). Błąd ES w s03 to błąd zgodności
  z kodem, który krok 10 Skilla miał wyłapać („każde twierdzenie o kodzie sprawdź w pliku”).

### 4. Mniej interakcji niż Superpowers

- Rundy: 6,2 vs 14,2 (0,44×). Puste zatwierdzenia: 0 vs 20. Korekty PO: 6 vs 8. Pytań biznesowych A zadaje
  ok. 3× więcej, ale w 2 rundach.
- Czas człowieka (liczba wiadomości) jest mniejszy, czas modelu i koszt większe: A +9% czasu, +27% do akceptacji,
  +7% kosztu.

## Weryfikacja ocen

Sprawdzone w eksporcie repo i plikach sesji (`verification.md`):
- ES: `version_type: external` z wersją produktu (`ElasticsearchProductIndexer.php:15,30-31`) i stale jako
  obsłużone (`IndexProduct.php:18,45`) – potwierdza błąd s03,
- `DbalCatalogReset.php:27-34`: `TRUNCATE` w transakcji, potem reset indeksu i Garage – potwierdza ryzyko seeda s08,
- K11 w s07: odpowiedź PO (`designs/s07/dialog.md:15`) vs „Przywrócenie bez powodu” (`first.md:172`, `final.md:46`),
- sprzeczności wewnętrzne s05 (`final.md:97-98` vs `:102-103`) i s10 (`final.md:136-147` vs `:167`),
- `/api/v1/products` bez parametru wyszukiwania, brak mapy strony i podpowiedzi w store – twierdzenie s07 prawdziwe,
- brak `symfony/rate-limiter` i mailera w `composer.json`, brak SMTP w `infra/keycloak/realms/backoffice.json` –
  punkty nacisku rzeczywiście ograniczone,
- zmiany plików tylko po akceptacji: we wszystkich 10 sesjach pierwszy zapis pliku nastąpił w rundzie po
  akceptacji projektu (`logs/sNN-<n>.git`).

Nie weryfikowałem drobnych zarzutów stylistycznych i ocen subiektywnych (np. „zbędna złożoność kontraktu Varnisha”
w s08, pętla odświeżania tokena w s10).

## Porównanie z brainstorm-003 i 004

| | 003 (v0.5) | 004 (v0.6) | 005 (v0.7) |
|---|---|---|---|
| Rundy A / B | 5,2 / 15,0 | 5,4 / 14,8 | 6,2 / 14,2 |
| Pytania biznesowe A / B | 87 / 48 | 110 / 34 | 127 / 41 |
| Punkty z pytań A / B | 70/89 / 53/89 | 64/87 / 36/87 | 69/85 / 43/85 |
| Punkty w korekcie A / B | 7 / 10 | 3 / 18 | 6 / 23 |
| Pierwsze projekty bez korekty A / B | 0 / 1 | 2 / 0 | 0 / 0 |
| 1. projekt A / B | 3,98 / 3,51 | 4,02 / 3,24 | 3,92 / 3,38 |
| Specyfikacja A / B | 4,20 / 4,05 | 4,19 / 4,11 | 4,06 / 4,05 |
| Koszt A / B | $2,47 / $2,31 | $2,90 / $2,87 | $3,36 / $3,14 |
| Czas A / B | 570 s / 553 s | 765 s / 669 s | 807 s / 738 s |
| Zawężenie potwierdzonego wymagania bez zgody (A) | t1 K15, t2 K18 (współbieżność) | s08 K15, s03 K08 | s07 K11 |
| Zmiana decyzji przy zapisie spec (A) | — | s06 K05 | brak |
| Zmiany `vision.md` przez B | 2 sesje | 1 sesja | 0 |

Zadania są inne w każdym eksperymencie, a 005 ma celowo wbudowane ograniczenia techniczne, więc liczby
bezwzględne nie są wprost porównywalne. Stały jest kierunek: A ma lepszy pierwszy projekt przy ok. 40% rund,
specyfikacje końcowe się wyrównują, a koszt A rośnie z wersji na wersję. Spadek ocen spec w 005 u obu stron może
wynikać z trudniejszych zadań, a nie z wersji Skilla.

## Powtarzające się błędy

| Typ | A (v0.7) | B (Superpowers) |
|---|---|---|
| Potwierdzone wymaganie zawężone bez zgody | s07 K11 | s08 K09, s10 K09 (sporne) |
| Założenie biznesowe zamiast pytania | s07 K11, s09 K12/K14 (1. projekt) | liczne (18 braków i 8 sprzeczności w 1. projektach) |
| Zawężenie jako decyzja w projekcie zamiast pytania przed projektem | s03 O4/O5, s06 D2/D3 | s04 limit 3 e-maili/dobę (zaakceptowany w części) |
| Zmiana decyzji przy zapisie spec | — | s08, s10 (sporne) |
| Niezgodność z kodem | s03 ES (krytyczny) | t1 brak reguł Deptrac dla Pricing (r1) |
| Błąd ścieżki awarii | s03 prośby w `sending` (sporny) | s08 seed, s10 reset hasła |
| Zmiany plików przed akceptacją | brak | brak |

## Odpowiedzi

**Czy v0.7 jest gotowy do stabilnego używania?** Jako narzędzie do pierwszego projektu: tak. Daje wyraźnie
pełniejszy pierwszy projekt (90% vs 69% punktów klucza) przy mniej niż połowie rund i bez pustych zatwierdzeń.
Jako gwarancja wierności wymaganiom: nie. W 1 z 5 sesji potwierdzone wymaganie zostało usunięte jako „założenie”
i przeszło do specyfikacji, a 2 z 5 specyfikacji A mają błąd krytyczny. Przegląd specyfikacji przez człowieka
(albo niezależne review) jest nadal konieczny. Dotyczy to też B.

**Czy v0.7 wyeliminowała dwa problemy z 004?**
- Zmiana zaakceptowanej decyzji przy zapisie spec: w tej próbie tak (0/5 vs 1/5 w 004). Przy n = 5 to zgodne
  z poprawą, ale nie dowód.
- Odkładanie/zawężanie potwierdzonego wymagania: nie. Zmienił się sposób: ograniczenia techniczne A zgłaszała
  jawnie jako decyzje (wcześniej odkładała je po cichu), ale pojedyncze zawężenie przez „założenie” wróciło (s07 K11).

**Rozwijać czy zamrozić?** Zamrozić v0.7 jako bazę i przejść do integracji z `/delivery`. Kolejne zmiany tekstu
Skilla nie dały w 004→005 mierzalnego postępu w jakości specyfikacji (4,19 → 4,06, remis z B), a koszt rośnie.
Pozostałe dwa ryzyka lepiej łapać mechanicznie w `/delivery` niż kolejnym zdaniem w Skillu:
1. Sprawdzenie „odpowiedź PO → miejsce w spec” jako krok przeglądu specyfikacji (lista odpowiedzi użytkownika
   porównana ze spec; wpis „Założenie” sprzeczny z odpowiedzią = błąd). To łapie s07 K11.
2. Niezależne review specyfikacji względem kodu przed planem (np. agent `architecture-reviewer`). To łapie s03 ES
   i błędy ścieżek awarii B.

Jeśli Skill miałby się jeszcze zmienić, to jedną rzeczą: decyzje zawężające wymaganie zadawać w rundzie pytań przed
projektem, a nie w projekcie. Taką zmianę trzeba zmierzyć osobno.

## Artefakty

Wszystko poza repo, niezacommitowane:
- `~/Development/claude/bench-brainstorm-005/`:
  - `tasks.txt`, `answer-key.md`, `pressure-points.md`, `answer-key.sha256`, `keys-written.txt`, `po-rules.md`,
  - `driver.py`, `run_pairs.sh`, `stats.py`, `stats*.jsonl`, `build_review.py`, `judge.sh`, `judge-prompt.md`,
    `aggregate.py`, `po_consistency.py`,
  - `mapping.json`, `blind-mapping.json`, `base-commit.txt`, `started.txt`, `progress.log`, `verification.md`,
  - `logs/` (stream-json, `.msg.md`, `.git`, `.wall`, `sNN.state.json`, `sNN.rounds.json`), `prompts/`, `po/sNN/`,
  - `designs/sNN/{first,accepted,final,dialog}.md`, `judge/r{1..5}.md` i `r{1..5}s.md`.
- `~/Development/claude/b005-runs/sNN/shop` – katalogi sesji w stanie końcowym.
- `~/Development/claude/b005-review/r{1..5}` i `r{1..5}s` – materiały recenzentów.
- Transkrypty: `~/.claude/projects/-Users-<user>-Development-claude-b005-runs-sNN-shop/`.
