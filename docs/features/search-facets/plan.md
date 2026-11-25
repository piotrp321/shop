# SEARCH-FACETS – plan zadań

Specyfikacja: `docs/features/search-facets/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | SEARCH-FACETS-1, SEARCH-FACETS-3 | |
| 2 | SEARCH-FACETS-2 | |

**Wstrzymane 2026-11-25 decyzją właściciela, przed akceptacją planu** (specyfikacja, „Stan”). Żadne zadanie nie jest
rozpoczęte.

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Po merge'u epiku do `main`: wdrożenie i pomiar z kryterium 7 („Przebieg na serwerze”, każdy krok po zgodzie właściciela).

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SEARCH-FACETS",
  "spec": "docs/features/search-facets/spec.md",
  "tasks": [
    {
      "id": "SEARCH-FACETS-1",
      "title": "API: fasety w osobnym zapytaniu z cache i facets null",
      "scope": "apps/api, moduł Catalog (port ProductSearch, adapter Elasticsearch, ListProductsHandler, kontroler listingu), nowa pula cache.search_facets z prune i znacznikiem niedostępności, parametr app.search.facets_timeout, OpenAPI (facets nullable) i wygenerowany klient TS sklepu (tylko schema.d.ts; obsługa null w sklepie i w load to SEARCH-FACETS-2). Spec: „API”, „Ochrona przed równoczesnym liczeniem”, „Limity czasu na ścieżce żądania”, decyzje F1–F4. Dokumentacja: docs/features/search/spec.md.",
      "acceptance_criteria": [
        "Kryterium 1: istniejące testy integracyjne facetów bez zmiany asercji o liczbach; nowy test porównuje fasety zapytania łączonego z osobnym dla filtrów: brak, kategoria, fraza, jedna oś, dwie osie, cena, available.",
        "Kryterium 2: klucz cache taki sam dla różnych page, perPage, sort, kolejności i powtórzeń option, białych znaków i wielkości liter frazy, wielkości liter id kategorii; różny dla każdej innej kategorii, frazy, opcji, ceny i available (test jednostkowy); integracyjnie fasety i wyniki dla `Buty  Biegowe` i `buty biegowe` są takie same.",
        "Kryterium 3: body strony wyników bez aggs; body facetów z size 0, bez post_filter, sort i from.",
        "Kryterium 4: 8 jednoczesnych żądań różnych stron i sortowań jednego filtra na serwerze FrankenPHP stosu compose wykonuje dokładnie 1 zapytanie facetów; wszystkie odpowiedzi mają te same fasety.",
        "Kryterium 5: 8 jednoczesnych żądań 8 różnych filtrów wykonuje dokładnie 8 zapytań facetów; wszystkie 200.",
        "Kryterium 6: błąd albo limit czasu tylko zapytania facetów daje 200, facets null, Cache-Control public, max-age=10; 8 jednoczesnych żądań tego klucza przy błędzie wykonuje 1 zapytanie facetów i kończy się w czasie jednego liczenia; po 15 s kolejne żądanie liczy ponownie; błąd strony wyników daje 503 jak dziś (zmienione testy funkcjonalne listingu opisują tę zmianę zachowania).",
        "Kryterium 7: przeterminowane pliki puli znikają po kolejnym zapisie; prune najwyżej raz na 60 s (test z podstawionym zegarem).",
        "Limity: facety app.search.facets_timeout 10 s, strona wyników 2 s, ważność wpisu 300 s, znacznik niedostępności 15 s; w when@test adapter array.",
        "openapi.json opisuje facets jako nullable z F2 i F4; apps/store/src/api/schema.d.ts wygenerowany z niego w tym samym commicie.",
        "docs/features/search/spec.md opisuje stan po zmianie (Fasety, Zapytanie, Infrastruktura z limitami czasu, Scenariusze błędów, Ryzyka).",
        "make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/config/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "docs/features/search/spec.md"
      ],
      "parallel_with": [
        "SEARCH-FACETS-3"
      ]
    },
    {
      "id": "SEARCH-FACETS-2",
      "title": "Sklep i load: listing bez filtrów przy facets null",
      "scope": "apps/store: komponenty i strony czytające facets (ListingFilters, CatalogListing, strona główna, /c/..., /szukaj) i ich fixtury; load/lib/data.js (readFacets). Spec: „Sklep”, „Konsumenci zmienianego kontraktu”, F4. Zależy od kontraktu z SEARCH-FACETS-1 (facets nullable w schema.d.ts).",
      "acceptance_criteria": [
        "Kryterium 8: przy facets null listing, paginacja i sortowanie działają, komunikat „Filtry są chwilowo niedostępne. Odśwież stronę za chwilę.” jest w aria-live polite, wybrane filtry zostają w adresie i zawężają wyniki (test komponentu).",
        "load/lib/data.js pomija kategorię z facets null jak z total 0.",
        "Ekran 375 px: komunikat bez przewijania w poziomie.",
        "E2E listingu przechodzą bez zmiany asercji.",
        "make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [
        "SEARCH-FACETS-1"
      ],
      "paths": [
        "apps/store/src/catalog/",
        "apps/store/app/",
        "load/lib/data.js"
      ],
      "parallel_with": [
        "SEARCH-FACETS-3"
      ]
    },
    {
      "id": "SEARCH-FACETS-3",
      "title": "open i load czyszczą pulę facetów",
      "scope": "infra/k8s: scripts/open.sh i scripts/load.sh czyszczą cache.search_facets razem z cache.sitemap w każdym podzie api (F5); testy skryptów; docs/features/demo-scale/spec.md (zdanie o czyszczeniu w open). Pula w API powstaje w SEARCH-FACETS-1; ten skrypt tylko podaje jej nazwę.",
      "acceptance_criteria": [
        "Kryterium 10: make open i load czyszczą obie pule w każdym podzie api; nieudane czyszczenie którejkolwiek puli kończy open błędem jak dziś dla cache.sitemap (testy skryptów, także ścieżka błędu).",
        "docs/features/demo-scale/spec.md i nagłówki skryptów opisują obie pule.",
        "make -C infra/k8s check przechodzi."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "infra/k8s/scripts/open.sh",
        "infra/k8s/scripts/load.sh",
        "infra/k8s/tests/",
        "docs/features/demo-scale/spec.md"
      ],
      "parallel_with": [
        "SEARCH-FACETS-1",
        "SEARCH-FACETS-2"
      ]
    }
  ]
}
```
