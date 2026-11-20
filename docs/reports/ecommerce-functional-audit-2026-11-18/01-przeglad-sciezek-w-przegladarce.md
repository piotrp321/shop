# Przegląd ścieżek użytkowników w działającej aplikacji (sesja główna)

Środowisko: lokalny stos Docker Compose z worktree na `origin/main` @ `1aeb5076` (projekt `shop-funcaudit`,
`STORE_MODE=dev`), dane `make seed` (191 produktów, 44 kategorie, 3 promocje, 3 kody). Data: 2026-11-18.
Przeglądarka: wbudowana, desktop 1024 px i emulacja 375 px. Konta demo z README. Wszystko niżej to obserwacje
z faktycznego klikania, nie z kodu. Demo publiczne worf.pl nie było klikane (to wdrożenie rewizji starszej niż main
i z wyłączonymi kontami klientów).

## Sklep (storefront)

### Strona główna i listing
- Hero „Sprzęt na każdy szlak”, kafelki 7 działów, listing 191 produktów, paginacja „Strona 1 z 8” (tylko
  Następna/Poprzednia). Mega menu z 3 poziomami kategorii, breadcrumbs.
- Fasety z licznikami: Kategorie, Kolor, Rozmiar, Smak, Długość, Rozmiar ramy, Oprawa, Porcja, Wersja, Cena od–do,
  „Tylko dostępne”. **Wada użyteczności**: wartości faset nie są znormalizowane – „czarny (26)”, „czarne (24)”,
  „czarna (22)” to trzy osobne wartości koloru (odmiana przez rodzaje), podobnie pomarańczowy/-a/-e, zielony/-a/-e.
  Klient filtrujący po kolorze musi zaznaczyć trzy pozycje.
- Sortowanie: tylko Nazwa A–Z (domyślne), Cena rosnąco, Cena malejąco; w wyszukiwarce dodatkowo Trafność.
  Brak „nowości”, „najpopularniejsze”, „największa obniżka”.
- Kafelek: zdjęcie, nazwa, cena, badge „Promocja”, linia „Najniższa cena z 30 dni przed obniżką: …”, „Dostępny”,
  przycisk „Wybierz wariant”. 3 produkty mają „Brak zdjęcia”.
- Stopka: wyłącznie logo, linki 7 działów i „© 2026 Worf · Zdjęcia: Unsplash”. **Brak** regulaminu, polityki
  prywatności, kontaktu, danych sprzedawcy, informacji o dostawie/zwrotach.

### Wyszukiwarka
- `namoit` (literówka) → 6 namiotów: tolerancja literówek działa.
- `buty biegowe` → 12 wyników (buty szosowe i trailowe z działu Bieganie): odmiana/kategoria działa.
- `buty do biegania` → **0 wyników** (naturalne zapytanie klienta; wymagane wszystkie słowa, brak synonimów
  i ignorowania słów typu „do”). Pusta strona wyników proponuje tylko „Zobacz wszystkie produkty”.
- Brak podpowiedzi/autocomplete w polu wyszukiwania (wyszukiwanie po Enter).

### Karta produktu (`/p/namiot-turystyczny-hallen-biwak-2`)
- Jedno zdjęcie z powiększeniem, nazwa, jednozdaniowy opis, cena 559,20 zł, badge „Promocja”, linia Omnibus
  (699,00 zł), „Dostępny”, wybór koloru (i rozmiaru w butach), „Do koszyka”.
- **Brak**: dłuższego opisu, tabeli parametrów, producenta/marki (GPSR), SKU, informacji o koszcie i czasie
  dostawy, informacji o zwrotach, opinii, produktów powiązanych, wyboru ilości, przekreślonej ceny regularnej
  (jest tylko linia Omnibus tekstem).
- Dane strukturalne: JSON-LD `Product` z ofertami per wariant (cena, PLN, `priceValidUntil`, dostępność) oraz
  `BreadcrumbList`; `canonical`, opis meta, `og:image` obecne. Brak `brand`, `sku`, `gtin`, `aggregateRating`.

### Koszyk (`/cart`)
- Pozycja z wariantem, pole ilości, „Usuń”, „Wartość brutto”, „Liczba sztuk”, „Przejdź do kasy”.
- Brak pola kodu rabatowego w koszyku (jest dopiero w kasie), brak kosztu dostawy i szacunku całości.

### Kasa – gość (`/checkout`)
- Jedna strona: podsumowanie, kod rabatowy, „Masz konto? Zaloguj się”, pola: E-mail, Imię i nazwisko, Telefon,
  Ulica i numer, Kod pocztowy, Miejscowość; „Sposób płatności”: „Szybki przelew (demo)” i „Karta płatnicza (demo)”;
  przycisk „Zamawiam z obowiązkiem zapłaty” (poprawne brzmienie ustawowe).
- **Brak**: wyboru metody dostawy i kosztu dostawy (kwota do zapłaty = suma pozycji), paczkomatu, kraju,
  danych firmy/NIP i adresu do faktury, checkboxa akceptacji regulaminu, linków do regulaminu i polityki
  prywatności, uwag do zamówienia.
- Kod `WITAJ10` zadziałał i **łączy się z promocją**: 559,20 zł (po promocji −20%) − 55,92 zł = 503,28 zł.
- Zamówienie R-100001 złożone; przekierowanie na stronę symulatora płatności w API
  (`/payment-simulator/demo-transfer/pay/…`: „To symulacja: żadne pieniądze nie zostaną pobrane”, przyciski
  „Zapłać” / „Odrzuć płatność”). Po „Zapłać” powrót na `/checkout/confirmation`: numer, kwota, „Płatność
  przyjęta. Potwierdzenie wysłaliśmy na adres …”.
- Gość po zamknięciu karty nie ma linku do statusu zamówienia (e-mail nie zawiera linku).

### Konto klienta
- Logowanie przez Keycloak (ekran po polsku, „Nie pamiętasz hasła?”, „Rejestracja” – lokalnie włączona).
- Po zalogowaniu w nagłówku są tylko „Moje zamówienia” i „Wyloguj się”. `/konto` zwraca **404** – nie ma strony
  profilu, książki adresowej, zmiany danych ani zgód.
- Kasa zalogowanego: e-mail z konta, ale adres trzeba wpisać ręcznie przy każdym zamówieniu (brak zapisanych
  adresów).
- Płatność odrzucona w symulatorze → strona potwierdzenia pokazuje „Płatność nie powiodła się”, „Opłać do 17:45”
  i pozwala **wybrać inną metodę i zapłacić ponownie** – działa (R-100002 opłacone drugim podejściem).
- „Moje zamówienia”: lista (numer, status, data, liczba sztuk, kwota) i szczegóły (pozycje, netto/VAT/brutto,
  adres, oś czasu Złożone/Opłacone). **Brak** akcji klienta: anulowania, zgłoszenia zwrotu/odstąpienia, reklamacji,
  pobrania faktury, śledzenia przesyłki.

### E-maile (Mailpit)
- Wysłane automatycznie, każdy raz: „Potwierdzenie zamówienia R-…” (pozycje, rabat, suma, adres, termin płatności
  30 min i zapowiedź automatycznego anulowania), „Płatność przyjęta – zamówienie R-…”, „Zamówienie R-… wysłane”,
  „Zamówienie R-… wróciło do nas” („Zwrócimy płatność.”).
- Wyłącznie zwykły tekst, bez HTML, bez danych sprzedawcy, bez pouczenia o prawie odstąpienia i formularza,
  bez linku do zamówienia, bez numeru przesyłki. W konfiguracji `prod` (`infra/k8s/environments/prod/values.yaml`:
  `orderMail: mailpit`, `customerAccounts: false`, `demoNotice: true`, `noindex: true`) e-maile zamówień nie
  wychodzą na zewnątrz, a konta klientów są wyłączone.

### Mobile 375 px
- Listing kategorii i karta produktu układają się poprawnie, bez poziomego przewijania (`scrollWidth` = 375),
  filtry pod przyciskiem „Filtry”, menu pod „Menu”, pole wyszukiwania stale widoczne, `lang="pl"`, jeden `h1`,
  wszystkie obrazy mają `alt`. Wizualnie spójne i czytelne.
- Panelu na 375 px i pełnego przejścia klawiaturą/czytnikiem ekranu w tej sesji nie sprawdzano (ograniczenie).

## Panel administracyjny

- Logowanie: Keycloak realm `backoffice`, hasło + obowiązkowy kod TOTP.
- Menu: Pulpit; Katalog (Produkty: lista/dodaj, Kategorie, Ceny, Stany magazynowe); Zamówienia (Lista zamówień,
  Zwroty do wykonania); Klienci; Marketing (Promocje, Kody rabatowe); System (Stan systemu). **Brak** sekcji
  Ustawienia, Dostawa, Płatności, Treści/CMS, Raporty, Pracownicy.
- Pulpit: liczba produktów/kategorii/bez zdjęć, sprzedaż dziś/7 dni/30 dni z porównaniem („Dane do 17:00” –
  agregacja godzinowa, świeże zamówienia pojawiają się po pełnej godzinie), kafelki „Do obsługi” (opłacone do
  wysłania, czekają na płatność, zwroty do wykonania/po terminie, brak stanu, niski stan, bez zdjęć, aktywne
  promocje, kody), ostatnie zamówienia. Górne pole wyszukiwania szuka tylko produktów.
- Zamówienia – lista: wyszukiwanie po numerze/e-mailu/nazwisku, filtr statusu i dat, przełącznik „Zwroty do
  wykonania”, sortowanie, rozmiar strony. Brak zaznaczania wielu wierszy, akcji masowych, eksportu, wydruków.
- Zamówienie – szczegóły: klient, adres, płatność (identyfikator), pozycje z opisem promocji i kodu, netto/VAT/
  brutto, historia zmian z autorem („Kto: admin”) i notatką.
  - „Oznacz jako wysłane” otwiera okno z jednym polem „Notatka (opcjonalnie)” – **brak przewoźnika, numeru
    przesyłki, etykiety**. Numer przesyłki wpisany w notatkę nie trafia do e-maila klienta.
  - „Przyjmij zwrot” – notatka wymagana, zwrot dotyczy **całego zamówienia** (brak wyboru pozycji/kwoty). Po
    przyjęciu pojawia się blok „Zwrot pieniędzy”: kwota 503,28 zł, termin +14 dni, stan „Do wykonania”,
    „Płatność do zwrotu” = identyfikator; akcja „Oznacz zwrot jako wykonany” (pieniądze zwraca się poza systemem).
  - **Stan magazynowy po zwrocie nie wraca**: wariant „zielony” ma 20 szt. wobec 21 w bliźniaczym wariancie –
    przyjęcie zwróconego towaru wymaga ręcznego „Przyjęcia” w Stanach magazynowych.
- Produkty: lista z filtrem kategorii/statusu/zdjęć, sortowaniem, „Podgląd w sklepie”. Formularz „Nowy produkt”:
  kategoria główna i dodatkowe, nazwa, slug, krótki opis (0/500), klasa podatkowa, zdjęcia (do 10), osie
  i warianty. **Cena i stan nie są w formularzu produktu** – ustawia się je na osobnych ekranach „Ceny” i „Stany
  magazynowe”, wariant po wariancie. Brak pól: opis długi, parametry, SKU/EAN, marka, waga, SEO.
- Ceny: lista wariantów pogrupowana produktami (brutto/netto/VAT/stawka), akcja na wiersz; 10 wierszy-produktów
  na stronę. Stany: fizycznie/zarezerwowane/dostępne/poziom, akcje „Przyjęcie”, „Korekta”, „Historia” per wariant.
- Promocje: 3 aktywne (procent, daty, liczba wariantów). Kody rabatowe: `WITAJ10` 10% (użycia: 1 – licznik
  zadziałał po zamówieniu testowym), `MINUS50` 50 zł od 300 zł, `LATO15` wygasły.
- Klienci: lista tylko kont z zamówieniami (Jan Kowalski); **gość z zamówienia R-100001 nie jest na liście**.
- Stan systemu: zdrowie 8 usług z czasem odpowiedzi, kolejki, outbox, zgodność indeksu z bazą.

## Weryfikacja kluczowych braków w kodzie (grep na `apps/api/src`, `apps/store/src`, `apps/admin/src`)

- Dostawa: brak jakiegokolwiek kodu metod/kosztów dostawy, przewoźników, numerów przesyłek, paczkomatów
  (jedyne trafienie to tekst e-maila o zwrocie). Trasy sklepu: `/`, `/c/…`, `/p/…`, `/szukaj`, `/cart`, `/checkout`,
  `/checkout/confirmation`, `/konto/zamowienia`, `/konto/zamowienia/[orderId]`, `/auth/*`, sitemap – **żadnej
  strony statycznej/prawnej**.
- Płatności: `apps/api/src/Payment/Domain/ProviderCode.php` ma dokładnie dwa przypadki: `demo-transfer`
  i `demo-card`; brak kodu PayU/Przelewy24/Stripe/Tpay/BLIK.
- Faktury: zero trafień dla invoice/faktura/NIP/KSeF w kodzie aplikacji.
- SKU/EAN/GTIN/waga/marka: brak w domenie Catalog i Inventory (trafienia tylko w szablonach danych demo).
- Import/eksport CSV: brak (poza generatorem danych demo).
- Opinie, lista życzeń, newsletter: brak.
- Webhooki wychodzące/klucze API dla integracji: brak (webhooki dotyczą tylko dostawców płatności).

## Backlog (tytuły otwartych zgłoszeń GitHub, przez bramkę pochodzenia; 157 otwartych)

Zaplanowane jako zgłoszenia (bez kodu): #120 „Checkout: metody i koszty dostawy”, #119 „RODO: anonimizacja danych
osobowych w zamówieniach”, #117 „Konto klienta: zapisane adresy dostawy w kasie”, #586 „ADMIN-TOOLS: notatki
i zwroty płatności, akcje masowe, eksport, Ctrl+K i dziennik zmian” (epik), #684 „Wiele magazynów w Inventory”,
#221 „Zwroty płatności oznaczane przez Payment widoczne w panelu”, #181 „Limity nadużyć przed publicznym startem”
(spec i plan ABUSE-LIMITS gotowe, status pusty), #179 „Tryb publicznego demo”, #296 „DEPLOY: utwardzenie
środowiska przed publicznym startem”. Specyfikacje i plany z pustym statusem: SMALL-FIXES, ABUSE-LIMITS,
ASYNC-RELIABILITY (niezawodność kolejek, m.in. siatka bezpieczeństwa wygasania zamówień).

**Nie ma zgłoszeń** dla: prawdziwego operatora płatności, faktur/KSeF, stron prawnych/CMS, zgód w kasie,
atrybutów/SKU/EAN/opisu produktu, importu/eksportu katalogu, numerów przesyłek i integracji kurierskich,
ścieżki odstąpienia/zwrotu po stronie klienta, zwrotów częściowych, ustawień sklepu w panelu, zarządzania
pracownikami z panelu, feedów produktowych, analityki (GA4) i zgody na cookies. Wizja (`docs/vision.md`,
„Poza zakresem (na razie)”) wprost wyłącza: wiele walut, wiele języków, prawdziwych dostawców płatności,
integracje z kurierami, faktury, funkcje marketplace.
