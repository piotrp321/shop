# Audyt funkcjonalny platformy e-commerce i analiza konkurencji (2026-11-18)

**Solidny silnik sprzedaży, któremu brakuje sklepu.**

| | |
|---|---|
| Rewizja | `1aeb5076` (`origin/main` z 2026-11-18) |
| Zakres | funkcje `apps/api`, `apps/store`, `apps/admin` z perspektywy właściciela sklepu, administratora, obsługi zamówień i klienta; porównanie z Shopify, WooCommerce, Shopware, Magento Open Source / Adobe Commerce, Sylius, Medusa, Saleor, Shoper, IdoSell, pomocniczo PrestaShop i Vendure; wymagania prawne i rynkowe startu sklepu w Polsce |
| Poza zakresem | infrastruktura Claude Code i agentów; bezpieczeństwo, wydajność, zależności i złożoność kodu (osobne audyty) |
| Metoda | 4 audytorów kodu (subagenci, odczyt statyczny z dowodami plik:linia), 11 przebiegów badań konkurencji, prawa i rynku (dwie rundy), przejście ścieżek w przeglądarce na lokalnym stosie z danymi `make seed`, weryfikacja kluczowych braków wyszukiwaniem w kodzie, synteza i przegląd raportu przez sesję prowadzącą |
| Materiał źródłowy | `docs/reports/ecommerce-functional-audit-2026-11-18/`: notatka przekazania do syntezy (`00`), przegląd ścieżek (`01`), audyt kodu (`02`–`05`), konkurencja (`06`–`13`), prawo, płatności, dostawa, faktury (`14`–`16`) |
| Status | Właściciel przyjął raport 18.11.2026 jako punkt wyjścia. **Rozdziały 11–14 (segment docelowy, roadmapa, pięć epików i ich kolejność, lista „nie budować”) są propozycją audytora, nie decyzją.** Stanowisko właściciela i wskazówki do wspólnej roadmapy po wszystkich audytach: `ecommerce-functional-audit-2026-11-18/00-przekazanie-do-syntezy.md` |

---

## 1. Executive summary

**Właściciel prawdziwego sklepu nie może dziś sprzedawać na tej platformie.** To nie jest kwestia jakości. Platforma nie ma kilku elementów, bez których w Polsce nie da się przyjąć pieniędzy, wysłać paczki i legalnie zawrzeć umowy. Działa za to rdzeń transakcyjny: katalog, wyszukiwarka, koszyk, kasa gościa, zamówienie, płatność (symulowana), statusy, e-maile, panel obsługi. W lokalnym stosie przeszliśmy pełną ścieżkę zakupu z kodem rabatowym, płatnością odrzuconą i ponowioną, wysyłką i zwrotem (`docs/reports/ecommerce-functional-audit-2026-11-18/01-przeglad-sciezek-w-przegladarce.md`). Ten rdzeń jest wyjątkowo poprawny. Rezerwacje stanu przy złożeniu zamówienia są sprawdzone testami wieloprocesowymi na PostgreSQL. Złożenie zamówienia jest idempotentne. Ceny w zamówieniu są zamrożoną kopią. E-maile wychodzą przez transactional outbox. Najniższą cenę z 30 dni (Omnibus) system liczy sam z historii cen.

Cykl sprzedaży urywa się w sześciu miejscach:

1. **Płatności.** Są tylko dwa symulatory (`apps/api/src/Payment/Domain/ProviderCode.php`).
2. **Dostawa.** Nie ma metod, kosztów, Paczkomatu ani numeru przesyłki.
3. **Treści i obowiązki prawne.** Nie ma regulaminu, polityki prywatności, danych sprzedawcy, pouczenia i formularza odstąpienia, zgód w kasie.
4. **Faktury.** Kasa nie zbiera NIP-u i nie ma eksportu dla księgowości.
5. **Dane produktu.** Jest tylko krótki opis. Brakuje parametrów, SKU/EAN i producenta wymaganego przez GPSR. Nie ma importu CSV.
6. **Samoobsługa właściciela.** Stawki VAT, termin płatności, nazwa sklepu i treści e-maili są w kodzie. Pracowników dodaje się w konsoli Keycloaka.

Konfiguracja produkcyjna pracuje w trybie demo: `orderMail: mailpit`, `customerAccounts: false`, `demoNotice: true`, `noindex: true`.

**Do funkcjonalnego MVP brakuje pięciu epików** w kolejności: dostawa → prawdziwy operator płatności ze zwrotami przez API → treści i zgodność prawna → dane produktu z importem → faktury i rozliczenia. Do tego dochodzą dwa przygotowane już epiki utwardzające (ASYNC-RELIABILITY, ABUSE-LIMITS) i zadania samego właściciela: umowa z operatorem, konto InPost, regulamin od prawnika, weryfikacja stawek VAT z księgową.

**Pozycja wobec konkurencji jest niejednoznaczna.** Omnibus natywnie, poprawność transakcji i polska wyszukiwarka dają przewagę nad Shopify, WooCommerce, Shopware CE i headless open source (Medusa, Saleor), bo tam Omnibus to płatna aplikacja, wtyczka albo własny kod. Nad Shoperem i IdoSell przewagi brak. Oba mają w abonamencie Omnibus, GPSR, InPost z mapą, zwroty i integracje ([shoper.pl/cennik](https://www.shoper.pl/cennik/), [idosell.com/pl/funkcje](https://www.idosell.com/pl/funkcje/)). Mały sklep wybierze więc Shopera, IdoSell albo Shopify. Realny segment to dedykowany sklep dla średniego polskiego sprzedawcy z dużym katalogiem i własnymi integracjami, czyli konkurencja dla wdrożeń Sylius, Magento i Shopware. Dowodem musiałby być jeden prawdziwy sklep. Dziś produkt ma głównie wartość demonstracyjną. Potencjał produktowy jest realny dopiero po MVP i tylko w tej niszy.

---

## 2. Odpowiedzi na siedem pytań

### (a) Czy właściciel rzeczywistego sklepu mógłby już korzystać z naszej aplikacji?

Nie. Platforma nie przyjmie prawdziwej płatności. Nie policzy kosztu dostawy, nie da wybrać Paczkomatu i nie zapisze numeru przesyłki. Nie pokaże regulaminu, danych sprzedawcy ani pouczenia o odstąpieniu. W konfiguracji produkcyjnej nie wyśle nawet e-maila z potwierdzeniem zamówienia, bo `orderMail: mailpit` kieruje pocztę do skrzynki w klastrze (`infra/k8s/environments/prod/values.yaml:20-23`). Właściciel może natomiast pokazać działający katalog, kasę i panel obsługi jako demonstrację albo podstawę wdrożenia.

### (b) Co konkretnie uniemożliwia rozpoczęcie sprzedaży?

Pięć braków, każdy blokuje sam:

1. Brak prawdziwego operatora płatności i zwrotu pieniędzy przez jego API. Ustawa wymaga zwrotu tym samym sposobem płatności (art. 32 ust. 2 UPK).
2. Brak dostawy. Bez pokazania kosztu dostawy przed kliknięciem przycisku zamówienia sklep nie może obciążyć nim klienta (art. 12 ust. 1 pkt 5, art. 17 ust. 1 i art. 23 UPK). Paczkomat wybiera 83% kupujących.
3. Brak treści wymaganych przez art. 12 ust. 1 UPK: dane sprzedawcy, procedura reklamacji, pouczenie o odstąpieniu ze wzorem formularza. Brak też potwierdzenia umowy na trwałym nośniku z tymi informacjami (art. 21).
4. Brak danych producenta i informacji o bezpieczeństwie produktu (art. 19 GPSR).
5. Brak NIP-u i danych do faktury w kasie.

Źródła tekstów prawnych: [UPK, tekst jedn. Dz.U. 2026 poz. 1244](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf), [UOKiK o GPSR](https://uokik.gov.pl/bezpieczenstwo-produktow-obowiazki-przedsiebiorcow).

Szóstym, organizacyjnym blokerem jest tryb demo w konfiguracji wdrożenia. Siódmym jest brak samoobsługi: każda zmiana stawki VAT, terminu płatności czy treści e-maila wymaga programisty i wdrożenia.

### (c) Jak daleko jesteśmy od funkcjonalnego MVP?

Pięć epików funkcjonalnych:

| Epik | Rozmiar |
|---|---|
| Dostawa | L |
| Płatności | L |
| Treści i zgodność prawna | M |
| Dane produktu i import | L |
| Faktury i rozliczenia | M |

Do tego dochodzi ukończenie ASYNC-RELIABILITY i ABUSE-LIMITS, które mają gotowe specyfikacje i plany, ale pusty status. Rozmiar względny oznacza epik porównywalny z dotychczasowymi CHECKOUT czy PAYMENT, bo każdy wprowadza nowe pojęcie do modelu zamówienia albo katalogu. Audyt kodu ocenia, że sam pierwszy prawdziwy operator płatności to epik, a nie „wpięcie adaptera”: port nie ma zwrotu, uzgadniania stanu transakcji ani statusów zwrotu (`04-kod-checkout-zamowienia-platnosci.md`, obszar 4). Równolegle właściciel musi załatwić sprawy poza kodem: umowę z operatorem, konto w Managerze Paczek InPost, regulamin i politykę prywatności, weryfikację stawek VAT i wybór systemu fakturowego. Część kodu nie wymaga zmian. Silnik transakcyjny jest gotowy i nie trzeba go przepisywać.

### (d) Jakie są nasze największe przewagi i słabości względem konkurencji?

**Przewagi:**

- Poprawność i odporność transakcji: rezerwacje z blokadą wiersza, idempotencja, outbox, ochrona przed zmianą ceny w trakcie kasy.
- Omnibus liczony automatycznie z historii cen i promocji. Shopify, WooCommerce i Shopware mają go jako aplikację lub wtyczkę.
- Wyszukiwarka z polską odmianą, wersją bez ogonków i tolerancją literówek.
- Dojrzałe SEO techniczne.
- Panel z osobnym realmem, obowiązkowym TOTP i rolami sprawdzanymi w API.
- Gruntowne testy E2E.

**Słabości:**

- Wąski zakres funkcji w każdym obszarze poza rdzeniem.
- Zero integracji i ekosystemu: brak kluczy API, webhooków wychodzących, wtyczek.
- Brak samoobsługi właściciela.
- Stos wielu usług (PostgreSQL, Redis, Elasticsearch, RabbitMQ, Keycloak, Garage, imgproxy, Varnish i aplikacje). Utrzymanie go nie ma sensu ekonomicznego dla małego sklepu.

Wobec Shopera i IdoSell nie mamy dziś przewagi funkcjonalnej. Oba deklarują Omnibus, GPSR i InPost z mapą jako standard.

### (e) Jakie pięć epików powinniśmy zrealizować w następnej kolejności?

1. **DOSTAWA**: metody i koszty, próg darmowej dostawy, Paczkomat przez Geowidget, numer przesyłki, e-mail ze śledzeniem (zgłoszenie #120).
2. **OPERATOR PŁATNOŚCI**: jeden operator z BLIK, szybkimi przelewami i kartami. Zwroty przez API, uzgadnianie stanu, widoczność płatności do ręcznego zwrotu (#221).
3. **ZGODNOŚĆ I TREŚCI**: strony prawne, dane sprzedawcy, akceptacja regulaminu, pełne potwierdzenie zamówienia z pouczeniem i formularzem, zgłoszenie odstąpienia przez klienta, anonimizacja (#119), wyjście z trybu demo (#296).
4. **DANE PRODUKTU I IMPORT**: opis długi, parametry, SKU/EAN, producent (GPSR), waga, import i eksport CSV produktów, cen i stanów.
5. **FAKTURY I ROZLICZENIA**: NIP i firma w kasie, faktura przez API jednego systemu fakturowego (KSeF po jego stronie), eksport zamówień, zwroty częściowe z przywróceniem stanu.

Epiki 3 i 4 nie mają wspólnego kodu i mogą biec równolegle.

### (f) Czego nie powinniśmy budować?

Na tym etapie nie budujemy:

- wielu walut, języków i OSS,
- marketplace'u i multi-vendor,
- pakietu B2B,
- systemu wtyczek,
- programu lojalnościowego i kart podarunkowych,
- wielu magazynów (#684),
- dalszych prac pod skalę 10 mln produktów,
- własnego page buildera,
- własnego silnika rekomendacji,
- POS i subskrypcji,
- własnego modułu KSeF,
- osobnych integracji z każdym kurierem zamiast jednej integracji InPost i ewentualnego brokera.

Uzasadnienie jest w rozdziale 14.

### (g) Czy produkt ma potencjał wykraczający poza projekt demonstracyjny i portfolio?

Warunkowo tak, ale wąski. Shoper deklaruje ponad 20 000 sklepów i ponad 21 mld zł GMV w 2025 roku ([investors.shoper.pl](https://investors.shoper.pl/en/press-office/wyniki-finansowe-q4-2025---komunikat)). Rywalizacja z SaaS-ami o mały sklep nie ma sensu. Rozsądny jest produkt typu „rdzeń sprzedaży z wbudowaną polską zgodnością” do dedykowanych wdrożeń dla średnich sprzedawców. Dziś te wdrożenia robi się na Sylius, Magento albo Shopware, a polskie płatności, InPost, Omnibus i faktury dokłada się wtyczkami lub własnym kodem. Uczciwie: do czasu pierwszego prawdziwego sklepu jest to wartość demonstracyjna. Najbliższy konkurent, Sylius, ma ten sam stos (Symfony/API Platform) i od 2026 roku jednego właściciela z BitBagiem i PrestaShopem: grupę cyber_Folks, która ma też 49,9% Shopera ([cyber_Folks IR](https://investors.cyberfolks.pl/en/?p=3794)).

---

## 3. Ocena faktycznej dojrzałości produktu

Ocena jest surowa. Istnienie encji, endpointu, zgłoszenia czy specyfikacji nie oznacza ukończonej funkcji. ZAPLANOWANE oznacza zgłoszenie lub specyfikację bez kodu.

| # | Obszar | Ocena | Uzasadnienie |
|---|---|---|---|
| 1 | Katalog | CZĘŚCIOWE | Produkt, warianty (do 2 osi), zdjęcia z imgproxy i drzewo kategorii działają. Nie ma opisu długiego, parametrów, SKU/EAN, marki, wagi ani importu, a agregat `Product` ma tylko krótki opis do 500 znaków (`apps/api/src/Catalog/Domain/Product/Product.php:19-31`). |
| 2 | Wyszukiwanie i odkrywanie | CZĘŚCIOWE | Polska odmiana, literówki i fasety z licznikami działają. Fraza „buty do biegania” daje 0 wyników, nie ma podpowiedzi, synonimów ani sortowania po nowościach i popularności, a wartości kolorów nie są znormalizowane („czarny/czarne/czarna”). |
| 3 | Ceny, podatki, waluty, promocje, kody | CZĘŚCIOWE | Omnibus, historia cen, promocja procentowa z harmonogramem i kody kwotowe lub procentowe działają. Stawki VAT są przykładowe i zaszyte w kodzie (`PolishVatRates.php:9-23`), walutą jest tylko PLN, nie ma promocji kwotowej, kategorii ani rabatu koszykowego. |
| 4 | Magazyn | CZĘŚCIOWE | Rezerwacje, korekty i historia ruchów są wzorcowe. Przyjęcie dotyczy jednego wariantu naraz, zwrot nie przywraca stanu, nie ma importu stanów, a wiele magazynów jest tylko w zgłoszeniu (#684). |
| 5 | Koszyk, checkout, zamówienia, płatności | CZĘŚCIOWE | Kasa gościa, idempotencja, statusy i ponowienie płatności działają. Płatności to wyłącznie symulatory, a zamówienia nie da się edytować ani obsługiwać masowo. |
| 6 | Dostawa | ZAPLANOWANE (#120) | W kodzie nie ma żadnego pojęcia dostawy. Suma zamówienia to suma linii (`OrderTotals.php:20-38`). |
| 7 | Zwroty, reklamacje, anulowania, refundacje | CZĘŚCIOWE | Obsługa przyjmuje zwrot całego zamówienia, a system pilnuje kolejki zwrotów z terminem 14 dni. Pieniądze przelewa się poza systemem, klient nie może zgłosić odstąpienia, nie ma zwrotów częściowych ani reklamacji. |
| 8 | Konta klientów i komunikacja | CZĘŚCIOWE | Logowanie OIDC i historia zamówień działają. Nie ma profilu (`/konto` daje 404), adresów (#117) ani anonimizacji (#119). E-maile to czysty tekst bez danych sprzedawcy, a w produkcji konta są wyłączone. |
| 9 | Panel i codzienna praca | CZĘŚCIOWE | Pulpit „Do obsługi”, filtry, historia i role działają. Nie ma akcji masowych, wydruków, ustawień sklepu ani zarządzania pracownikami. |
| 10 | Raportowanie i eksport | CZĘŚCIOWE | Pulpit pokazuje sprzedaż dziś, z 7 i z 30 dni brutto, bez odejmowania zwrotów. Eksportu nie ma, jest tylko zaplanowany w #586. |
| 11 | Marketing, SEO, CMS, integracje | CZĘŚCIOWE | SEO techniczne jest dojrzałe. Nie ma CMS-u, stron treści, opinii, feedów, GA4, porzuconych koszyków ani kluczy API i webhooków. |
| 12 | Wielojęzyczność, waluty, międzynarodowość, wielokanałowość | BRAK | Świadomie poza zakresem (`docs/vision.md:385`). Kraj dostawy jest zawsze PL (`ShippingAddress.php:15,79`). |
| 13 | B2B i automatyzacje | BRAK | Nie ma NIP-u, grup klientów, cenników ani reguł automatyzacji. Jedyne automaty to wygaszanie nieopłaconych zamówień i granice promocji. |
| 14 | UX, dostępność, mobile | CZĘŚCIOWE | Sklep na 375 px nie przewija się w poziomie, ma `lang="pl"` i `alt` na obrazach, a statusy są w `aria-live`. Panelu na 375 px i pełnej obsługi klawiaturą i czytnikiem nie sprawdzaliśmy, a e-maile nie mają HTML-a. |

**Czy da się dziś przejść pełny cykl sprzedaży?** Nie. Krok po kroku:

- **Produkt.** Da się go opublikować, ale bez opisu, parametrów i danych GPSR.
- **Zakup.** Pierwszy brak pojawia się w koszyku: klient nie wie, ile zapłaci za wysyłkę. W kasie nie ma wyboru dostawy, NIP-u ani akceptacji regulaminu.
- **Płatność.** Tu cykl urywa się technicznie, bo symulator w tym samym procesie API nie przyjmuje pieniędzy.
- **Realizacja.** „Oznacz jako wysłane” ma tylko pole notatki. Numer przesyłki wpisany w notatkę nie trafia do klienta, a adres trzeba przepisać ręcznie do panelu kuriera.
- **Posprzedaż.** Klient nie ma ścieżki odstąpienia ani zwrotu. Obsługa przyjmuje tylko zwrot całości, stan magazynowy nie wraca, a zwrot pieniędzy to ręczny przelew poza systemem.
- **Rozliczenie.** Brak faktur i eksportu, więc całe rozliczenie odbywa się poza systemem.

Bez programisty działa część od wyboru produktu do statusu „wysłane”. Tylko ta część jest naprawdę zautomatyzowana.

---

## 4. Macierz porównawcza funkcjonalności

**Legenda.**

- Konkurencja: **N** – natywnie w planie bazowym lub darmowym rdzeniu; **P** – płatna edycja, wyższy plan, aplikacja lub rozszerzenie (także bezpłatna wtyczka spoza rdzenia, oznaczona w komórce); **I** – integracja zewnętrzna; **C** – własna implementacja; **—** – brak; **?** – notatki nie zawierają informacji.
- Nasza aplikacja: **✔** kompletne, **◐** częściowe, **☐** zaplanowane (zgłoszenie lub spec bez kodu), **✖** brak.
- **Gwiazdka (np. N\*)**: researcher oznaczył komórkę w notatkach jako niezweryfikowaną albo podał ją z wiedzy własnej, bez odczytu oficjalnej strony w sesji badawczej. Komórki bez gwiazdki mają źródło w notatkach (oficjalna strona, dokumentacja albo, przy Shoperze i IdoSell, cennik lub strona produktu).
- Kolumna Magento: N oznacza rdzeń Open Source, P funkcję tylko Adobe Commerce lub rozszerzenie.
- Kolumna Shopware: N oznacza Community Edition, P plan Rise, Evolve lub Beyond.

Główne źródła komórek: `07-konkurencja-shopify-woocommerce-uzupelnienie.md`, `09-konkurencja-shopware-magento-uzupelnienie.md`, `11-konkurencja-sylius-medusa-saleor-uzupelnienie.md`, `13-konkurencja-shoper-idosell-prestashop-rynek-uzupelnienie.md` oraz notatki `06`, `08`, `10`, `12` (pierwsza runda badań konkurencji).

### Obszary 1–2: katalog, wyszukiwanie i odkrywanie

| Funkcja | Nasza | Shopify | Woo | Shopware | Magento/Adobe | Sylius | Medusa | Saleor | Shoper | IdoSell |
|---|---|---|---|---|---|---|---|---|---|---|
| Warianty produktu | ✔ (≤2 osie) | N | N\* | N\* | N\* | N\* | N | N | N | ? |
| Atrybuty, parametry | ✖ | N\* | N | N\* | N\* | N\* | ? | ? | N | ? |
| SKU i EAN/GTIN | ✖ | N\* | N | ? | N | ? | ? | ? | ? | ? |
| Marka, producent (dane GPSR) | ✖ | ? | ? | N\* | C\* | ? | ? | ? | N | N |
| Import i eksport CSV produktów | ✖ | N | N\* | N | N\* | P\*/I\* | N\* | —\* | N | ? |
| Edycja masowa | ✖ | N\* | N | N\* | N\* | ? | ? | ? | N | ? |
| Zestawy (bundle) | ✖ | N\* | P | P | N | ? | N (kits) | ? | ? | N |
| Wyszukiwanie z tolerancją literówek | ✔ (+ polska odmiana) | N\* | —\* | P (Evolve+) | N\*, pełne P | P (Plus) | N/I | N\*/P (Cloud) | N, zaawans. P | ? |
| Fasety z licznikami | ◐ (bez marki i parametrów) | N\* | N\* (częściowo) | N\* | N\* | ? | ? | N\* | ? | ? |
| Podpowiedzi (autocomplete) | ✖ | N\* | P\*/C\* | N\* | N\* | ? | ? | ? | ? | ? |
| Cross-sell, rekomendacje | ✖ | N\* | N\* (ręczne) | N | N\*, AI P | ? | ? | ? | N, P (2,5%) | ? |

### Obszary 3–4: ceny, podatki, promocje, magazyn

| Funkcja | Nasza | Shopify | Woo | Shopware | Magento/Adobe | Sylius | Medusa | Saleor | Shoper | IdoSell |
|---|---|---|---|---|---|---|---|---|---|---|
| Omnibus liczony automatycznie | ✔ | P (aplikacje 14,99–16,99 USD/mies.) | P (wtyczki) | P (pola ręczne N) | C\* | P (bezpł. wtyczka) | C\* | C\* | N | N |
| Stawki VAT edytowalne w panelu | ◐ (w kodzie) | N | N | N\* | N\* | N\* | N | N | ? | ? |
| Promocja z harmonogramem | ✔ (tylko %) | N | N\* | N\* | N\* | N\* | N | N | N | ? |
| Rabat koszykowy bez kodu | ✖ | N | P\*/C\* | N | N\* | N\* | N | N | ? | ? |
| Kody rabatowe (minimum, limity) | ◐ (bez limitu na klienta) | N\* | N | N\* | N\* | N\* | N | N\* | N | ? |
| Karty podarunkowe | ✖ | N\* | P\* | P\*/I\* | P | P\*/I\* | N/P\* | N | N | N |
| Rezerwacje stanu przy zamówieniu | ✔ (testy współbieżności) | ? | ? | —\* | N | N\* | N | N | ? | ? |
| Historia ruchów z powodami korekt | ✔ | N\* | —\* | ? | ? | ? | ? | ? | ? | ? |
| Wiele magazynów | ☐ (#684) | N (10 lokalizacji) | P\*/C\* | P (Beyond) | N (MSI) | P (Plus) | N | N | P (Premium) | N |
| Alert niskiego stanu | ◐ (pulpit, próg stały) | N\* | N\* | C\*/I\* | N\* | ? | ? | P (Cloud) | ? | ? |

### Obszary 5–7: kasa, zamówienia, płatności, dostawa, zwroty

| Funkcja | Nasza | Shopify | Woo | Shopware | Magento/Adobe | Sylius | Medusa | Saleor | Shoper | IdoSell |
|---|---|---|---|---|---|---|---|---|---|---|
| Kasa gościa | ✔ | N\* | N\* | N\* | N\* | N\* | N\* | N | N\* | N\* |
| BLIK, P24, PayU | ✖ (symulatory) | P/I (+0,2–2% opłaty) | I | I | I | I\* (wtyczki 1.x) | I (społeczność 0.x) | ? | N/I | N (IdoPay) |
| Przelew tradycyjny, pobranie | ✖ | N\* | N\* | N\* | N\* | N\* | N\* | ? | N\* | N\* |
| Zwrot pieniędzy przez operatora z panelu | ✖ (tylko ewidencja) | N | N | N\*/I\* | N\* | P (bezpł. RefundPlugin) | N | N\* | ? | N |
| Edycja złożonego zamówienia | ✖ | N\* | N | N\* | C\* | C\* | N | ? | N\* | N\* |
| Zamówienia ręczne (draft) | ✖ | N\* | N | N\* | N\* | C\*/P\* | N | N | N\* | N\* |
| Akcje masowe na zamówieniach | ☐ (#586) | N\* | N | N\* | N\* | ? | ? | ? | N | N\* |
| Eksport zamówień CSV | ☐ (#586) | N\* | N | N\* | N\* | ? | ? | ? | N\* | N\* |
| NIP w kasie i faktury | ✖ | I\* (aplikacje KSeF) | P\* (wtyczki PDF) | N (dokumenty), KSeF ? | N\* | P (bezpł. InvoicingPlugin) | I\*/C\* | I\* | P/I (aplikacja KSeF) | N (KSeF z UPO) |
| Metody i stawki dostawy, próg darmowej | ☐ (#120) | N\* | N\* | N | N\* | N\* | N | N | N\* | N\* |
| Paczkomaty InPost z mapą w kasie | ✖ | P (9–19 USD/mies.) | P (WP Desk) | I (BitBag, zgodność z 6.7 niepewna) | I (oficjalne moduły InPost) | P\*/I\* (1.x) | C/I | C/I | N | N |
| Numer przesyłki i e-mail ze śledzeniem | ✖ | N\* | P | N\* | N\* | N\* | N\* | N | N\* | N |
| Etykiety, broker kurierski | ✖ | I\*/P\* | I\*/P\* | I\*/P\* | N\* (przewoźnicy z USA) | ? | I (ShipStation) | ? | N/I (Shoper Przesyłki) | N (broker, osobny cennik) |
| Wysyłka częściowa | ✖ | N\* | — | N\* | N\* | P (Plus) | N\* | N | ? | ? |
| Zgłoszenie zwrotu przez klienta online | ✖ | N | P\*/C\* | P (Rise+) | P (tylko Adobe) | P (Plus) | N | N\* | N (bezpł. aplikacja) | N |
| Zwrot częściowy z przywróceniem stanu | ✖ (całość, bez stanu) | N | N | ? | N\* | ? | N | ? | ? | ? |
| Obsługa reklamacji | ✖ | ? | P\* | ? | P (RMA Adobe) | P (RMA Plus) | N (claims) | ? | N | N |

### Obszary 8–10: klienci, panel, raporty

| Funkcja | Nasza | Shopify | Woo | Shopware | Magento/Adobe | Sylius | Medusa | Saleor | Shoper | IdoSell |
|---|---|---|---|---|---|---|---|---|---|---|
| Konto z historią zamówień | ◐ (bez profilu, wyłączone w prod) | N | N\* | N\* | N\* | N\* | N | N | ? | ? |
| Książka adresowa | ☐ (#117) | N\* | N\* | N\* | N\* | N\* | N | N | ? | ? |
| Edytowalne szablony e-maili | ✖ | N\* | N\* | N | N\* | N\* | I | P (Cloud)/I | P (Premium) | ? |
| Narzędzia RODO (eksport, usunięcie) | ☐ (#119) | N\* | N\* | N\* | N\* | ? | ? | ? | ? | ? |
| E-maile porzuconych koszyków | ✖ | N\* | P\*/I\* | ? | ? | ? | ? | ? | N | ? |
| Role personelu sprawdzane w API | ✔ (4 role, TOTP) | N (limit kont wg planu) | N\* | N/P (rozbieżność źródeł) | N\* | P (Plus) | P (licencja enterprise) | N | ? | N |
| Konta pracowników zakładane z panelu | ✖ (konsola Keycloaka) | N | N\* | N\* | N\* | ? | ? | N | ? | ? |
| Dziennik działań | ◐ (rozproszony) | N | P\*/C\* | P\*/C\* | P\* | P\*/C\* | P (Enterprise) | —\* | ? | ? |
| Raporty sprzedaży i produktów | ◐ (pulpit brutto) | N | N | N\* | N\* | ? | I\* | P (Pulse, Cloud) | ? | ? |
| Raport podatków / CSV z raportów | ✖ | ? | N | ? | ? | ? | ? | ? | ? | ? |

### Obszary 11–14: marketing, międzynarodowość, B2B, UX

| Funkcja | Nasza | Shopify | Woo | Shopware | Magento/Adobe | Sylius | Medusa | Saleor | Shoper | IdoSell |
|---|---|---|---|---|---|---|---|---|---|---|
| SEO techniczne (sitemap, canonical, JSON-LD) | ✔ | N\* | N\* | N\* | N\* | ? | C | ? | ? | ? |
| Edytowalne meta i przekierowania | ✖ | N\* | P\* | N\* | N\* | ? | C | N\* | ? | ? |
| Strony treści / CMS | ✖ | N\* | N\* | N | N (Page Builder od 2.4.3) | C/I | I | N | ? | ? |
| Blog | ✖ | N\* | N\* | P\*/I\* | P\*/I\* | ? | I | ? | P (Premium) | N |
| Opinie o produktach | ✖ | P\* | N\* | N\* | N\* | N\* | C/I | C\*/I\* | I | N |
| Newsletter i zgody marketingowe | ✖ | ? | ? | N\* | N\* | ? | I | ? | N | ? |
| Feed produktowy (Google, porównywarki) | ✖ | ? | ? | N | ? | ? | ? | P (Cloud) | I | ? |
| Publiczne API z kluczami i webhookami | ✖ (OpenAPI tylko wewnętrzne) | N\* | N\* | N\* | N | C\* | N | N | N (API), webhooki ? | N (API), webhooki ? |
| Wiele języków | ✖ | N\* | P\* | N\* | N\* | N\* | N | N | N | ? |
| Wiele walut | ✖ | N | P\* | N\* | N\* | N\* | N | N | ? | N |
| Allegro i marketplace'y | ✖ | ? | ? | P (ChannelEngine) | ? | ? | ? | ? | I | N (16 marketplace'ów) |
| Ceny dla grup klientów | ✖ | N (B2B od Basic) | P\*/C\* | N\*, per klient P | N\* | P (cenniki Plus) | N\* | ? | N | P (wyższe plany) |
| Konta firmowe, zapytania ofertowe | ✖ | N/P (wg planu) | P\*/C\* | P (Evolve+) | P (Adobe) | P (Plus) | C (B2B Starter) | ? | ? | P |
| Automatyzacje (workflow) | ✖ | N (Flow) | P\*/I\* | N (Flow Builder) | C\*/I\* | ? | N (workflows) | ? | N (automatyczne statusy) | ? |
| Dostępność (EAA/WCAG) | ◐ (praktyki w kodzie, bez audytu) | ? | ? | N\* | ? | ? | ? | ? | N (deklaracja) | ? |

### Wnioski z macierzy

1. **Omnibus „z pudełka” jest naszą realną przewagą, ale tylko wobec platform globalnych i headless.** Shopify wymaga płatnej aplikacji ([OmniProof](https://apps.shopify.com/omniproof)). WooCommerce wymaga wtyczki, a część recenzji zarzuca wtyczkom błędne liczenie i budowanie historii dopiero od aktywacji ([Omnibus na wordpress.org](https://wordpress.org/plugins/omnibus/)). Shopware CE ma dwa pola wypełniane ręcznie ([Shopware, kwiecień 2022](https://www.shopware.com/en/news/shopware-6-release-news-april-2022/)). Shoper i IdoSell mają Omnibus w abonamencie, więc na polskim rynku SaaS przewagi nie ma.
2. **Polskie płatności i InPost to nasza największa dziura, a u Shopera i IdoSell standard.** Shoper ma wbudowaną integrację InPost z mapą ([pomoc Shoper](https://www.shoper.pl/help/artykul/integracja-shoper-inpost/)), IdoSell własnego brokera i IdoPay. U platform globalnych to płatne aplikacje: na Shopify Basic z dopłatą 2% za zewnętrzną bramkę ([shopify.com/pricing](https://www.shopify.com/pricing)). Przy Saleorze nie znaleziono w ogóle polskich aplikacji płatniczych.
3. **Zwroty inicjowane przez klienta są natywne w Shopify, Medusie, Shoperze i IdoSell, a płatne w Shopware, Magento i Sylius.** My ich nie mamy. Shoper deklaruje przy tym zgodność z dyrektywą 2023/2673 ([shoper.pl/cennik](https://www.shoper.pl/cennik/)). Jeśli polska ustawa wdrażająca wejdzie w życie, będzie to dla nas brak wobec rynku krajowego.
4. **Rezerwacje stanu i historia ruchów to nasza mocna strona, ale niekoniecznie unikatowa.** Rezerwacje mają też Magento MSI, Medusa i Saleor ([Adobe Inventory](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/introduction), [Medusa inventory](https://docs.medusajs.com/resources/commerce-modules/inventory)). Unikatowe jest udokumentowane testami wieloprocesowymi zachowanie pod współbieżnością. Notatki nie mają danych porównawczych o jakości tego zachowania u konkurencji.
5. **Każda platforma ma natywnie to, czego nam brakuje w codziennej pracy:** import i eksport CSV, edycję masową, edycję zamówień, zamówienia ręczne, akcje masowe. WooCommerce core ma edycję zamówień, ręczne zamówienia, zwroty z wywołaniem bramki i przywrócenie stanu po zwrocie ([WooCommerce refunds](https://woocommerce.com/document/woocommerce-refunds/), [managing orders](https://woocommerce.com/document/managing-orders/)).
6. **Faktury z KSeF ma natywnie tylko IdoSell.** U pozostałych to integracja albo wtyczka, a Sylius ma bezpłatny oficjalny InvoicingPlugin ([GitHub](https://github.com/Sylius/InvoicingPlugin)). Własny moduł KSeF nie byłby więc wyróżnikiem, tylko kosztem. Integracja z systemem fakturowym jest normą.
7. **W uprawnieniach personelu jesteśmy lepsi „z pudełka” od Sylius Standard i Medusy OSS** (RBAC płatny w Sylius Plus i w licencji enterprise Medusy; [Medusa RBAC](https://docs.medusajs.com/resources/enterprise/rbac)). Mamy obowiązkowe TOTP i role w API. Przegrywamy jednak w samym zarządzaniu kontami pracowników, które Shopify, Saleor i inne mają w panelu.
8. **Macierz ma dużo komórek „?” i „\*”.** Szczególnie dotyczy to Shopera, IdoSell, Sylius i Saleora. Wnioski o natywności u konkurencji mają w wielu wierszach średnią pewność i trzeba je potwierdzić przed użyciem w materiałach sprzedażowych.

---

## 5. Wyniki przeglądu najważniejszych ścieżek użytkowników

Przegląd wykonano 18.11.2026 na lokalnym stosie Docker Compose z rewizji `1aeb5076` (`STORE_MODE=dev`). Dane pochodziły z `make seed`: 191 produktów, 44 kategorie, 3 promocje, 3 kody. Użyto wbudowanej przeglądarki na desktopie (1024 px) i w emulacji 375 px.

**Ograniczenia przeglądu:** dane seed zamiast prawdziwego asortymentu; publicznego demo worf.pl nie klikano (to starsza rewizja z wyłączonymi kontami klientów); panelu na 375 px, pełnego przejścia klawiaturą i czytnikiem ekranu nie sprawdzano.

**Klient-gość.**

Co działa:
- Strona główna ma mega menu z trzema poziomami, breadcrumbs, fasety z licznikami i linię Omnibus na kafelkach.
- Wyszukiwarka toleruje literówki: „namoit” zwraca 6 namiotów, a „buty biegowe” 12 wyników.
- Kasa mieści się na jednej stronie. Ma poprawny przycisk „Zamawiam z obowiązkiem zapłaty”, który prawdopodobnie spełnia wymóg „równoważnego jednoznacznego sformułowania” z art. 17 ust. 3 UPK. Najbezpieczniejsze pozostaje brzmienie ustawowe.
- Kod `WITAJ10` połączył się z promocją: 559,20 zł − 55,92 zł = 503,28 zł.
- Strona symulatora jasno informuje, że to symulacja. Po opłaceniu klient wraca na potwierdzenie i dostaje e-mail.

Co nie działa:
- „Buty do biegania” zwraca 0 wyników, bo wyszukiwarka wymaga wszystkich słów i nie ma synonimów.
- Nie ma podpowiedzi w polu wyszukiwania.
- Karta produktu ma jedno zdanie opisu. Brakuje parametrów, marki, kosztu i czasu dostawy oraz informacji o zwrotach.
- Koszyk nie pokazuje kosztu dostawy.
- W kasie nie ma metody dostawy, Paczkomatu, NIP-u, akceptacji regulaminu ani linków do polityk.
- Stopka nie ma regulaminu, danych sprzedawcy ani kontaktu.
- Po zamknięciu karty gość nie ma drogi do statusu zamówienia, bo e-mail nie zawiera linku.

**Klient zalogowany.**

Co działa:
- Logowanie przez Keycloak ma polski ekran z resetem hasła.
- Płatność odrzucona w symulatorze daje komunikat „Płatność nie powiodła się” z terminem „Opłać do 17:45”. Zapłata inną metodą zadziałała (R-100002).
- „Moje zamówienia” pokazuje listę i szczegóły z rozbiciem netto, VAT i brutto oraz osią czasu.

Co nie działa:
- `/konto` zwraca 404. Nie ma profilu, adresów ani zgód.
- Adres trzeba wpisywać przy każdym zamówieniu.
- Klient nie może anulować zamówienia, odstąpić od umowy, złożyć reklamacji, pobrać faktury ani śledzić przesyłki.

**Obsługa zamówień.**

Co działa:
- Logowanie wymaga hasła i kodu TOTP.
- Pulpit ma kafelki „Do obsługi”.
- Lista zamówień ma wyszukiwanie po numerze, e-mailu i nazwisku, filtr statusu i dat oraz przełącznik „Zwroty do wykonania”.
- Szczegóły zamówienia pokazują historię z autorem i notatką.

Co nie działa:
- „Oznacz jako wysłane” ma tylko pole notatki. Brakuje przewoźnika, numeru i etykiety.
- „Przyjmij zwrot” dotyczy całego zamówienia. Po przyjęciu pojawia się kwota 503,28 zł z terminem +14 dni do ręcznego oznaczenia.
- **Stan magazynowy po zwrocie nie wrócił**: wariant ma 20 sztuk wobec 21 w bliźniaczym.
- Lista nie ma akcji masowych, eksportu ani wydruków.
- Gość z zamówienia R-100001 nie pojawił się na liście klientów.

**Administrator katalogu, cen, magazynu i marketingu.**

Co działa:
- Formularz produktu ma kategorie, slug, krótki opis do 500 znaków, klasę podatkową, do 10 zdjęć i warianty.
- Promocje i kody działają. Licznik użycia `WITAJ10` zwiększył się po zamówieniu testowym.
- Ekran „Stan systemu” pokazuje zdrowie 8 usług.

Co nie działa:
- **Cena i stan nie są w formularzu produktu.** Ustawia się je osobno na ekranach „Ceny” i „Stany magazynowe”, wariant po wariancie.
- Brakuje pól opisu długiego, parametrów, SKU/EAN, marki, wagi i SEO.
- Menu nie ma sekcji Ustawienia, Dostawa, Płatności, Treści, Raporty ani Pracownicy.

**Mobile (375 px).**

Co działa: listing i karta produktu bez poziomego przewijania (`scrollWidth` = 375), filtry pod przyciskiem, stale widoczna wyszukiwarka, jeden `h1`, `alt` na wszystkich obrazach.

Czego nie sprawdzono: panelu na telefonie.

---

## 6. Mocne strony obecnego produktu

- **Zakupy równoległe nie sprzedają towaru ponad stan.** Rezerwacja powstaje przy złożeniu zamówienia z `SELECT … FOR UPDATE` w rosnącej kolejności `variant_id`. Testy wieloprocesowe na PostgreSQL pokazują, że z 20 rezerwacji na 7 sztuk dokładnie 7 się udaje, a odwrotna kolejność linii nie daje zakleszczeń (`apps/api/tests/Integration/Inventory/Concurrency/ReservationConcurrencyTest.php`). Dla właściciela to brak telefonów „zamówiłem, a towaru nie ma”.
- **Złożenie zamówienia jest idempotentne i odporne na zmianę ceny.** Klucz `Idempotency-Key` z odciskiem żądania jest sprawdzany przed blokadą koszyka i pod nią. `expectedTotalGross` daje 409, gdy cena zmieniła się w trakcie (`PlaceOrderHandler.php:44-60,80-89`). Podwójne kliknięcie nie tworzy dwóch zamówień.
- **Pieniądze są w groszach, a sumy zawsze się zgadzają.** Netto linii liczy się z brutto linii, a rabat rozkłada metodą największych reszt (`PriceBreakdown.php`, `BasketQuote.php`, testy własności). Zamówienie kopiuje ceny, stawki i dane promocji. To gotowa podstawa pod fakturę z rozbiciem VAT.
- **Omnibus jest liczony poprawnie, nie na skróty.** Wynika z historii cen i historii innych promocji w oknie 30 dni (`LowestPriorPrice.php` z testem). Cenę wariantu w aktywnej promocji blokuje `PriceLockedByPromotion`, co chroni cenę referencyjną. Spełnia to art. 4 ust. 2 ustawy o informowaniu o cenach ([tekst jedn. Dz.U. 2023 poz. 168](https://api.sejm.gov.pl/eli/acts/DU/2023/168/text/T/D20230168L.pdf)) dla promocji.
- **Webhooki płatności są przygotowane na produkcję.** System sprawdza HMAC na surowej treści przed parsowaniem, deduplikuje zdarzenia, blokuje wiersz płatności i dwa razy weryfikuje kwotę (`HandleProviderWebhookHandler.php:20-32`). Port płatności z dwoma różnymi symulatorami wymusił prawdziwą abstrakcję.
- **E-maile się nie gubią.** Wysyłka idzie przez transactional outbox do RabbitMQ, a dzierżawa `UPSERT` gwarantuje jeden e-mail na zdarzenie (`messenger.yaml:29-30,108`).
- **Wyszukiwarka działa po polsku.** Ma stemming, wersję bez znaków diakrytycznych, literówki, fasety liczone bez własnego warunku i działa bez JS (`ProductIndexDefinition.php:165-174`, `ProductSearchQuery.php:150-182`).
- **SEO techniczne jest dojrzałe.** Strony są renderowane po stronie serwera (SSR/ISR). Są canonical, noindex,follow dla filtrów i sitemap w porcjach. Stare adresy kategorii dostają przekierowanie 308. JSON-LD zawiera ofertę na wariant z `priceValidUntil` (`e2e/tests/seo.spec.ts`).
- **Panel jest bezpieczny i ma sensowne role.** Osobny realm `backoffice` ma obowiązkowe TOTP i wylogowanie po 15 minutach bezczynności. Cztery role (admin, obsługa, magazyn, marketing) odpowiadają działom i API sprawdza je voterami, nie tylko ukrywa w menu (`OrderVoter.php:32-62`).
- **Panel jest zorientowany na pracę.** Pulpit „Do obsługi” prowadzi do przefiltrowanych list, stan filtrów jest w URL, kolejka zwrotów ma termin i status przeterminowania, a historia statusów zapisuje autora.
- **Ścieżki są pokryte testami E2E.** Testy obejmują `checkout-guest`, `checkout-customer`, `payment-resume`, `order-management`, `cart-merge`, `price-change`, `discount-codes`, katalog, filtry i SEO (`e2e/tests/`). Komunikaty walidacji są po polsku, a dostępność kasy wspierają `aria-live` i obsługa focusu.

---

## 7. Braki funkcjonalne wraz ze znaczeniem biznesowym

Podstawa prawna według notatek. Teksty ustaw cytowane są z [UPK, Dz.U. 2026 poz. 1244](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf), chyba że wskazano inaczej.

| Brak | Kogo boli | Klasyfikacja | Uzasadnienie |
|---|---|---|---|
| Prawdziwy operator płatności (BLIK, szybkie przelewy, karty) | właściciel, klient | NIEZBĘDNE | Bez operatora nie ma pieniędzy. BLIK wybiera 72% kupujących, szybkie przelewy 64%, karty 43% (Gemius 2025 za [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers), źródło wtórne, wydawca jest operatorem). |
| Zwrot pieniędzy przez API operatora (pełny i częściowy) | obsługa, prawo | NIEZBĘDNE | Art. 32 ust. 1–2 UPK (obowiązuje): zwrot w 14 dni tym samym sposobem płatności. Ręczny przelew zwrotu do płatności kartą lub BLIK-iem jest w praktyce niewykonalny. |
| Płatności „do ręcznego zwrotu” widoczne w panelu (#221) | obsługa, klient | NIEZBĘDNE (przy prawdziwych pieniądzach) | Dziś widać je tylko w bazie i logu. Klient, który zapłacił po terminie, dostaje e-mail „anulowane”, a obsługa nie widzi jego pieniędzy. |
| Metody i koszt dostawy w koszyku i kasie | klient, prawo, właściciel | NIEZBĘDNE | Art. 12 ust. 1 pkt 5 i art. 17 ust. 1 UPK: łączna cena z kosztami dostawy przed zamówieniem. Art. 23: koszty niepokazane ponosi sklep. |
| Paczkomat InPost z mapą | klient | NIEZBĘDNE (rynkowo) | 83% kupujących wybiera automat paczkowy, 87% z nich InPost (Gemius 2025, źródło wtórne). Interpretacja tych liczb różni się między źródłami ([antyweb](https://antyweb.pl/e-commerce-w-polsce-2025-gemius)). |
| Numer przesyłki i link śledzenia w e-mailu | klient, obsługa | NIEZBĘDNE (jakość obsługi) | Bez numeru każde „gdzie paczka” trafia do obsługi. Dziś numer da się wpisać tylko w notatkę, której klient nie widzi. |
| Dane sprzedawcy, regulamin, polityka prywatności, strona reklamacji i zwrotów | prawo, klient | NIEZBĘDNE | Art. 12 ust. 1 pkt 2–4, 8–10, 13, 21 UPK (obowiązuje). Brak pouczenia o odstąpieniu wydłuża termin odstąpienia o 12 miesięcy (art. 29 ust. 1). |
| Akceptacja regulaminu w kasie i zapis wersji regulaminu w zamówieniu | prawo | NIEZBĘDNE | Art. 24 UPK: ciężar dowodu spełnienia obowiązków informacyjnych spoczywa na przedsiębiorcy. |
| Potwierdzenie zamówienia z pełną treścią (dane sprzedawcy, koszty, pouczenie, wzór formularza) i faktyczna wysyłka e-maili w prod | prawo, klient | NIEZBĘDNE | Art. 21 ust. 1 UPK: potwierdzenie na trwałym nośniku najpóźniej przy dostarczeniu towaru. Załącznik nr 2 to wzór formularza. Dziś e-mail nie ma tych treści, a w prod nie wychodzi. |
| Funkcja odstąpienia online („odstąp od umowy tutaj”) | klient, prawo | ISTOTNE (zalecane przed startem) | Art. 11a dyr. 2011/83/UE (dyr. 2023/2673) stosuje się w UE od 19.06.2026, ale **polska ustawa wdrażająca nie obowiązuje**. Projekt UC82 wycofano, UC169 jest w konsultacjach, bez daty ([UOKiK BIP](https://uokik.gov.pl/bip/implementacja-dyrektywy-parlamentu-europejskiego-i-rady-ue-2023-2673), [Bezprawnik](https://bezprawnik.pl/nowy-obowiazek-dla-sklepow-internetowych/)). Jeśli sklep oferuje odstąpienie elektronicznie, art. 30 ust. 3–4 UPK już dziś wymaga niezwłocznego potwierdzenia na trwałym nośniku. |
| Dane producenta i informacje o bezpieczeństwie na karcie (GPSR) | prawo | NIEZBĘDNE | Art. 19 rozp. 2023/988, stosowane od 13.12.2024 (tekst artykułu niezweryfikowany w EUR-Lex). Polska ustawa w mocy od 3.01.2026 przewiduje karę do 500 000 zł za brak informacji przy sprzedaży online ([PARP](https://www.parp.gov.pl/component/content/article/90288:bezpieczenstwo-produktow-konsumenckich-rozporzadzenie-gpsr-i-nowa-ustawa-o-nadzorze-nad-ogolnym-bezpieczenstwem-produktow), źródło wtórne). |
| Opis długi i parametry produktu | klient, właściciel | NIEZBĘDNE (rynkowo) | Art. 12 ust. 1 pkt 1 UPK wymaga podania „głównych cech świadczenia”. Jedno zdanie opisu nie wystarczy przy sprzęcie technicznym. |
| NIP, firma i adres do faktury w kasie | klient B2B, właściciel | NIEZBĘDNE | Faktura na żądanie konsumenta w ciągu 3 miesięcy (art. 106b ust. 3 ustawy o VAT, wg [podatki.biz](https://www.podatki.biz/artykuly/jak-dlugo-konsumenci-moga-zadac-wystawienia-faktury-do-paragonu_34_57765.htm), źródło wtórne). Faktura dla nabywcy z NIP podlega KSeF ([ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/)). |
| Wystawianie faktur przez API systemu fakturowego (KSeF po jego stronie) | właściciel, księgowość | ISTOTNE (na start wystarczy ręcznie w zewnętrznym systemie) | KSeF obowiązuje od 1.02.2026 (duzi podatnicy) i od 1.04.2026 (pozostali, oficjalnie). Termin 1.01.2027 dla najmniejszych podają tylko źródła wtórne. **Faktury B2C nie są objęte obowiązkiem KSeF**, wystawia się je w nim dobrowolnie. |
| Eksport zamówień (CSV) dla księgowości i kuriera | właściciel, obsługa | NIEZBĘDNE (praktycznie) | Bez eksportu i integracji rozliczenie wymaga dostępu do bazy. |
| Weryfikacja stawek VAT i ich edycja bez programisty | właściciel, prawo | NIEZBĘDNE (weryfikacja) / ISTOTNE (edycja) | W kodzie stawki są opisane jako „EXAMPLES… must be verified before anything is sold” (`PolishVatRates.php`). Zmiana stawki wyłącza sprzedaż klasy do ręcznego uruchomienia CLI. |
| Import CSV produktów, cen i stanów | właściciel | NIEZBĘDNE (przy istniejącym asortymencie) / ISTOTNE | Dziś jedyną drogą masową jest seed z CLI. 30 wariantów to 30 dialogów ceny i 30 przyjęć. |
| SKU/EAN na wariancie | magazyn, właściciel | ISTOTNE (NIEZBĘDNE przy integracjach) | Kody są potrzebne do kompletacji, hurtowni, feedów i porównywarek. JSON-LD nie ma `sku`, `gtin`, `brand`. |
| Wyjście z trybu demo (#296) | właściciel | NIEZBĘDNE | Dziś `orderMail: mailpit`, `customerAccounts: false`, `demoNotice: true`, `noindex: true`. Zgłoszenie #179 („tryb publicznego demo”: nocny reset, konta demo) idzie w przeciwną stronę i nie przybliża sprzedaży. |
| Siatka bezpieczeństwa wygasania zamówień (ASYNC-RELIABILITY) | właściciel, klient | NIEZBĘDNE (przy realnym ruchu) | Wiadomość w stanie `failed` oznacza towar zarezerwowany na zawsze, a płatność `pending` może wisieć bez końca. Plan jest gotowy, status pusty. |
| Limity nadużyć (ABUSE-LIMITS, #181) | właściciel | NIEZBĘDNE (przed publicznym startem) | Podgląd kodu rabatowego działa jak wyrocznia bez limitu, a gość może zablokować stan (50 × 99 sztuk na 35 minut). |
| Anonimizacja i obsługa żądań RODO (#119) | prawo, obsługa | NIEZBĘDNE (minimum: procedura + narzędzie) | Prawo do usunięcia danych jest ograniczone obowiązkami podatkowymi (art. 17 RODO, wiedza ogólna researchera, niezweryfikowana w tekście). |
| Informacja Omnibus przy kodach rabatowych | prawo | ISTOTNE (do weryfikacji prawnej) | UOKiK: przy kodach ogłaszanych w sklepie informacja o najniższej cenie powinna być przy produktach objętych kodem, a przy kodach spoza sklepu najpóźniej po wpisaniu kodu ([Wyjaśnienia UOKiK](https://uokik.gov.pl/storage/archiwum/e6e552a1.pdf)). Nasza kasa pokazuje sam rabat. |
| Obsługa reklamacji z terminem 14 dni | prawo, obsługa | ISTOTNE (na start możliwe mailem) | Art. 7a UPK (obowiązuje od 1.01.2023): brak odpowiedzi w 14 dni oznacza uznanie reklamacji. |
| Zwroty częściowe z automatycznym przywróceniem stanu | obsługa | ISTOTNE | Zwrot jednego z trzech rozmiarów to codzienność w branży sportowej. Dziś obsługa musi rozliczyć go poza systemem. |
| Akcje masowe, wydruki, notatki wewnętrzne, edycja adresu, anulowanie zamówienia `new` (#586) | obsługa | ISTOTNE | Przy 200 zamówieniach dziennie każde trzeba otworzyć, oznaczyć i potwierdzić osobno. |
| Zakładanie kont pracowników i ustawienia sklepu w panelu | właściciel | ISTOTNE (NIEZBĘDNE dla nietechnicznego właściciela) | Dziś potrzebna jest konsola Keycloaka przez port-forward. |
| Książka adresowa i profil klienta (#117) | klient | ISTOTNE | To główna korzyść z konta dla klienta. |
| Status zamówienia gościa (numer + e-mail albo link w e-mailu) | klient, obsługa | ISTOTNE | Ciasteczko potwierdzenia wygasa po godzinie. Nie ma na to zgłoszenia; pokrewne #118 dotyczy tylko przypisania zamówień gościa do konta o tym samym e-mailu. |
| E-mail „nowe zamówienie” do sklepu | obsługa | ISTOTNE | Dziś panel odpytuje API co 30 sekund i nikt nie dostaje powiadomienia. |
| GA4 z banerem zgód | właściciel, prawo | ISTOTNE | Zgoda na cookies nieistotne obowiązuje z art. 399 PKE ([Dz.U. 2024 poz. 1221](https://api.sejm.gov.pl/eli/acts/DU/2024/1221/text/O/D20241221.pdf)). Dziś sklep używa tylko cookies niezbędnych, więc baner jest zbędny. |
| Feed Google Merchant i Ceneo, porzucone koszyki, opinie | właściciel | ISTOTNE (wzrost) | Opinie wymagają informacji o weryfikacji (art. 6 ust. 4 pkt 7 i art. 7 pkt 25 UPNPR, [Dz.U. 2023 poz. 845](https://api.sejm.gov.pl/eli/acts/DU/2023/845/text/T/D20230845L.pdf)). |
| Dostępność interfejsu (EAA) | prawo, klient | ISTOTNE (zależnie od statusu sprzedawcy) | Ustawa z 26.04.2024 obowiązuje od 28.06.2025, ale **art. 4 pkt 1 wyłącza usługi mikroprzedsiębiorców** ([Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)). Mały lub średni sprzedawca musi zapewnić dostępny interfejs. |
| Pobranie (COD), przelew tradycyjny | klient | OPCJONALNE (po MVP) | Pobranie deklaruje 33% respondentów, w 2024 roku 39% ([eurobuildcee](https://eurobuildcee.com/news/35098-za-zakupy-w-internecie-wolimy-placic-gotowka), źródło wtórne). Gotówka u kuriera może wymagać kasy fiskalnej, a obecny model z terminem 30 minut wyklucza pobranie. |
| Pole „zharmonizowana informacja o zgodności towaru” | prawo | OPCJONALNE teraz | Obowiązuje dopiero od 27.09.2027 (Dz.U. 2026 poz. 1353). |
| Wiele walut i języków, OSS, B2B, marketplace, wiele magazynów | — | NIE WARTO teraz | Sklep sprzedający tylko do Polski nie potrzebuje OSS. Próg 10 000 EUR dotyczy sprzedaży wysyłkowej do konsumentów w innych krajach UE ([Komisja Europejska](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en)). |

---

## 8. Funkcjonalności istniejące, ale niekompletne lub trudne w użyciu

- **Ceny i stany są poza formularzem produktu.** Nowy produkt z 30 wariantami wymaga 30 dialogów ceny i 30 przyjęć na dwóch osobnych ekranach. Do tego nie ma generatora kombinacji wariantów.
- **Promocje są sztywne.** Działa tylko procent równy dla wszystkich wariantów, a warianty wybiera się ręcznie (do 1000, bez opcji „cała kategoria”). Aktywnej promocji nie da się poprawić. Pomyłka oznacza zakończenie promocji i założenie nowej, co psuje punkt odniesienia Omnibus dla następnej.
- **Kod rabatowy jest tylko w kasie i ginie po odświeżeniu strony.** Zawsze łączy się z promocją, bez górnej granicy łącznego rabatu i bez opcji „nie łączy się z promocjami”. Nie ma limitu na klienta, więc kod „WITAJ10 na pierwsze zakupy” łatwo nadużyć.
- **Zwrot nie przywraca stanu.** Pracownik musi pamiętać o osobnym „Przyjęciu” w module magazynu. Nic nie łączy ruchu magazynowego ze zwrotem.
- **Zwrot pieniędzy to dwie czynności poza systemem i jedna w systemie.** Przelew robi się ręcznie, potem klika „Oznacz zwrot jako wykonany”. Klient nie dostaje e-maila o wykonaniu zwrotu.
- **Zamówienia `new` nie da się anulować.** Klient, który dzwoni „pomyliłem się”, musi czekać 35 minut na automatyczne wygaśnięcie. Termin płatności 30 minut jest zaszyty w kodzie (`Order.php:31`).
- **Notatkę można dodać tylko przy zmianie statusu.** „Klient dzwonił” nie ma gdzie trafić.
- **Pulpit sprzedaży nie odejmuje anulowań i zwrotów** i pokazuje tylko brutto. Właściciel widzi zawyżony przychód.
- **Lista klientów pokazuje tylko konta z co najmniej jednym zamówieniem.** Goście są niewidoczni. Obsługa nie zmieni danych klienta ani nie wyśle mu resetu hasła.
- **Wyszukiwarka w panelu szuka tylko produktów**, a obsługa częściej szuka zamówienia albo klienta.
- **Fasety kolorów są nieznormalizowane.** „Czarny (26)”, „czarne (24)” i „czarna (22)” to trzy filtry, więc klient musi zaznaczyć trzy pozycje.
- **Sortowanie domyślne to nazwa A–Z.** Nie ma sortowania po nowościach, popularności ani wysokości obniżki. Paginacja ma tylko „poprzednia” i „następna”.
- **Klasy podatkowe mają nazwy asortymentu demo** (żele, batony, mapy). Nie dodasz klasy ani nie zmienisz stawki bez programisty.
- **Próg „niski stan” to 5 sztuk dla całego sklepu**, zaszyty w kodzie i indeksie. Nie ma powiadomienia e-mail.
- **Przyjęcie towaru dotyczy jednego wariantu naraz.** Nie ma dostawcy, numeru dokumentu ani ceny zakupu, więc nie da się policzyć marży ani wartości magazynu.
- **Rejestracja klienta używa domyślnego ekranu Keycloaka** bez motywu sklepu i bez akceptacji regulaminu. Klient zmienia dane tylko w konsoli konta Keycloaka, do której sklep nie linkuje.
- **E-maile to czysty tekst bez marki, danych sprzedawcy i linku do zamówienia.** Treść jest w klasach PHP.
- **Strona główna (hero, kafelki) jest zaszyta w kodzie** (`apps/store/src/home/home-content.ts`). Zmiana banera wymaga wdrożenia.

---

## 9. Funkcjonalności, które mogą być niepotrzebne lub nadmiernie rozbudowane

Ta ocena nie dotyczy jakości kodu, tylko proporcji inwestycji wobec potrzeb pierwszego realnego sklepu. Tych elementów nie trzeba usuwać. Nie należy w nie dalej inwestować, dopóki brakuje podstaw.

- **Architektura pod 10 mln produktów** obejmuje porcjowaną sitemapę, Elasticsearch z aliasami i reindeksacją partiami, Varnish z tagami xkey oraz ISR z czyszczeniem cache. Tymczasem katalogu nie da się zaimportować inaczej niż seedem. Nie wiemy też, czy skala jest osiągnięta, dopóki nie potwierdzą tego testy obciążeniowe.
- **Pulpit sprzedaży liczony z tabeli godzinowej z 16 shardami**, „żeby równoległe płatności nie czekały na jeden wiersz”. Nie ma natomiast zakresu dat, wykresu ani netto/VAT.
- **Liczniki list ograniczone do 1000 i 10 000** oraz liczba produktów z `reltuples`. Dla sklepu z tysiącami pozycji to utrata dokładności bez korzyści.
- **Ekran „Stan systemu” w panelu sklepu** (sondy usług, outbox, kolejki, zgodność indeksu) to narzędzie operatora infrastruktury. Powstał przed ekranem ustawień i pracowników.
- **Model odczytu `ordering_customer` jest budowany pod milion klientów**, a obsługa nie może edytować danych żadnego klienta.
- **Symulator dwóch dostawców płatności** (ok. 1300 linii, `apps/api/src/PaymentSimulator/`) ma wartość testową i demonstracyjną. W realnym sklepie zastąpi go sandbox operatora. Nie warto go rozwijać.
- **Rozbudowane zabezpieczenia wokół stawek VAT** (`pricing:reconcile-tax` z kodami wyjścia, przeliczenie przez outbox) przy stawkach, których nie da się edytować z panelu.
- **Blokady advisory i wykrywanie nakładających się okresów promocji** przy braku podstawowych typów promocji (kwotowej, kategorii, koszykowej).
- **Rozbudowane reguły tożsamości wariantów** (blokady osi po publikacji, klucz porównania). Chronią spójność koszyka i magazynu, ale redaktorowi trudno zrozumieć komunikaty błędów.
- **Idempotencja i wersjonowanie widoczne dla użytkownika** (409 „zmieniono w międzyczasie”, klucze przyjęć). Są poprawne, ale przy 1–3 pracownikach rzadko potrzebne, a wydłużają implementację każdej prostej funkcji, np. masowej zmiany cen.

---

## 10. Minimalny zakres umożliwiający rzeczywiste uruchomienie sklepu

Lista kontrolna „dzień pierwszej sprzedaży” dla jednego polskiego sklepu B2C ze sprzętem sportowym, sprzedającego tylko do Polski, z płatnością wyłącznie online.

**Pieniądze**

- [ ] Jeden operator z BLIK, szybkimi przelewami i kartami. Webhook jest idempotentny (to już działa), zwroty pełne i częściowe idą przez API.
- [ ] Płatności „do ręcznego zwrotu” są widoczne w panelu. E-mail „zwrot wykonany” wychodzi do klienta.
- [ ] Stawki VAT zweryfikowane przez księgową. Klasy podatkowe odpowiadają realnemu asortymentowi.

**Dostawa**

- [ ] Paczkomat InPost z mapą (Geowidget v5) i zapisem ID punktu w zamówieniu, plus kurier.
- [ ] Ceny stałe i próg darmowej dostawy.
- [ ] Koszt dostawy w koszyku, kasie, sumie zamówienia i e-mailu.
- [ ] Pole przewoźnik + numer przesyłki w panelu i e-mail z linkiem śledzenia.

**Prawo i treść**

- [ ] Dane sprzedawcy w stopce i w kasie.
- [ ] Strony: regulamin, polityka prywatności (z informacją o cookies), dostawa i płatności, zwroty i reklamacje, kontakt.
- [ ] Akceptacja regulaminu w kasie i wersja regulaminu zapisana w zamówieniu.
- [ ] Potwierdzenie zamówienia na trwałym nośniku: pozycje, ceny, koszt i metoda dostawy, dane sprzedawcy, procedura reklamacji, pouczenie o odstąpieniu, wzór formularza. Najbezpieczniej w treści albo w załączniku PDF, bo link do zmiennej strony może nie spełniać wymogu (wykładnia niezweryfikowana).
- [ ] Formularz odstąpienia online z potwierdzeniem e-mailem z datą i godziną. Prawnie dziś dobrowolne, zalecane.
- [ ] Dane GPSR: producent, adres i e-mail producenta, osoba odpowiedzialna w UE dla producentów spoza UE, identyfikator modelu, ostrzeżenia.
- [ ] Procedura RODO i narzędzie anonimizacji (#119).

**Dane i operacje**

- [ ] Opis długi i parametry, SKU/EAN, import CSV produktów, cen i stanów.
- [ ] NIP, firma i adres do faktury w kasie. Eksport zamówień do CSV.
- [ ] E-mail „nowe zamówienie” do sklepu.

**Utrzymanie**

- [ ] ASYNC-RELIABILITY (siatka wygasania zamówień i płatności `pending`).
- [ ] ABUSE-LIMITS (#181).
- [ ] Wyjście z trybu demo: poczta produkcyjna, `noindex: false`, bez paska demo (#296).
- [ ] Kopie zapasowe.

**Rekomendowany minimalny zestaw integracji**

**Płatności: PayU jako wybór domyślny, decyzja właściciela po krótkim rozpoznaniu technicznym.**

- **PayU.** Ma oficjalnie udokumentowane API zwrotów: pełny bez kwoty, częściowy z kwotą w groszach, wiele zwrotów częściowych z `extRefundId` ([PayU docs](https://developers.payu.com/europe/docs/payment-flows/refunds/)). Oferuje BLIK, karty, przelewy, Apple Pay i Google Pay, a dla małych firm aktywację za 29 zł i prowizję 1,49% lub 1,1% + 0,30 zł ([poland.payu.com/smb](https://poland.payu.com/smb/)). Ograniczenie: nowy sprzedawca przed weryfikacją przyjmie do 2 500 zł, tylko przelewy i BLIK ([PayU first steps](https://poland.payu.com/wp-content/uploads/sites/14/2026/07/PayU_first_steps_pl.pdf)).
- **Przelewy24.** Nominalnie tańszy: 1,29% + 0,30 zł dla nowych klientów, zwroty bezpłatne ([przelewy24.pl/oferta](https://www.przelewy24.pl/oferta)). Jego dokumentacji REST nie udało się zweryfikować.
- **Stripe.** Najprostsze jednolite API (BLIK, P24, karty, zwroty, podpisane webhooki; [docs.stripe.com/payments/blik](https://docs.stripe.com/payments/blik)), ale stała opłata 1 zł przy BLIK-u (1,6% + 1 zł; [stripe.com/pl/pricing](https://stripe.com/pl/pricing)) jest dotkliwa przy średnim koszyku BLIK 167 zł ([bank.pl](https://bank.pl/?p=564233)).

Ceny pochodzą ze stron bez daty, pobranych 18.11.2026, i wymagają potwierdzenia przed umową.

**Dostawa: InPost ShipX i Geowidget v5 bezpośrednio, bez brokera na start.**

Token ShipX można wygenerować bez umowy kurierskiej, jeśli sklep używa tylko Paczkomatów. Jest sandbox i webhook statusów ([dokumentacja InPost](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/1187086337)). Shoper podaje, że stary mechanizm mapy ma być wyłączony 1.01.2027 ([Shoper](https://www.shoper.pl/learn/?p=69068), źródło wtórne), dlatego od razu Geowidget v5.

Kurier na start może działać bez integracji: stała cena, etykieta nadawana ręcznie w panelu przewoźnika, numer przesyłki wpisywany w nowe pole. Dla gabarytów (namioty, rowery) kurier jest konieczny, bo gabaryt A Paczkomatu to 8×38×64 cm do 25 kg ([kb.pl](https://kb.pl/transport/koszty-transportu/cennik-inpost-zobacz-jakie-sa-ceny-paczkomatow), źródło wtórne).

Broker (Furgonetka bez umowy i deklaracji wolumenu, [furgonetka.pl](https://furgonetka.pl/en/e-commerce-tools); Apaczka z API v2, [apaczka.pl](https://www.apaczka.pl/integracje/)) to krok drugi, gdy przybędzie przewoźników. Dokumentacji API brokerów nie zweryfikowano.

**Faktury: integracja z jednym systemem fakturowym (wFirma albo Fakturownia), bez własnego KSeF i bez kasy fiskalnej.**

Oba systemy mają oficjalnie potwierdzone API z KSeF:

- wFirma: API bezpłatne do 1000 dokumentów miesięcznie, automatyczna wysyłka do KSeF i webhook statusu ([pomoc.wfirma.pl](https://pomoc.wfirma.pl/-api-interfejs-dla-programistow)),
- Fakturownia: publiczne REST API zgodne z FA(3) ([github.com/fakturownia/api](https://github.com/fakturownia/api)).

Kasa fiskalna nie jest potrzebna przy dostawie pocztą lub kurierem z pełną zapłatą przez bank lub bramkę (poz. 41 załącznika do rozporządzenia MF, Dz.U. 2024 poz. 1902, do 31.12.2027; według cytatu wtórnego w [jpk.info.pl](https://jpk.info.pl/aktualnosci/2025/sprzedaz-wysylkowa-bez-kasy-fiskalnej/)). Właśnie dlatego na start pomijamy pobranie i odbiór osobisty.

Na pierwszą sprzedaż wystarczą NIP w kasie, eksport zamówień i ręczne wystawianie faktur w wybranym systemie. Automatyczne wystawianie przez API to epik 5.

**Decyzje właściciela:**

- wybór operatora (PayU, P24 albo Stripe) i przejście jego weryfikacji,
- konto w Managerze Paczek z potwierdzonymi danymi firmy i ewentualna umowa kurierska,
- wybór systemu fakturowego razem z księgową,
- regulamin, polityka prywatności i pouczenia od prawnika,
- weryfikacja stawek VAT,
- status mikroprzedsiębiorcy (wyłączenie z wymogów dostępności),
- czy na start oferować odstąpienie online.

---

## 11. Proponowane pozycjonowanie produktu i docelowy segment rynku

| Segment | Dopasowanie dziś | Dopasowanie po MVP | Koszt alternatywy u konkurencji (z notatek) |
|---|---|---|---|
| Mały sklep (do kilkuset zamówień miesięcznie) | Brak | Słabe: stos wielu usług i własne utrzymanie przegrywają z abonamentem | Shoper Standard 299 zł netto/mies. albo 3 249 zł/rok regularnie, plus Shoper Płatności od 1,58% + 0,39 zł ([shoper.pl/cennik](https://www.shoper.pl/cennik/), [pełny cennik](https://www.shoper.pl/pelny-cennik-uslug-shoper)). Shopify Basic 109 zł/mies. (79 zł przy rocznym) z dopłatą 2% za zewnętrzną bramkę i aplikacjami InPost (9–19 USD) i Omnibus (14,99–16,99 USD) ([shopify.com/pricing](https://www.shopify.com/pricing)). IdoSell Start 119 zł/mies. do 20 zamówień (wg artykułu Shopera, [shoper.pl/learn](https://www.shoper.pl/learn/?p=66989), marketing konkurenta) |
| Rozwijający się średni sklep (tysiące zamówień miesięcznie) | Brak | Częściowe: rdzeń i Omnibus wystarczą, ale bez Allegro, BaseLinkera, akcji masowych i raportów obsługa będzie wolniejsza niż w SaaS | Shoper Premium od 729 zł/mies. albo 7 849 zł/rok, bez opłat za zamówienie, do 50 magazynów. IdoSell Expert+ 729 zł/mies. + 1,49 zł za zamówienie + 0,25% opłaty integracyjnej (wg Shopera, marketing konkurenta). Shopify Grow 319 zł, Advanced 1 630 zł/mies. BaseLinker Business 279 zł/mies. do 1000 zamówień ([base.com](https://base.com/pl-PL/cennik/)) |
| Duży sklep z rozbudowanym katalogiem | Brak (brak importu, atrybutów i kodów) | Umiarkowane: architektura była projektowana pod duży katalog, ale wydajności nie potwierdziły testy obciążeniowe, a brakuje PIM-u, integracji ERP i wielu magazynów | Shopware Rise od 600 EUR/mies., Evolve od 2 400 EUR/mies., z płatnym planem wymaganym powyżej 1 mln EUR GMV dla dostępu do konta i Store ([shopware.com/pricing](https://www.shopware.com/en/pricing/)). Adobe Commerce: licencja ok. 22 tys. USD/rok on-prem przy GMV 1–5 mln USD, TCO 2–3× licencji (szacunek agencji, [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)). Magento OS: hosting 400–3 000+ USD/mies. i rozszerzenia 500–5 000+ USD/rok (ten sam szacunek). Shoper Premium do 500 000 produktów |
| Platforma headless z indywidualnymi integracjami | Słabe: dobre REST API z OpenAPI, ale bez publicznej dokumentacji, kluczy, webhooków i rozszerzeń | Słabe, dopóki nie powstanie warstwa integracyjna | Medusa Cloud od 29/99/299 USD/mies., rdzeń MIT ([medusajs.com/pricing](https://medusajs.com/pricing)). Saleor Cloud od 1 599 USD/mies. do 200 tys. USD GMV ([saleor.io/pricing](https://saleor.io/pricing)). Sylius Plus „od 800 EUR/rok” według GMV (strona niespójna, [sylius.com/plus](https://sylius.com/plus/)) |

**Segment o największym potencjale to połączenie drugiego i trzeciego:** dedykowany sklep dla średniego polskiego sprzedawcy z dużym katalogiem, któremu nie wystarcza SaaS albo przeszkadzają opłaty od zamówień (IdoSell od 1.08.2026). Chodzi o sprzedawcę, który potrzebuje własnych integracji, np. z ERP czy hurtownią, i dziś zleca wdrożenie na Sylius, Magento lub Shopware. Tam nasza przewaga, czyli poprawność transakcji, Omnibus natywnie, polska wyszukiwarka i bezpieczny panel, zastępuje pracę, którą agencja i tak musiałaby wykonać z wtyczek lub od zera. Polskie płatności są w każdej platformie headless integracją lub własnym kodem, a wtyczki BitBag dla Sylius są potwierdzone tylko dla 1.x ([Sylius Addons](https://addons.sylius.com/en_US/products/bitbag-przelewy24-plugin)).

**Ryzyka:**

- **Koszt alternatywy jest niski.** Shoper Premium ze stałym abonamentem i wbudowanymi integracjami kosztuje rocznie mniej niż utrzymanie stosu i programisty.
- **Bezpośredni konkurent jest mocny.** Sylius ma ten sam stos, a cyber_Folks razem z BitBagiem i PrestaShopem może dowieźć polskie integracje szybciej.
- **Brak ekosystemu.** Każda integracja (Allegro, BaseLinker, ERP, Ceneo) to nasz koszt.
- **Utrzymanie zgodności prawnej to stały koszt.** Przykłady: UC169, zmiany od 27.09.2027, KSeF.
- **Operacje.** Stos wielu usług w Kubernetes wymaga kompetencji, których średni sprzedawca nie ma.
- **Zero referencji.** Żaden sklep nie przyjął jeszcze prawdziwego zamówienia.

**Uczciwa ocena:** dziś to głównie wartość demonstracyjna i dowód kompetencji. Potencjał produktowy jest realny jako akcelerator wdrożeń w jednej niszy, pod warunkiem uruchomienia jednego prawdziwego sklepu.

---

## 12. Priorytetowa roadmapa

**Etap 1: do pierwszej sprzedaży.**

Etap kończy się prawdziwą sprzedażą z prawdziwą płatnością, Paczkomatem, potwierdzeniem zgodnym z UPK i fakturą wystawioną w zewnętrznym systemie. Zakres:

- ASYNC-RELIABILITY i ABUSE-LIMITS (gotowe plany),
- epiki 1–5 w wersji minimalnej, gdzie w epiku 5 wystarczą NIP, eksport i ręczne faktury, a zwroty częściowe mogą przejść do etapu 2,
- wyjście z trybu demo (#296),
- zadania właściciela z rozdziału 10.

Równolegle drobne poprawki wykryte w przeglądzie, np. w ramach istniejącego SMALL-FIXES:

- normalizacja wartości kolorów w fasetach,
- zapytanie „buty do biegania” (dopasowanie nie wszystkich słów albo słowa pomijane),
- domyślne sortowanie inne niż alfabetyczne.

**Etap 2: do wygodnej codziennej pracy.**

- ADMIN-TOOLS (#586): akcje masowe, eksport, notatki, zwroty płatności, Ctrl+K, dziennik zmian,
- ustawienia sklepu i zarządzanie pracownikami w panelu,
- profil klienta i zapisane adresy (#117), zamówienia gościa widoczne po założeniu konta (#118),
- zwroty częściowe z automatycznym przywróceniem stanu i fakturą korygującą,
- automatyczne faktury przez API,
- edycja adresu w zamówieniu i anulowanie zamówienia `new`,
- e-mail „nowe zamówienie”,
- raporty z zakresem dat, netto/VAT i odejmowaniem zwrotów,
- rejestr reklamacji z terminem 14 dni,
- etykiety ShipX z panelu,
- szablony e-maili HTML z danymi sprzedawcy,
- edycja cen i stanów z formularza produktu, generator kombinacji wariantów.

**Etap 3: wzrost.**

- GA4 z banerem zgód,
- feed Google Merchant i Ceneo (wymaga marki i GTIN z epiku 4),
- e-maile porzuconych koszyków,
- opinie wyłącznie od klientów z opłaconym zamówieniem, z informacją o weryfikacji,
- podpowiedzi w wyszukiwarce i synonimy,
- promocje: kwotowa, na kategorię, rabat koszykowy bez kodu, wykluczenia i limit na klienta,
- edycja strony głównej z panelu (proste bloki, nie page builder),
- integracja z BaseLinkerem jako bramą do Allegro i kurierów zamiast budowy każdej integracji osobno,
- broker kurierski,
- BNPL w pakiecie operatora,
- ewentualnie pobranie po decyzji w sprawie kasy fiskalnej.

Ta roadmapa świadomie nie dąży do parytetu z Shopify ani Magento. Każdy etap kończy się mierzalnym stanem prawdziwego sklepu, a nie liczbą funkcji.

---

## 13. Propozycje konkretnych epików

Rozmiar względny porównuje epik do dotychczasowych epików projektu. **L** to skala CHECKOUT, PAYMENT czy ORDER-MANAGEMENT: nowe pojęcie w modelu, zmiany w API, sklepie, panelu i E2E. **M** to skala DISCOUNT-CODES lub mniejsza. To ocena autora raportu, nie pomiar.

### 1. DOSTAWA (DELIVERY)

**Zakres:**

- metody dostawy z ceną stałą, edytowalne w panelu,
- próg darmowej dostawy,
- dostawa jako pozycja w `OrderTotals` i zamrożona kopia w zamówieniu,
- koszt w koszyku, kasie, potwierdzeniu i e-mailu,
- Paczkomat przez Geowidget v5 z ID punktu zapisanym w zamówieniu,
- kurier,
- prosta flaga „gabaryt, nie do Paczkomatu” na produkcie,
- pole przewoźnik + numer przesyłki przy „Oznacz jako wysłane”,
- e-mail o wysyłce z linkiem śledzenia,
- metoda dostawy na liście i w szczegółach zamówienia.

**Uzasadnienie:** cały cykl potrzebuje dostawy w modelu zamówienia. Informacje przedumowne pokazują jej koszt (art. 12 ust. 1 pkt 5 UPK), faktura ma pozycję dostawy, a zwrot przy odstąpieniu obejmuje koszt najtańszej dostawy (art. 33 UPK). Paczkomat to oczekiwanie większości klientów.

**Zależności:** brak, epik startowy. Tylko etykiety z ShipX wymagają konta InPost.

**Nakład:** L.

**Powiązane:** #120, decyzja P3 w `docs/features/checkout/spec.md:31`.

**Poza zakresem:** generowanie etykiet przez API (etap 2), broker i inni przewoźnicy, pobranie, wysyłka zagraniczna, wysyłka częściowa, cenniki wagowe.

### 2. OPERATOR PŁATNOŚCI (PAYMENT-OPERATOR)

**Zakres:**

- adapter jednego operatora: BLIK, szybkie przelewy, karty,
- rozszerzenie portu `PaymentProvider` o zwrot pełny i częściowy oraz o uzgadnianie stanu transakcji (np. weryfikacja po powiadomieniu),
- statusy `refunded` i `partially_refunded`,
- zlecanie zwrotu z panelu zamiast ręcznego przelewu i e-mail „zwrot wykonany”,
- widok płatności w zamówieniu: dostawca, status, próby, kwota,
- lista płatności „do ręcznego zwrotu” (#221),
- wyprowadzenie wywołań dostawcy poza transakcję bazy z blokadą,
- sekrety w compose i chartcie,
- sandbox operatora w E2E zamiast symulatora tam, gdzie to możliwe.

**Uzasadnienie:** bez tego nie ma pieniędzy. Ustawa wymaga zwrotu tym samym sposobem płatności w 14 dni (art. 32 UPK).

**Zależności:** ASYNC-RELIABILITY (płatność `pending` na zawsze, W5/W6). Do e-maila zwrotu przy odstąpieniu potrzebny jest też epik 1, bo zwraca się koszt dostawy. Po stronie właściciela potrzebna jest umowa z operatorem i jego weryfikacja.

**Nakład:** L.

**Powiązane:** #221, PAYMENT-OPERATOR wspomniany w `docs/features/abuse-limits/spec.md:48`, `docs/features/payment/spec.md`.

**Poza zakresem:** drugi operator, BNPL i raty poza pakietem operatora, płatności cykliczne, obsługa chargebacków poza zapisem statusu, przelew tradycyjny i pobranie.

### 3. ZGODNOŚĆ I TREŚCI (LEGAL-CONTENT)

**Zakres:**

- proste strony treści edytowane w panelu: regulamin, polityka prywatności z informacją o cookies, dostawa i płatności, zwroty i reklamacje, kontakt,
- dane sprzedawcy w ustawieniach, stopce i kasie,
- akceptacja regulaminu w kasie i wersja regulaminu w zamówieniu,
- pełne potwierdzenie zamówienia z danymi sprzedawcy, kosztem dostawy, pouczeniem o odstąpieniu i wzorem formularza (treść lub PDF),
- formularz odstąpienia online dla zalogowanego klienta i dla gościa po numerze i e-mailu, z drugim krokiem potwierdzenia i e-mailem z datą i godziną, przygotowany pod etykietę „odstąp od umowy tutaj”,
- rejestr zgłoszeń odstąpienia w panelu z terminem 14 dni na zwrot,
- anonimizacja danych zamówień (#119),
- link do statusu zamówienia w e-mailu (także dla gościa; brak zgłoszenia),
- wyjście z trybu demo (#296),
- przegląd prezentacji Omnibus przy kodach rabatowych.

**Uzasadnienie:** art. 12, 17, 21, 24, 27–34 UPK obowiązują już dziś. Funkcja odstąpienia online jest prawnie dobrowolna do czasu polskiej ustawy (UC169), ale tania przy tym samym epiku i zgodna z art. 30 ust. 3–4.

**Zależności:** epik 1 (koszt dostawy w potwierdzeniu). Treść regulaminu przygotowuje prawnik właściciela. Może biec równolegle z epikiem 4.

**Nakład:** M.

**Powiązane:** #119, #296; pokrewne #118.

**Poza zakresem:** generator regulaminu, page builder, blog, wielojęzyczne treści, baner cookies (dopóki nie ma analityki), pełny moduł reklamacji (etap 2).

### 4. DANE PRODUKTU I IMPORT (PRODUCT-DATA)

**Zakres:**

- opis długi w bezpiecznym formacie,
- parametry produktu ze słownikiem wartości (porządkuje też fasety kolorów) i wyświetlaniem w tabeli na karcie,
- SKU i EAN na wariancie z wyszukiwaniem po kodzie w panelu,
- marka i producent z danymi GPSR (nazwa, znak, adres, e-mail, osoba odpowiedzialna w UE) oraz ostrzeżenia i instrukcje,
- waga i flaga gabarytu,
- flaga „wyłączone z prawa odstąpienia” (art. 38 UPK),
- JSON-LD z `brand`, `sku`, `gtin`,
- import i eksport CSV produktów, cen i stanów z podglądem błędów i idempotentnym wczytaniem,
- duplikowanie produktu.

**Uzasadnienie:**

- GPSR (art. 19) i „główne cechy świadczenia” (art. 12 ust. 1 pkt 1 UPK) są obowiązkowe,
- bez importu sprzedawca z istniejącym asortymentem w ogóle nie zacznie,
- kody są warunkiem feedów i integracji (etap 3).

**Zależności:** brak, może biec równolegle z epikiem 3. Waga przyda się epikowi 1, gdy pojawią się cenniki wagowe.

**Nakład:** L.

**Powiązane:** wykluczenia w `docs/features/catalog-admin/spec.md:30-41`.

**Poza zakresem:** PIM, zdjęcia per wariant, generator kombinacji (etap 2), wideo, importy XML z hurtowni, synchronizacja z ERP, pola SEO edytowalne (etap 2).

### 5. FAKTURY I ROZLICZENIA (INVOICING)

**Zakres:**

- w kasie opcja „chcę fakturę” z firmą, NIP-em (walidacja sumy kontrolnej) i adresem rozliczeniowym,
- dane do faktury widoczne w panelu i w eksporcie,
- eksport zamówień CSV (minimalny zakres z #586),
- wystawianie faktury przez API jednego systemu (wFirma albo Fakturownia): automatycznie dla NIP-u, na żądanie dla konsumenta, z numerem, statusem KSeF i PDF w zamówieniu i na koncie,
- zwroty częściowe per pozycja z automatycznym przywróceniem stanu, zwrotem przez operatora i fakturą korygującą,
- raport sprzedaży netto/VAT według stawek za okres.

**Uzasadnienie:** faktura na żądanie konsumenta (art. 106b ust. 3 ustawy o VAT) i faktura w KSeF dla firm. Zwroty częściowe to codzienność w branży sportowej.

**Zależności:**

- epik 1: pozycja dostawy na fakturze,
- epik 2: zwrot częściowy przez operatora,
- epik 4: SKU na fakturze, opcjonalnie,
- konto KSeF sprzedawcy i system fakturowy wybrany z księgową.

**Nakład:** M (bez zwrotów częściowych), L z nimi.

**Powiązane:** #586 (eksport), spec order-management P1 („bez zwrotów częściowych”).

**Poza zakresem:** własny moduł KSeF, paragony i kasa fiskalna, JPK, integracja z ERP, korekty kwotowe niezwiązane ze zwrotem.

### Kolejne epiki (etap 2 i 3, bez numeracji)

- **ADMIN-TOOLS (#586):** notatki, akcje masowe, wydruki, Ctrl+K, dziennik zmian.
- **USTAWIENIA SKLEPU I PRACOWNICY:** stawki VAT, termin płatności, próg niskiego stanu i nazwa sklepu w panelu; zakładanie i blokowanie kont pracowników przez Admin API Keycloaka bez konsoli.
- **KONTO KLIENTA:** profil, zapisane adresy (#117), motyw Keycloaka z akceptacją regulaminu, eksport danych.
- **MARKETING-PODSTAWY:** GA4 z banerem zgód, feed Google Merchant i Ceneo, porzucone koszyki, e-mail „nowe zamówienie”.
- **PROMOCJE-2:** promocja kwotowa i na kategorię, rabat koszykowy, wykluczenie z promocji, limit na klienta.

---

## 14. Czego nie budować

| Nie budować | Uzasadnienie |
|---|---|
| Wielu walut, języków, OSS | Sklep sprzedaje do Polski. OSS dotyczy sprzedaży wysyłkowej do konsumentów w innych krajach UE powyżej 10 000 EUR. Wizja projektu wyłącza to wprost (`docs/vision.md:385`). |
| Marketplace i multi-vendor | Nie ma pierwszego sprzedawcy, a co dopiero wielu. To osobny produkt (Sylius Marketplace Suite, Mercur dla Medusy). |
| Pakietu B2B (konta firmowe, RFQ, cenniki, limity) | U konkurencji to funkcje płatnych edycji (Shopware Evolve+, Adobe Commerce, Sylius Plus), czyli drogie w budowie. Polski B2C potrzebuje tylko NIP-u i faktury (epik 5). |
| Systemu wtyczek i sklepu z aplikacjami | Ekosystem buduje się latami. Zamiast tego, na etapie 3, wystarczą klucze API i webhooki wychodzące oparte na istniejącym outboxie. |
| Programu lojalnościowego, kart podarunkowych | Nie podnoszą konwersji pierwszego sklepu, a mają skutki księgowe. Shoper i IdoSell mają je natywnie i nie warto z nimi tu rywalizować. |
| Wielu magazynów (#684) | Jeden magazyn wystarcza sklepowi na starcie. Shoper daje tę funkcję dopiero w Premium, Shopware dopiero w Beyond. |
| Dalszych prac pod skalę 10 mln produktów | Architektura jest przygotowana, ale nie ma realnego katalogu ani importu. Skala zostanie potwierdzona testami obciążeniowymi, nie kolejną optymalizacją. |
| Własnego page buildera i rozbudowanego CMS-u | Wystarczą proste strony treści (epik 3) i edycja kilku bloków strony głównej. Page buildery Shopware i Magento to lata rozwoju. |
| Własnego silnika rekomendacji | Bez danych o ruchu nie ma czego rekomendować. Na start wystarczą ręczne „produkty powiązane” albo usługa zewnętrzna. |
| POS, subskrypcji | Sklep internetowy ze sprzętem sportowym ich nie potrzebuje. |
| Osobnych integracji z każdym kurierem | Wystarczy jedna integracja InPost (Paczkomat), potem broker (Furgonetka, Apaczka) albo BaseLinker zamiast kolejnych API przewoźników. |
| Własnego modułu KSeF | Certyfikaty, FA(3), kolejki i UPO są w gotowych systemach fakturowych z API (wFirma, Fakturownia). Własny moduł byłby dużym kosztem bez przewagi. |
| Własnego narzędzia e-mail marketingu | Wystarczą zgoda i synchronizacja z zewnętrznym narzędziem. |
| Pobrania na start | Wymaga zmiany modelu zamówienia (wysyłka nieopłaconego) i grozi obowiązkiem kasy fiskalnej. Udział pobrania spada. |
| Dalszego rozwoju symulatora płatności | Po wejściu operatora jego rolę przejmuje sandbox operatora. |

---

## 15. Potencjalne rzeczywiste przewagi konkurencyjne

1. **Polska zgodność wbudowana w domenę.** Konkurencja dokłada ją wtyczkami. Omnibus liczony z historii cen i promocji już działa. Funkcja odstąpienia online, dane GPSR jako wymagane pola publikacji i potwierdzenie zamówienia zgodne z art. 21 UPK mogą działać po epikach 3 i 4. W headless open source (Medusa, Saleor) i w Shopify, WooCommerce czy Shopware CE wszystko to jest wtyczką, aplikacją albo własnym kodem. W Shoperze i IdoSell jest natywne, więc to przewaga wyłącznie w segmencie wdrożeń dedykowanych.
2. **Poprawność transakcyjna udowodniona testami.** Wieloprocesowe testy współbieżności rezerwacji i kodów rabatowych, idempotencja złożenia i płatności, zamrożone migawki zamówienia, outbox. To argument dla sprzedawcy z wysokim ruchem w szczytach. Notatki nie zawierają danych porównawczych o jakości tego zachowania u konkurencji, więc przewagę trzeba pokazać na dowodach, np. raporcie z testów, a nie deklarować.
3. **Brak opłat od zamówienia i od GMV.** IdoSell od 1.08.2026 pobiera opłatę za zamówienie (wg Shopera), Saleor Cloud i Shopware wyceniają według GMV, Adobe według GMV. Własny rdzeń daje przewidywalny koszt przy wysokim wolumenie, ale kosztem hostingu i zespołu.
4. **Bezpieczeństwo panelu „z pudełka”:** osobny realm, obowiązkowe TOTP i role sprawdzane w API. RBAC w Sylius i Medusie jest płatny. Dla sprzedawców po incydencie albo z wymaganiami audytu to argument sprzedażowy.
5. **Polska wyszukiwarka na Elasticsearch z fasetami i działaniem bez JS.** Lepsza niż domyślne wyszukiwanie WooCommerce, które według notatek nie toleruje literówek (niezweryfikowane). Zaawansowane wyszukiwanie w Shopware i Sylius jest płatne.
6. **Kontrakt OpenAPI generowany z kodu i testowany.** Po dodaniu kluczy integracyjnych, publicznej dokumentacji i webhooków (etap 3) może stać się czystym API do integracji z ERP. Dziś to tylko potencjał.

Żadna z tych przewag nie zastępuje brakujących podstaw. Ich wartość pojawia się dopiero wtedy, gdy sklep w ogóle może sprzedawać.

---

## 16. Ograniczenia audytu i pewność wniosków

- **Nasza aplikacja.** Audyt kodu był statycznym odczytem rewizji `1aeb5076`, z dowodami plik:linia. Przegląd w przeglądarce wykonano w jednej sesji na lokalnym stosie dev z danymi seed. Publicznego demo worf.pl nie klikano. Panelu na 375 px, pełnego przejścia klawiaturą i czytnikiem ekranu ani czasu propagacji zmian ceny do sklepu w tej sesji nie sprawdzano. Wnioski o brakach potwierdzono wyszukiwaniem w kodzie. **Pewność: wysoka.**
- **Wydajność.** Nie przeprowadzono testów obciążeniowych. Raport nie twierdzi, że system spełnia wymagania wydajnościowe z `docs/vision.md`.
- **Konkurencja.** Badania miały budżet kilkunastu wywołań na platformę. Duża część komórek macierzy jest oznaczona gwiazdką albo „?”. Strony Adobe i Shopware często zwracały 404, a dokumentacja Sylius wymagała logowania. Część danych o Shoperze i IdoSell pochodzi z artykułów konkurenta. Ceny pochodzą ze stron bez daty, pobranych w listopadzie 2026, a promocje są zmienne. Szacunki kosztów Magento i Adobe to szacunki agencji. W dokumentacji Medusy researcher zauważył wstrzyknięte instrukcje dla agentów AI i je zignorował. Vendure i PrestaShop opisano skrótowo, commercetools nie zbadano. **Pewność: średnia dla porównań funkcji, średnio-wysoka dla cenników z oficjalnych stron.**
- **Prawo.** Teksty UPK, UPNPR, PKE, ustawy o dostępności i ustawy o informowaniu o cenach odczytano z ISAP i API Sejmu. Nie odczytano tekstów art. 19 GPSR, art. 11a dyrektywy ani RODO z EUR-Lex. Status UC169 pochodzi ze źródeł wtórnych. Wykładnia, czy link do strony jest trwałym nośnikiem, jest niezweryfikowana. Daty KSeF dla najmniejszych podatników podają tylko źródła wtórne. **Raport nie jest poradą prawną ani podatkową.** Klasyfikacja „NIEZBĘDNE z mocy prawa” jest wnioskiem z odczytanych przepisów i wymaga potwierdzenia przez prawnika i doradcę podatkowego przed uruchomieniem sklepu.
- **Rynek.** Udziały metod płatności i dostawy pochodzą ze streszczeń raportu Gemius publikowanych przez operatora płatności. Udziały platform pochodzą z trackerów o sprzecznych metodologiach. Nie przeprowadzono wywiadów ze sprzedawcami.
- **Rozmiary epików** to ocena względna autora, nie estymacja zespołu.

---

## 17. Źródła i bezpośrednie linki

### Nasza aplikacja (ścieżki w repozytorium, rewizja `1aeb5076`)

Notatki badawcze: `docs/reports/ecommerce-functional-audit-2026-11-18/` – `01-przeglad-sciezek-w-przegladarce.md`, `02-kod-katalog-wyszukiwanie-seo.md`, `03-kod-ceny-promocje-magazyn.md`, `04-kod-checkout-zamowienia-platnosci.md`, `05-kod-klienci-panel-raporty-integracje.md`.

Kod i konfiguracja:
- `apps/api/src/Catalog/Domain/Product/Product.php`, `ShortDescription.php`, `VariantAxes.php`, `ProductVariant.php`
- `apps/api/src/Pricing/Domain/Tax/PolishVatRates.php`, `Price/PriceBreakdown.php`, `Basket/BasketQuote.php`, `Promotion/LowestPriorPrice.php`, `DiscountCode/DiscountCode.php`
- `apps/api/src/Inventory/Domain/Stock/StockItem.php`, `AvailabilityLevel.php`; `apps/api/tests/Integration/Inventory/Concurrency/ReservationConcurrencyTest.php`
- `apps/api/src/Ordering/Domain/Order.php`, `OrderStatus.php`, `OrderTotals.php`, `ShippingAddress.php`, `Refund.php`; `Application/Command/PlaceOrderHandler.php`, `ChangeOrderStatusHandler.php`, `ExpireUnpaidOrderHandler.php`; `Application/OrderConfirmationText.php`, `OrderShippedText.php`
- `apps/api/src/Payment/Domain/ProviderCode.php`, `PaymentStatus.php`; `Application/Port/PaymentProvider.php`; `Application/Command/HandleProviderWebhookHandler.php`, `StartPaymentHandler.php`; `apps/api/src/PaymentSimulator/`
- wyszukiwanie: `Infrastructure/Search/ProductIndexDefinition.php`, `ProductSearchQuery.php`, `ListProductsQuery.php` (ścieżki skrócone jak w notatce katalogu); `apps/api/config/packages/messenger.yaml`, `rate_limiter.yaml`
- `apps/api/openapi.json`, `apps/api/openapi-admin.json`
- `apps/store/src/checkout/CheckoutPage.tsx`, `apps/store/src/catalog/SiteFooter.tsx`, `PromotionNotice.tsx`, `apps/store/src/home/home-content.ts`, `apps/store/app/**`
- `apps/admin/src/views/OrderDetailView.vue`, `OrdersView.vue`, `ProductFormView.vue`, `CustomerDetailView.vue`, `apps/admin/src/router/index.ts`
- `infra/k8s/environments/prod/values.yaml`, `infra/keycloak/realms/shop.json`, `backoffice.json`, `infra/k8s/Makefile`
- `docs/vision.md`, `docs/features/catalog-admin/spec.md`, `checkout/spec.md`, `order-management/spec.md`, `payment/spec.md`, `promotions/spec.md`, `discount-codes/spec.md`, `inventory/spec.md`, `admin-features/spec.md`, `abuse-limits/spec.md`, `async-reliability/spec.md`
- `e2e/tests/` (m.in. `checkout-guest`, `payment-resume`, `order-management`, `seo.spec.ts`, `catalog-admin.spec.ts`)

Zgłoszenia: #117, #118, #119, #120, #179, #181, #221, #296, #586, #684.

### Konkurencja

**Shopify**
- [shopify.com/pricing](https://www.shopify.com/pricing)
- [help.shopify.com – zwroty](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns)
- [help.shopify.com – import CSV](https://help.shopify.com/en/manual/products/import-export/import-products)
- [help.shopify.com – rabaty](https://help.shopify.com/en/manual/discounts)
- [help.shopify.com – Flow](https://help.shopify.com/en/manual/shopify-flow)
- [help.shopify.com – EU VAT](https://help.shopify.com/en/manual/taxes/eu-vat)
- [help.shopify.com – Markets](https://help.shopify.com/en/manual/markets)
- [help.shopify.com – B2B](https://help.shopify.com/en/manual/b2b)
- [help.shopify.com – konta personelu](https://help.shopify.com/en/manual/your-account/staff-accounts)
- [help.shopify.com – raporty](https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports)
- [help.shopify.com – nowe konta klientów](https://help.shopify.com/en/manual/customers/customer-accounts/new-customer-accounts)
- [changelog – 2048 wariantów](https://changelog.shopify.com/posts/we-ve-increased-the-product-variant-limit-to-2048)
- [changelog – lokalne metody płatności](https://changelog.shopify.com/posts/more-local-payment-methods-are-now-available-in-additional-countries)
- [OmniProof](https://apps.shopify.com/omniproof), [Omnibus Price Compliance](https://apps.shopify.com/omnibus-price-compliance?locale=pl)
- [InPost Paczkomaty Orlen Paczka](https://apps.shopify.com/inpost-paczkomaty-1), [InPost Pro](https://apps.shopify.com/inpost-pro?locale=pl)
- [shopify.dev – checkout](https://shopify.dev/docs/apps/build/checkout)
- [attomy – BLIK](https://www.attomy.io/en/post/blik-shopify-a-comprehensive-guide-for-polish-online-shops), [Sufio – KSeF](https://sufio.com/articles/shopify/e-invoicing/poland-ksef-einvoices/)

**WooCommerce**
- [woocommerce.com/pricing](https://woocommerce.com/pricing/), [woocommerce.com/products](https://woocommerce.com/products/)
- [managing products](https://woocommerce.com/document/managing-products/), [managing orders](https://woocommerce.com/document/managing-orders/), [refunds](https://woocommerce.com/document/woocommerce-refunds/)
- [coupon management](https://woocommerce.com/document/coupon-management/), [taxes](https://woocommerce.com/document/setting-up-taxes-in-woocommerce/), [analytics](https://woocommerce.com/document/woocommerce-analytics/)
- [WooCommerce 10.9](https://developer.woocommerce.com/2026/06/23/woocommerce-10-9/)
- [Przelewy24 – wtyczka WooCommerce](https://www.przelewy24.pl/wtyczki/woocommerce)
- [WC Price History](https://pl.wordpress.org/plugins/wc-price-history/), [Omnibus](https://wordpress.org/plugins/omnibus/)

**Shopware**
- [Pricing](https://www.shopware.com/en/pricing/), [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial), [Evolve](https://docs.shopware.com/en/shopware-6-en/features/shopware-evolve)
- [Import/Export](https://docs.shopware.com/en/shopware-en/settings/importexport), [Documents](https://docs.shopware.com/en/shopware-6-en/settings/documents), [Email templates](https://docs.shopware.com/en/shopware-6-en/settings/email-templates)
- [Dynamic product groups](https://docs.shopware.com/en/shopware-6-de/Catalogues/Dynamicproductgroups), [Returns management](https://docs.shopware.com/en/shopware-6-en/orders/returns-management), [Advanced Search 2.0](https://docs.shopware.com/en/shopware-6-en/settings/advanced-search-2-0)
- [Release news July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/), [Release news April 2022](https://www.shopware.com/en/news/shopware-6-release-news-april-2022/)
- [Przelewy24 gateway](https://store.shopware.com/en/kodef94657448840f/przelewy24-gateway.html), [Makolab Omnibus](https://store.shopware.com/en/makol73529386666m/omnibus-lowest-price.html), [BitBag InPost](https://root.packagist.org/packages/bitbag/shopware-inpost-plugin)

**Magento / Adobe Commerce**
- [Adobe – platformy](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services), [Inventory](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/introduction), [Returns](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/returns/returns)
- [Page Builder](https://experienceleague.adobe.com/en/docs/commerce-admin/page-builder/introduction), [2.4.3 release notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/magento-open-source/2-4-3), [GraphQL](https://developer.adobe.com/commerce/webapi/graphql/)
- [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/), [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/), [MageComp 2.4.8](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)
- [Przelewy24 Magento](https://commercemarketplace.adobe.com/przelewy24-magento2-przelewy24.html), [InPost Pay Magento](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/359956481/ENG+InPost+Pay+-+Magento)
- [G2 Adobe vs Shopware](https://www.g2.com/compare/adobe-commerce-formerly-magento-commerce-vs-shopware)

**Sylius, Medusa, Saleor, Vendure**
- Sylius: [sylius.com/plus](https://sylius.com/plus/), [GitHub Sylius](https://github.com/Sylius/Sylius), [price-history-plugin](https://packagist.org/packages/sylius/price-history-plugin), [InvoicingPlugin](https://github.com/Sylius/InvoicingPlugin), [RefundPlugin](https://github.com/Sylius/RefundPlugin), [BitBag P24](https://addons.sylius.com/en_US/products/bitbag-przelewy24-plugin)
- Medusa: [medusajs.com/pricing](https://medusajs.com/pricing), [moduły](https://docs.medusajs.com/resources/commerce-modules), [order](https://docs.medusajs.com/resources/commerce-modules/order), [inventory](https://docs.medusajs.com/resources/commerce-modules/inventory), [RBAC](https://docs.medusajs.com/resources/enterprise/rbac), [integracje](https://docs.medusajs.com/resources/integrations)
- Saleor: [saleor.io/pricing](https://saleor.io/pricing), [GitHub saleor](https://github.com/saleor/saleor), [order](https://docs.saleor.io/developer/order/overview), [gift cards](https://docs.saleor.io/developer/gift-cards), [permissions](https://docs.saleor.io/developer/permissions)
- Vendure: [license change](https://vendure.io/blog/license-change-announcement), [docs.vendure.io](https://docs.vendure.io)

**Shoper, IdoSell, PrestaShop, BaseLinker, rynek PL**
- Shoper: [shoper.pl/cennik](https://www.shoper.pl/cennik/), [pełny cennik](https://www.shoper.pl/pelny-cennik-uslug-shoper), [integracja InPost](https://www.shoper.pl/help/artykul/integracja-shoper-inpost/), [statusy zamówień](https://www.shoper.pl/help/artykul/statusy-zamowien), [porzucone koszyki](https://www.shoper.pl/help/artykul/porzucone-koszyki/), [Shoper Zwroty](https://www.shoper.pl/shoper-zwroty), [Faktury KSeF od Furgonetka](https://www.shoper.pl/aplikacje/faktury-ksef-od-furgonetka-pl), [artykuł o IdoSell](https://www.shoper.pl/learn/?p=66989), [wyniki Q4 2025](https://investors.shoper.pl/en/press-office/wyniki-finansowe-q4-2025---komunikat)
- IdoSell: [funkcje](https://www.idosell.com/pl/funkcje/), [cennik](https://www.idosell.com/pl/cennik/), [szczegółowy cennik](https://www.idosell.com/pl/szczegolowy-cennik-idosell/), [RMA](https://idosell.com/en/shop/functions/complaints), [logistyka](https://www.idosell.com/pl/logistyka), [WMS](https://www.idosell.com/pl/system-zarzadzania-magazynem/), [API](https://idosell.readme.io/docs/getting-started)
- PrestaShop i cyber_Folks: [PrestaShop 9.1](https://build.prestashop-project.org/news/2026/prestashop-9-1-0-available/), [cyber_Folks IR](https://investors.cyberfolks.pl/en/?p=3794)
- BaseLinker: [base.com/pl-PL/cennik](https://base.com/pl-PL/cennik/)
- Trackery platform: [AfterShip](https://www.aftership.com/ecommerce/statistics/regions/pl), [wmtips](https://www.wmtips.com/technologies/e-commerce/country/pl/)

### Prawo i rynek

**Prawo konsumenckie i Omnibus**
- [UPK, tekst jedn. Dz.U. 2026 poz. 1244 (ISAP)](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf), [metadane ELI](https://api.sejm.gov.pl/eli/acts/DU/2014/827)
- [Dz.U. 2026 poz. 1353 (od 27.09.2027)](https://api.sejm.gov.pl/eli/acts/DU/2026/1353/text/O/D20261353.pdf)
- [UPNPR, Dz.U. 2023 poz. 845](https://api.sejm.gov.pl/eli/acts/DU/2023/845/text/T/D20230845L.pdf)
- [Ustawa o informowaniu o cenach, Dz.U. 2023 poz. 168](https://api.sejm.gov.pl/eli/acts/DU/2023/168/text/T/D20230168L.pdf)
- [Wyjaśnienia UOKiK o obniżkach](https://uokik.gov.pl/storage/archiwum/e6e552a1.pdf), [UOKiK – zasady](https://prawakonsumenta.uokik.gov.pl/prawo-do-informacji/informacje-o-obnizkach-cen/)

**Funkcja odstąpienia online (dyr. 2023/2673)**
- [UOKiK BIP – implementacja dyrektywy 2023/2673](https://uokik.gov.pl/bip/implementacja-dyrektywy-parlamentu-europejskiego-i-rady-ue-2023-2673)
- [PARP – one click return](https://www.parp.gov.pl/component/content/article/91021:one-click-return-czyli-zwroty-w-e-commerce-po-nowemu)
- [Bezprawnik – UC169](https://bezprawnik.pl/nowy-obowiazek-dla-sklepow-internetowych/), [Bezprawnik – zwrot jednym kliknięciem](https://bezprawnik.pl/zwrot-jednym-kliknieciem/)
- [Akademia LTCA – UC169](https://akademialtca.pl/blog/odstap-od-umowy-tutaj-czyli-co-nowego-szykuje-ustawodawca-dla-sklepow-internetowych), [Akademia LTCA – zwrot jednym kliknięciem](https://akademialtca.pl/blog/od-dzis-obowiazkowy-zwrot-jednym-kliknieciem-co-grozi-sklepom-ktore-nie-sa-gotowe)
- [Infor – zwroty online](https://www.infor.pl/prawo/konsument-i-umowy/7612423,zwroty-online-od-19-czerwca-2026-r-na-nowych-zasadach-co-zmienia-dyrektywa-ue-20232673.html), [CMS Poland](https://cms.law/en/gbr/legal-updates/consumer-sales-in-2026-new-obligations-for-e-commerce-and-brick-and-mortar-shops-in-poland)

**GPSR, cookies, dostępność**
- GPSR: [UOKiK – obowiązki](https://uokik.gov.pl/bezpieczenstwo-produktow-obowiazki-przedsiebiorcow), [prawo.pl – GPSR](https://www.prawo.pl/biznes/rozporzadzenie-gpsr-obowiazki-dla-firm-i-sklepow-internetowych,530380.html), [prawo.pl – ustawa](https://www.prawo.pl/biznes/ustawa-gpsr-zmiany-w-nadzorze-nad-bezpieczenstwem-produktow,536366.html), [PARP – bezpieczeństwo produktów](https://www.parp.gov.pl/component/content/article/90288:bezpieczenstwo-produktow-konsumenckich-rozporzadzenie-gpsr-i-nowa-ustawa-o-nadzorze-nad-ogolnym-bezpieczenstwem-produktow), [PARP webinar](https://redakcja.parp.gov.pl/attachments/article/89363/PARP_GPSR_webinar_3009.pdf)
- [PKE, Dz.U. 2024 poz. 1221](https://api.sejm.gov.pl/eli/acts/DU/2024/1221/text/O/D20241221.pdf)
- [Ustawa o dostępności, Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf), [gov.pl – dostępność e-commerce](https://www.gov.pl/web/dostepnosc-cyfrowa/polski-akt-o-dostepnosci--uslugi-handlu-elektronicznego)

**Podatki i faktury**
- [KSeF – terminy](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/), [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/)
- [jpk.info.pl – harmonogram KSeF](https://jpk.info.pl/faktury/e-faktura-ustrukturyzowana-ksef/harmonogram/), [jpk.info.pl – faktura na osobę fizyczną](https://jpk.info.pl/faktury/faktura-na-osobe-fizyczna/)
- [Rozporządzenie MF, Dz.U. 2024 poz. 1902 (ELI)](https://eli.gov.pl/eli/DU/2024/1902/ogl), [jpk.info.pl – sprzedaż wysyłkowa bez kasy](https://jpk.info.pl/aktualnosci/2025/sprzedaz-wysylkowa-bez-kasy-fiskalnej/)
- [podatki.biz – faktura do paragonu](https://www.podatki.biz/artykuly/jak-dlugo-konsumenci-moga-zadac-wystawienia-faktury-do-paragonu_34_57765.htm)
- [Komisja Europejska – OSS](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en)
- [Fakturownia API](https://github.com/fakturownia/api), [wFirma API](https://pomoc.wfirma.pl/-api-interfejs-dla-programistow)

**Płatności**
- [Przelewy24 – oferta](https://www.przelewy24.pl/oferta), [PayU SMB](https://poland.payu.com/smb/), [PayU – zwroty](https://developers.payu.com/europe/docs/payment-flows/refunds/), [PayU first steps](https://poland.payu.com/wp-content/uploads/sites/14/2026/07/PayU_first_steps_pl.pdf)
- [Tpay – oferta](https://tpay.com/oferta), [Tpay – webhooki](https://docs-api.tpay.com/en/webhooks/), [imoje](https://www.ing.pl/bramka-platnicza-imoje)
- [Stripe – cennik](https://stripe.com/pl/pricing), [Stripe – BLIK](https://docs.stripe.com/payments/blik)

**Dostawa**
- [InPost – dostęp do API](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/1187086337), [InPost Integration FAQ](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/451903492/InPost+Integration+FAQ), [Geowidget](https://geowidget.inpost.pl/docs/)
- [Furgonetka](https://furgonetka.pl/en/e-commerce-tools), [Apaczka](https://www.apaczka.pl/integracje/)
- [kb.pl – cennik InPost](https://kb.pl/transport/koszty-transportu/cennik-inpost-zobacz-jakie-sa-ceny-paczkomatow)

**Dane rynkowe**
- [Przelewy24 o Gemius 2025](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers), [antyweb o Gemius 2025](https://antyweb.pl/e-commerce-w-polsce-2025-gemius)
- [bank.pl – BLIK Q3 2026](https://bank.pl/?p=564233)
- [eurobuildcee – pobranie](https://eurobuildcee.com/news/35098-za-zakupy-w-internecie-wolimy-placic-gotowka)
- [Shoper Learn – progi darmowej dostawy](https://www.shoper.pl/learn/?p=56997)
- [PAP – liczba e-sklepów](https://www.pap.pl/mediaroom/analiza-rynku-e-commerce-w-polsce)
