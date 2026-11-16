# EVALS 2.0: eksperyment A/B, punkt „Przepływ wartości wejściowych” w `code-reviewer` (2026-11-16)

Pierwsza próba poprawy `code-reviewer` z użyciem EVALS 2.0 (`docs/features/evals-2/spec.md`): obecny agent kontra ten
sam agent z jednym dopisanym punktem listy kontrolnej. Tryb `replay`, 6 przypadków, 2 przebiegi na przypadek
w każdym wariancie, bez stosu Dockera.

**Wynik: zmiana nie spełnia kryteriów przyjęcia. Rekomendacja: zostać przy obecnym prompcie.**

- **Wariant A (obecny):** `.claude/agents/code-reviewer.md` z `main`, blob `057c8649`.
- **Wariant B:** gałąź `evals/code-reviewer-value-flow`, commit `b65a582d`, blob `0d1bf82f`. Jedna dopisana linia:
  punkt „Przepływ wartości wejściowych” (klasy wartości, które walidacja przepuszcza, a testy diffu nie sprawdzają;
  prześledzenie ich do miejsca użycia; niezakładanie zachowania biblioteki). Model i effort bez zmian
  (`claude-opus-5-5`), Claude Code 2.1.311 w obu wariantach.
- **Przebiegi:** wariant A ma 10 przebiegów z pomiaru `evals-2-replay-code-reviewer-2026-11-16.md` (te same przypadki,
  zlecenia i wersja CLI, ten sam dzień) i 2 nowe na przypadku `cart-4`. Wariant B ma 12 nowych przebiegów.
- **Surowe wyniki** (poza repo): `~/.local/state/shop-evals-pilot/2026-11-16-ab/` i `…/2026-11-16-replay/`.
- **Etykiety:** właściciel. Zgłoszenia z nowego przypadku `cart-4` ocenił na ślepo, bez informacji o wariancie; dla
  pozostałych przypadków ślepa ocena nie była możliwa, bo w eksperymencie działał na nich tylko wariant B.
- Kryteria przyjęcia zapisano przed pomiarem i nie zmieniano ich po wynikach.

## 1. Wykrywanie wad wzorcowych

| Wada | Przypadek | Wariant A | Wariant B |
|---|---|---|---|
| #113: nil i max UUID dają dwa naruszenia jednego pola | `cart-fixes-3` | 0 z 2 | 0 z 2 |
| #197: UUID kategorii wielkimi literami | `search-4` | 0 z 2 | 0 z 2 |
| #256: notatka z białych znaków Unicode (przykład z promptu) | `order-management-2` | 2 z 2 | 2 z 2 |
| #81: zły komunikat po 404 dla linii, która zostaje (kontrola spoza promptu) | `cart-4` | 0 z 2 | 2 z 2 |

Wariant B stosował nowy punkt w każdym przebiegu: raporty mają sekcję o przepływie wartości. W `cart-fixes-3`
prześledził pusty napis, a w jednym przebiegu także UUID wielkimi literami, ale nie nil ani max UUID. W `search-4` prześledził `option`, ceny
i `q`, ale nie identyfikator kategorii. Obie wady, dla których punkt powstał, zostały więc przeoczone tak jak wcześniej.

Wada #81, której punkt nie dotyczył, została wykryta tylko przez wariant B, w obu przebiegach. To jedyny przypadek
porównany w pełni równolegle (przebiegi obu wariantów na przemian).

## 2. Zgłoszenia `IMPORTANT`

| Etykieta | Wariant A (12 przebiegów) | Wariant B (12 przebiegów) |
|---|---|---|
| `wada` (wada wzorcowa) | 2 | 3 |
| `trafne`, usterka kodu | 6 | 6 |
| `trafne`, proces TDD | 4 | 4 |
| `trafne` warunkowo (PROVISIONAL) | 5 | 5 |
| `drobne` | 3 | 2 |
| `nieznane` (UNKNOWN) | 1 | 2 |
| `falszywe` | 0 | 0 |
| Razem | 21 | 22 |

- **Trafne usterki kodu** to w obu wariantach te same trzy w `search-4`: 500 przy niepoprawnym UTF-8, fraza `q`
  z białych znaków Unicode i wspólny limit 400 wartości osi, każda w obu przebiegach. W jednym przebiegu wariantu B
  fraza `q` i końcowy znak nowej linii są w jednym zgłoszeniu; liczone raz.
- **PROVISIONAL** to trzy problemy niepotwierdzone odtworzeniem: wyścig pierwszej ceny ze zmianą klasy podatkowej
  (`catalog-admin-3`, po 1 w każdym wariancie), równoległe pierwsze dodania gościa i utrata focusu po zmianie ilości
  (`cart-4`, po 2 w każdym wariancie). Nie są liczone jako potwierdzone usterki.
- **UNKNOWN:** liczniki dla osi z wyborem w `search-4` (po 1 w każdym wariancie) i dowód RED bez nazw testów
  w `order-management-1` (tylko wariant B, 1 przebieg).
- **Fałszywych alarmów nie było** w żadnym wariancie.
- Wszystkie 77 zgłoszeń z 14 nowych przebiegów ma cytat z raportu; 76 jest dosłownych, jeden to nagłówek z drobną
  różnicą zapisu.

### Jakość werdyktu

| Ocena werdyktu | Wariant A | Wariant B |
|---|---|---|
| `CHANGES_REQUIRED` uzasadniony (jest `wada` albo potwierdzone `trafne`) | 6 | 6 |
| `PASS` zgodny | 4 | 2 |
| `CHANGES_REQUIRED` oparty tylko na PROVISIONAL | 0 | 1 |
| `CHANGES_REQUIRED` oparty tylko na UNKNOWN | 0 | 1 |
| `CHANGES_REQUIRED` nieuzasadniony (tylko `drobne`) | 1 | 1 |
| `PASS` błędny (wada wzorcowa przeoczona) | 1 | 1 |

W przypadkach czystych wariant A dał cztery razy `PASS`, a wariant B dwa razy `PASS` i dwa razy `CHANGES_REQUIRED`:
raz z powodu wyścigu pierwszej ceny (`catalog-admin-3`), raz z powodu dowodu RED (`order-management-1`).

## 3. Powtarzalność i wagi

| Przypadek | Werdykty A | Werdykty B |
|---|---|---|
| `cart-fixes-3` | CHANGES_REQUIRED, PASS | PASS, CHANGES_REQUIRED |
| `search-4` | 2× CHANGES_REQUIRED | 2× CHANGES_REQUIRED |
| `order-management-2` | 2× CHANGES_REQUIRED | 2× CHANGES_REQUIRED |
| `cart-4` | 2× CHANGES_REQUIRED | 2× CHANGES_REQUIRED |
| `catalog-admin-3` | 2× PASS | PASS, CHANGES_REQUIRED |
| `order-management-1` | 2× PASS | CHANGES_REQUIRED, PASS |

- Werdykt powtórzył się w 5 z 6 przypadków w wariancie A i w 3 z 6 w wariancie B.
- Wynik dla każdej wady wzorcowej był taki sam w obu przebiegach, w obu wariantach.
- **Wagi:**
  - #81 w wariancie B: raz `IMPORTANT`, raz `SUGGESTION`. Wykrycie liczone w obu, waga zaniżona w jednym.
  - #256: zawsze `IMPORTANT`, w obu wariantach.
  - Pusty napis w `cart-fixes-3` (etykieta MINOR): w każdym wariancie raz `IMPORTANT`, raz `SUGGESTION`.
  - Wyścig pierwszej ceny: w każdym wariancie raz `IMPORTANT`, raz `SUGGESTION`.
  - Końcowy znak nowej linii w cenie (etykieta MINOR): `IMPORTANT` we wszystkich czterech przebiegach `search-4`.

## 4. Koszt i czas

| | Przebiegi | Koszt USD | Średnio na przebieg | Średni czas |
|---|---|---|---|---|
| Wariant A, zmierzone wcześniej (5 przypadków) | 10 | 20,12 | 2,01 | 341 s |
| Wariant A, nowe (`cart-4`) | 2 | 3,54 | 1,77 | 314 s |
| **Wariant A razem** | 12 | 23,66 | **1,97** | **337 s** |
| **Wariant B, wszystkie nowe** | 12 | 25,99 | **2,17** | **372 s** |

Wariant B jest droższy o 10% i wolniejszy o 10%. Na `cart-4`, mierzonym równolegle, kosztował 1,71 USD wobec 1,77 USD.

Koszt eksperymentu: 32,12 USD z limitu 35 USD (nowe przebiegi 29,53, ekstrakcja 0,75, sędzia 1,84). Żaden przebieg
nie trafił w limit 4 USD; wszystkie 14 zakończyły się poprawnie. Koszt to szacunek CLI według cennika.

## 5. Kryteria przyjęcia (zapisane przed pomiarem)

| # | Kryterium | Wynik | Ocena |
|---|---|---|---|
| 1 | Co najmniej jedna z wad #113, #197 wykryta w obu przebiegach wariantu | 0 z 2 i 0 z 2 | **niespełnione** |
| 2 | Brak regresji: #256 2 z 2; oba oryginalne Important w `search-4` w obu przebiegach; #81 nie gorzej niż w A | 2 z 2; oba w obu; 2 z 2 wobec 0 z 2 | spełnione |
| 3 | Żadnego `falszywe` `IMPORTANT` w przypadkach czystych, oba czyste 2× `PASS`, najwyżej jedno nowe `falszywe` | 0 fałszywych; `PASS` tylko w 2 z 4 przebiegów czystych | **niespełnione** |
| 4 | Średni koszt przebiegu ≤ 125% wariantu A | 110% | spełnione |

Reguła zapisana z góry: zmiana wchodzi tylko przy spełnieniu wszystkich kryteriów, a niespełnienie któregoś
z kryteriów 2–4 ją wyklucza. Kryteria 1 i 3 nie są spełnione.

## 6. Rekomendacja

**Nie wdrażać punktu „Przepływ wartości wejściowych”. Zostać przy obecnym prompcie.**

- Punkt nie zrobił tego, po co powstał: reviewer go stosował, ale śledził inne wartości niż te, w których są wady
  #113 i #197. Ogólna instrukcja sposobu pracy nie wystarczyła, żeby trafić w te dwie klasy.
- W przypadkach czystych werdykt przestał być stabilny: dwa z czterech przebiegów dały `CHANGES_REQUIRED`
  z powodu zgłoszeń niepotwierdzonych albo nierozstrzygniętych.
- Wykrycie #81 w 2 z 2 przebiegów wobec 0 z 2 to sygnał wart sprawdzenia, ale nie dowód: dwa przebiegi na wariant,
  jeden przypadek, a kryterium sukcesu go nie obejmowało. Nie uzasadnia wdrożenia wbrew kryteriom.

## Ograniczenia

- Dwa przebiegi na przypadek w wariancie; różnice mniejsze niż „zawsze kontra nigdy” nie są rozstrzygalne.
- Dziesięć przebiegów wariantu A pochodzi z pomiaru wcześniej tego samego dnia, a nie z przebiegów na przemian.
- Etykiety PROVISIONAL (10 z 43 zgłoszeń `IMPORTANT`) czekają na odtworzenie; jeśli się nie potwierdzą, część
  werdyktów wariantu B w przypadkach czystych okaże się nieuzasadniona, a jeśli się potwierdzą, uzasadniona.
- Bez stosu Dockera: #113 może wymagać uruchomienia walidatora, którego kodu nie ma w checkoucie.
- Jeden niezależny przypadek kontrolny z wadą (#81).
