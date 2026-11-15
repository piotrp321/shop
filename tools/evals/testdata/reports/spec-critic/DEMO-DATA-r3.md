Przegląd szkicu `docs/features/demo-data/spec.md` (DEMO-DATA, #482)

Sprawdziłem szkic względem kodu, migracji, realmów Keycloaka, CI i `plan.md`. Większość założeń w kodzie się potwierdza, między innymi:
- DBAL 4.5 tworzy połączenie przez `PDO::connect`, więc `Pdo\Pgsql::copyFromArray` jest dostępne;
- `ProductSearchIndexRebuilder.php:137-139` wygląda tak, jak opisuje szkic;
- kolumny i ograniczenia `CHECK` tabel zamówień, płatności i stanów odpowiadają opisowi;
- w CI Keycloak, Garage i Elasticsearch są uruchomione w jobie `checks`.

Znalazłem 6 problemów `Important` i 5 `Minor`.

## Important

**1. Zamówienie gościa da się odczytać, powtarzając żądanie złożenia zamówienia** (techniczne; sekcje „Model zagrożeń” oraz „Identyfikatory, czas i determinizm”)
- Dowód:
  - Według szkicu (l. 183-184, 236-237) losowe są tylko tokeny dostępu. Pozostałe kolumny, w tym `idempotency_key` i `request_fingerprint` zamówienia, wynikają z publicznego ziarna.
  - `PlaceOrderHandler.php:96-99` szuka zamówienia po samym kluczu idempotencji. Jeśli odcisk żądania się zgadza, zwraca `OrderView::of($earlier)` z tokenem dostępu i danymi adresowymi, zanim sprawdzi koszyk.
  - Odcisk (`RequestFingerprint.php:25-43`) składa się z `guest:<cartId>` (`CartReference.php:34-37`), danych kontaktowych, adresu i kwoty. Wszystkie te wartości da się wyliczyć z ziarna, a identyfikator koszyka podaje klient.
  - Ten sam problem dotyczy kluczy idempotencji w `payment_payment`.
- Skutek: to ta sama droga ataku co „odczyt po wyliczonym tokenie”, a szkic jej nie wymienia.
- Propozycja:
  - `idempotency_key` zamówień i płatności z bezpiecznego generatora losowego, jak tokeny, i bez nich w sumach kontrolnych determinizmu;
  - nowa droga w modelu zagrożeń;
  - test: żądanie odtworzone z ziarna nie zwraca istniejącego zamówienia.

**2. Wykrywanie konfliktu kont nie działa przy `ifResourceExists=SKIP`** (techniczne; „Konta w Keycloak”: „Własność”, „Konflikt”)
- Dowód:
  - Szkic (l. 272, 280-283, 297-299) zakłada, że konflikt nazwy daje 409 i odrzucenie całej paczki.
  - Przy `SKIP` Keycloak pomija użytkownika, którego nazwa albo e-mail już istnieje. Zwraca wtedy wynik `SKIPPED`, a nie 409. Repo samo opisuje tę semantykę w `infra/k8s/charts/shop/files/scripts/keycloak-config.sh:4-5`.
  - 409 i wycofanie paczki wystąpią tylko przy kolizji identyfikatora albo duplikacie wewnątrz paczki.
- Skutek: konto spoza grupy o nazwie generatora zostanie po cichu pominięte, a zamówienia wskażą cudze konto lub konto, którego nie ma.
- Propozycja: generator sprawdza każdy wynik `SKIPPED`. Pominięte konto, które nie należy do `demo-data-generated` albo ma inny `id` niż wyliczony, to konflikt i błąd kroku. Opis eksperymentu z 409 trzeba poprawić i rozdzielić na oba przypadki.

**3. Kasowanie kont podczas stronicowania z przesunięciem pomija członków grupy** (techniczne; „Ponowne generowanie”, „Ryzyka”, l. 296 i 491-493)
- Usuwanie członków w trakcie przeglądania listy stronami (`first`/`max`) przesuwa kolejne rekordy wstecz, więc część z nich nie zostanie sprawdzona.
- Skutek: zostają stare konta, a W1 i kryterium 7 („dokładnie konta bieżącej generacji”) nie są spełnione.
- Propozycja: najpierw zebrać pełną listę kandydatów, potem usuwać. Alternatywnie przesuwać wskaźnik strony tylko o konta, które zostają, albo powtarzać przegląd od początku, aż przejście nie usunie żadnego konta. Kryterium 7 powinno to sprawdzać na liczbie kont większej niż strona.

**4. Testy kolidują z kontami na stosie deweloperskim, bo identyfikatory kont są globalne** (techniczne; „Testy i lokalnie”, kryterium 7)
- Tymczasowe realmy testów dostają identyfikatory UUID v5 z tej samej przestrzeni nazw (`profiles.yaml`). Identyfikator użytkownika jest kluczem tabeli Keycloaka wspólnym dla wszystkich realmów.
- Testy działają na Keycloaku stosu deweloperskiego, na którym `make seed-scale PROFILE=small` już utworzył konta (DEMO-DATA-5 robi to w tym samym zadaniu).
- Skutek: import w teście dostaje kolizję identyfikatorów (409) albo test zaczyna zależeć od stanu stosu.
- Propozycja: testy zawsze nadpisują przestrzeń nazw unikalną wartością na każdy przebieg. Trzeba to zapisać w sekcji testów.

**5. Rola pracownika zależy od liczby kont, a tej liczby nie ma w odcisku** (techniczne; „Pracownicy”, „Zapis generacji”, „Ponowne generowanie”)
- Przydział 10/200/200/90 jest „skalowany profilem” (`plan.md`, DEMO-DATA-4). Odcisk zawiera tylko wersję słowników i ról (l. 289-293).
- Przykład: przejście `small` → `server` przy tej samej przestrzeni nazw i tym samym odcisku. Konta `staff:1..20` zostają pominięte (`SKIP`) ze starymi rolami. Zamówienia przypiszą im czynności według nowego podziału ról, co łamie regułę ról P6 z `docs/features/order-management/spec.md`.
- Propozycja (do wyboru): rola jako funkcja samego `n`, niezależna od liczby kont, albo liczba kont i reguła ról w odcisku. Odcisk liczony jako hash słowników i reguły, a nie ręcznie podbijana wersja. Kryterium 7 powinno dodatkowo sprawdzać zwiększenie liczby kont.

**6. Unikalny slug przy równoległych częściach nie jest określony** (techniczne; „Realizm: Katalog”, l. 153-154)
- `catalog_product_slug_key` to unikalny indeks. Kombinacji „typ × marka” jest około 36 tys., więc przy 10 mln produktów nazwy będą masowo kolidować.
- „Kolizja dostaje numer serii” nie mówi, jak część przetwarzana w osobnym procesie ma poznać slugi z innych części ani z seeda. Do tego wynik nie może zależeć od liczby procesów (kryterium 1).
- Skutek: `COPY` odrzuci paczkę na indeksie, w profilach `server` i `full` dopiero po godzinach.
- Propozycja: slug unikalny z konstrukcji jako czysta funkcja globalnego indeksu produktu, np. sufiks z indeksu albo model z przestrzeni indeksowanej numerem, rozłączny z `catalog.yaml`. Do tego test kontraktu albo sprawdzenie w `verify`.

## Minor

**7. Kryterium 7 przeczy szkicowi.** Kryterium wymaga „atrybutów generatora”, a l. 283-285 mówią, że atrybutów użytkownika generator nie używa. Propozycja: „członkostwo w grupie `demo-data-generated`”.

**8. W „Zakresie” brakuje plików, których zadania będą potrzebować:**
- `apps/api/config/packages/flysystem.yaml` (osobny system plików dla puli zdjęć; obecna konfiguracja AsyncAws jest tam);
- miejsca na narzędzie wysyłki puli (DEMO-DATA-6) i jego test;
- ewentualnie `apps/api/phpunit.dist.xml`, jeśli osobna grupa testu determinizmu ma być wykluczana lub uruchamiana osobno.

**9. Kryterium 4 nie obejmuje części tabel ani jednego przejścia statusu.**
- Nie ma w nim płatności (`provider_payment_id`, `idempotency_key`, `request_fingerprint`; unikalny indeks `(provider, provider_payment_id)`), historii statusów, promocji ani kodów rabatowych.
- „Historia działań” (l. 189-190) nie podaje aktora przejścia do `paid`, a tabela przyjmuje tylko `system`, `customer` i `staff`.
- Nie wiadomo, czy anulowanie przez pracownika wymaga notatki.

**10. Nie opisano błędu jednej z równoległych części** (`bin/generate-demo`, l. 211-213). W `sh` samo `wait` bez listy procesów zwraca 0. Szkic powinien mówić, że błąd jednej części kończy krok kodem różnym od zera i co dzieje się z pozostałymi częściami. Test skryptu powinien obejmować błąd jednej z równoległych części.

**11. Pliki puli trafiają do Garage bez walidacji uploadu.** `docs/vision.md` („Upload plików”) wymaga sprawdzenia typu po zawartości, rozmiaru i wymiarów, a pula omija ścieżkę uploadu. Propozycja: zadanie DEMO-DATA-6 sprawdza każdy plik tymi samymi regułami co upload w Catalog, a wynik trafia do PR.

## Werdykt

**DO POPRAWY.** Wszystkie 6 problemów `Important` to braki techniczne do uzupełnienia w szkicu. Żaden nie wymaga decyzji produktowej ani architektonicznej.
