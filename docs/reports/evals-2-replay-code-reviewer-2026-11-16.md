# EVALS 2.0: pomiar `code-reviewer` w trybie odtworzenia (2026-11-16)

Drugi pomiar EVALS 2.0 (`docs/features/evals-2/spec.md`): 5 przypadków po 2 przebiegi, razem 10, w trybie `replay`
narzędzia `tools/evals-pilot/`. Każdy przebieg odtwarza pierwsze review FULL zadania: commit faktycznie recenzowany,
oryginalne zlecenie orkiestratora `/delivery`, `code-reviewer` jako subagent. Bez stosu Dockera i bez sieci.

- **Rewizja:** agent `.claude/agents/code-reviewer.md` z `main` `e30554f` (blob `057c8649`, ten sam co w pilocie),
  subagent na `claude-opus-5-5`, sesja nadrzędna na `claude-sonnet-5-5`, Claude Code 2.1.311.
- **Surowe wyniki** (poza repo): `~/.local/state/shop-evals-pilot/2026-11-16-replay/`.
- **Etykiety:** 8 zgłoszeń `IMPORTANT` z pierwszych przebiegów zatwierdził właściciel (2026-11-16). W drugich
  przebiegach 6 etykiet jest przeniesionych z tych decyzji na te same zgłoszenia, a 1 nowe zgłoszenie ma tylko
  propozycję (`nieznane`). Kryteriów wad wzorcowych nie zmieniano.
- **Pilot** dla porównania: `evals-2-pilot-code-reviewer-2026-11-15.md` (tryb `squash`, 3 przebiegi na przypadek).

## 1. Wykrywanie pierwotnych wad wzorcowych

| Wada | Przypadek | Odtworzenie (2 przebiegi) | Pilot (3 przebiegi) |
|---|---|---|---|
| #113: nil i max UUID dają dwa naruszenia jednego pola | `cart-fixes-3` | 0 z 2 | 0 z 3 |
| #197: UUID kategorii wielkimi literami daje pustą listę i fasety | `search-4` | 0 z 2 | 0 z 3 |
| #256: notatka z samych białych znaków Unicode jest przyjmowana | `order-management-2` | 2 z 2 | 3 z 3 |

Żaden raport nie wspomina o #113 ani #197. #256 jest przykładem w prompcie agenta.

## 2. Powtarzalność na dwóch przebiegach

| Przypadek | Werdykty | Zgłoszenia `IMPORTANT` |
|---|---|---|
| `cart-fixes-3` | CHANGES_REQUIRED, PASS | pusty napis `""`: raz `IMPORTANT`, raz `SUGGESTION` |
| `search-4` | 2× CHANGES_REQUIRED | cztery te same w obu przebiegach, w drugim jedno dodatkowe |
| `order-management-2` | 2× CHANGES_REQUIRED | te same dwa w obu przebiegach |
| `catalog-admin-3` | 2× PASS | wyścig pierwszej ceny: raz `IMPORTANT` spoza zakresu, raz `SUGGESTION` |
| `order-management-1` | 2× PASS | brak w obu |

Werdykt powtórzył się w 4 z 5 przypadków, wynik dla wady wzorcowej w 3 z 3. Z 8 zgłoszeń `IMPORTANT` pierwszego
przebiegu 6 wróciło jako `IMPORTANT`, a 2 jako `SUGGESTION`.

## 3. Zgłoszenia `IMPORTANT` (15 w 10 przebiegach)

| Etykieta | Liczba | Które |
|---|---|---|
| `wada` | 2 | #256 w obu przebiegach `order-management-2` |
| `trafne`, usterka kodu | 6 | `search-4`: 500 przy niepoprawnym UTF-8, fraza `q` z białych znaków Unicode, wspólny limit 400 wartości osi (każde w obu przebiegach) |
| `trafne`, proces TDD | 2 | `order-management-2`: poprawka `save()` bez fazy RED (w obu przebiegach) |
| `trafne` warunkowo | 1 | `catalog-admin-3`: wyścig pierwszej ceny ze zmianą klasy podatkowej; wymaga testu potwierdzającego |
| `drobne` | 3 | `cart-fixes-3`: pusty napis (1); `search-4`: końcowy znak nowej linii w cenie (2) |
| `nieznane` (propozycja) | 1 | `search-4`: liczniki dla osi z wyborem a kryterium 4; spec jest tu niejednoznaczny |
| `falszywe` | 0 | |

Jakość werdyktu, osobno: 4 uzasadnione `CHANGES_REQUIRED`, 4 zgodne `PASS`, 1 nieuzasadniony `CHANGES_REQUIRED`
(`cart-fixes-3`, tylko zgłoszenie `drobne`), 1 błędny `PASS` (`cart-fixes-3`, wada przeoczona).

**Dodatkowe cele pomiarowe** (`cases/<id>/replay/extra-targets.md`: 20 zgłoszeń oryginalnych pierwszych review, w tym
2 Important): w pierwszym przebiegu reviewer postawił ponownie 15 z 20, w drugim 14 z 20; oba Important w obu
przebiegach. To odczyt z raportów bez etykiet właściciela, a lista powstała po pierwszym przebiegu, więc niezależnym
pomiarem jest tylko drugi.

W pilocie na tych samych przypadkach było 2 fałszywe alarmy i 9 z 18 zgłoszeń `nieznane`. Tu zmieniły się naraz
recenzowany commit, zlecenie i sposób uruchomienia, więc różnicy nie da się przypisać jednemu z nich. W danych widać
tylko tyle: z dziewięciu zgłoszeń `nieznane` z pilota trzy dotyczyły kodu, którego w recenzowanym diffie nie ma, trzy
brakujących dowodów, które tutaj są, a trzy (angielskie komunikaty) wróciły raz, jako `SUGGESTION`.

## 4. Koszt i czas

| Pozycja | Koszt USD |
|---|---|
| 10 ocenianych przebiegów | 20,12 |
| 2 testy limitu kosztu (bez oceny) | 0,64 |
| Ekstrakcja (Sonnet) | 0,49 |
| Sędzia (Opus) | 0,90 |
| **Razem** | **22,16 z 25,00** |

Przebieg kosztował średnio 2,01 USD (od 1,00 do 2,78; limit 3 USD) i trwał średnio 341 s; 10 przebiegów zajęło
57 min. W pilocie przebieg kosztował średnio 1,20 USD. Sesja nadrzędna to 0,12–0,23 USD na przebieg, resztę zużywa
subagent. Limit `--max-budget-usd` obejmuje sesję nadrzędną i subagenta: przy limicie 0,30 USD sesja z pracującym
subagentem została przerwana przy 0,32 USD. Koszt to szacunek CLI według cennika.

## 5. Ograniczenia wiarygodności

- **Mała próba:** trzy wady wzorcowe po dwa przebiegi. Wynik pokazuje tylko duże różnice.
- **Przykład w prompcie:** jedyna wykryta wada (#256) jest wymieniona w prompcie agenta. Dwie wady spoza promptu
  nie zostały wykryte w żadnym z 10 przebiegów obu pomiarów.
- **Bez stosu Dockera:** produkcyjny reviewer może uruchamiać celowane testy, tutaj czyta tylko kod. Nie wiadomo,
  czy #113 da się znaleźć bez uruchomienia walidatora.
- **Stare zlecenia, nowy agent:** zlecenia napisała ówczesna wersja `/delivery`, a sesja nadrzędna to krótki prompt
  zamiast orkiestratora.
- **Etykiety:** 8 z 15 nadał właściciel, 6 jest przeniesionych, 1 to propozycja. Dodatkowe cele i przypadki czyste
  nie mają potwierdzenia właściciela, a sędzia widział tylko jeden rodzaj trafienia (#256).

## 6. Rekomendacje

1. **Zamrozić runner i używać trybu odtworzenia do porównań parami.** Wariant promptu `code-reviewer` kontra obecny,
   na tych samych przypadkach i zleceniach, z wynikiem w trzech osobnych liczbach: wady wzorcowe, cele dodatkowe,
   fałszywe alarmy. Budżet liczyć po 2 USD na przebieg.
2. **Przed strojeniem powiększyć zestaw o wady spoza promptu i potwierdzone przypadki czyste** oraz raportować
   osobno przypadki będące przykładami w prompcie. Inaczej optymalizacja nauczy agenta przykładów, a nie przeglądu.
3. **Zacząć od dwóch stale przeoczonych wad (#113, #197):** najpierw ustalić, czy da się je znaleźć z samego kodu,
   potem sprawdzić jedną zmianę promptu na przypadkach, których ta zmiana nie wymienia.

## Problemy infrastrukturalne pomiaru

- Pierwsza próba uruchomiła subagenta w tle, bo prompt nadrzędny nie ustawiał `run_in_background` na `false`;
  przebieg nie dostał oceny.
- Skrypt nie obsługiwał kilku linii wyniku w jednej sesji ani linii systemowej z tekstowym polem `message`; po jednym
  przebiegu wysypał się przed zapisem kosztu. Wynik i koszt odczytano z zapisanego transkryptu bez ponownego
  uruchamiania modelu. Wszystkie trzy błędy są poprawione i pokryte testami.
