# Próba błędnych danych wejściowych API, 2026-11-21

Rewizja: `e250032a`. Dowód do specyfikacji `docs/features/api-input-contract/spec.md` (epik API-INPUT-CONTRACT, #779,
etap 1). Raport opisuje wynik próby na tej rewizji; nie jest gwarancją zachowania całego API.

## Metoda

- Stos compose z własnym projektem i portami (`.env` worktree), `make up`, potem `make seed` (dane demo).
- Skrypt `api-input-contract-probe-2026-11-21/probe.py` (Python, biblioteka standardowa) czyta operacje z
  `apps/api/openapi.json` i `apps/api/openapi-admin.json` i do każdej wysyła błędne dane:
  - parametry ścieżki: tekst, nil i max UUID, UUID wielkimi literami, NUL, NBSP, 3000 znaków, niepoprawny UTF-8;
  - parametry zapytań: tablica (`p[]=x`), mapa, pusty, NBSP, NUL, U+2028, niepoprawny UTF-8, 5000 znaków, liczby
    ujemne, zero, ułamek, liczba ponad zakres, tekst, nil i max UUID, powtórzony klucz, U+200B, nieznany klucz;
  - nagłówki z OpenAPI: brak, pusty, spacja, 3000 znaków, tekst, nil UUID, znaki spoza ASCII, tabulator;
  - treść: brak, pusta, zepsuty JSON, `null`, lista, tekst, liczba, pusty obiekt, inne typy treści, zagnieżdżenie 600
    poziomów, niepoprawny UTF-8, a dla każdego klucza schematu jego brak i 23 wartości (złe typy, puste, NBSP, U+200B,
    NUL, U+2028, U+0085, flaga emoji, 5000 znaków, liczba ponad zakres);
  - metoda `PATCH` na każdej ścieżce.
- Identyfikatory w ścieżkach i wartości bazowe treści pochodzą z danych demo: produkt, wariant, kategoria, metoda
  dostawy, kod rabatowy, promocja z list panelu, koszyk gościa i zamówienie gościa założone przez API. Trasy panelu
  dostają token konta demo `admin` (`.claude/skills/integration/kc_login.py`).
- Komenda: `python3 docs/reports/api-input-contract-probe-2026-11-21/probe.py <repo> http://localhost:<API_PORT>
  http://localhost:<KEYCLOAK_PORT> http://localhost:<ADMIN_PORT> <wynik.jsonl>`. Surowy wynik (jeden wiersz JSON na
  żądanie) zostaje poza repozytorium.

## Wynik

6051 żądań do 72 operacji (30 sklepu, 42 panelu).

| Status | 422 | 400 | 401 | 404 | 409 | 200 | 415 | 405 | 201 | 5xx |
|---|---|---|---|---|---|---|---|---|---|---|
| Odpowiedzi | 3132 | 1248 | 634 | 384 | 263 | 192 | 92 | 72 | 34 | 0 |

Każda odpowiedź 4xx miała `Content-Type: application/problem+json`.

### Teksty po angielsku w `detail` i `violations[].title`

30 różnych tekstów; wszystkie poza polami treści JSON (odpowiedzi 422 na pola treści były po polsku).

| Status | Pole odpowiedzi | Tekst (liczby zastąpione przez N) | Operacje | Odpowiedzi |
|---|---|---|---|---|
| 405 | `detail` | Method not allowed. | 72 | 72 |
| 404 | `detail` | Resource not found. | 38 | 221 |
| 400 | `detail` | The request is invalid. | 25 | 127 |
| 415 | `detail` | Unsupported media type. | 23 | 92 |
| 401 | `detail` | Authentication is required. | 12 | 634 |
| 400 | `violations[].title` | This value should be of type int. | 8 | 144 |
| 400 | `violations[].title` | This value should be of type null|string. | 8 | 44 |
| 400 | `violations[].title` | This value should be between N and N. | 7 | 21 |
| 400 | `violations[].title` | This value does not match the expected UTF-N charset. | 7 | 10 |
| 400 | `violations[].title` | This value is too long. It should have N characters or less. | 7 | 10 |
| 400 | `violations[].title` | The paging cursor is invalid. | 6 | 90 |
| 400 | `violations[].title` | This value is too short. It should have N character or more. | 6 | 6 |
| 400 | `violations[].title` | This is not a valid UUID. | 5 | 96 |
| 400 | `violations[].title` | This value should not be blank. | 5 | 7 |
| 400 | `violations[].title` | This value is too short. It should have N characters or more. | 4 | 20 |
| 404 | `detail` | Product not found. | 4 | 8 |
| 400 | `violations[].title` | The search term must not contain a NUL character. | 3 | 6 |
| 400 | `violations[].title` | The Idempotency-Key header is required. | 3 | 3 |
| 400 | `violations[].title` | The value you selected is not a valid choice. | 1 | 35 |
| 400 | `violations[].title` | The price must be a whole number of grosze, N or more. | 1 | 30 |
| 400 | `violations[].title` | The status must be draft or published. | 1 | 18 |
| 409 | `detail` | The stock was modified concurrently. Reload it and try again. | 1 | 18 |
| 400 | `violations[].title` | The status is not one of the statuses of an order. | 1 | 18 |
| 400 | `violations[].title` | An option must have the form `<axis>:<value>`. | 1 | 17 |
| 409 | `detail` | The price was modified concurrently. Reload it and try again. | 1 | 6 |
| 400 | `violations[].title` | A receipt key must have N to N characters: letters, digits, ".", "_", ":" or "-". | 1 | 5 |
| 400 | `violations[].title` | This collection should contain N element or more. | 1 | 4 |
| 400 | `violations[].title` | Send the options as a repeated `option` key, not as `option[]`. | 1 | 2 |
| 400 | `violations[].title` | This value should be positive. | 1 | 2 |
| 400 | `violations[].title` | An option must be valid UTF-N. | 1 | 1 |

### Błędne wartości przyjęte z 2xx

Wybór dotyczy parametrów zapytań i pól treści; część wartości jest poprawna dla danego pola (np. tekst w `q`), część
to luki reguł tekstów (same białe albo niewidoczne znaki), które należą do kolejnego etapu epiku.

| Operacja | Wejście | Wartości przyjęte (2xx) |
|---|---|---|
| `GET /guest-carts/{cartId}` | zapytanie `discountCode` | `empty`, `nbsp`, `nul`, `a-nul-b`, `u2028`, `minus`, `zero`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `twice`, `zwsp` |
| `GET /products` | zapytanie `q` | `nbsp`, `a-nul-b`, `minus`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `zwsp` |
| `GET /products` | zapytanie `priceMin` | `zero`, `twice` |
| `GET /products` | zapytanie `priceMax` | `zero`, `twice` |
| `GET /products` | zapytanie `available` | `true` |
| `GET /products` | zapytanie `page` | `huge`, `twice` |
| `GET /products` | zapytanie `perPage` | `twice` |
| `POST /orders` | treść `discountCode` | `null`, ``, `\u00a0`, ` \u3000\u2003`, `\u200b` |
| `GET /admin/categories` | zapytanie `withCounts` | `true` |
| `POST /admin/categories` | treść `parentId` | `null` |
| `PUT /admin/categories/{id}` | treść `slug` | `00000000-0000-0000-0000-` |
| `GET /admin/products` | zapytanie `q` | `nbsp`, `minus`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `zwsp` |
| `GET /admin/products` | zapytanie `perPage` | `twice` |
| `POST /admin/delivery-methods` | treść `name` | `\u200b`, `Promo \ud83c\udff4\udb40`, `00000000-0000-0000-0000-`, `not-a-uuid`, `1e3`, `-0` |
| `GET /admin/stock` | zapytanie `q` | `nbsp`, `minus`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `zwsp` |
| `GET /admin/stock` | zapytanie `perPage` | `twice` |
| `POST /admin/variants/{variantId}/stock/receipts` | treść `quantity` | `123` |
| `POST /admin/variants/{variantId}/stock/receipts` | treść `note` | `null`, ``, `\u00a0`, ` \u3000\u2003`, `\u200b`, `a\u2028b`, `a\u0085b`, `Promo \ud83c\udff4\udb40`, `00000000-0000-0000-0000-`, `not-a-uuid`, `1e3`, `-0` |
| `GET /admin/variants/{variantId}/stock-history` | zapytanie `perPage` | `twice` |
| `GET /admin/customers` | zapytanie `q` | `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp` |
| `GET /admin/customers` | zapytanie `perPage` | `twice` |
| `GET /admin/orders` | zapytanie `q` | `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp` |
| `GET /admin/orders` | zapytanie `customerId` | `nbsp`, `minus`, `zero`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `twice`, `zwsp` |
| `GET /admin/orders` | zapytanie `perPage` | `twice` |
| `GET /admin/prices` | zapytanie `q` | `nbsp`, `minus`, `float`, `huge`, `text`, `nil-uuid`, `max-uuid`, `true`, `exp`, `zwsp` |
| `GET /admin/prices` | zapytanie `perPage` | `twice` |
| `GET /admin/discount-codes` | zapytanie `q` | `nul`, `a-nul-b`, `minus`, `zero`, `float`, `huge`, `text`, `true`, `exp`, `twice` |
| `GET /admin/discount-codes` | zapytanie `perPage` | `twice` |
| `POST /admin/discount-codes` | treść `code` | `not-a-uuid`, `1e3` |
| `GET /admin/variants/{variantId}/price-history` | zapytanie `perPage` | `twice` |
| `GET /admin/promotions` | zapytanie `perPage` | `twice` |

## Reprodukcje pojedyncze

Żądania na tym samym stosie, z poprawnymi pozostałymi danymi.

| Żądanie | Wynik |
|---|---|
| `GET /api/v1/products?perPage=100` | 400, `perPage`: „This value should be between 1 and 48.” |
| `GET /api/v1/products?q=%C2%A0%C2%A0` (dwa NBSP) | 200, pusta lista |
| `GET /api/v1/products?page=99999999999999999999999` | 200, `page`: 9223372036854775807 |
| `GET /api/v1/admin/products?q=%C2%A0%C2%A0` | 200, pusta lista |
| `GET /api/v1/admin/prices?productId=<uuid>&q=x` | 400, dwa naruszenia `q`: „Filtr produktu nie łączy się z wyszukiwaniem, kategorią, sortowaniem ani kursorem.” i „This value is too short. It should have 2 characters or more.” |
| `GET /api/v1/admin/stock?productId=00000000-0000-0000-0000-000000000000` | 400, dwa identyczne naruszenia `productId`: „Identyfikator produktu musi być poprawnym UUID.” |
| `DELETE /api/v1/admin/promotions/<id>?version[]=x` | 400, `detail` „The request is invalid.”, bez `violations` |
| `POST /api/v1/orders` z `fullName`, `street` albo `city` równym NBSP albo U+200B | 201, wartość zapisana bez zmian |
| `POST /api/v1/admin/variants/<id>/stock/corrections`, `reason: other`, `note` równe NBSP, U+200B, `a` U+2028 `b`, `a` U+0085 `b` | 200 |
| `POST /api/v1/admin/variants/<id>/stock/corrections` z nieaktualną `version` | 409, „The stock was modified concurrently. Reload it and try again.” |
| `POST /api/v1/admin/categories`, `name` równe U+200B, U+FEFF, U+00AD albo U+202E | 201 |
| `POST /api/v1/admin/categories`, `name` równe NBSP | 422 przy `name` |
| `POST /api/v1/admin/delivery-methods`, `name` równe U+00AD | 201 |

W kontenerze `api`:

| Sprawdzenie | Wynik |
|---|---|
| Typy parametrów konstruktorów klas z `#[RequiredKeysBody]` (refleksja po kontrolerach) | 13 klas, żaden parametr z typem innym niż `mixed` |
| `preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', 'Promo ' . flaga Anglii)` (reguła nazwy promocji) | 13 znaków → 7 |

## Framework

- `symfony/translation` nie jest zainstalowany (`vendor/symfony` ma tylko `translation-contracts`), więc komunikaty
  walidatora bez własnego tekstu są angielskie.
- `Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver` (wersja z `composer.lock`)
  składa komunikat błędu typu „This value should be of type {{ type }}.” i tłumaczy go tylko wtedy, gdy dostał
  translator.
- `vendor/symfony/validator/Resources/translations/validators.pl.xlf` ma 161 wpisów. Wyrenderowane tym katalogiem
  (translator zainstalowany w katalogu tymczasowym kontenera, poza projektem):

| Źródło | Tekst polski |
|---|---|
| This value should be of type {{ type }}. (`int`) | Ta wartość powinna być typu int. |
| This value should be between {{ min }} and {{ max }}. (1, 48) | Ta wartość powinna być pomiędzy 1 a 48. |
| This value is too short… (2) | Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków. |
| This value is too short… (1) | Ta wartość jest zbyt krótka. Powinna mieć 1 lub więcej znaków. |
| This value is too long… (100) | Ta wartość jest zbyt długa. Powinna mieć 100 lub mniej znaków. |
| This value does not match the expected {{ charset }} charset. | Ta wartość nie pasuje do oczekiwanego zestawu znaków UTF-8. |
| This is not a valid UUID. | Ta wartość nie jest prawidłowym UUID. |
| This value should not be blank. | Ta wartość nie powinna być pusta. |
| The value you selected is not a valid choice. | Ta wartość powinna być jedną z podanych opcji. |
| This value should be positive. | Ta wartość powinna być dodatnia. |
| This collection should contain {{ limit }} element or more. (1) | Ten zbiór powinien zawierać 1 lub więcej elementów. |

  Tekst bez wpisu w katalogu (np. „Nazwa jest wymagana.”, „The paging cursor is invalid.”) wraca bez zmian.

## Ograniczenia

- 12 operacji `/api/v1/me` odpowiedziało 401 (634 żądania): próba nie miała tokenu klienta, więc nie doszła do ich
  walidacji.
- Upload zdjęcia (treść `multipart/form-data`) i weryfikacja podpisu webhooka dostały tylko żądania bez poprawnej treści.
- Błędy zależne od stanu danych (konflikty, wyścigi, wiersze zapisane przed zmianą reguł, #440) są poza zasięgiem próby.
- Wartości bazowe treści kilku operacji nie były w pełni poprawne (np. tworzenie kategorii z nieistniejącym
  `parentId`), więc przy tych operacjach próba sprawdza walidację klas żądań, nie reguły domeny.
- Pola adresu zamówienia w przebiegu głównym kończyły się 409 (zmieniona kwota); ich zachowanie pokazują reprodukcje
  pojedyncze.
