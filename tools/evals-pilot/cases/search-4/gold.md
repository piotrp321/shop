Źródło: zgłoszenie #197.
Pliki: `apps/api/src/Catalog/Application/Query/ListProductsHandler.php` (także zapytanie do indeksu w `ProductSearchQuery.php` i `ElasticsearchProductSearch.php`).
Mechanizm: identyfikator kategorii z parametru `category` jest normalizowany tylko do sprawdzenia, czy kategoria istnieje; do kryteriów wyszukiwania w indeksie i do wyboru faset kategorii (`facetCategories`, `find` z porównaniem `===`) idzie surowa wartość z żądania.
Skutek: UUID kategorii zapisany wielkimi literami daje pustą listę produktów i puste fasety kategorii, choć ten sam UUID małymi literami zwraca produkty.
Uwaga: pusta lista dla wielkich liter istniała już przed zadaniem; diff przepisuje tę linię i dokłada ten sam błąd w fasetach. Zgłoszenie o surowej (nieznormalizowanej) wartości identyfikatora kategorii w zapytaniu albo w fasetach to ta sama wada.
