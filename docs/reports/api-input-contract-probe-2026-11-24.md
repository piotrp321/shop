# Próba tekstów w danych wejściowych API, 2026-11-24

Rewizja: `db954a20`. Dowód do specyfikacji `docs/features/api-input-contract/spec.md` (epik API-INPUT-CONTRACT, #779,
etap 2). Raport opisuje wynik prób na tej rewizji; nie jest gwarancją zachowania całego API.

## Metoda

- Stos compose z własnym projektem i portami (`.env` worktree), `make up`, potem `make seed` (dane demo).
- **Próba ogólna:** skrypt etapu 1 (`api-input-contract-probe-2026-11-21/probe.py`) bez zmian, na tej rewizji.
- **Próba tekstów:** skrypt `api-input-contract-probe-2026-11-24/probe.py` (Python, biblioteka standardowa) wysyła 26
  tekstów do 27 wejść tekstowych (19 pól treści JSON i 8 parametrów zapytań), każdy w żądaniu z poprawnymi pozostałymi
  danymi; po odpowiedzi 2xx odczytuje zapisaną wartość tam, gdzie API ją zwraca. Trasy panelu dostają token konta demo
  `admin` (`.claude/skills/integration/kc_login.py`). Zamówienia skrypt składa jako gość, każde z nowego koszyka.
- Komenda (po `make seed`): `python3 docs/reports/api-input-contract-probe-2026-11-24/probe.py <repo>
  http://localhost:<API_PORT> http://localhost:<KEYCLOAK_PORT> http://localhost:<ADMIN_PORT> <wynik.jsonl>`. Surowy
  wynik (jeden wiersz JSON na żądanie) zostaje poza repozytorium.

Teksty próby:

| Klasa | Teksty |
|---|---|
| białe | U+00A0; spacja, U+3000, U+2003, U+202F |
| niewidoczne | U+200B; U+FEFF; U+00AD; U+2060; U+200E; U+202E; U+180E; U+200D; U+3164 |
| sterujące | `a`, znak, `b` dla U+0000, U+0009, U+000A, U+000D, U+007F, U+0085, U+009F, U+2028, U+2029 |
| brzegi | tekst między U+00A0; tekst między U+200B |
| do zachowania | tekst zakończony flagą Anglii (U+1F3F4 i znaki tagów); tekst zakończony U+2764 U+FE0F; tekst z emoji rodziny (U+200D); tekst z polskimi znakami |

Frazy `q` z klas „białe” i „niewidoczne” mają znak powtórzony trzy razy, żeby przejść minimalną długość.

## Próba ogólna

6191 żądań do 72 operacji (30 sklepu, 42 panelu).

| Status | 422 | 400 | 401 | 404 | 409 | 200 | 415 | 405 | 201 | 5xx |
|---|---|---|---|---|---|---|---|---|---|---|
| Odpowiedzi | 3201 | 1242 | 704 | 445 | 220 | 185 | 92 | 72 | 30 | 0 |

- Zero tekstów po angielsku w `detail` i `violations[].title` (etap 1: 30 różnych tekstów).
- Każda odpowiedź 4xx miała `Content-Type: application/problem+json`.
- Skrypt etapu 1 nie wysyła `expectedLines` (pole wymagane od `8fbb88ad`), więc jego żądanie bazowe `POST /orders`
  kończy się 422 przy `expectedLines`, a operacje zależne od złożonego zamówienia dostają nieistniejący identyfikator.

## Próba tekstów

727 żądań: 233 × 422, 230 × 201, 202 × 200, 46 × 400, 16 × 409 (odrzucony kod rabatowy zamówienia).

Komórka: liczba tekstów klasy przyjętych z 2xx / liczba wysłanych; „zm.” to liczba przyjętych, których zapisana wartość
różni się od wysłanej.

| Wejście | białe | niewidoczne | sterujące | brzegi | do zachowania |
|---|---|---|---|---|---|
| `POST /admin/categories` `name` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/products` `name` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/products` `shortDescription` | 2/2, zm. 2 | 9/9, zm. 1 | 2/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/products` `images[0].alt` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/products` `variantAxes[0]` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/products` `variants[0].values[0]` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/delivery-methods` `name` | 0/2 | 8/9 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/delivery-methods` `description` | 2/2, zm. 2 | 9/9, zm. 1 | 1/9 | 2/2, zm. 1 | 4/4 |
| `POST /admin/variants/{variantId}/stock/corrections` `note` (`reason: other`) | 2/2, zm. 1 | 9/9 | 7/9 | 2/2 | 4/4 |
| `POST /admin/variants/{variantId}/stock/receipts` `note` | 2/2, zm. 1 | 9/9 | 7/9 | 2/2 | 4/4 |
| `POST /orders` `fullName` | 2/2, zm. 1 | 9/9 | 1/9 | 2/2 | 4/4 |
| `POST /orders` `street` | 2/2, zm. 1 | 9/9 | 1/9 | 2/2 | 4/4 |
| `POST /orders` `city` | 2/2, zm. 1 | 9/9 | 1/9 | 2/2 | 4/4 |
| `POST /orders` `postalCode`, `phone`, `email` | 0/2 | 0/9 | 0/9 | 0/2 | 0/4 |
| `POST /orders` `discountCode` | 2/2 | 8/9 | 0/9 | 0/2 | 0/4 |
| `POST /admin/promotions` `name` | 0/2 | 1/9 | 0/9 | 2/2, zm. 2 | 4/4, zm. 1 |
| `POST /admin/discount-codes` `code` | 0/2 | 0/9 | 0/9 | 2/2, zm. 2 | 0/4 |
| `GET /products` `q` | 2/2 | 9/9 | 9/9 | 2/2 | 4/4 |
| `GET /admin/products`, `GET /admin/stock`, `GET /admin/prices` `q` | 2/2 | 9/9 | 8/9 | 2/2 | 4/4 |
| `GET /admin/customers`, `GET /admin/orders` `q` | 0/2 | 8/9 | 1/9 | 2/2 | 4/4 |
| `GET /admin/discount-codes` `q` | 0/2 | 1/9 | 9/9 | 2/2 | 4/4 |
| `GET /guest-carts/{cartId}` `discountCode` | 2/2 | 9/9 | 9/9 | 2/2 | 4/4 |

Szczegóły:

- **Niewidoczne.** Jedyny znak odrzucany w Catalog, Delivery i w `q` zamówień i klientów to U+180E (wzorzec `\s`
  z flagą `/u` go obejmuje). Nazwa promocji i `q` kodów przyjmują tylko U+3164. Nazwa kategorii, produktu, metody
  dostawy, tekst alternatywny, oś i wartość wariantu równe U+200B, U+FEFF, U+00AD, U+2060, U+200E, U+202E, U+200D albo
  U+3164 dają 201 i są zapisane bez zmian.
- **Białe.** `fullName`, `street` i `city` równe U+00A0 dają 201 i są zapisane jako U+00A0. Notatka korekty z powodem
  „inne” i notatka przyjęcia równe U+00A0 są zapisane jako U+00A0. Krótki opis i opis metody dostawy z samych białych
  znaków są zapisane jako pusty tekst.
- **Sterujące.** Przyjęty znak w Catalog, Delivery, danych zamówienia i `q` zamówień i klientów to U+009F (krótki opis
  przyjmuje też U+000A, zgodnie ze swoją regułą). Notatki magazynu odrzucają tylko U+0000 i U+007F. `q` list
  produktów, stanów i cen panelu odrzuca tylko U+0000; `q` listy sklepu i listy kodów niczego.
- **Brzegi.** Catalog i Delivery zapisują tekst bez U+00A0 z brzegów, a U+200B zostawiają. Dane zamówienia i notatki
  magazynu zapisują oba znaki. Nazwa promocji i kod rabatowy tracą oba.
- **Do zachowania.** Nazwa promocji zakończona flagą Anglii jest zapisana bez znaków tagów (sama czarna flaga, #305).
  Pozostałe teksty tej klasy są zapisane bez zmian we wszystkich polach, które je przyjmują.

## Reprodukcje pojedyncze

Żądania na tym samym stosie, z poprawnymi pozostałymi danymi.

| Żądanie | Wynik |
|---|---|
| `POST /api/v1/orders`, `postalCode` równe `00-950` między U+00A0 | 422 przy `postalCode`: „Podaj kod pocztowy w formacie 00-000.” |
| to samo między spacjami ASCII | 201, zapisane `00-950` |
| `POST /api/v1/orders`, `phone` równe `600100200` między U+00A0 | 422 przy `phone`: „Podaj 9-cyfrowy numer telefonu.” |
| to samo między spacjami ASCII | 201, zapisane `+48600100200` |
| `POST /api/v1/orders`, poprawny `email` między U+00A0 | 422 przy `email`: „Podaj poprawny adres e-mail.” |
| to samo między spacjami ASCII | 201 |
| `POST /api/v1/orders`, `fullName` z pięciu U+00A0 i stu liter | 422 przy `fullName`: „Imię i nazwisko mogą mieć najwyżej 100 znaków.” |
| `GET /api/v1/products?q=%20%20%20` | 400 przy `q`: „Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków.” |
| `GET /api/v1/admin/products?q=%20%20%20` (tak samo `/admin/stock`, `/admin/prices`) | 200 |
| `POST /api/v1/orders`, `expectedLines` jako obiekt `{"0": {…}}` zgodny z koszykiem | 201 |
| `POST /api/v1/admin/promotions`, `variantIds` jako obiekt `{"0": "<uuid>"}` | 201 |
| `POST /api/v1/admin/products`, każda lista jako obiekt (`additionalCategoryIds: {}`, `images: {}`, `variantAxes: {"0": …}`, `variants: {"0": {…, "values": {"0": …}}}`) | 201 |
| `GET /api/v1/admin/prices?productId=<uuid>&q=x` | 400, dwa naruszenia `q`: „Filtr produktu nie łączy się z wyszukiwaniem, kategorią, sortowaniem ani kursorem.” i „Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków.” |
| `POST /api/v1/admin/products`, `images[0].alt`, `variantAxes[0]` albo `variants[0].values[0]` równe `123`, `["a"]` albo `null` | 422 przy polu, komunikat braku wartości tego pola |

## Pola z formatem

`POST /api/v1/orders`, pozostałe dane poprawne; zapisana wartość z `GET /api/v1/admin/orders/<id>`.

| Pole | Wysłane | Wynik |
|---|---|---|
| `phone` | `600100200`; `+48600100200`; `600 100 200`; `600-100-200`; `+48 600-100-200` | 201, zapisane `+48600100200` |
| `phone` | `600` U+00A0 `100` U+00A0 `200`; `600` U+200B `100200`; `600` U+009F `100200`; `60010020`; `6001002000`; `+49600100200` | 422 „Podaj 9-cyfrowy numer telefonu.” |
| `postalCode` | `00-950` | 201, zapisane `00-950` |
| `postalCode` | `00950`; `00 950`; `00` U+2013 `950`; `00-` U+00A0 `950`; `00-9500` | 422 „Podaj kod pocztowy w formacie 00-000.” |
| `email` | adres małymi literami; adres z wielkimi literami | 201, zapisany bez zmian |
| `email` | adres 254 znaków | 201 |
| `email` | adres 255 znaków; adres 254 znaków między U+00A0; adres po U+FEFF; U+200B w części lokalnej; spacja w części lokalnej; U+00A0 po `@` | 422 „Podaj poprawny adres e-mail.” |

## Odczyt wiersza, którego reguła nie przyjmuje

Na lokalnej bazie `full_name` jednego zamówienia zmieniono zapytaniem SQL na `a`, tabulator, `b` (tabulator jest
niedozwolony od QUALITY-FIXES), potem przywrócono.

| Żądanie | Wynik |
|---|---|
| `GET /api/v1/admin/orders/<id>` | 422, `detail`: „Imię i nazwisko zawierają niedozwolone znaki.” |
| `GET /api/v1/admin/orders?perPage=3` | 200 |

`DoctrineOrderRepository` buduje `Contact` i `ShippingAddress` konstruktorami, które walidują, więc agregatu takiego
zamówienia nie da się wczytać. Tak samo czytają `DoctrineProductRepository` i `DoctrineDeliveryMethodRepository`.

## W kontenerze `api`

| Sprawdzenie | Wynik |
|---|---|
| `PCRE_VERSION`, `PHP_VERSION` | 10.44, 8.5.12 |
| `\p{DI}` z flagą `/u` na U+200B, U+FEFF, U+00AD, U+2060, U+200E, U+202E, U+180E, U+200D, U+FE0F, U+E0067, U+3164, U+034F | dopasowanie |
| `\p{DI}` na U+00A0, U+2800, U+0085, U+2028, `A`, `ł` | brak dopasowania |
| `\s` z flagą `/u` na U+180E | dopasowanie |
| `vendor/bin/phpunit --testsuite functional --filter InputContract` | 396 testów, 2567 asercji, 3,3 s |

## Ograniczenia

- Próba tekstów nie doszła do `POST /api/v1/me/orders` (brak tokenu klienta) ani do notatek zmiany statusu i wykonania
  zwrotu (pracownik nie może zmienić nieopłaconego zamówienia, a próba nie opłaca zamówień).
- Operacje `PUT` kategorii, produktu, metody dostawy i promocji nie były próbowane osobno; używają tych samych klas
  żądań albo walidatorów co `POST`.
- Zamówienia złożone przez skrypt zostają nieopłacone i rezerwują stan do wygaśnięcia; skrypt najpierw przyjmuje 300
  sztuk wariantu, którego używa.
- Tekst, który nie jest poprawnym UTF-8, nie jest częścią tej próby: w treści JSON daje 400 dekodera (etap 1).
