# Uzupełnienie: faktury, podatki, płatności i dostawa dla małego sklepu B2C w Polsce (stan: 18.11.2026)

Uwaga: to nie jest porada prawna ani podatkowa. Fakty z oficjalnych stron są oznaczone [oficjalne]. Źródła wtórne (blogi, portale księgowe, resellerzy jak Shoper) są oznaczone [wtórne]. Strony cenników operatorów zwykle nie podają daty; tam, gdzie jej brak, jest to zapisane. Ceny i stawki zmieniają się, więc przed umową trzeba je potwierdzić u operatora.

## 1. KSeF: harmonogram, B2C, obowiązki sklepu

### Takeaway
Oficjalna strona KSeF potwierdza obowiązek od 1.02.2026 (duzi podatnicy, sprzedaż 2024 powyżej 200 mln zł z VAT) i od 1.04.2026 (pozostali). Najmniejsi (faktury do 10 000 zł miesięcznie) mają termin 1.01.2027, co potwierdzają tylko źródła wtórne. Faktury dla osób fizycznych nieprowadzących działalności można wystawiać w KSeF dobrowolnie, więc B2C jest poza obowiązkiem. Faktura dla nabywcy z NIP (B2B) jest fakturą ustrukturyzowaną w KSeF.

### Cited Findings
- 1.01.2022: KSeF dobrowolny. 1.02.2026: obowiązek dla podatników ze sprzedażą w 2024 r. (z VAT) powyżej 200 mln zł; 1.04.2026: obowiązek dla pozostałych; 1.02.2026: produkcyjna wersja KSeF 2.0. Ułatwienia do końca 2026 r. obejmują m.in. limit sprzedaży 10 000 zł miesięcznie i możliwość wystawiania faktur z kas. Strona zmodyfikowana 15.10.2026 — [Podstawy prawne oraz kluczowe terminy, ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/) [oficjalne]
- Akty: ustawa z 5.08.2025 (Dz.U. 2025 poz. 1203) wprowadziła etapowy start i uproszczenia; wcześniej ustawa z 9.05.2024 (Dz.U. 2024 poz. 852) przesunęła obowiązek na 1.02.2026; rozporządzenia wykonawcze z 7.12.2025 (Dz.U. 2025 poz. 1742 i poz. 1740) i z 12.12.2025 (Dz.U. 2025 poz. 1815) — [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/) [oficjalne]
- Faktury dla osób fizycznych nieprowadzących działalności „mogą być wystawiane w KSeF dobrowolnie” (decyduje wystawca) — [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/) [oficjalne]
- Oficjalna strona główna KSeF ma podstrony „Konsumenci i osoby fizyczne” oraz „Do 10 000 zł miesięcznie”; ich treści nie udało się pobrać — [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/) [oficjalne, treść nieotwarta]
- Termin 1.01.2027 dla najmniejszych (wykluczeni cyfrowo, do 10 tys. zł miesięcznie): przekroczenie limitu wcześniej oznacza obowiązek od faktury, która limit przekroczyła (art. 145m); kary administracyjne od 1.01.2027 — [jpk.info.pl, harmonogram](https://jpk.info.pl/faktury/e-faktura-ustrukturyzowana-ksef/harmonogram/) [wtórne]; wyszukiwanie zbiorcze: [amavat.pl](https://amavat.pl/ustawa-z-podpisem-prezydenta-ksef-odroczony-do-1-lutego-2026) [wtórne]. Oficjalna strona w pobranym fragmencie wymienia tylko „ułatwienia do końca 2026” bez daty 1.01.2027.
- Brak NIP nabywcy = B2C, KSeF dobrowolny (podstawa wg źródła: art. 106ga ust. 2 ustawy o VAT); podanie NIP = sprzedaż na firmę z KSeF; faktury do paragonu dla konsumentów bez NIP pozostają poza KSeF — [jpk.info.pl, faktura na osobę fizyczną](https://jpk.info.pl/faktury/faktura-na-osobe-fizyczna/) [wtórne]
- Odbiór faktur w KSeF obowiązuje od 1.02.2026 niezależnie od terminu wystawiania — [amavat.pl](https://amavat.pl/ustawa-z-podpisem-prezydenta-ksef-odroczony-do-1-lutego-2026) [wtórne]

### Inferences
- Sklep będący podatnikiem VAT (nie na limicie do 10 tys. zł miesięcznie) wystawia faktury B2B (z NIP) w KSeF; praktycznie robi to system fakturowy podłączony przez API albo własny moduł (patrz sekcja 4). Dla B2C wystarczy faktura na żądanie w PDF/HTML albo dobrowolnie w KSeF [wniosek własny].
- Jeśli sklep jest mikroprzedsiębiorcą z fakturami do 10 tys. zł miesięcznie, start przed 2027 może być poza KSeF; ale ta granica dotyczy wartości sprzedaży dokumentowanej fakturami, i warto ją zweryfikować u księgowej [wniosek własny].

### Gaps
- Nie otwarto oficjalnych podstron „Konsumenci i osoby fizyczne” i „Kalendarium KSeF”; data 1.01.2027 dla najmniejszych jest tylko ze źródeł wtórnych.
- Nie zweryfikowano w ISAP tekstu art. 106ga ust. 2 ani art. 145m. Brak oficjalnego wskazania, czy faktura B2C wystawiona w KSeF „na żądanie” ma inne reguły.

## 2. Paragony, faktury w sprzedaży wysyłkowej, dane w checkout

### Takeaway
Kasy rejestrującej nie trzeba stosować przy dostawie towarów pocztą lub kurierem, gdy całość zapłaty trafia przez pocztę, bank lub SKOK (płatność bramką jest traktowana jak wpłata na rachunek) i dokumentacja wskazuje, jakiej transakcji i czyjej dotyczy wpłata (poz. 41 załącznika do rozporządzenia MF z 17.12.2024, Dz.U. 2024 poz. 1902, obowiązuje do końca 2027). Faktura na żądanie konsumenta (art. 106b ust. 3) musi być wystawiona; checkout powinien zbierać opcjonalnie nazwę firmy, NIP i adres rozliczeniowy.

### Cited Findings
- Rozporządzenie MF z 17.12.2024, Dz.U. 2024 poz. 1902, stosowane od 1.01.2025, zwolnienia nie później niż do 31.12.2027 — [ELI](https://eli.gov.pl/eli/DU/2024/1902/ogl); tekst PDF nie dał się odczytać automatycznie — [PDF ELI](https://eli.gov.pl/api/acts/DU/2024/1902/text/O/D20241902.pdf) [oficjalne, treści załącznika nie odczytano]
- Poz. 41 załącznika: „dostawę towarów pocztą lub przesyłkami kurierskimi, jeżeli dostawca towaru otrzymuje w całości zapłatę” przez pocztę, bank lub SKOK, a z ewidencji i dowodów „jednoznacznie wynika, jakiej konkretnie czynności dotyczyła i na czyją rzecz została dokonana” — cytat z interpretacji w [jpk.info.pl](https://jpk.info.pl/aktualnosci/2025/sprzedaz-wysylkowa-bez-kasy-fiskalnej/) [wtórne, cytuje przepis]
- Zmiana od 31.03.2026 (nowa poz. 59 – mechaniczne automaty na monety; parkingi spółdzielni) nie dotyczy poz. 41; artykuł podaje, że wysyłka pozostaje zwolniona — [podatki.biz](https://www.podatki.biz/artykuly/kasy-fiskalne-2025-2027-od-31-marca-2026-r-obowiazuja-nowe-zwolnienia_34_61696.htm) [wtórne]
- Płatności przez PayU, Przelewy24, BLIK itp. traktowane jak wpłata na rachunek; częściowa zapłata gotówką psuje warunek pełnej zapłaty — wyniki wyszukiwania opisujące m.in. [ifirma.pl](https://www.ifirma.pl/kasa-fiskalna/platnosc-przelewem-a-zwolnienie-z-kasy-fiskalnej/) [wtórne, nieotwarte w całości]
- Interpretacja indywidualna opisana w jpk.info.pl: zapłata gotówką/kartą kurierowi, gdy pieniądze trafiają do sprzedawcy bezgotówkowo (przelew od firmy kurierskiej z zestawieniem), nadal mogła mieścić się w zwolnieniu; to indywidualny przypadek — [jpk.info.pl](https://jpk.info.pl/aktualnosci/2025/sprzedaz-wysylkowa-bez-kasy-fiskalnej/) [wtórne]
- Zwolnienie z § 4 ust. 1 rozporządzenia (towary wyłączone ze zwolnień) — lista towarów nieodczytana; źródło potwierdza tylko, że istnieje — [jpk.info.pl](https://jpk.info.pl/aktualnosci/2025/sprzedaz-wysylkowa-bez-kasy-fiskalnej/) [wtórne]
- Art. 106b ust. 3: faktura na żądanie nabywcy niebędącego podatnikiem; termin zgłoszenia żądania 3 miesiące od końca miesiąca dostawy/zapłaty — [podatki.biz](https://www.podatki.biz/artykuly/jak-dlugo-konsumenci-moga-zadac-wystawienia-faktury-do-paragonu_34_57765.htm) [wtórne]; faktura do paragonu w ewidencji VAT z oznaczeniem „FP” — [infor](https://ksiegowosc.infor.pl/podatki/vat/jpk-vat/7554826,skarbowka-wyjasnia-jak-ujac-w-ewidencji-vat-jpk-vat-z-deklaracja-fakture-do-paragonu-wystawiona-w-ksef-na-zadanie-konsumenta-albo-rolnika-ryczaltowego.html) [wtórne]

### Inferences
- Przy płatności wyłącznie online (BLIK, pay-by-link, karta) wysyłka kurierem lub do Paczkomatu mieści się w poz. 41, więc moduł paragonów/kasy nie jest potrzebny na start; warunkiem jest powiązanie wpłaty z zamówieniem (identyfikator transakcji operatora w zamówieniu) [wniosek własny].
- Pobranie (gotówka u kuriera) i odbiór osobisty to ryzyko wymogu kasy; zalecane pominięcie przy MVP [wniosek własny, zgodny z poprzednimi notatkami].
- Checkout: pole „chcę fakturę”, nazwa firmy, NIP (walidacja sumy kontrolnej), adres rozliczeniowy (jeśli inny niż dostawy); dla konsumentów faktura na żądanie w ciągu 3 miesięcy, więc potrzebna akcja „wystaw fakturę” w panelu i na koncie klienta [wniosek własny].

### Gaps
- Nie odczytano tekstu załącznika ani § 4 ust. 1 z ELI/ISAP (PDF skompresowany); pozycja 41 i warunki pochodzą z cytatów wtórnych. Nie ustalono, czy przepis art. 106b ust. 3 w obecnym brzmieniu ma inny termin; brak oficjalnego źródła podatki.gov.pl.
- Brak oficjalnego wskazania terminu wystawienia faktury (art. 106i) – niezweryfikowano.

## 3. VAT OSS i próg 10 000 EUR

### Takeaway
Próg 10 000 EUR (bez VAT, w bieżącym i poprzednim roku kalendarzowym łącznie, dla dostawcy ustanowionego w jednym państwie UE) dotyczy wewnątrzwspólnotowej sprzedaży wysyłkowej do konsumentów. Poniżej progu stosuje się VAT państwa dostawcy; po przekroczeniu miejscem opodatkowania jest państwo zakończenia transportu, a rozliczenie możliwe przez procedurę unijną OSS.

### Cited Findings
- Od 1.07.2021 próg 10 000 EUR (bez VAT) łącznie dla sprzedaży wysyłkowej WE i usług telekomunikacyjnych/elektronicznych; poniżej progu opodatkowanie w państwie dostawcy; „as soon as the threshold is exceeded, the general rule applies without exception”, miejsce dostawy w państwie, w którym kończy się transport — [Komisja Europejska, OSS](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en) [oficjalne]
- Podatnik korzystający z OSS rejestruje się tylko w jednym państwie członkowskim identyfikacji — [Komisja Europejska](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en) [oficjalne]
- Strony podatki.gov.pl o OSS nie zostały pobrane (404 na testowanym adresie).

### Inferences
- Sklep sprzedający tylko do Polski nie potrzebuje OSS. Na start wystarczy ograniczyć wysyłkę do PL albo monitorować łączną sprzedaż do konsumentów w innych krajach UE względem 10 000 EUR [wniosek własny].

### Gaps
- Nie sprawdzono polskiej strony podatki.gov.pl (sposób rejestracji OSS w e-Urzędzie Skarbowym, terminy deklaracji kwartalnych) ani implementacji w ustawie o VAT.

## 4. Fakturowanie w praktyce: wbudowane vs API zewnętrznego systemu

### Takeaway
Wszystkie cztery systemy (Fakturownia, wFirma, iFirma, inFakt) deklarują obsługę KSeF; publiczne API potwierdzono oficjalnie dla Fakturowni (GitHub) i wFirmy (pomoc). Dla inFakt i iFirma API istnieje wg źródeł wtórnych, a ceny w źródłach są sprzeczne i wymagają sprawdzenia na stronach cenników. Najprościej: API z jednym systemem fakturowym zamiast własnego modułu KSeF.

### Cited Findings
- Fakturownia: publiczne REST API (wystawianie faktur, paragonów, klienci, produkty); token w nagłówku Authorization; pola zgodne z FA(3); niektóre pola ról tylko przy włączonym KSeF; limit opisu 256 znaków przy KSeF; osobny przewodnik KSeF.md — [github.com/fakturownia/api](https://github.com/fakturownia/api) [oficjalne]
- wFirma: dokumentacja API na doc.wfirma.pl; klucz z zakładki „integracja z API”; API bezpłatne do 1000 dokumentów generowanych przez API miesięcznie, powyżej indywidualna umowa; faktury z API wysyłane do KSeF automatycznie na koncie użytkownika z aktywną autoryzacją KSeF; status KSeF przez /invoices/get/{id} lub webhook „Zmiana statusu przetwarzania”; wysyłka wsadowa co 10 min (<5 faktur) lub 5 min — [pomoc.wfirma.pl](https://pomoc.wfirma.pl/-api-interfejs-dla-programistow) [oficjalne]
- inFakt: dostęp do zasobów przez inFakt API (integracje własne) — wzmianka w [infakt.pl](https://www.infakt.pl/blog/slownik-ksef-najwazniejsze-terminy-i-pojecia/) [oficjalne, tylko wzmianka]; porównanie, które podaje wysyłkę do KSeF jako ręczną, jest sprzeczne — [eurofiscalis](https://www.eurofiscalis.com/pl/e-commerce-programy-do-fakturowania-ranking-2026/) [wtórne]
- iFirma: programy automatycznie przesyłają dokumenty do KSeF przez API; integracja wymaga tokenu/certyfikatu; dokumentacja API i ograniczenia planów nie zostały potwierdzone — [ifirma.pl, blog KSeF](https://www.ifirma.pl/blog/programy-do-obslugi-ksef-przeglad-rozwiazan-dla-malych-firm-jdg-i-freelancerow-2026/) [oficjalne, tylko wzmianka]
- Ceny: źródła wtórne podają sprzeczne kwoty (inFakt od 4,99 zł netto vs 29 zł; Fakturownia od 18,99 zł vs plan Mini 39 zł; iFirma 19 zł vs 49 zł; wFirma 19 zł vs 39 zł) — [vatax.pl](https://www.vatax.pl/blog/obsluga-ksef-porownanie-cen), [freenance.io](https://freenance.io/przedsiebiorczosc/fakturownia-recenzja-2026-koszty-faktury-online-ksef-mala-firma/) [wtórne, nie używać do decyzji]

### Inferences
- Dla MVP najlepsze jest wystawianie faktur przez API jednego systemu (Fakturownia lub wFirma mają potwierdzone API i KSeF), a platforma przechowuje numer i status faktury oraz link do PDF. Własny moduł KSeF (certyfikaty, FA(3), kolejki, UPO) to duży koszt [wniosek własny].
- Sklep musi mieć dane księgowej i konto KSeF sprzedawcy zanim zacznie wystawiać faktury B2B [wniosek własny].

### Gaps
- Brak potwierdzonych cenników i limitów API dla inFakt i iFirma, brak oficjalnych cenników wszystkich czterech (sprzeczne dane wtórne). Brak danych o tym, ilu małych sklepów używa wbudowanej fakturacji platformy vs API.

## 5. Operatorzy płatności (stan cenników: strony bez daty, pobrane 18.11.2026)

### Takeaway
Oficjalnie zweryfikowane stawki dla nowego małego sprzedawcy: Przelewy24 1,29% + 0,30 zł (BLIK, karty, przelewy; aktywacja 59 zł zwrotna po 10 transakcjach), PayU 1,49% (lub 1,1% + 0,30 zł w promocji; aktywacja 29 zł), Tpay Starter 1,59% + 0,39–0,41 zł (aktywacja 99 zł), imoje (ING) 0% przez 3 miesiące, potem 0,89%, Stripe 1,6% + 1 zł BLIK, 1,9% + 1 zł P24, 1,5% + 1 zł karty EEA. Autopay nie został zweryfikowany oficjalnie. Wszystkie obsługują zwroty przez API i podpisywane powiadomienia (potwierdzone w pełni tylko dla PayU zwrotów, Tpay podpisów, Stripe zwrotów BLIK). Najprostsze API przy jednym operatorze z BLIK, pay-by-link i kartami: Stripe (jedno API, BLIK i P24, zwroty częściowe/pełne, webhooki z podpisem), ale z droższą prowizją i opłatą 1 zł; wśród polskich operatorów trzeba porównać dokumentację w praktyce.

### Cited Findings
**Przelewy24**
- Cennik: karty, BLIK, przelewy online, portfele: 1,9% standard, dla nowych klientów 1,29% + 0,30 zł; PayPo 1,5% + 0,30 zł; abonament 0 zł; aktywacja „0 zł”, ale pobierane 59 zł zwracane po 10 transakcjach w pierwszych dwóch miesiącach; zwroty zawsze bezpłatne; wypłaty: 4 pierwsze miesięcznie bezpłatne, kolejne 0,99 zł; program Polska Bezgotówkowa 0% prowizji od kart przez 12 miesięcy dla nowych e-sprzedawców; strona bez daty — [przelewy24.pl/oferta](https://www.przelewy24.pl/oferta) [oficjalne]
- Rozbieżność: reseller Shoper podaje 1,58% + 0,39 zł (stała opłata 0,39 zł od 1.08.2026) i aktywację 49 zł dla P24 i Autopay w ramach Shoper Płatności — [shoper.pl](https://www.shoper.pl/pelny-cennik-uslug-shoper) [wtórne]; freenance.io podaje BLIK 0,79% w abonamencie 50 zł, co nie pokrywa się z oficjalną stroną — [freenance.io](https://freenance.io/przedsiebiorczosc/bramki-platnosci-2026-ranking-stripe-payu-przelewy24-tpay-bluemedia-porownanie/) [wtórne]
- Dokumentacja P24 REST (zwroty, podpis powiadomienia, BLIK level 0): strona developers.przelewy24.pl nie zwróciła treści; nie zweryfikowano.

**PayU**
- Strona SMB: aktywacja 29 zł (przekreślone 199 zł); BLIK, karty (w tym Apple Pay, Google Pay), przelewy, raty 5x0%, płatności odroczone (Twisto, PayPo): „0,30 zł + 1,1%” oraz „1,49%” (strona nie wyjaśnia różnicy); oferta indywidualna powyżej 800 000 zł obrotu; link do cennika „Price_list_PL_ENG4_01_2026” (sugeruje styczeń 2026) — [poland.payu.com/smb](https://poland.payu.com/smb/) [oficjalne]
- Nowy sprzedawca: do weryfikacji firmy płatności do 2 500 zł, tylko przelewy i BLIK; potem karty i inne — wyniki wyszukiwania z oficjalnego dokumentu [PayU first steps](https://poland.payu.com/wp-content/uploads/sites/14/2026/07/PayU_first_steps_pl.pdf) [oficjalne, wg streszczenia wyszukiwarki]
- Zwroty: `POST /api/v2_1/orders/{orderId}/refunds`; bez `amount` zwrot pełny; z `amount` (w groszach) zwrot częściowy; wiele zwrotów częściowych do wartości zamówienia, `extRefundId` dla równoległych; statusy FINALIZED/CANCELED — [PayU docs](https://developers.payu.com/europe/docs/payment-flows/refunds/) [oficjalne]
- Redirect: kupujący wraca przez `continueUrl`, PayU wysyła powiadomienia o statusie zamówienia — [developers.payu.com](https://developers.payu.com/pl/restapi.html) [oficjalne]; BLIK level 0, podpis OpenPayU-Signature, Apple/Google Pay w API, „Płacę później”: nie zweryfikowano w dokumentacji.

**Tpay**
- Starter: abonament 0 zł, aktywacja 99 zł, BLIK 1,59% + 0,41 zł, karty i przelewy 1,59% + 0,39 zł, zwrot 1 zł (nie dla kart), wypłata 2 zł; Business: abonament 99 zł (tylko w miesiącach z transakcjami), aktywacja 1 zł, BLIK 0,99% + 0,02 zł, karty i przelewy 0,99%, zwroty i wypłaty 0 zł; chargeback 120 zł; strona bez daty (© 2026) — [tpay.com/oferta](https://tpay.com/oferta) [oficjalne]
- Powiadomienia POST podpisane nagłówkiem `X-JWS-Signature`; weryfikacja JWS obowiązkowa; checksum md5sum w powiadomieniach rozliczeniowych; zwrot pełny przez panel lub `POST /transactions/{id}/refunds` wysyła `tr_status = chargeback` (w znaczeniu zwrot) — [docs-api.tpay.com, webhooks](https://docs-api.tpay.com/en/webhooks/) [oficjalne]
- REST API: sandbox, zwroty; BLIK (on-site, one-click, recurring), karty, Apple Pay w ofercie; BLIK level 0 nie potwierdzony w pobranych dokumentach — [tpay.com](https://www.tpay.com/), [docs-api.tpay.com](https://docs-api.tpay.com/) [oficjalne]

**imoje (ING)**
- Oferta standardowa: 0% prowizji przez 3 miesiące, potem 0,89% (BLIK, szybkie przelewy, karty, płatności odroczone); Polska Bezgotówkowa: 0% do 12 miesięcy lub do 50 000 zł transakcji, potem BLIK 1,59% (w tym 0,19% opłaty systemowej BLIK), pozostałe 1,4%; brak opłat za aktywację, abonament, zwroty i wypłaty; Apple Pay, Google Pay, Click to Pay; raty 0% — [ing.pl/bramka-platnicza-imoje](https://www.ing.pl/bramka-platnicza-imoje) [oficjalne, bez daty; warunki promocji, w tym brak umowy z innym operatorem przez 12 miesięcy, wg strony]
- Dokumentacja API imoje nie została sprawdzona.

**Autopay (Blue Media)**
- Oficjalny cennik nie został pobrany (403). Reseller Shoper: aktywacja 49 zł brutto jako przelew weryfikacyjny, aktywacja płatności szybkich i BLIK ok. 24 h roboczych, karty ok. 30 dni; startupy zwykle dostają odmowę kart; standard Shoper 1,58% + 0,39 zł — [shoper.pl/systemy-platnosci/autopay](https://www.shoper.pl/systemy-platnosci/autopay), [Shoper FAQ](https://www.shoper.pl/learn/artykul/shoper-platnosci-najczesciej-zadawane-pytania-faq) [wtórne]

**Stripe**
- Cennik PL: karty EEA standardowe 1,5% + 1,00 zł, premium 1,9% + 1,00 zł; BLIK 1,6% + 1,00 zł; Przelewy24 1,9% + 1,00 zł; brak opłat konfiguracyjnych i miesięcznych — [stripe.com/pl/pricing](https://stripe.com/pl/pricing) [oficjalne, bez daty]
- BLIK: PL jako lokalizacja firmy obsługiwana; minimum 2 PLN; kod 6-cyfrowy wpisywany w formularzu (Payment Element), klient autoryzuje w aplikacji w 60 s; zwroty pełne i częściowe, okno 2 lata, do 120 minut, asynchroniczne, webhooki `refund.updated`/`refund.failed` z weryfikacją podpisu; brak ręcznego capture; spory pełne — [docs.stripe.com/payments/blik](https://docs.stripe.com/payments/blik) [oficjalne]
- Wsparcie P24 przez Stripe w cenniku, ale dokumentacja P24 w Stripe nie została przeczytana.

### Inferences
- Nominalnie najtańszy na starcie (poza promocjami) jest P24 (1,29% + 0,30 zł) lub imoje/ING (0% przez 3 miesiące); Stripe jest najdroższy przy niskich koszykach (1 zł stałej, średni koszyk BLIK 167 zł według wcześniejszej notatki), ale ma najlepiej udokumentowane jednolite API (BLIK, P24, karty, zwroty, webhooki) [wniosek własny].
- Rekomendacja do dalszej weryfikacji: wybór między P24 a PayU/Tpay powinien wynikać z przeczytania ich REST API (podpis powiadomienia, zwroty, BLIK level 0), czego w tym badaniu nie udało się potwierdzić dla P24 i imoje [wniosek własny].
- Promocje „Polska Bezgotówkowa” mają warunki (brak wcześniejszej współpracy z operatorem), więc nie liczyć na nie z góry [wniosek własny].

### Gaps
- Nie sprawdzono: oficjalnego cennika Autopay; dokumentacji API P24 i imoje; BLIK level 0 u P24, PayU, Tpay, imoje; podpisu powiadomień P24 i PayU; BNPL (PayPo, PayU „Płacę później”) w API; wymagań onboardingu (dokumenty, regulamin na stronie, czas weryfikacji) poza informacjami o PayU (weryfikacja, limit 2 500 zł) i Autopay (przelew weryfikacyjny, wtórne).

## 6. Pobranie (COD)

### Takeaway
Pobranie nadal istnieje, ale maleje: 33% respondentów w raporcie Gemius 2025 deklaruje jego użycie, a w 2024 r. było to 39%. Dla sprzedawcy oznacza nieodebrane paczki i ryzyko kasy fiskalnej.

### Cited Findings
- Płatność przy odbiorze: 33% respondentów (2025), w 2024: 39% korzystało, prawie co trzeci klient mówił, że wpływa na wybór sklepu; ryzyko nieodebranych zamówień — [eurobuildcee.com, streszczenie raportów Gemius](https://eurobuildcee.com/news/35098-za-zakupy-w-internecie-wolimy-placic-gotowka) [wtórne]
- Mężczyźni częściej wybierają pobranie i raty — [Przelewy24 o Gemius 2025](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers) [wtórne]

### Inferences
- Pobranie nie jest wymogiem startowym, jeśli oferujesz BLIK i przelewy; jest opcją po MVP, z uwagą na kasę fiskalną (sekcja 2) [wniosek własny].

### Gaps
- Nie ustalono dokładnych liczb z Gemius 2026 ani z IGE; źródło streszcza, nie jest pełnym raportem.

## 7. Dostawa: InPost ShipX i Geowidget

### Takeaway
InPost ShipX i Geowidget są dostępne po założeniu konta w Managerze Paczek; token ShipX można wygenerować bez umowy kurierskiej, jeśli używa się tylko Paczkomatów. Jest sandbox, etykiety PDF/ZPL/EPL, webhook śledzenia ustawiany w organizacji. Geowidget v5 jest docelowy: stary mechanizm mapy ma być wyłączony 1.01.2027 (wg Shopera).

### Cited Findings
- Manager Paczek: potwierdzone dane firmy (Moje Konto > Dane) wymagane do wygenerowania dostępu API; token ShipX (do 10 tokenów), pole numeru umowy kurierskiej opcjonalne („jeśli potrzebujesz tylko Paczkomatów, generujesz bez niego”); token Geowidget przypisany do domen; opcjonalny webhook statusów w ustawieniach organizacji — [dokumentacja InPost](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/1187086337) [oficjalne]
- Sandbox: https://sandbox-api-shipx-pl.easypack24.net, dostęp przez Sandbox Manager; ShipX asynchroniczny (po utworzeniu przesyłki status `created` bez `tracking_number`); etykieta PDF/ZPL/EPL z endpointu etykiety, niewykorzystana anulowana po 45 dniach; śledzenie w sandboxie nie działa (testować na produkcji lub mock) — [InPost Integration FAQ](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/451903492/InPost+Integration+FAQ) [oficjalne, wg streszczenia wyszukiwarki]
- Geowidget v5: osadzenie CSS/JS z geowidget.inpost.pl, element `inpost-geowidget` z `token`, `language`, `config`, wynik przez zdarzenie lub callback; token publiczny z Managera Paczek (Moje konto > API > Geowidget); docs: [geowidget.inpost.pl/docs](https://geowidget.inpost.pl/docs/) [oficjalne, wg streszczenia]; punkty przez `https://api.inpost.pl/v1/points`
- Wyłączenie poprzedniego mechanizmu mapy 1.01.2027 — [Shoper](https://www.shoper.pl/learn/?p=69068) [wtórne]
- Cennik InPost od 1.03.2026: kurierskie biznes Paczkomat gab. A ok. 14,55 zł netto (było 14,13), gab. C 18,55 zł netto; ceny dla konsumentów obniżone o 0,50 zł; opłata paliwowa 13% w jednym z zestawień (czy dotyczy cen biznesowych — niepotwierdzone); gab. A 8×38×64 cm, do 25 kg — [chip.pl](https://www.chip.pl/2026/02/inpost-zmiana-cennik-uslug-taniej), [kb.pl](https://kb.pl/transport/koszty-transportu/cennik-inpost-zobacz-jakie-sa-ceny-paczkomatow) [wtórne]

### Inferences
- Technicznie MVP możliwe bez brokera: ShipX (tworzenie przesyłki do punktu, etykieta PDF, numer śledzenia) + Geowidget v5 (ID punktu zapisane w zamówieniu); kurier wymaga numeru umowy kurierskiej InPost [wniosek własny, z opcjonalnego pola numeru umowy].
- Wymiary gabarytów A/B/C i dopłaty za niezgodność dotyczą sprzętu outdoor; dla dużych towarów kurier jest konieczny [wniosek własny].

### Gaps
- Nie ustalono oficjalnie: czy do cen biznesowych InPost potrzebna jest umowa handlowa i jakie są stawki dla nowych małych firm; czy konto Manager Paczek wymaga firmy; zakres webhooków (lista zdarzeń); aktualna wersja i limity API. Oficjalnego cennika dla biznesu nie otwarto.

## 8. Brokerzy przesyłek: Furgonetka, Apaczka, Epaka, BaseLinker

### Takeaway
Furgonetka i Apaczka mają API i obsługują wielu przewoźników bez umowy. Furgonetka deklaruje „no contract or volume declaration” i 14 przewoźników; Apaczka 17 przewoźników i API v2 (klucze App ID/App secret). Epaka i BaseLinker nie zostały zweryfikowane. Dla małego sklepu najprostsza ścieżka to broker z API plus widget mapy punktów, ale brak oficjalnie przeczytanej dokumentacji ogranicza pewność.

### Cited Findings
- Furgonetka: API dla sklepów na własnym oprogramowaniu; narzędzia (generator etykiet, książka adresowa, powiadomienia) „100% free”; przewoźnicy m.in. DHL, FedEx, GLS, Pocztex, Orlen, DPD, UPS, InPost; wysyłka „no contract or volume declaration”, możliwość dodania własnej umowy; monitoring przesyłek i powiadomienia; cennik za przesyłki na osobnej stronie — [furgonetka.pl/en/e-commerce-tools](https://furgonetka.pl/en/e-commerce-tools) [oficjalne]
- Furgonetka sandbox api.sandbox.furgonetka.pl i OAuth (grant types) — z nieoficjalnego pakietu Laravel — [packagist](https://packagist.org/packages/beziworld/laravel-furgonetka) [wtórne]
- Apaczka: Web API v2 (wysyłanie przesyłek bez logowania na stronę), 17 przewoźników (InPost, GLS, DPD, ORLEN Paczka, DHL, FedEx, UPS, Pocztex), konto bezpłatne, oferta indywidualna wg wolumenu — [apaczka.pl/integracje](https://www.apaczka.pl/integracje/) [oficjalne]; regulamin API z 7.04.2025 (Alsendo sp. z o.o.), klucze generowane z konta — [PDF](https://www.apaczka.pl/app/uploads/2025/04/Regulamin-API-Apaczka-7.04.2025.pdf) [oficjalne, wg streszczenia]; tryb testowy wymaga kontaktu z Apaczką — [Comarch](https://pomoc.comarch.pl/shipping/index.php/documentation/apaczka) [wtórne]
- BaseLinker pozwala nadawać przesyłki i drukować etykiety z menedżera zamówień — [base.com blog](https://base.com/pl-PL/blog/?p=16027) [oficjalne, tylko wzmianka]

### Inferences
- Jeśli platforma ma własne API zamówień, broker pozwala ominąć umowy kurierskie, ale dodaje warstwę kosztów i zależności; dla zgodności z Paczkomatem trzeba osobno wybrać mechanizm mapy [wniosek własny].

### Gaps
- Nie przeczytano oficjalnych dokumentacji API Furgonetki i Apaczki (endpointy etykiet, śledzenia, webhooki), nie sprawdzono Epaka ani BaseLinker Shipping, ani cen za przesyłki brokerów. Nie zweryfikowano obsługi widgetu mapy punktów w tych API.

## 9. Ceny dostawy, progi darmowej dostawy, oczekiwania

### Takeaway
Nie znaleziono wiarygodnego badania średnich cen dostawy w polskich sklepach. Przykładowe progi darmowej dostawy w dużych sklepach odzieżowych to 249–250 zł, a poradniki podają zakres 200–300 zł; to nie badanie.

### Cited Findings
- Modivo próg 249 zł, Wittchen 250 zł; poradnik podaje typowy próg 200–300 zł — [Shoper Learn](https://www.shoper.pl/learn/?p=56997) [wtórne, data publikacji 27.10.2025, aktualizacja 11.02.2026]
- Wysokie koszty wysyłki to bariera dla 26% (Gemius 2025), długi czas dostawy 28% — [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers) [wtórne]
- Poradnik iFirma: próg ustawia się względem kosztów wysyłki i zwrotów oraz AOV, testując wersje AOV + 40, +60, +70 zł — [ifirma.pl](https://www.ifirma.pl/blog/darmowa-dostawa-w-e-commerce-jak-ustalic-prog-ktory-podniesie-aov-i-nie-zje-marzy/) [wtórne]
- Przewidywalność kosztów i jasne warunki dostawy ważniejsze niż lokalizacja magazynu (badanie K+ Research dla ID Logistics, kwiecień 2026, zakupy spoza UE) — [Shoper Learn](https://www.shoper.pl/learn/?p=56997) [wtórne, odniesienie niepewne]

### Inferences
- Pokazywanie kosztu dostawy przed finalizacją (w koszyku) i progu darmowej dostawy jest zgodne z dostępnymi danymi o barierach, ale bez polskiej liczby o porzuceniach [wniosek własny].

### Gaps
- Brak Gemius „E-commerce w Polsce 2026” i raportów IGE z liczbami o cenach i oczekiwaniach; brak średnich stawek kurier/Paczkomat widzianych przez klienta.
