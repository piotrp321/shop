# Cart – plan zadań

Specyfikacja: `docs/features/cart/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | CART-1 | |
| 2 | CART-2, CART-3, CART-4 | |
| 3 | CART-5 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "CART",
  "spec": "docs/features/cart/spec.md",
  "tasks": [
    {
      "id": "CART-1",
      "title": "Koszyk gościa i klienta w API",
      "scope": "apps/api: nowy context Cart. Domain: agregat Cart (add, changeQuantity, remove, touch, isExpired, clearIfExpired, sprawdzenie status = open dla gościa; mergeFrom i markMerged należą do CART-2), value objecty CartId (UUID v4), CartOwner, ProductId, Quantity, Money, ProductOffer, CartLine, stałe limitów i czasów życia, wyjątki z komunikatami detail (spec: „Model domenowy”). Migracja tabel cart_cart i cart_line z CHECK, indeksem unikalnym customer_id i indeksem expires_at; rekordy Doctrine i mapowanie Cart w config/packages/doctrine.yaml; repozytorium z blokadą PESSIMISTIC_WRITE przez ORM (refresh rekordu obecnego w identity map) i lockOrCreateForCustomer (INSERT … ON CONFLICT DO NOTHING); odczyty przez DBAL (spec: „Persystencja i transakcje” bez podsekcji „Scalanie” i „Czyszczenie”). Application: CreateGuestCart, AddCartItem, ChangeCartItemQuantity, RemoveCartItem, GetCart, GetCartSummary, CartViewBuilder, porty ProductCatalog, CartPrices, StockAvailability z adapterami przez query.bus; nowe zapytanie Catalog GetProductSummaries (spec: „Warstwa aplikacji”). UI: endpointy gościa /api/v1/guest-carts… i klienta /api/v1/me/cart, /me/cart/summary, /me/cart/items… (bez /me/cart/merge), reguła PUBLIC_ACCESS w security.yaml, Cache-Control private, no-store (spec: „API”); eksport openapi.json i klient TS sklepu (make api-client). Testy współbieżności K3, K4 i K7 (pcntl_fork, bariera advisory lock, 5 rund). Deptrac: warstwy Cart z regułami Cart_Infrastructure → Catalog_Application, Pricing_Application, Inventory_Application i Cart_UI → Customer_Application, z komentarzami, oraz próby obejścia ze spec („Testy – Architektura”). Dokumentacja: docs/vision.md (Redis bez koszyków gości, Cart w PostgreSQL, blokada wiersza koszyka w „Spójność i współbieżność”) i apps/api/CLAUDE.md (wyjątek Cart w „Architektura”, koszyk bez optimistic locking w „Reguły modelu”). Nie obejmuje: scalania, cart:purge-expired, zmian w apps/store poza wygenerowanym schema.d.ts.",
      "acceptance_criteria": [
        "Gość zakłada koszyk (POST /api/v1/guest-carts: 201 z pustym CartView i Location), a gość i klient dodają, zmieniają i usuwają pozycje; każda zmiana zwraca CartView, usunięcie brakującej linii jest sukcesem bez zmian",
        "Limity: ilość 1–99 w treści (poza zakresem 422), wynik ponad 99 daje 409 CartLineQuantityLimit, 51. pozycja daje 409 CartLineLimit",
        "Dodanie i zwiększenie ilości produktu bez aktualnej ceny albo z available = 0 daje 409 ProductNotPurchasable; ilość większa niż dostępna daje 409 InsufficientStock bez liczby sztuk; zmniejszenie ilości jest zawsze możliwe; produkt nieznany Catalogowi daje 404",
        "CartView ma stany linii w kolejności priorytetu unavailable_product, no_price, out_of_stock, insufficient_stock, purchasable; lineTotal i totals.gross tylko z linii purchasable, totals.itemCount ze wszystkich linii, hasExcludedLines; liczba dostępnych sztuk nigdy nie jest zwracana",
        "Koszyk gościa wygasa 30 dni, a klienta 180 dni po ostatniej zmianie; wygasły, nieznany albo scalony koszyk gościa daje 404; wygasły koszyk klienta jest pusty, a pierwsza zmiana czyści jego linie; GET koszyka klienta bez rekordu zwraca pusty widok z id null i niczego nie zakłada",
        "/api/v1/me/cart… bez tokena i z tokenem realmu backoffice daje 401; endpointy gościa są publiczne; identyfikator niebędący UUID daje 404, zły JSON 400, treść inna niż JSON 415, typy ścisłe bez rzutowania 422",
        "Wszystkie odpowiedzi koszyka mają Cache-Control: private, no-store",
        "GetProductSummaries zwraca dla 1–100 id {id, name, image} jednym zapytaniem do PostgreSQL, zdjęcie w rozmiarze listing; produkt nieobecny jest pominięty",
        "CHECK i indeks unikalny customer_id odrzucają bezpośredni SQL łamiący reguły; linie są usuwane kaskadowo z koszykiem",
        "Testy K3, K4 i K7 przechodzą w 5 rundach na PostgreSQL w make -C apps/api test",
        "Deptrac zawiera warstwy i reguły Cart i odrzuca próby obejścia wymienione w specyfikacji",
        "openapi.json i klient TS sklepu są aktualne; docs/vision.md, apps/api/CLAUDE.md i deptrac.yaml opisują Cart zgodnie z sekcją „Dokumentacja do aktualizacji”",
        "Przechodzą make -C apps/api check, make -C apps/api test i make -C apps/store check"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "CART-2",
      "title": "Scalanie koszyka gościa z koszykiem klienta w API",
      "scope": "apps/api, context Cart: Cart.mergeFrom i markMerged (spec: „Scalanie (mergeFrom)”), komenda MergeGuestCart w jednej transakcji z kolejnością blokad gość, potem klient (spec: „Scalanie (MergeGuestCart)”), endpoint POST /api/v1/me/cart/merge z MergeResult (spec: „API”); eksport openapi.json i klient TS sklepu. Testy współbieżności K1, K2, K5 i K6; test integracyjny wymuszonego błędu w trakcie scalenia i scalenia z wygasłym koszykiem klienta zapisanym w bazie. Nie obejmuje: wywołania scalenia w sklepie (CART-5).",
      "acceptance_criteria": [
        "Scalenie sumuje ilości tego samego produktu z przycięciem do 99, dodaje linie gościa w kolejności addedAt do limitu 50 linii, pomija nadmiar i ustawia truncated przy przycięciu albo pominięciu; nie sprawdza ceny ani dostępności",
        "Wygasły koszyk klienta jest czyszczony przed scaleniem: po scaleniu zawiera wyłącznie pozycje gościa, a expires_at jest odnowione (test jednostkowy i integracyjny)",
        "Udane scalenie w tej samej transakcji usuwa linie gościa i ustawia status merged, merged_into i merged_at; pusty koszyk gościa też jest oznaczany jako merged",
        "Nieznany, wygasły albo kliencki guestCartId daje result nothing_to_merge, a scalony koszyk gościa already_merged, w obu przypadkach bez zmian; odpowiedź 200 zawsze zawiera CartView klienta",
        "Wymuszony błąd w trakcie scalenia wycofuje całość: koszyk gościa zostaje open, koszyk klienta bez zmian",
        "Po scaleniu każda zmiana koszyka gościa daje 404 bez zmian",
        "Testy K1, K2, K5 i K6 przechodzą w 5 rundach na PostgreSQL w make -C apps/api test, bez zakleszczeń",
        "POST /api/v1/me/cart/merge: 401 bez tokena i z tokenem backoffice, 400 zły JSON, 415, 422 przy złym UUID albo braku klucza, Cache-Control private, no-store",
        "openapi.json i klient TS sklepu są aktualne",
        "Przechodzą make -C apps/api check, make -C apps/api test i make -C apps/store check"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CART-1"]
    },
    {
      "id": "CART-3",
      "title": "Komenda cart:purge-expired",
      "scope": "apps/api, context Cart: komenda PurgeExpiredCarts i komenda konsoli cart:purge-expired (spec: „Czyszczenie (cart:purge-expired)”); usuwanie partiami po 1000 id po indeksie expires_at, każda partia w osobnej transakcji. Dokumentacja: cart:purge-expired w sekcji „Komendy” apps/api/CLAUDE.md. Nie obejmuje: harmonogramu (CronJob przychodzi z wdrożeniem k3s).",
      "acceptance_criteria": [
        "Komenda usuwa koszyki z expires_at < now (gości w każdym statusie i klientów) razem z liniami, a koszyki niewygasłe zostają",
        "Usuwanie idzie partiami w osobnych transakcjach; kod wyjścia 0 przy sukcesie i gdy nic nie ma do usunięcia",
        "Przy niedostępnej bazie komenda kończy się kodem 2, a partie usunięte wcześniej zostają usunięte (test ścieżki błędu)",
        "make init, make up ani make seed nie uruchamiają komendy; apps/api/CLAUDE.md opisuje ją w „Komendy”",
        "Przechodzą make -C apps/api check i make -C apps/api test"
      ],
      "risk": "STANDARD",
      "depends_on": ["CART-1"]
    },
    {
      "id": "CART-4",
      "title": "Koszyk w sklepie: dodawanie z listy, strona koszyka i licznik",
      "scope": "apps/store: BFF koszyka w src/cart/ i route handlery app/api/cart/ (GET /api/cart, GET /api/cart/summary, POST /api/cart/items, PATCH i DELETE /api/cart/items/[productId]) z withCartDeps, ciasteczkiem store_cart (podpisane, nie szyfrowane), sprawdzaniem Origin i Content-Type, wyborem koszyka klienta przy sesji i gościa bez sesji, jednorazowym ponowieniem po 404 koszyka gościa, obsługą 401 i 503 sesji (spec: „Sklep – BFF” bez scalenia, store_cart_notice i zmian w /auth/callback). Funkcje przeglądarki w src/api/client.ts. UI: AddToCartButton w ProductTile, CartBadge w nagłówku, strona /cart z noindex, wszystkie stany linii i komunikaty błędów z tabeli „UI” poza notice merge_truncated. Vitest dla BFF, ProductTile, AddToCartButton, CartPage i CartBadge. E2E e2e/tests/cart-guest.spec.ts. Dokumentacja: apps/store/CLAUDE.md (struktura src/cart/ i app/api/cart/, client.ts, koszyk przez BFF, store_cart, Origin, /cart z noindex). Nie obejmuje: scalania przy logowaniu (CART-5).",
      "acceptance_criteria": [
        "Kafelek pokazuje „Do koszyka” tylko przy cenie i poziomie dostępności innym niż out_of_stock (przy braku etykiety dostępności przycisk jest widoczny); przycisk jest zablokowany na czas żądania; sukces pokazuje „Dodano do koszyka” i aktualizuje licznik",
        "Strona /cart ma robots noindex i pokazuje linie, ceny, wartości, sumę brutto, liczbę sztuk i etykiety stanów z odpowiedzi API, bez liczenia na froncie; pusty koszyk: „Twój koszyk jest pusty”",
        "Każdy błąd z tabeli „UI” specyfikacji (409 z detail, 404 produktu, 404 linii z odświeżeniem, 422/5xx/sieć, 401 sesji, błąd wczytania z ponowieniem) daje opisany komunikat; po 409 linia pokazuje poprzednią ilość",
        "Gość: pierwsze dodanie zakłada koszyk i ustawia store_cart (HttpOnly, SameSite=Lax, podpis HMAC, 30 dni); niepoprawny podpis jest usuwany; 404 koszyka gościa usuwa ciasteczko i powtarza operację raz",
        "Zalogowany klient używa koszyka /api/v1/me/cart z tokenem z sesji; koszyk przeżywa odświeżenie i ponowne wejście",
        "Żądania zmieniające bez Origin równego STORE_URL albo bez Content-Type application/json dostają 403 bez wywołania API; odpowiedzi BFF mają Cache-Control private, no-store",
        "CartBadge pokazuje liczbę sztuk i znika przy błędzie; HTML katalogu nie zależy od sesji",
        "E2E cart-guest.spec.ts: gość dodaje produkt in_stock z listy, licznik pokazuje 1, na /cart zmienia ilość na 3, suma jest równa 3 × cena z kafelka, usuwa pozycję i koszyk jest pusty",
        "apps/store/CLAUDE.md opisuje koszyk zgodnie z sekcją „Dokumentacja do aktualizacji” (bez store_cart_notice)",
        "Przechodzą make -C apps/store check, make -C apps/store test i make e2e"
      ],
      "risk": "STANDARD",
      "depends_on": ["CART-1"]
    },
    {
      "id": "CART-5",
      "title": "Scalanie koszyka przy logowaniu w sklepie",
      "scope": "apps/store: wywołanie POST /api/v1/me/cart/merge w /auth/callback po utworzeniu sesji oraz w BFF przed operacją, gdy jest sesja i store_cart (spec: „Sklep – BFF”, punkt 1 wyboru koszyka i /auth/callback); usuwanie store_cart po sukcesie, zostawienie po błędzie z logFailure; ciasteczko store_cart_notice i jednorazowe notice w GET /api/cart/summary; komunikat „Część produktów z koszyka gościa nie zmieściła się w koszyku”. Vitest BFF, /auth/callback i CartBadge. E2E e2e/tests/cart-merge.spec.ts. Dokumentacja: store_cart_notice w apps/store/CLAUDE.md.",
      "acceptance_criteria": [
        "Po zalogowaniu koszyk gościa jest scalony z koszykiem klienta (suma ilości), a store_cart usunięty; błąd scalenia nie psuje logowania, zostawia store_cart i scalenie powtarza pierwsze żądanie koszyka",
        "Gdy scalenie zwraca truncated, klient raz widzi „Część produktów z koszyka gościa nie zmieściła się w koszyku” (store_cart_notice podpisane, HttpOnly, 5 minut)",
        "Ponowne logowanie i równoległe żądania nie przenoszą pozycji drugi raz (already_merged, nothing_to_merge obsłużone bez błędu)",
        "Po wylogowaniu przeglądarka pokazuje pusty koszyk gościa, a koszyk klienta wraca po zalogowaniu",
        "E2E cart-merge.spec.ts: Jan opróżnia koszyk i dodaje B, wylogowuje się, jako gość dodaje A i B, po zalogowaniu ma A × 1 i B × 2; drugi kontekst przeglądarki zalogowany jako Jan widzi ten sam koszyk",
        "apps/store/CLAUDE.md opisuje store_cart_notice",
        "Przechodzą make -C apps/store check, make -C apps/store test i make e2e"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CART-2", "CART-4"]
    }
  ]
}
```
