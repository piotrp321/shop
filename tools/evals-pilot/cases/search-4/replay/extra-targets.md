# Dodatkowe cele pomiarowe: SEARCH-4

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`; część została naprawiona w rundzie poprawek, więc
w squashu ich nie ma. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, po pierwszym przebiegu odtworzenia, a przed drugim.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Important | `OptionFilter`: `option` z niepoprawnym UTF-8 przechodzi walidację i kończy się 500 zamiast 400. |
| E2 | Important | Wartości osi bez wyboru czyta jeden `terms` z globalnym limitem 400 zamiast „do 30 na oś”; odstępstwo od spec bez decyzji. |
| E3 | Minor | Opisy OpenAPI liczby przy wartości fasety są nieprawdziwe dla osi, która ma już wybór. |
| E4 | Minor | Komentarz w `ElasticsearchProductSearch` nie zgadza się z kodem (wybrana wartość jest dopisywana, lista może mieć ponad 30 pozycji). |
| E5 | Minor | `GROSZE_PATTERN` (`/^\d{1,12}$/`) przepuszcza końcowy znak nowej linii w `priceMin` i `priceMax`. |
| E6 | Minor | Nieużywany kod: `ProductSearchCriteria::hasVariantCondition`. |
| E7 | Minor | Test funkcjonalny pomija kontrolę braku echa wartości w nowych przypadkach `option`. |
| E8 | Minor | Nazwa testu naruszeń `option` i innych parametrów obiecuje więcej, niż test sprawdza. |
