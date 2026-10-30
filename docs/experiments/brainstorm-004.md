# Eksperyment brainstorm-004: `/brainstorm` v0.6 vs `superpowers:brainstorming`

Data: 2026-10-30. A = nasz `/brainstorm` v0.6 (`fdf8913`), B = `superpowers:brainstorming` (plugin z
`.claude/settings.json`). Pięć nowych zadań, po jednej sesji A i B na zadanie. Metodologia jak w 003: ślepy PO
z kluczem i korektą całego projektu, dwie ślepe oceny z zamianą X/Y.

## Werdykt

**v0.6 zostaje. Poprawki 003 zadziałały częściowo, a jedna wymaga dalszej zmiany.**

- **Pierwsze projekty: A lepsza w 5 z 5 par.** Średnia z 10 ocen: A 4,02, B 3,24, czyli większa przewaga niż
  w 003 (3,98 vs 3,51). Kierunek nie zmienił się po zamianie etykiet w żadnej parze.
- **Specyfikacje końcowe: remis.** A 4,19, B 4,11. A wygrywa w t1, t3 i t5, B w t2 i t4.
- **Interakcje.** Na sesję: A 5,4 rundy, B 14,8. A miała 0 pustych zatwierdzeń sekcji, B 23 łącznie.
- **Koszt i czas bez przewagi A.** Koszt: A $2,90, B $2,87 na sesję. Czas modelu: A 765 s, B 669 s.
  Do akceptacji: A 635 s, B 485 s.
- **Błąd z 003 wrócił.** Przekazany punkt klucza pominięty w projekcie: w s08 K15 (RODO) podany w odpowiedzi
  i świadomie odłożony z powodu technicznego. To dokładnie przypadek, który v0.6 miała wyeliminować.

## Metoda

- **Kod.**
  - `git archive fdf8913` w 10 katalogach `b004-runs/sNN/shop`, `git init` i commit z ustaloną datą.
  - Wszystkie mają commit `71e2790` i drzewo `1acfe83` (= drzewo `fdf8913`).
  - Bez `docs/experiments` i historii. Auto-memory była pusta (nowe ścieżki projektów).
- **Sesje.**
  - `claude -p` (2.1.308), `--model claude-opus-5-5 --effort high --strict-mcp-config --permission-mode acceptEdits`,
    każda runda przez `--resume`.
  - Narzędzia i blokady jak w 003. Zablokowane: `docker`, `make`, `rm`, `git push`.
  - Driver z 003 z jedną zmianą: 3 próby z odstępem 90 s zamiast 2, na wypadek limitów. Żadna próba nie była potrzebna.
- **Przydział.** Losowy w parze (`mapping.json`): t1 s01/s02, t2 s03/s04, t3 s06/s05, t4 s08/s07, t5 s10/s09 (A/B).
  Ślepe etykiety X/Y: A jako X w t1 i t5, B jako X w t2–t4. Każda para oceniona też z zamienionymi etykietami.
- **Zadania** (nowe, żadne nie powtarza 001–003; brief = zadanie + jedno zdanie o brakujących zależnościach):
  1. Ceny produktów w panelu: netto/VAT/brutto, role, historia pod Omnibus (logika biznesowa i uprawnienia).
  2. Przenoszenie kategorii z poddrzewem (współbieżność i spójność, ES).
  3. Płatności u zewnętrznego dostawcy: webhooki, podpisy, niezawodność (integracja).
  4. Książka adresowa klienta (API + sklep).
  5. Zwroty towaru i odstąpienie od umowy (złożony proces).
- **Weryfikacja Skilli.** W transkryptach `~/.claude/projects/...b004-runs-sNN-shop` każda sesja ma jeden
  „Base directory for this skill”: A → `.claude/skills/brainstorm`, B → `plugins/.../skills/brainstorming`.
- **Klucz i PO.**
  - `answer-key.md`: 17–18 punktów na zadanie. Spisany przed startem (16:13 UTC), hash SHA-256 w
    `answer-key.sha256`, niezmieniany.
  - Reguły PO (`po-rules.md`) skopiowane bez zmian z 003. PO to osobny proces Opus medium, bez narzędzi, ślepy na
    przydział.
- **Ocena.**
  - 10 sesji Opus high, tylko odczyt, z eksportem repo bez `.claude`. Prompt recenzenta jak w 003.
  - Recenzent dostał pierwszy projekt, końcową specyfikację z diffem, odpowiedzi PO, klucz i brief.
  - Z materiałów usunięto jedno zdanie ujawniające Skill („a nie w `docs/superpowers/specs/`”, s02).
- **Orkiestracja.** Ja (główna sesja). Zarzuty recenzentów sprawdzałem w eksporcie repo.

### Odstępstwa i ograniczenia

1. **Współbieżność z benchmarkiem `/delivery`.**
   - W chwili startu działały dwa procesy `claude -p` z `shop-bench/delivery-001`. Nie ingerowałem w nie.
   - Wszystkie 10 sesji szło równolegle (16:15–16:31 UTC), a oceny potem (ok. 5 min każda).
   - Czasy ścienne obejmują więc konkurencję o przepustowość API i są porównywalne tylko w obrębie tego
     eksperymentu: obie strony działały w tym samym oknie. Bezwzględne czasy mogą być zawyżone względem 003.
   - Limit subskrypcji został osiągnięty pod koniec eksperymentu, po zakończeniu sesji i ocen. Nie wpłynął na
     pomiary, ale ograniczył weryfikację zarzutów (p. 4).
2. **Niespójność PO na korzyść A (t2).**
   - s04 (B) dostała korektę K08, bo przy niedostępnym ES pozwalała przenieść kategorię bez liczby produktów.
   - s03 (A) ma to samo zachowanie (`designs/s03/final.md:177,306`, `productCount: null`), a PO tego nie zgłosił.
   - Recenzent t2 ocenił to jako sprzeczność z K08 w specyfikacji A.
3. **Niespójność PO na korzyść A (t5).** s10 (A) ma w specyfikacji „zwrot poza systemem” (`designs/s10/final.md:75`),
   sprzeczny z K12. PO przeoczył to w streszczeniu. Recenzent t5 to wychwycił.
4. **Weryfikacja zarzutów częściowa** z powodu limitu: sprawdzone 5 (sekcja „Weryfikacja ocen”), reszta nie.
5. PO ocenia zapisaną specyfikację ze streszczenia agenta, a nie z pliku (jak w 003). Stąd p. 3.
6. Brak stosu. Twierdzenia o repo sprawdzane tylko statycznie.

## Wyniki zbiorcze

Czas = suma `wall` rund agenta. Koszt = `total_cost_usd` ostatniej rundy (narasta przy `--resume`).

| Zadanie | Skill | Rundy | Czas | Do 1. projektu | Do akceptacji | Wyjście (tok.) | Odczyt cache | Koszt |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| 1 Ceny | A | 6 | 837 s | 664 s | 740 s | 75 184 | 2,83 M | $3,35 |
| | B | 15 | 540 s | 330 s | 374 s | 52 483 | 2,60 M | $2,49 |
| 2 Kategorie | A | 6 | 653 s | 439 s | 537 s | 64 788 | 2,74 M | $3,15 |
| | B | 13 | 761 s | 312 s | 579 s | 65 184 | 4,66 M | $3,56 |
| 3 Płatności | A | 5 | 884 s | 683 s | 683 s | 68 589 | 1,56 M | $2,86 |
| | B | 17 | 744 s | 445 s | 526 s | 72 017 | 2,54 M | $2,93 |
| 4 Adresy | A | 5 | 628 s | 365 s | 520 s | 58 045 | 1,94 M | $2,66 |
| | B | 13 | 640 s | 295 s | 495 s | 52 501 | 3,10 M | $2,68 |
| 5 Zwroty | A | 5 | 821 s | 697 s | 697 s | 63 781 | 1,22 M | $2,47 |
| | B | 16 | 661 s | 386 s | 451 s | 65 050 | 2,47 M | $2,70 |
| **Średnia** | A | 5,4 | 765 s | 570 s | 635 s | 66 077 | 2,06 M | $2,90 |
| | B | 14,8 | 669 s | 354 s | 485 s | 61 447 | 3,07 M | $2,87 |

- Koszt PO na sesję: A $0,42, B $0,57. Czas PO: A 86 s, B 189 s.
- Oceny: ok. $21 łącznie (10 sesji po $1,8–2,7).
- Koszt podany jako `total_cost_usd`, czyli ekwiwalent cen API. Sesje szły w ramach subskrypcji.

## Interakcja i discovery

| Miara (suma 5 sesji) | A | B |
|---|---:|---:|
| Pytania biznesowe | **110** (20, 18, 20, 16, 36) | 34 (8, 6, 7, 6, 7) |
| Rundy z pytaniami | 7 | 31 |
| Puste zatwierdzenia sekcji | 0 | 23 |
| Punkty klucza przekazane na pytanie (z 87) | **64** (13, 7, 12, 15, 17) | 36 (8, 6, 7, 7, 8) |
| Punkty ujawnione dopiero w korekcie | **3** | 18 |
| Pierwszy projekt bez uwag PO | **2** (s06, s10) | 0 |
| Rundy korekty projektu | 3 | 6 (s04: 2) |
| Korekty sekcji przed pełnym projektem | 0 | 2 (s07, s09) |
| Korekty zapisanej specyfikacji | 1 (s06) | 0 |
| Pokrycie w 1. projekcie wg PO (spełnione/brak/sprzeczne) | 82 / 2 / 2 | 64 / 16 / 6 |
| Zmiany plików poza specyfikacją | 0 | s09: `docs/vision.md` (trzeci raz z rzędu u B) |
| Branch i commit | brak (zostaje na `main`) | 4/5 sesji |

Sprzeczności i braki w pierwszych projektach (wg PO):

| Para | A | B |
|---|---|---|
| 1 Ceny | K12 sprzeczny (ta sama cena = błąd 422) | K06 sprzeczny (limit 999 999,99), brak K11, K12 |
| 2 Kategorie | brak K10 (adresy, breadcrumby), K16 (ponowienie) | brak K05, K06, K08, K10, K15 |
| 3 Płatności | brak | brak K06, K09, K15, K16 |
| 4 Adresy | K15 sprzeczny (RODO odłożone mimo odpowiedzi) | brak K10, K15, K17; sprzeczne K12, K13, K16 |
| 5 Zwroty | brak | K06, K09, K12 sprzeczne; brak K13, K17 |

## Oceny jakości (średnia z oceny oryginalnej i zamienionej)

| Para | 1. projekt A | 1. projekt B | Spec. A | Spec. B |
|---|---:|---:|---:|---:|
| 1 Ceny | **3,9** | 3,3 | **4,1** | 4,0 |
| 2 Kategorie | **3,9** | 3,1 | 4,1 | **4,3** |
| 3 Płatności | **4,2** | 3,3 | **4,35** | 3,85 |
| 4 Adresy | **3,9** | 3,3 | 4,1 | **4,3** |
| 5 Zwroty | **4,2** | 3,2 | **4,3** | 4,1 |
| **Średnia** | **4,02** | 3,24 | **4,19** | 4,11 |

Zamiana etykiet zmieniała pojedyncze średnie o 0–0,8. Największy rozrzut miał 1. projekt B w t5 (2,8 vs 3,6).
Kierunek przewagi w pierwszych projektach był stały we wszystkich 10 ocenach.

### Wyniki A/B według zadań

**1. Ceny (A lepsza).** A pokryła 16/17 punktów, a twierdzenia o repo się potwierdziły. Jedyny błąd to K12
podany jako „Założenie” w projekcie, a nie jako pytanie (wbrew krokowi 8 Skilla). B miała limit niezgodny
z kluczem, brak powodu zmiany i dwa fałszywe twierdzenia o kodzie. W specyfikacji B pominęła wyszukiwarkę sklepu
i zależność Deptrac `→ Backoffice_Application`.

**2. Kategorie (1. projekt A, specyfikacja B).** A miała tylko 7 punktów z pytań (najmniej w A) i dwa braki, ale bez
fałszywych twierdzeń o repo. B w pierwszym projekcie pominęła pięć kryteriów akceptacji i podała 422 dla walidacji.
Po dwóch korektach specyfikacja B jako jedyna spełnia K08. Ma jednak luki: `TRUNCATE` w `DbalCatalogReset`
bez nowej tabeli historii (FK) i zbędne `name_key`. Specyfikacja A została sprzeczna z K08 (odstępstwo 2).

**3. Płatności (A lepsza).** Pierwszy projekt A przeszedł bez korekty: 12 punktów z pytań i prostsza architektura.
Przy zapisie specyfikacji A sama zmieniła jednak zaakceptowaną decyzję (spóźnione potwierdzenie nie dawało
`OrderPaid`, wbrew K05) i dostała korektę. To nowy typ błędu, którego krok „porównaj spec z projektem” nie
zatrzymał. B pominęła stronę powrotu, e-mail i niedostępność dostawcy, a w specyfikacji zostawiła sprzeczność
z K05 (opłacone zamówienie anulowane po 24 h) i ciężką infrastrukturę.

**4. Adresy (1. projekt A, specyfikacja B).** A zebrała 15 punktów pytaniami, ale K15 (RODO) odłożyła na przyszłą
funkcję z powodu braku akcji usuwania konta w Keycloak. Po korekcie dodała synchronizację kont: nową usługę,
sekret i punkt kontrolny. Recenzent uznał to za dużą złożoność z niewykonalnym ponawianiem w handlerze
transakcyjnym i błędnym `varchar(255)`. Specyfikacja B jest prostsza, ale usuwanie adresów wymaga ręcznej komendy.
B w pierwszym projekcie miała sześć uwag, w tym „wygrywa ostatni zapis” (K13).

**5. Zwroty (A lepsza).** A zadała 36 pytań biznesowych w dwóch rundach, dostała wszystkie 17 punktów i pierwszy
projekt przeszedł bez korekty. W specyfikacji została jedna sprzeczność z K12 („zwrot poza systemem”), którą PO
przeoczył. B miała pięć uwag w częściach projektu, a w specyfikacji 422 zamiast 400, nierozwiązaną integrację
między contextami wobec Deptrac i niezgłoszoną zależność `symfony/rate-limiter`. Zmieniła też `docs/vision.md`.

## Weryfikacja ocen

Sprawdzone w eksporcie repo (wszystkie potwierdzone):
- walidacja DTO → 400 (`ProblemDetailsSubscriber.php:113`), więc 422 u B (t2, t5) to błąd,
- `DbalCatalogReset.php:28` czyści tylko trzy tabele, więc nowa tabela historii z FK wymaga zmiany resetu (s04),
- `symfony/rate-limiter` nie ma w `composer.json` (s09),
- „zwrot poza systemem” w `designs/s10/final.md:75`,
- `productCount: null` w `designs/s03/final.md:177,306`.

Pozostałych zarzutów (fałszywe twierdzenia B w t1, `varchar(255)` i ponawianie w s08) nie zweryfikowałem
z powodu limitu.

## Czy poprawki v0.6 eliminują błędy z 003?

| Rekomendacja z 003 / zmiana v0.6 | Efekt w 004 |
|---|---|
| 1. Śledzenie odpowiedzi użytkownika (każde ustalenie w projekcie) | **Nie w pełni.** s08: K15 przekazany na pytanie, odłożony w projekcie. s06: zaakceptowana decyzja zmieniona przy zapisie spec. Poza tym 82/86 punktów pokrytych w 1. projektach. |
| 2. Przegląd współbieżności i konsumentów danych | **Tak.** W 003 błędy t1 K15 i t2 K18. W 004 żaden pierwszy projekt A nie miał sprzeczności we współbieżności (K13 w t1, K12–K14 w t2, K08/K10 w t3, K14 w t4, K16 w t5). |
| 3. Ograniczenie techniczne zawężające wymaganie = decyzja użytkownika | **Nie.** s08 (RODO z powodu Keycloak), s03 (liczba produktów pomijana przy awarii ES). |
| 4. Zbiorcze pytania, jedna akceptacja | **Tak.** 5,4 rundy vs 14,8; 2/5 projektów bez korekty (w 003: 0/5). |
| 5. Mniej tekstu w rundzie projektu | **Nie zmierzone celowo.** v0.6 tego nie zmieniała. Koszt A wzrósł z $2,47 do $2,90, choć zadania są inne. |

Dodatkowo: założenie biznesowe podane w projekcie zamiast pytania (s01 K12), wbrew istniejącemu krokowi 8.

## Porównanie z brainstorm-003

| | 003 (v0.5) | 004 (v0.6) |
|---|---|---|
| Rundy A / B | 5,2 / 15,0 | 5,4 / 14,8 |
| Pytania biznesowe A / B | 87 / 48 | 110 / 34 |
| Punkty z pytań A / B | 70/89 / 53/89 | 64/87 / 36/87 |
| Punkty w korekcie A / B | 7 / 10 | 3 / 18 |
| Pierwsze projekty bez korekty A / B | 0 / 1 | 2 / 0 |
| 1. projekt A / B | 3,98 / 3,51 | 4,02 / 3,24 |
| Specyfikacja A / B | 4,20 / 4,05 | 4,19 / 4,11 |
| Koszt A / B | $2,47 / $2,31 | $2,90 / $2,87 |
| Czas A / B | 570 s / 553 s | 765 s / 669 s |
| Zmiany `vision.md` przez B | 2 sesje | 1 sesja |

Zadania są inne, więc liczby bezwzględne nie są wprost porównywalne. Czasy 004 dodatkowo obciąża równoległy
benchmark `/delivery`.

## Powtarzające się błędy

| Typ | A (v0.6) | B (Superpowers) |
|---|---|---|
| Przekazany punkt pominięty / odłożony | s08 K15 | — |
| Zmiana zaakceptowanej decyzji przy zapisie spec | s06 K05 | — |
| Założenie zamiast pytania | s01 K12 | liczne (16 braków w 1. projektach) |
| Zawężenie z powodów technicznych | s08 K15, s03 K08 | s04 K08 (poprawione), s07 K15 |
| Sprzeczność ze specyfikacją nieprzechwycona przez PO | s03 K08, s10 K12 | s05 K05 |
| Niezgodność z kontraktem repo | drobne | 422 zamiast 400 (t2, t5), Deptrac (t1, t5), zależność bez zgody (t5) |
| Overengineering | s08 synchronizacja kont po korekcie | s05 infrastruktura płatności, s04 `name_key` |
| Zmiany plików przed kodem | brak | s09 `vision.md` |

## Rekomendacja

**Zostawić v0.6 jako bazę.** Pierwszy projekt jest wyraźnie lepszy przy ok. 1/3 interakcji, bez wzrostu kosztu
względem B. Proponuję dwie małe zmiany, bez nowej ceremonii:

1. **Odpowiedź użytkownika nie może zostać odłożona bez decyzji.** Gdy potwierdzone wymaganie koliduje z obecnym
   repo (np. brak funkcji w Keycloak), przedstaw to jako pytanie, a nie przyjmuj odłożenia. To wzmocnienie kroków 8
   i 10, nie nowy krok.
2. **Zapis spec bez zmiany decyzji.** Po zapisie wypisz każdą różnicę względem zaakceptowanego projektu; zmiana
   reguły biznesowej wymaga ponownej akceptacji (s06).

Przy obu zmianach warto zmierzyć efekt osobno. Koszt i czas A ($2,90, 765 s) pozostają otwartym problemem,
którego v0.6 nie adresowała.

## Artefakty

Wszystko poza repo, niezacommitowane:
- `~/Development/claude/bench-brainstorm-004/`:
  - `tasks.txt`, `answer-key.md`, `answer-key.sha256`, `keys-written.txt`, `po-rules.md`,
  - `driver.py`, `run_all.sh`, `stats.py`, `stats.jsonl`, `build_review.py`, `judge*.sh`, `judge-prompt.md`,
  - `mapping.json`, `blind-mapping.json`, `started.txt`,
  - `logs/` (stream-json, `.msg.md`, `.git`, `.wall`, `sNN.rounds.json`), `prompts/`, `po/sNN/`,
  - `designs/sNN/first.md` i `final.md`, `judge/r{1..5}.md` i `r{1..5}s.md`.
- `~/Development/claude/b004-runs/sNN/shop` – katalogi sesji w stanie końcowym.
- `~/Development/claude/b004-review/r{1..5}` i `r{1..5}s` – materiały recenzentów.
- Transkrypty: `~/.claude/projects/-Users-<user>-Development-claude-b004-runs-sNN-shop/`.
