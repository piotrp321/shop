# EVALS 2.0: pilot ewaluacji `code-reviewer` (2026-11-15)

Pierwszy pomiar EVALS 2.0 (`docs/features/evals-2/spec.md`): 5 prawdziwych przypadków po 3 przebiegi, razem 15,
narzędziem `tools/evals-pilot/` w trybie `squash` (squash commit zadania, zlecenie odtworzone ze skilla, agent jako
wątek główny).

- **Rewizja:** agent `.claude/agents/code-reviewer.md` z `main` `e30554f` (blob `057c8649`), model `claude-opus-5-5`
  z frontmattera, Claude Code 2.1.311, sandbox AGENT-SECURITY bez sieci i Dockera. Te same pliki przypadków i ta sama
  wersja agenta we wszystkich 15 przebiegach.
- **Surowe wyniki** (poza repo): `~/.local/state/shop-evals-pilot/2026-11-15/`: `runs/<przebieg>/report.md`,
  `stdout.jsonl`, `findings.json`, `judge.json`, `meta.json` oraz `labels.json`, `summary.md`, `ledger.jsonl`.
- **Etykiety:** właściciel, 2026-11-15.

## Wynik w skrócie

- **Wady wzorcowe:** wykryte w 3 z 9 przebiegów. #256 w 3 z 3, #113 w 0 z 3, #197 w 0 z 3.
- **Fałszywe alarmy `IMPORTANT`:** 2 w 15 przebiegach; oba to to samo zgłoszenie w `cart-fixes-3`.
- **Inne trafne usterki `IMPORTANT`:** 3, wszystkie to ta sama usterka w `search-4`, zgłoszona w każdym przebiegu.
- **PARTIAL:** 0. **UNKNOWN:** 9 z 18 zgłoszeń `IMPORTANT`. **MINOR:** 1.
- **Koszt:** 19,48 USD z 25 USD. **Czas przebiegów:** 48 min.
- **Etykiety:** wszystkie 18 zgłoszeń `IMPORTANT` ma etykietę zatwierdzoną przez właściciela: 6 bezpośrednio, 9
  przeniesionych na powtórzenia po sprawdzeniu w raportach, że to ten sam problem bez nowych okoliczności, 3 nowe jako
  UNKNOWN. UNKNOWN jest liczone osobno, poza trafieniami i fałszywymi alarmami.
- **Ocena właściciela:** udany proof of concept. Wynik 3 z 9 nie jest reprezentatywną skutecznością produkcyjnego
  `code-reviewer` (sekcja „Ocena właściciela i ograniczenia wyniku”).

## Wszystkie przebiegi

| Przebieg | Werdykt agenta | Wada wzorcowa | Etykiety zgłoszeń `IMPORTANT` | `SUGGESTION` | Koszt USD | Czas s | Tury |
|---|---|---|---|---|---|---|---|
| `cart-fixes-3-1` | CHANGES_REQUIRED | przeoczona | fałszywe | 1 | 0,62 | 98 | 15 |
| `cart-fixes-3-2` | CHANGES_REQUIRED | przeoczona | fałszywe | 1 | 0,68 | 176 | 13 |
| `cart-fixes-3-3` | PASS | przeoczona | nieznane | 1 | 0,76 | 152 | 17 |
| `search-4-1` | CHANGES_REQUIRED | przeoczona | trafne, nieznane, drobne, nieznane | 2 | 1,61 | 292 | 28 |
| `search-4-2` | CHANGES_REQUIRED | przeoczona | nieznane, trafne, nieznane | 1 | 1,64 | 308 | 34 |
| `search-4-3` | CHANGES_REQUIRED | przeoczona | nieznane, trafne, nieznane | 1 | 1,27 | 179 | 23 |
| `order-management-2-1` | CHANGES_REQUIRED | wykryta | wada | 1 | 1,44 | 202 | 25 |
| `order-management-2-2` | CHANGES_REQUIRED | wykryta | wada | 1 | 1,77 | 228 | 26 |
| `order-management-2-3` | CHANGES_REQUIRED | wykryta | wada | 1 | 1,56 | 175 | 23 |
| `catalog-admin-3-1` | PASS | — | — | 1 | 0,78 | 114 | 9 |
| `catalog-admin-3-2` | PASS | — | — | 3 | 0,73 | 140 | 11 |
| `catalog-admin-3-3` | PASS | — | — | 2 | 0,90 | 142 | 17 |
| `order-management-1-1` | PASS | — | — | 4 | 1,54 | 269 | 20 |
| `order-management-1-2` | CHANGES_REQUIRED | — | nieznane | 3 | 1,51 | 235 | 19 |
| `order-management-1-3` | CHANGES_REQUIRED | — | nieznane | 2 | 1,15 | 169 | 14 |

Wszystkie 15 przebiegów zakończyło się poprawnie; żaden nie trafił w limit 3 USD ani w limit czasu.

## Skuteczność wykrywania wad wzorcowych

| Wada | Przypadek | Wykryta | Uwagi |
|---|---|---|---|
| #113: nil i max UUID dają dwa naruszenia jednego pola | `cart-fixes-3` | 0 z 3 | Żaden raport nie wspomina o nil ani max UUID. Dwa przebiegi piszą, że `Sequentially` daje jedno naruszenie na pole. |
| #197: UUID kategorii wielkimi literami daje pustą listę i fasety | `search-4` | 0 z 3 | Żaden raport nie wspomina o wielkości liter ani o surowej wartości identyfikatora. |
| #256: notatka z samych białych znaków Unicode jest przyjmowana | `order-management-2` | 3 z 3 | Każdy raport wskazuje `trim()` działające tylko na ASCII i samą NBSP, a do tego separatory linii. Ta wada jest przykładem w prompcie agenta. |

Sam werdykt `CHANGES_REQUIRED` padł w 8 z 9 przebiegów z wadą. Gdyby to on był miarą, wynik wyglądałby na 8 z 9
zamiast 3 z 9.

## Zgłoszenia `IMPORTANT` według etykiet (18)

| Zgłoszenie | Przebiegi | Etykieta | Źródło etykiety |
|---|---|---|---|
| `cart-fixes-3`: pusty napis `""` przechodzi walidację, a spec go odrzuca | 1, 2 | fałszywe | właściciel; w powtórzeniu przeniesiona |
| `cart-fixes-3`: brak wyniku `check`, `test` i eksportu OpenAPI w dzienniku | 3 | nieznane | właściciel |
| `order-management-2`: notatka z białych znaków Unicode (mechanizm #256) | 1, 2, 3 | wada | właściciel; w powtórzeniach przeniesiona |
| `search-4`: fraza `q` z samych białych znaków Unicode przechodzi walidację | 1, 2, 3 | trafne | właściciel; w powtórzeniach przeniesiona |
| `search-4`: komunikaty naruszeń po angielsku | 1, 2, 3 | nieznane | właściciel; w powtórzeniach przeniesiona |
| `search-4`: zmiana modelu indeksu i spec poza zakresem | 1, 2, 3 | nieznane | właściciel; w powtórzeniach przeniesiona |
| `search-4`: test odtwarza serializer klienta w PHP | 1 | drobne | właściciel |
| `order-management-1`: dziennik TDD nie dowodzi RED po nazwach testów | 2, 3 | nieznane | właściciel |

### Fałszywe alarmy

Dwa, oba w `cart-fixes-3` i oba to samo zgłoszenie o pustym napisie. W trzecim przebiegu ten sam reviewer uznał to
zachowanie za poprawne i zgodne ze spec („kody odpowiedzi bez zmian”), więc na tych samych danych doszedł do
przeciwnego wniosku.

### PARTIAL

Brak. Wszystkie trzy przebiegi `order-management-2` opisują NBSP i `trim()`, a nie same separatory linii.

### UNKNOWN (9)

- **Komunikaty po angielsku w `search-4` (3):** do potwierdzenia wymagań lokalizacji API.
- **Zmiana poza zakresem w `search-4` (3):** przypadek nie zawiera historii akceptacji.
- **Brak dowodu RED w `order-management-1` (2):** dziennik ma wpis RED (24 błędy i 10 niepowodzeń na
  35 testów, w drzewie tylko pliki testów), ale ucięty `output_tail` nie wymienia trzech klas testów po nazwie.
  Czy to „brak RED” w rozumieniu reguły agenta, zależy od właściciela. Przebieg 1 i oryginalne review dały tu `PASS`.
- **Brak wyniku `check` i `test` w `cart-fixes-3-3` (1):** raport sam zastrzega, że uwaga znika, jeśli
  raport developera zawiera te wyniki. Przypadek nie ma raportu developera.

### Inne trafne usterki

Fraza `q` z samych białych znaków Unicode w `search-4`: zgłoszona w 3 z 3 przebiegów i potwierdzona w kodzie
(`Assert\Length(normalizer: 'trim')`). Ten kod jest dziś na `main` i nie ma zgłoszenia.

## Przypadki czyste

- **`catalog-admin-3`:** 3 z 3 `PASS`, bez `IMPORTANT`. Wyścig pierwszej ceny ze zmianą klasy podatkowej został sprawdzony
  w kodzie i jest prawdziwy: `SetVariantPriceHandler` czyta klasę przed zapisem i potem jej nie sprawdza, a przeliczenie
  widzi tylko zapisane ceny. Skutek to błędne rozbicie VAT przy poprawnym brutto, do ręcznego `pricing:reconcile-tax`.
  Diff opisuje to w spec jako znany przypadek, a kod na `main` jest taki sam. Reviewer zgłosił to w 1 z 3 przebiegów,
  jako `SUGGESTION`. Przypadek jest oznaczony jako czysty warunkowo (`cases/catalog-admin-3/known.md`). Potwierdzenie
  pochodzi z czytania kodu, bez testu.
- **`order-management-1`:** 1 z 3 `PASS`. Dwa przebiegi dały `CHANGES_REQUIRED` wyłącznie przez zgłoszenie o dowodach
  RED (UNKNOWN). Żaden przebieg nie zgłosił błędu w kodzie jako `IMPORTANT`.

## Stabilność między powtórzeniami

| Przypadek | Werdykty | Zgłoszenia `IMPORTANT` |
|---|---|---|
| `cart-fixes-3` | CHANGES_REQUIRED, CHANGES_REQUIRED, PASS | to samo w 2 przebiegach, inne w trzecim |
| `search-4` | 3× CHANGES_REQUIRED | trzy te same w każdym przebiegu, czwarte tylko w pierwszym |
| `order-management-2` | 3× CHANGES_REQUIRED | to samo w każdym przebiegu |
| `catalog-admin-3` | 3× PASS | brak |
| `order-management-1` | PASS, CHANGES_REQUIRED, CHANGES_REQUIRED | brak, potem to samo w 2 przebiegach |

Werdykt zmienił się między powtórzeniami w 2 z 5 przypadków.

## Sędzia i ekstrakcja

- **Sędzia (Opus):** 26 odpowiedzi w przebiegach z wadą: 3×TAK (zgłoszenie #256 w każdym przebiegu), 23×NIE, 0×NIE WIEM.
  Zgodny z 16 etykietami właściciela w tych przebiegach. To nie jest kalibracja: sędzia nie dostał ani
  jednego trudnego przypadku (częściowe trafienie, inna wada w tym samym mechanizmie), a wszystkie TAK dotyczą
  jednej wady. Żadna jego odpowiedź nie została użyta jako etykieta.
- **Ekstrakcja (Sonnet):** 43 zgłoszenia, wszystkie 43 cytaty są dosłownie w raportach. Liczbę i wagi zgłoszeń
  porównano z nagłówkami raportów dla wszystkich 15 przebiegów (13 skryptem, 2 ręcznie); zgadzają się. Pole „pliki” bywa niepełne i nie wchodzi do wyniku.

## Koszt i czas

| Pozycja | Koszt USD |
|---|---|
| 15 przebiegów agenta | 17,94 |
| Ekstrakcja znalezisk (15 wywołań, Sonnet) | 0,53 |
| Sędzia (26 wywołań, Opus) | 0,85 |
| Test limitu w trakcie wywołania | 0,16 |
| **Razem** | **19,48 z 25,00** |

Przebieg kosztował średnio 1,20 USD (od 0,62 do 1,77) i trwał średnio 192 s (od 98 do 308); łącznie 48 min.
Koszt to szacunek CLI według cennika. Próby narzędzi przed pilotem (ok. 2,5 USD) nie wchodzą do tej sumy.

## Co ten pilot mówi o metodzie

**Co działa**
- Każdy wynik da się prześledzić: etykieta → zgłoszenie z cytatem → raport agenta → transkrypt.
- Wynik nie zależy od werdyktu, więc nie ma fałszywych zaliczeń typu „`CHANGES_REQUIRED` z innego powodu”.
- Limity kosztu zadziałały: przed startem, w trakcie wywołania i łącznie.
- Wyniki dla trzech wad wzorcowych są zgodne we wszystkich powtórzeniach (0 z 3, 0 z 3, 3 z 3).

**Co ogranicza wiarygodność**
- Połowy zgłoszeń `IMPORTANT` (9 z 18) nie da się ocenić z danych przypadku. Przypadkom brakuje tego, co reviewer
  ma w produkcji albo co wie tylko właściciel: raportu developera z wynikami `check` i `test`, historii akceptacji zmian
  spec, wymagań lokalizacji, wykładni reguły RED.
- Jedyna wykryta wada jest przykładem w prompcie agenta. Dwie wady spoza promptu dały 0 z 6, ale to trzy wady
  i po trzy przebiegi: wystarcza na „duża różnica”, nie na odsetki.
- Przypadki czyste nie są potwierdzone przez właściciela, a jeden okazał się mieć udokumentowane ograniczenie.
- Sędzia jest niesprawdzony na trudnych parach.
- 9 z 18 etykiet to decyzje właściciela przeniesione na powtórzenia tego samego zgłoszenia.
- Warunki różnią się od produkcji: agent jest wątkiem głównym, a nie subagentem `/delivery`; nie ma działającego
  stosu; sandbox odrzucił 6 poleceń w 4 przebiegach; raport `search-4-1` wyszedł po angielsku.

## Jakość werdyktu (osobno od wykrywania wad)

Werdykt jest oceniany po tym, czy ma podstawę w zatwierdzonych etykietach, niezależnie od tego, czy wada wzorcowa została
wykryta.

| Ocena werdyktu | Przebiegi | Które |
|---|---|---|
| `CHANGES_REQUIRED` uzasadniony (jest zgłoszenie `wada` albo `trafne`) | 6 | `search-4` ×3 (inna trafna usterka, wada wzorcowa przeoczona), `order-management-2` ×3 |
| `PASS` zgodny (czysty przypadek, brak `IMPORTANT`) | 4 | `catalog-admin-3` ×3, `order-management-1-1` |
| `CHANGES_REQUIRED` nieuzasadniony (tylko fałszywy alarm) | 2 | `cart-fixes-3-1`, `cart-fixes-3-2` |
| `PASS` błędny (wada wzorcowa przeoczona) | 1 | `cart-fixes-3-3` |
| Nierozstrzygnięty (werdykt oparty tylko na UNKNOWN) | 2 | `order-management-1-2`, `order-management-1-3` |

W `search-4` werdykt jest uzasadniony, choć wada wzorcowa nie została wykryta. Dlatego obie miary trzeba czytać osobno.

## Ocena właściciela i ograniczenia wyniku

Właściciel uznał pilot za udany proof of concept (2026-11-15): da się uruchomić prawdziwego agenta, zebrać raport,
ocenić konkretne znaleziska i zmierzyć koszt. Wynik 3 z 9 **nie jest** miarą skuteczności produkcyjnego `code-reviewer`:

- **Przykład w prompcie.** Jedyna wykryta wada (#256) jest wymieniona w prompcie agenta jako przykład. Wynik 3 z 3
  mierzy więc także to, że agent zna ten wzorzec, a nie tylko jego umiejętność przeglądu.
- **Mała próba.** Trzy wady i dwa czyste przypadki po trzy przebiegi. To wystarcza, żeby zobaczyć dużą różnicę, ale
  nie żeby podać odsetek.
- **Różnice środowiskowe.** Zlecenie pilota porównano po pomiarze z prawdziwym zleceniem z transkryptu AO zadania
  CART-FIXES-3. Produkcyjny reviewer:
  - jest subagentem wywołanym przez orkiestratora `/delivery` (Opus), a nie wątkiem głównym;
  - dostaje zlecenie napisane przez orkiestratora, z wynikami `make check` i eksportu OpenAPI, ze wskazówkami,
    co sprawdzić, i z ujawnieniem: „developer świadomie pominął przypadek pustego napisu `""` […] oceń, czy to
    rzeczywiście poza zakresem”;
  - ma działający stos Dockera i może uruchamiać celowane testy;
  - recenzuje HEAD gałęzi zadania sprzed rundy poprawek, a nie squash commit z gałęzi epiku. Squash zawiera już
    poprawkę dokumentacji, która powstała po tamtym review, i dziennik TDD odnosi się do commitów, których w squashu nie ma.
    W SEARCH-4 squash obejmuje też rundę poprawek po pierwszym review (56 plików zamiast 38), w tym zmianę modelu
    indeksu (`ProductIndexDefinition.php`), której pierwsze review w ogóle nie widziało.

  Oba fałszywe alarmy (pusty napis) i trzy z dziewięciu UNKNOWN (brak wyniku `check` i `test`, brak dowodu RED)
  dotyczą informacji, które produkcyjny reviewer ma w zleceniu albo w checkoucie (ujawnienie o `""`, wyniki `check`,
  commity gałęzi zadania), a reviewer w pilocie nie miał. Kolejne trzy UNKNOWN (zmiana modelu indeksu w `search-4`)
  dotyczą kodu, którego w recenzowanym wtedy diffie nie było. Czy z nimi by tych zgłoszeń nie postawił, pokaże dopiero
  pomiar w trybie `replay`.

## Kandydaci do osobnych zgłoszeń

Do triage właściciela; żadne zgłoszenie nie powstało.

- Fraza `q` z samych białych znaków Unicode przechodzi walidację `GET /api/v1/products` (potwierdzone w kodzie na `main`).
- Wyścig pierwszej ceny wariantu ze zmianą klasy podatkowej (potwierdzony czytaniem kodu; wymaga testu potwierdzającego).
