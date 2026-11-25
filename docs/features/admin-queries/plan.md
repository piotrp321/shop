# PERF-7. Zapytania panelu i mapy strony – plan zadań

Specyfikacja: `docs/features/admin-queries/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami
(`docs/adr/agentic-software-house.md`, „Przebieg epiku”). Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main`
otwartym od początku jako szkic; zadania realizuje subagent we własnym worktree, sesja orkiestrująca merguje gałęzie
zadań do gałęzi epiku. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem roboczym: po
ukończeniu epiku usuń go.

Start dopiero po raporcie PERF-0, zatwierdzeniu W1–W20 i decyzjach D1–D3 (specyfikacja, „Wymagane przed startem”).

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PERF-7-1 | |
| 2 | PERF-7-2, PERF-7-4, PERF-7-5, PERF-7-6 | |
| 3 | PERF-7-3 | |
| 4 | PERF-7-7 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## TDD i pomiary w zadaniach

Zadania 2–6 dopisują najpierw testy (równoważność wyników, testy planów, walidacja, pager), uruchamiają je i zapisują
w raporcie komendę i wynik RED, potem zmiana i GREEN, `check` i testy dotkniętych aplikacji. Test planu, który
przechodzi przed zmianą dla klasy już dziś ograniczonej (np. lista bez filtrów), jest regresją i raport to zaznacza.
Zadania 1 i 7 to pomiary: raport podaje polecenia, wersję danych, konfigurację Postgresa i wyniki trzech przebiegów
z rozrzutem. Lokalne pomiary nie nakładają się na ciężkie pomiary PERF-2; pomiar na worf.pl tylko żądaniami GET i za
osobną zgodą właściciela.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PERF-7",
  "spec": "docs/features/admin-queries/spec.md",
  "tasks": [
    {
      "id": "PERF-7-1",
      "title": "Scenariusze W7 i P4, pomiar przed zmianą, prototypy progów",
      "scope": "Scenariusz HTTP mieszanki panelu (k6 w `load/`, token administratora jak w E2E, mieszanka generowana z danych i zapisywana z wynikiem), zestaw zapytań macierzy (`EXPLAIN (ANALYZE, BUFFERS)`, na bazie plików `.sql` z `docs/reports/performance-audit-2026-11-25/results/admin` i `results/pg`, uzupełnionych o przypadki macierzy, których audyt nie miał) i scenariusz P4 (W1 i W5 z równoległym panelem i bez, scenariusze PERF-0). Pomiar przed zmianą na `small` i `medium`. Sprawdzenie hipotez specyfikacji: duży liść z typem na końcu alfabetu na `medium`, zapytanie żyjące w `pg_stat_activity` po przerwaniu klienta (A3). Prototypy SQL strategii (zbiór kandydatów, gałęzie kategorii na tymczasowej tabeli członkostwa, przejście okienkowe, kandydaci z GIN bigramów, granice z histogramu) na kopii `medium`; wybór T, W i B z pomiaru. Bez zmian kodu aplikacji.",
      "acceptance_criteria": [
        "Scenariusze uruchamia jedno polecenie opisane w `load/README.md`; mieszanka i wynik są zapisane razem.",
        "Raport w `docs/reports/` z tabelą macierzy (czas i bufory, `small` i `medium`), W7 mieszanki i P4 przed zmianą, trzy przebiegi z rozrzutem.",
        "Raport rozstrzyga obie hipotezy specyfikacji wynikiem pomiaru i podaje wybrane T, W i B z uzasadnieniem z prototypów.",
        "Jeśli prototyp pokazuje, że rekomendacja D1, D2 albo D3 nie spełnia P2 albo P3, zadanie kończy się raportem i pytaniem do właściciela, zanim ruszy fala 2."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "parallel_with": [],
      "paths": ["load/", "docs/reports/"]
    },
    {
      "id": "PERF-7-2",
      "title": "Lista produktów: wybór planu po liczbie trafień dla frazy, szkiców i bez zdjęć",
      "scope": "`DbalBackofficeProductSource` (albo wydzielona klasa wyboru planu w tym samym module): ograniczone liczniki filtrów selektywnych, zbiór kandydatów do T, przejście okienkowe z budżetem B i przejściem na kandydatów z GIN, licznik pierwszej strony z tej samej strategii. Funkcja `catalog_name_bigrams` i indeks GIN bigramów (migracja nietransakcyjna, `CONCURRENTLY`) dla fraz bez trygramu. Filtr kategorii zostaje w obecnej postaci do zadania 3. Specyfikacja: „Strategia listy produktów” (kroki 1, 2, 4, 5), „Frazy krótkie i bez trygramu” (produkty). Komentarz o planach w `DbalBackofficeProductSource` opisuje stan po zmianie.",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla fraz, szkiców i bez zdjęć: test równoważności z obecnym SQL (wiersze, kolejność, kursory, liczniki), także dla fraz z polskimi znakami, wielkością liter, `%`, `_` i `\\`.",
        "Kryterium 2 dla klas „zbiór kandydatów”, „przejście okienkowe” i „przejście okienkowe + kandydaci”: testy planów na ok. 100 tys. wierszy pokazują liczbę wierszy ograniczoną klasą; przypadek skorelowany (fraza skupiona na końcu alfabetu) i 2-znakowy rzadki są w testach.",
        "Kandydaci z GIN bigramów są nadzbiorem wyniku `ILIKE` w teście na korpusie z polskimi znakami i wielkimi literami.",
        "Ekrany Ceny, Stany i wybór wariantów promocji: istniejące testy przechodzą bez zmian.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-7-1"],
      "parallel_with": ["PERF-7-4", "PERF-7-5", "PERF-7-6"],
      "paths": ["apps/api/src/Catalog/", "apps/api/migrations/", "apps/api/tests/"]
    },
    {
      "id": "PERF-7-3",
      "title": "Tabela członkostwa kategorii i gałęzie kategorii w liście produktów",
      "scope": "Wariant wybrany w D1 (rekomendacja: `catalog_product_category_listing`). Migracja tabeli i indeksów z wypełnieniem `INSERT … SELECT`; utrzymanie w `DoctrineProductRepository::save` w tej samej transakcji; uzupełnienie w każdym miejscu, które zapisuje `catalog_product` albo `catalog_product_category` z pominięciem repozytorium (`git grep` obu nazw: generator `DemoData`, `ProductPhotos`, seed). Filtr kategorii w liście: poddrzewo jako identyfikatory, licznik kategorii w strategii z zadania 2, gałęzie kategorii ze scaleniem i usunięciem powtórzeń, licznik pierwszej strony po kluczu głównym tabeli. Specyfikacja: „Strategia listy produktów” (krok 3), „Tabela członkostwa kategorii (D1)”.",
      "acceptance_criteria": [
        "Kryterium 9 specyfikacji: test zgodności tabeli z danymi źródłowymi po zapisie produktu (nazwa, status, zdjęcia, kategorie dodatkowe), usunięciu produktu, aktualizacji zdjęć z `DemoData` i po generatorze.",
        "Kryterium 1 dla filtra kategorii, także z frazą, szkicami i bez zdjęć: równoważność z obecnym SQL, w tym produkt z kategorią główną i dodatkową w tym samym poddrzewie (bez duplikatu) i kategoria pusta.",
        "Kryterium 2 dla klasy „gałęzie kategorii”: testy planów na ok. 100 tys. wierszy dla małej kategorii, dużego liścia z typem na końcu alfabetu, działu i kategorii głównie dodatkowej.",
        "Zmiana nazwy i przeniesienie kategorii nie zmieniają tabeli, a lista po nich pokazuje produkty nowego poddrzewa (test).",
        "Czas generatora dla `medium` przed i po w raporcie zadania (wpływ na W20); rozmiar tabeli i indeksów na `medium`.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": ["PERF-7-2"],
      "parallel_with": [],
      "paths": ["apps/api/src/Catalog/", "apps/api/src/DemoData/", "apps/api/migrations/", "apps/api/tests/"]
    },
    {
      "id": "PERF-7-4",
      "title": "Granice porcji mapy strony ze stałym kosztem",
      "scope": "Wariant wybrany w D3 (rekomendacja: histogram statystyk z keysetem dla końca zakresu i bez statystyk). `DbalSitemapProductSource::boundaries`, migracja `SET STATISTICS` dla `catalog_product.id`. `CachingSitemapProductSource` bez zmian. Dokumentacja: decyzja D1 i opis zapytania granic w `docs/features/seo/spec.md`, opis odpowiedzi w OpenAPI (`product-chunks`) jeśli mówi o dokładnej liczbie produktów porcji, eksport specyfikacji i klientów TS.",
      "acceptance_criteria": [
        "Kryterium 6 specyfikacji: każda porcja do `row_limit`, każdy produkt w dokładnie jednej porcji, pierwsza bez początku i ostatnia bez końca; testy ze statystykami, bez statystyk i z produktami dodanymi po `ANALYZE`.",
        "Test planu: zapytanie granic nie czyta całego klucza głównego przy aktualnych statystykach.",
        "`make -C apps/api check`, `make -C apps/api test` i `make -C apps/store check` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-7-1"],
      "parallel_with": ["PERF-7-2", "PERF-7-5", "PERF-7-6"],
      "paths": ["apps/api/src/Catalog/", "apps/api/migrations/", "apps/api/tests/", "apps/api/openapi.json", "apps/store/src/api/schema.d.ts", "docs/features/seo/spec.md"]
    },
    {
      "id": "PERF-7-5",
      "title": "Frazy zamówień i klientów z trygramem",
      "scope": "Wariant wybrany w D2 (rekomendacja: fragment z co najmniej trzema kolejnymi literami lub cyframi, inaczej 400 z polem `q` i komunikatem „Wpisz co najmniej 3 litery lub cyfry obok siebie.”). `OrderSearchTerm` i `BackofficeCustomerSearchTerm`; numer `R-…` i pełny e-mail bez zmian. Panel: ta sama wskazówka przy polu przed wysłaniem w listach zamówień i klientów. OpenAPI panelu i klient TS, jeśli zmienia się opis parametru. Specyfikacje list zamówień i klientów (miejsca reguły minimum 3 znaków w `docs/features/`, `git grep`) opisują regułę.",
      "acceptance_criteria": [
        "Kryterium 8 specyfikacji: `#!#`, `---`, `a.b` dają 400 z polem i polskim komunikatem; `ab-cd`, `R-1000`, `ąęś`, `kowal` i pełny e-mail przechodzą; reguła liczy litery Unicode.",
        "Test planu: każdy przyjęty fragment idzie przez GIN (bez przejścia całego indeksu `placed_at`).",
        "Panel: wskazówka w `aria-live`, lista bez zmiany przy frazie odrzuconej (test komponentu).",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-7-1"],
      "parallel_with": ["PERF-7-2", "PERF-7-4", "PERF-7-6"],
      "paths": ["apps/api/src/Ordering/", "apps/api/tests/", "apps/api/openapi-admin.json", "apps/admin/src/", "docs/features/"]
    },
    {
      "id": "PERF-7-6",
      "title": "Anulowanie nieaktualnych żądań list w panelu",
      "scope": "`useCursorPager`: `AbortController` na żądanie, przerwanie poprzedniego przy nowym zapytaniu, zmianie strony, ponowieniu i odmontowaniu; `PageRequest` z `signal`; każde `fetchPage` (produkty, ceny, stany, wybór wariantów promocji, zamówienia, klienci, zamówienia klienta, kody rabatowe, promocje) przekazuje `signal` do `openapi-fetch`. Przerwanie nie jest błędem. Specyfikacja: „Front panelu”; „Standard listy” w `docs/features/admin-design/spec.md` opisuje zachowanie po zmianie.",
      "acceptance_criteria": [
        "Kryterium 7 specyfikacji: testy pagera (przerwanie poprzedniego, brak komunikatu błędu po przerwaniu, przerwanie przy odmontowaniu, ponowienie po błędzie nadal działa) i test każdej listy, że `signal` trafia do żądania.",
        "Istniejące testy wyścigów pagera (`latest`) przechodzą bez zmian asercji.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-7-1"],
      "parallel_with": ["PERF-7-2", "PERF-7-4", "PERF-7-5"],
      "paths": ["apps/admin/src/", "docs/features/admin-design/spec.md"]
    },
    {
      "id": "PERF-7-7",
      "title": "Pomiar po zmianie i raport epiku",
      "scope": "Scenariusze z zadania 1 na tej samej konfiguracji i danych: macierz na `small` i `medium`, W7 mieszanki, P4, granice mapy strony; rozmiary nowych indeksów i tabeli. Pomiar W7 na `server` na worf.pl po wdrożeniu tylko żądaniami GET i za osobną zgodą właściciela; bez zgody raport oznacza go jako niezmierzony. Status PERF-7 w `docs/features/performance-program/plan.md`.",
      "acceptance_criteria": [
        "Kryteria 3, 4, 5 i 11 specyfikacji: raport w `docs/reports/` z tabelą przed i po, rozrzutem trzech przebiegów, poleceniami do powtórzenia i listą niespełnionych wymagań z przyczyną (w tym R1–R4 z wynikiem).",
        "`make check` i testy `apps/api` i `apps/admin` przechodzą na gałęzi epiku."
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-7-2", "PERF-7-3", "PERF-7-4", "PERF-7-5", "PERF-7-6"],
      "parallel_with": [],
      "paths": ["load/", "docs/reports/", "docs/features/performance-program/plan.md"]
    }
  ]
}
```

## Przegląd całej zmiany

Po fali 4 przegląd w świeżym kontekście: `architecture-reviewer` (model odczytu, granice modułu `Catalog`, wzrost
kosztu z N) i `qa-adversarial` (równoważność wyników, współbieżny zapis produktu a tabela członkostwa, kursory
między strategiami). `ux-reviewer` tylko dla zmian zadań 5 i 6 (wskazówka frazy, brak błędu po przerwaniu).
