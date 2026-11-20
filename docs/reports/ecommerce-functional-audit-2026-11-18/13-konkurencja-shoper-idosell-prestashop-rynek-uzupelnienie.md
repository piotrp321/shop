# Uzupełnienie: operacyjne funkcje Shoper i IdoSell, PrestaShop, dane rynkowe (stan: listopad 2026)

Metoda: ok. 15 wywołań narzędzi, głównie wyszukiwanie; większość stron pomocy nie została otwarta w całości, więc wiele wierszy to etykieta z wiedzy własnej oznaczona [niezweryfikowane]. Legenda: N = w abonamencie, P = płatny dodatek/wyższy plan, I = integracja zewnętrzna, C = custom. Rozstrzygnięcie bazowe: w App Store Shopera "bezpłatne" aplikacje liczą się jako N/I, a płatne jako P. Strona IdoSell /pl/funkcje nie rozróżnia planów i nie zawiera większości szczegółów (potwierdzone), a pomoc IdoSell (idosell.com/pl/help) nie została odczytana.

## 1. Dostawa (Shoper Standard / IdoSell)

### Takeaway
Shoper ma w panelu mapę Paczkomatów w koszyku, broker Shoper Przesyłki (Apaczka) oraz bezpłatne integracje Furgonetka/Globkurier; IdoSell ma wbudowanego brokera i własne umowy z InPost, DPD, Orlen Paczka. Tabele cen, próg darmowej dostawy i etykiety w liście zamówień są standardem obu, ale nie potwierdziłem ich na stronach pomocy.

### Cited Findings
| Funkcja | Shoper Standard | IdoSell | Źródło |
|---|---|---|---|
| Paczkomaty InPost z mapą w checkout | N: wbudowana integracja InPost, mapa od wersji 5.20.7, wyszukiwanie po kodzie lub z mapy; nowa mapa (Geowidget) wymaga tokenu z Managera Paczkomatów | N: Paczkomat 24/7 przez delivery profiles; widżet mapy [niezweryfikowane] | [Shoper pomoc InPost](https://www.shoper.pl/help/artykul/integracja-shoper-inpost/); [InPost o IdoSell](https://bliskociebie.inpost.pl/en/news-integrating-inpost-idosell-comprehensive-configuration-guide) |
| Kurierzy i etykiety z panelu | N/I: Shoper Przesyłki (broker, bez własnej umowy, opłata tylko za nadane paczki, współpraca z Apaczka), Furgonetka, Globkurier; "Szybka Przesyłka" tworzy przesyłkę z zamówienia i etykietę. Cena brokera nieweryfikowana | N: IdoSell Broker (uproszczona konfiguracja, cenniki, etykiety, tracking, pobranie, wysyłki zagraniczne, InPost Pay) lub własna umowa; broker ma osobny cennik od 31.07.2026 (P) | [Shoper kurierzy](https://www.shoper.pl/kurierzy), [formy dostawy](https://www.shoper.pl/help/sekcja/dostawy/), [Shoper Przesyłki](https://www.shoper.pl/learn/artykul/jak-tworzyc-formy-dostawy-w-usludze-shoper-przesylki); [IdoSell logistyka](https://www.idosell.com/pl/logistyka) |
| Mapa punktów odbioru innych przewoźników | I: aplikacja "Mapa punktów odbioru od Furgonetka.pl" (wymaga darmowego konta Furgonetka) | [niezweryfikowane] | [Shoper App Store](https://www.shoper.pl/aplikacje/mapa-punktow-odbioru-od-furgonetka-pl) |
| Numer przesyłki i powiadomienia do klienta | N (tracking w integracjach) [niezweryfikowane szczegóły] | N: tracking w brokerze | [InPost o IdoSell](https://bliskociebie.inpost.pl/en/news-integrating-inpost-idosell-comprehensive-configuration-guide) |
| Tabele cen dostawy (waga/wartość), próg darmowej dostawy | N [niezweryfikowane] (formy dostawy z konfiguracją cenową; zaawansowana edycja dostawy dopiero w Premium wg cennika) | N [niezweryfikowane] | [Shoper formy dostawy](https://www.shoper.pl/help/sekcja/dostawy/) |
| Etykiety czasu dostawy na karcie produktu | N [niezweryfikowane] | N [niezweryfikowane] | brak źródła |
| InPost Szybkie Zwroty / etykiety zwrotne | I: przez Furgonetkę/Przesyłki [niezweryfikowane] | N: Return Shipment (etykiety UPS/DHL Express dla klienta) w module RMA | [IdoSell RS](https://idosell.com/en/thousands-of-ready-to-use-features-/returns-module-in-online-stores/return-shipment-rs-allowing-ups-and-dhl-express-labels-to-be-shared-with-customers-for-return-and-complaint) |

### Inferences
- InPost z mapą w koszyku jest dla obu platform standardem natywnym, nie dodatkiem; dla Polski to wymóg wejścia. Broker kurierski (stawki bez własnej umowy) jest elementem platformy u obu.

### Gaps
- Brak potwierdzenia na stronach pomocy: tabele cen, próg darmowej dostawy, etykiety czasu dostawy, powiadomienia o przesyłce, konkretna lista kurierów brokera IdoSell, cena Shoper Przesyłki.

## 2. Zamówienia

### Takeaway
Statusy własne i masowa zmiana statusu na liście zamówień są w Shoperze opisane w pomocy; IdoSell ma obsługę zamówień rozbudowaną (WMS), ale konkretów nie potwierdziłem.

### Cited Findings
| Funkcja | Shoper | IdoSell | Źródło |
|---|---|---|---|
| Statusy i własne statusy, automatyczne zmiany | N: Ustawienia > Zarządzanie sprzedażą > Statusy zamówień; statusy mogą zmieniać się automatycznie | N [niezweryfikowane] | [Shoper statusy](https://www.shoper.pl/help/artykul/statusy-zamowien) |
| Masowa zmiana statusu | N: zaznaczenie zamówień na liście i akcja zmiany statusu | N [niezweryfikowane] | [Shoper statusy](https://www.shoper.pl/help/artykul/statusy-zamowien) |
| Edycja zamówienia, zamówienia ręczne, notatki, eksport, masowy wydruk, anulowanie nieopłaconych | N [niezweryfikowane] | N [niezweryfikowane]; WMS (kompletacja, pakowanie) wbudowany | [IdoSell WMS](https://www.idosell.com/pl/system-zarzadzania-magazynem/) |

### Gaps
- Brak potwierdzenia: edycja zamówień, ręczne zamówienia, notatki wewnętrzne, masowe wydruki faktur/etykiet, eksport zamówień, automatyczne anulowanie nieopłaconych w obu systemach.

## 3. Zwroty i reklamacje

### Takeaway
Oba systemy mają dedykowany moduł zwrotów/reklamacji: Shoper jako bezpłatną aplikację "Shoper Zwroty", IdoSell jako wbudowany moduł RMA bez dopłaty.

### Cited Findings
- Shoper: aplikacja Shoper Zwroty (bezpłatna wg opisu) automatyzuje zwroty, reklamacje rozpatruje się w panelu (Shoper Zwroty > Rozpatrywanie reklamacji), lista zgłoszeń w Zamówienia > Zwroty; formularz online dla klienta. Etykieta: N/I (aplikacja bezpłatna z App Store) — [Shoper Zwroty](https://www.shoper.pl/shoper-zwroty), [aplikacja](https://www.shoper.pl/aplikacje/zwroty-i-reklamacje)
- IdoSell: kompletny moduł reklamacji/RMA "out of the box, at no extra cost" (deklaracja IdoSell), kreator zgłoszenia dla klienta, zwrot środków na kartę lub kartę podarunkową, etykiety zwrotne UPS/DHL Express (N) — [IdoSell RMA](https://idosell.com/en/shop/functions/complaints), [blog RMA](https://idosell.com/en/blog/many-new-facilitations-are-waiting-for-you-in-the-complaint-module-rma-for-example-you-will-not-miss-the-customers-answers-to-the-complaint-or-you-can-make-a-cash-back-to-a-card-and-a-gift-voucher-1235326190)
- IdoSell: bramka zwrotów w API admin (zwroty płatności) — [IdoSell blog](https://idosell.com/en/blog/new-refunds-gateway-within-api-admin-easy-management-of-refunds-for-orders-1235328081)

### Gaps
- Zwrot środków przez operatora płatności z panelu Shopera: nie potwierdzono. InPost Szybkie Zwroty w obu: niezweryfikowane.

## 4. Fakturowanie

### Cited Findings
- IdoSell: faktury z zamówień, fakturowanie z KSeF i statusem UPO wbudowane (N wg strony produktu) — [IdoSell bezpieczeństwo/funkcje](https://www.idosell.com/pl/funkcje/)
- Shoper: KSeF przez płatną aplikację Furgonetka (P) oraz moduł "Shoper Faktury i Magazyn" wg bloga zewnętrznego [niezweryfikowane]; integracje Fakturownia/wFirma/iFirma/Subiekt = I (iFirma ma stronę integracji Shoper) — [Faktury KSeF od Furgonetka](https://www.shoper.pl/aplikacje/faktury-ksef-od-furgonetka-pl), [iFirma/Shoper](https://www.ifirma.pl/integracje/shoper/)
- IdoSell: integracja iFirma i inne (I) — [iFirma/IdoSell](https://www.ifirma.pl/ecommerce/integracje/idosell/)
- Drukarka fiskalna / kasa online: nie znaleziono ani dla Shopera, ani IdoSell (B2C wysyłkowe zwykle bez kasy; [niezweryfikowane]).

### Gaps
- Numeracja i faktury korygujące natywne: nie potwierdzono dla żadnej platformy. Szczegóły KSeF Shoper do sprawdzenia w pomocy.

## 5. Płatności

### Takeaway
Operatorzy aktywowani są przez platformę (Shoper Płatności, IdoPay), ale komisje i ewentualne opłaty integracyjne opisano w poprzednich notatkach; tu tylko klasyfikacja.

### Cited Findings
- Shoper: BLIK, przelew, karty przez Shoper Płatności (Przelewy24/Autopay) oraz Stripe, PayU, Tpay, Paynow (N/I z prowizją); PayPo od 1,58%; InPost Pay — [Shoper cennik](https://www.shoper.pl/pelny-cennik-uslug-shoper) (zob. poprzednie notatki)
- IdoSell: IdoPay (licencja KNF) z BLIK, PayPo i innymi metodami (N, z cennikiem od 1.09.2026) — [IdoSell płatności](https://www.idosell.com/pl/integracje/systemy-platnosci/)
- Pobranie i przelew tradycyjny: N [niezweryfikowane] w obu. PayU Płacę później, Twisto: [niezweryfikowane].

## 6. Strona klienta

### Cited Findings
- IdoSell: moduł zestawów, kolekcji i opinii (reklamacja opinii, zestawy produktów = N) — [IdoSell moduł](https://www.idosell.com/pl/modul-zestawow-kolekcji-i-opinii); dokumenty prawne (regulamin, polityka, DSA) opisują tylko regulamin IdoSell, nie generator dla sklepu — [regulamin](https://www.idosell.com/pl/regulamin-idosell/)
- Shoper: newsletter, wiersz zgód i program lojalnościowy w cenniku (N) — [Shoper cennik](https://www.shoper.pl/cennik/)
- Rozpoznane jako [niezweryfikowane] dla obu: zakup bez konta, historia zamówień, książka adresowa, NIP w checkout, status zamówienia dla gościa, lista życzeń, oznaczenie zweryfikowanego kupującego (Omnibus), narzędzie zgód cookies, generatory regulaminu/polityki/formularza odstąpienia, funkcja odstąpienia online (obowiązek 2026).

### Gaps
- Cała ta sekcja poza dwiema pozycjami wymaga odczytu pomocy; nie znaleziono dla żadnej platformy dokumentacji "przycisku odstąpienia online".

## 7. Marketing i kanały sprzedaży

### Cited Findings
- Shoper porzucone koszyki: wbudowana automatyczna wiadomość e-mail (Wygląd i treści > Szablony e-maili automatycznych > Wiadomości automatyczne; koszyk przechowywany 7 dni, w Storefront 30 dni) = N; aplikacje GrowMail (10 zł/mies. lub 100 zł/rok po 14 dniach), Witly, PushPushGo, iPresso (e-mail, SMS, web push) = P/I — [Shoper porzucone koszyki](https://www.shoper.pl/help/artykul/porzucone-koszyki/), [GrowMail](https://www.shoper.pl/aplikacje/growmail-e-mail-marketing-i-porzucone-koszyki)
- IdoSell: porzucone koszyki, SMS i newsletter nie znalezione w pomocy; nowe dodatki "automatyzacja marketingu", A/B, Google Ads (P, bez cen) — [IdoSell cennik](https://www.idosell.com/pl/cennik/) [niezweryfikowane dla koszyków]
- Allegro: Shoper I (dwustronna integracja, nie jest wbudowana w cenie wg poprzedniej notatki), IdoSell N (16 marketplace'ów, wg strony produktu) — [IdoSell funkcje](https://www.idosell.com/pl/funkcje/)
- Blog/CMS: IdoSell blog wbudowany (N); Shoper blog tylko Premium (P) — [Shoper cennik](https://www.shoper.pl/cennik/)
- Program lojalnościowy: Shoper standard N, premium w Premium; IdoSell program lojalnościowy/afiliacyjny N — oba z poprzednich notatek.
- Feedy Ceneo/Google, rekomendacje, edytor wizualny banerów: [niezweryfikowane].

## 8. Katalog i magazyn

### Cited Findings
- Shoper Standard: brak wielomagazynowości (P: Premium do 50); CSV import/eksport N; dropshipping 300+ dostawców (I, wg konkurenta) — [Shoper cennik](https://www.shoper.pl/cennik/)
- IdoSell: wbudowany WMS, wiele magazynów własnych, zewnętrznych i fulfillment (N, pełny podział planów nieznany) — [IdoSell WMS](https://www.idosell.com/pl/system-zarzadzania-magazynem/); zestawy produktów w module zestawów — [IdoSell moduł](https://www.idosell.com/pl/modul-zestawow-kolekcji-i-opinii); migracja produktów jako jedyny import opisany na stronie.
- Rezerwacje stanów, alerty niskiego stanu, statusy dostępności, pola GPSR (GPSR deklarowane jako N u obu): niezweryfikowane szczegółowo.

## 9. Administracja

### Cited Findings
- Shoper: wersja mobilna panelu i powiadomienia push w abonamencie (N), 2FA — [Shoper cennik](https://www.shoper.pl/cennik/)
- IdoSell: prawa dostępu dla pracowników definiowane szczegółowo także dla API (starszy wpis blogowy, data niepotwierdzona) — [IdoSell blog](https://idosell.com/en/blog/we-increase-the-level-of-the-administration-panel-access-security-by-introducing-additional-rights-for-managing-product-prices-and-panel-users-1235325858)
- Rejestr aktywności, aplikacja mobilna IdoSell, raporty sprzedaży/produktów/klientów: [niezweryfikowane] w obu.

## 10. Deweloperska

### Cited Findings
- Shoper: otwarte API w abonamencie i App Store (N); Storefront: zaawansowana edycja modułów, Macro i JS tylko w Premium (P) — [Shoper cennik](https://www.shoper.pl/cennik/)
- IdoSell: publiczna dokumentacja API — [idosell.readme.io](https://idosell.readme.io/docs/getting-started); limity wywołań 100 tys.-1 mln/mies. zależnie od planu (wg artykułu konkurenta, poprzednie notatki); dostęp do kodu szablonów (CSS/JS); "IdoSell Apps" nowy sklep aplikacji (szczegóły nieznane) — [IdoSell Apps](https://www.idosell.com/pl/idosell-apps)
- Webhooki: niezweryfikowane u obu (strona IdoSell ich nie wymienia). Headless/front API: nie badano; API Shopera i IdoSell pozwalają teoretycznie, ale limity i oficjalne wsparcie nieznane.

## 11. PrestaShop (krótko)

### Takeaway
PrestaShop 9.1.x jest aktualną linią (9.1.0 wyszedł 23.03.2026, 9.1.5 jest ostatnim wydaniem 9.1 przed 9.2 w becie), 8.2 jest w przedłużonym wsparciu; platformę od 2026 posiada grupa cyber_Folks razem z Sylius i BitBag.

### Cited Findings
- 9.1: wydane 23.03.2026, domyślny motyw Hummingbird 2.0 — [PrestaShop 9.1](https://build.prestashop-project.org/news/2026/prestashop-9-1-0-available/); 9.1.5 wydanie bezpieczeństwa — [9.1.5](https://build.prestashop-project.org/news/2026/prestashop-9-1-5-security-release/); 9.1 ma multi-shipping (wg źródła wtórnego) — [E-Commerce Nation](https://www.ecommerce-nation.fr/prestashop-9-1-nouveau-theme-multi-shipping-repositionnement-technique/)
- 8.2.x w fazie extended support, koniec po wydaniu 10.0.0 — [PrestaShop](https://build.prestashop-project.org/news/2025/82x-extended-support-phase/)
- Przejęcie 100% przez cyber_Folks z Sylius i BitBag sfinalizowane (komunikat inwestorski), razem ponad 700 000 klientów i 35 mld EUR GMV; data 18.02.2026 według jednego źródła wtórnego, cena ok. 53,8 mln EUR według jednego źródła (niepotwierdzona); poprzednie notatki mówiły o 2025, więc data wymaga uzgodnienia z komunikatem — [cyber_Folks IR](https://investors.cyberfolks.pl/en/?p=3794), [ecommerce-nation](https://www.ecommerce-nation.fr/rachat-prestashop-par-cyber_folks-sylius/)
- Rdzeń (wiedza własna, [niezweryfikowane]): N: katalog, kombinacje, reguły koszyka, przewoźnicy z zakresami cen/wagi, zakup jako gość, faktury PDF, zwroty i noty kredytowe, wielojęzyczność i wielowalutowość, multistore. Dla Polski P/I: InPost (np. moduł agencji 49 USD), Allegro (ok. 350-650 zł rocznie wg BaseLinker, ok. 400 zł wg iFirmy, źródła marketingowe), Omnibus (brak źródeł); moduły Przelewy24/PayU dostarczają operatorzy bezpłatnie [niezweryfikowane]. Zakres cen modułów 41,99-6 999,99 EUR, wdrożenie od ok. 500-900 zł — [iFirma o PrestaShop](https://www.ifirma.pl/blog/przewodnik-po-prestashop-ile-kosztuje-sklep-internetowy-i-jak-go-otworzyc/), [cocos.codes InPost](https://cocos.codes/en/archives/prestashop-inpost-parcel-machines/)

## 12. Dane rynkowe niezależne

### Takeaway
Gemius 2025 (sierpień 2025): 78% internautów kupuje online, 83% wybiera Paczkomat, BLIK jest najczęstszy; nie ma wiarygodnych niezależnych danych o udziałach platform sklepowych, a udział Allegro podawany jest rozbieżnie.

### Cited Findings
- Raport Gemius "E-commerce w Polsce 2025" (sierpień 2025): 78% internautów (ok. 30 mln) kupuje online; 83% korzysta z Paczkomatów, w tym 87% z nich InPost; płatności: BLIK 72%, szybkie przelewy 64%, karty 43%; wyzwania: długi czas dostawy 28%, natrętne reklamy 28%, koszty dostawy 26%; mobile: niewygodne formularze 33%. Brak w streszczeniu przyczyn porzucania koszyków i procentowych czynników zaufania (trust: BLIK i szybkie przelewy; 46% użytkowników szybkich przelewów wybiera Przelewy24) — [Przelewy24 o raporcie](https://www.przelewy24.pl/en/news/ecommerce-2025-zakupy-online-trendy-polscy-konsumenci) (Przelewy24 jest operatorem płatności, streszczenie wtórne); średnie miesięczne wydatki na obuwie 227 zł — [AntyWeb](https://antyweb.pl/e-commerce-w-polsce-2025-gemius)
- Wydanie 2026 istnieje (Gemius z PBI, IAB Polska, ALK): cena, wygoda dostawy i bezpieczeństwo ważne przy wyborze sklepu; liczby nieodczytane — [itwiz](https://itwiz.pl/?p=75765), [Eurobuild o wiarygodności e-sklepów](https://eurobuildcee.com/news/31445-wiarygodnosc-e-sklepow-pod-lupa-gemiusa)
- Allegro: GMV blisko 70 mld zł w 2025, wzrost w Polsce 9,4% (Q4 2025 +8,6%, Q1 2026 +11,6%) — [Allegro Q4 2025](https://about.allegro.eu/wp-content/uploads/2026/03/Allegro-Q4-2025-Komunikat-prasowy.pdf) (liczba z podsumowania wyszukiwania, nie z odczytanego PDF; zweryfikować w dokumencie)
- Rynek e-commerce Polski ponad 110 mld zł (PMR, wg wyszukiwania; udział Allegro ok. 60% wyniknąłby z dzielenia, ale źródła podają "około jedna trzecia"; metodologie niewyjaśnione) [niezweryfikowane] — [fashionbiznes o Allegro](https://fashionbiznes.pl/allegro-rosnie-mimo-konkurencji-ponad-20-mln-kupujacych-i-miliardy-przychodow/)
- Liczba sklepów: niemal 75 tys. (D&B wg PAP, data niepotwierdzona; 68,9 tys. w czerwcu 2024) — [PAP](https://www.pap.pl/mediaroom/analiza-rynku-e-commerce-w-polsce), [D&B](https://www.dnb.com/pl-pl/blog/dane/liczba-sklepow-internetowych-w-polsce.html)

### Gaps
- Nie odczytano pierwotnych PDF Gemius/PBI/IAB; brak 2026 danych o porzucaniu koszyków i zaufaniu. Brak niezależnych (e-Izba, Senuto, TrustMate) udziałów platform; trackery z poprzednich notatek pozostają jedyną miarą.
