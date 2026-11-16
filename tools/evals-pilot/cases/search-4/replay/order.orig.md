Zrecenzuj task SEARCH-4 (FULL review) na zamrożonym HEAD `78ddc299117301d42d048ba71a57fa30b047cd2e` w checkoutcie <home>/Development/claude/shop-SEARCH-4 (gałąź `task/SEARCH-4`). Diff taska: `git diff 07524862d7a208b446b522d5266d675b3a4efbf3..78ddc299117301d42d048ba71a57fa30b047cd2e` (merge-base z `origin/epic/search`). Nie zmieniaj kodu ani drzewa roboczego.

Specyfikacja: `docs/features/search/spec.md`, sekcja „Catalog: `GET /api/v1/products` (wyszukiwanie, filtry, fasety)” (parametry, Zapytanie, Fasety, nagłówki), decyzje S1, S2, S3, S8, S11, „Scenariusze błędów”, „Testy”, „Ryzyka”. Task w manifeście `docs/features/search/plan.md` (id SEARCH-4).

Scope: apps/api Catalog, apps/api/openapi.json, store TS client (`apps/store/src/api/schema.d.ts`), docs (`apps/api/CLAUDE.md`: parametry listProducts). Bez UI sklepu i bez zmian w admin.

Kryteria akceptacji:
1. „asfalt” znajduje „Buty szosowe Tarvik Asfalt 200”; „kurtki” kurtki, „spiwor” śpiwory, „plecal” plecaki; dwa słowa z jednym niepasującym nie dają wyniku, także z literówką.
2. Trafienie w nazwie wyżej niż w opisie, dokładne wyżej niż literówka.
3. „Kolor: czarne” + „Rozmiar: 42” tylko produkty z wariantem czarnym w rozmiarze 42; cena i dostępność dotyczą tego samego wariantu.
4. Liczba przy każdej wartości fasety = total po jej dodaniu; availableCount = total z available=true; wybrana wartość zostaje z 0.
5. Sortowanie po cenie wg from_gross, bez ceny na końcu, name i id jako tie-breakery.
6. Każdy niepoprawny parametr → 400 z violations; test funkcjonalny woła API adresem złożonym jak wygenerowany klient (powtarzany option).
7. openapi.json i klient sklepu aktualne; apps/api/CLAUDE.md opisuje parametry.
8. make check i make test dla api i store przechodzą (zweryfikowane przez orkiestratora: wszystkie cztery zielone; openapi i klient bez dryfu).

TDD: `tdd.status: lost_verified_on_base`. Dziennik dowodów: `<home>/.local/state/ao-search/tasks/SEARCH-4/delivery-state.json.evidence.jsonl`. Testy jednostkowe i integracyjny ES mają RED przed implementacją. Funkcjonalne `ProductsEndpointTest` i `testNumericAxisAndValueNamesWork` napisano bez RED. Orkiestrator sprawdził je na kodzie bazowym: padają z asercji. Zostanie to ujawnione w PR, nie odtwarzaj RED.

Decyzje developera do oceny (czy mieszczą się w spec):
- `option` czytany z surowego QUERY_STRING (`OptionFilter`), `option[]` → 400; `priceMin`/`priceMax` jako napisy z Regex w DTO.
- Liczba przy wartości osi, która już ma wybór, to liczba produktów z tą wartością i pozostałymi warunkami (bez warunku własnej osi), zgodnie z opisem agregacji w spec. Nie jest to suma zbiorów z już wybranymi wartościami. Oceń, czy to zgodne ze spec (kryterium 4 i opis „Fasety”).
- Wartości osi bez wyboru liczone jednym `terms` (do 400 wartości łącznie) i grupowane w PHP; osie z wyborem mają osobne gałęzie.
- Fasety kategorii składane w handlerze z drzewa (`CategoryTreeBuilder` wydzielony z `GetCategoryTreeHandler`).

Sprawdź zgodność ze spec, błędy logiczne (zapytanie ES, post_filter vs agregacje, reverse_nested, sortowanie z missing last, escapowanie regexu prefiksu osi, separator U+001F), architekturę (warstwy, Deptrac, port), przypadki brzegowe, testy i zbędną złożoność. Zwróć znaleziska z wagą (Critical/Important/Minor), plikiem:linią i uzasadnieniem, oraz werdykt.
