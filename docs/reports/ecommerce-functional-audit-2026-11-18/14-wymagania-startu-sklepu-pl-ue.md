# Wymagania startu i prowadzenia sklepu B2C w PL/UE (stan: 18.11.2026) – sprzęt sportowy i outdoor, sprzedaż fizyczna

Uwaga ogólna: to nie jest porada prawna. Badanie było krótkie (ok. 19 wywołań narzędzi). Wiele liczb pochodzi ze źródeł wtórnych (blogi, portale), nie z pełnych raportów ani aktów prawnych. Takie miejsca są oznaczone „[wtórne]” albo „[niezweryfikowane]”. Przed decyzjami prawnymi potrzebny jest prawnik i sprawdzenie tekstów w ISAP i EUR-Lex.

## 1. Płatności: metody, operatorzy, minimum

### Takeaway
BLIK jest metodą dominującą (72% kupujących online według Gemius 2025), potem szybkie przelewy (64%) i karty (43%). Minimum to operator z BLIK (najlepiej z BLIK Level 0), szybkimi przelewami i kartami. BNPL, Apple/Google Pay i pobranie są dodatkiem. Brak preferowanej metody podobno odstrasza niemal co drugiego kupującego (źródło badania nieznane).

### Cited Findings
- Gemius „E-commerce w Polsce 2025” (za Przelewy24): BLIK 72%, szybkie przelewy 64% (20% używa ich najczęściej, 46% z nich woli Przelewy24), karta 43%; mężczyźni częściej wybierają pobranie i raty; 78% internautów kupuje online; 82% kupuje na smartfonie; 19% kupuje przez social media — [Przelewy24 o raporcie Gemius](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers) [wtórne, wydawca jest operatorem płatności]
- W 2024 BLIK po raz pierwszy wyprzedził szybkie przelewy (68%) — [Przelewy24 / Gemius](https://www.przelewy24.pl/en/news/ecommerce-2025-zakupy-online-trendy-polscy-konsumenci) [wtórne]. Tpay (2025) podaje, że BLIK wybiera 58% klientów, więc metodologie się różnią — [Tpay za antyweb](https://antyweb.pl/e-commerce-w-polsce-2025-gemius) [wtórne]
- BLIK Q3 2026: 822 mln transakcji (+10% r/r), 131,8 mld zł, 21,9 mln aktywnych użytkowników; e-commerce 46,7% operacji, 384,3 mln płatności online (+10%), 64 mld zł (+18%), średni koszyk 167 zł; płatności powtarzalne 2,9 mln; przez Stripe BLIK jest dostępny w sklepach w ponad 30 krajach — [bank.pl](https://bank.pl/?p=564233)
- BLIK Q1 2026: 756 mln transakcji, 117,3 mld zł; online 378,8 mln płatności (50,1% operacji) — [bank.pl](https://bank.pl/blik-utrzymuje-dynamiczne-tempo-wzrostu-w-i-kw-2026-r-ponad-750-mln-transakcji/); [komunikat BLIK](https://www.blik.com/en/over-750-million-transactions-and-nearly-pln-120-billion-in-turnover-blik-maintains-double-digit-growth-in-q1-2026). Dane z różnych kwartałów podają różne udziały (50,1%, 48,5% w H1, 46,7% w Q3) – nie porównywać wprost.
- 46% Polaków rezygnuje z zakupu z powodu braku preferowanej metody płatności; w artykule nie podano nazwy badania — [podatki.biz, 30.06.2026](https://www.podatki.biz/artykuly/46-proc-polakow-rezygnuje-z-transakcji-z-powodu-braku-preferowanej-formy-platnosci_16_62661.htm) [wtórne, źródło badania nieznane]
- Orientacyjne stawki (blogi porównawcze, 2026): Przelewy24 karty od 1,4% + 0,30 zł, BLIK 0,79% w abonamencie 50 zł/mc; Tpay karty od 1,4%, BLIK 0,99%; PayU karty od 1,6% + 0,20 zł, BLIK 0,99%, wypłaty D+1; Autopay karty 1,0–1,5%, BLIK od 0,79%; aktywacja Autopay i P24 po 49 zł brutto; realne stawki zależą od wolumenu i negocjacji — [freenance.io, ranking bramek 2026](https://freenance.io/przedsiebiorczosc/bramki-platnosci-2026-ranking-stripe-payu-przelewy24-tpay-bluemedia-porownanie/) [wtórne, niezweryfikowane w cennikach operatorów]
- Stripe udostępnia BLIK bez dodatkowej integracji — [bank.pl](https://bank.pl/?p=564233). Szczegóły P24 przez Stripe nie zostały sprawdzone w docs.stripe.com.

### Inferences
- Minimum przed pierwszą sprzedażą: jeden operator obejmujący BLIK, szybkie przelewy i karty, z webhookiem statusu płatności (idempotentnym) i API zwrotów. Obecne „symulowane” płatności to bloker.
- Zwrot jako „status” bez wywołania API operatora nie wystarczy: ustawa wymaga zwrotu pieniędzy tym samym sposobem płatności (patrz sekcja 3). Potrzebne jest wywołanie refund API operatora.
- BNPL/raty (PayPo, mRaty) i pobranie to funkcje wzrostowe. Udziałów nie zweryfikowano.

### Gaps
- Brak zweryfikowanych udziałów BNPL, pobrania, Apple/Google Pay. Brak wyników z docs.przelewy24.pl, developers.payu.com, docs.tpay.com.
- Nie sprawdzono wymagań onboardingu (KYC, dokumenty, czas) ani szczegółów BLIK Level 0 i API zwrotów u konkretnych operatorów.

## 2. Dostawa: metody, integracje

### Takeaway
Paczkomat InPost to oczekiwane minimum: 83% kupujących wybiera automaty paczkowe, a 87% z nich Paczkomaty InPost. Minimum to Paczkomat (z mapą w checkout) i kurier. Integracja idzie przez ShipX API i Geowidget albo przez brokera.

### Cited Findings
- Gemius 2025: 83% wybiera dostawę do automatu; 87% z nich InPost; 88% uznaje dostawę w 12 h za silny motywator — [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers) [wtórne]
- Wśród automatów: Paczkomaty InPost 95% wskazań, Orlen Paczka 32%, Allegro One Box 30%, DPD Pickup 23%, DHL POP BOX 19% — [antyweb o Gemius 2025](https://antyweb.pl/e-commerce-w-polsce-2025-gemius) [wtórne; interpretacja 83%/87% różni się między źródłami]
- Bariery klienta: długi czas dostawy 28%, wysokie koszty wysyłki 26% — [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers); darmowa dostawa „kluczowa dla konwersji”, bez procentu
- InPost ShipX: token i ID organizacji z Parcel Managera (do 10 tokenów); osobny token Geowidget (przypisany do domen); środowiska produkcyjne i sandbox; jeśli nie można użyć Geowidget, zasób Points w ShipX daje listę punktów — [dokumentacja InPost](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/1187086337); [przykład integracji](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/175177729)
- Pozostałe ShipX/Geowidget i platformy sklepowe używają tych samych poświadczeń — [InPost, lista integracji](https://bliskociebie.inpost.pl/en/integration)

### Inferences
- Sklep musi mieć: metody dostawy z kosztem (także wg wagi/gabarytu, np. rowery, namioty), próg darmowej dostawy, wybór punktu na mapie z zapisem ID punktu w zamówieniu, etykiety i numer śledzenia, powiadomienie o wysyłce.
- Dla sprzętu outdoor ważne są gabaryty (rozmiar A/B/C paczkomatu, ograniczenia wagowe), więc kurier jest drugą obowiązkową metodą.
- Najszybsza ścieżka: bezpośrednio ShipX + Geowidget; alternatywnie broker (Furgonetka, Apaczka, BaseLinker) dla wielu przewoźników.

### Gaps
- Nie zweryfikowano API Furgonetka, Apaczka, BaseLinker. Brak progów darmowej dostawy i średnich kosztów (rynkowych) ze źródeł. Brak udziałów kurierów, Poczty Polskiej, Pocztex.

## 3. Obowiązki prawne sprzedawcy

### Takeaway
Pewne i obowiązujące: informacje przedumowne, przycisk „zamówienie z obowiązkiem zapłaty”, 14 dni na odstąpienie, rękojmia, Omnibus, GDPR, GPSR. Funkcja odstąpienia online (dyr. 2023/2673) obowiązuje od 19.06.2026 na poziomie UE, ale polskiego aktu wdrażającego nie potwierdzono.

### Cited Findings
- Funkcja odstąpienia (nowy art. 11a dyrektywy 2011/83/UE, dyr. 2023/2673): państwa miały przyjąć przepisy do 19.12.2025 i stosować od 19.06.2026; etykieta „odstąp od umowy tutaj” lub równoważna, dostępna przez cały okres odstąpienia, widoczna — [Bezprawnik](https://bezprawnik.pl/zwrot-jednym-kliknieciem/)
- Status w Polsce: Polska nie przyjęła przepisów w terminie; w maju 2026 rząd wycofał projekt UOKiK (UC82); nowy projekt ma przejść ścieżkę od początku — [Bezprawnik](https://bezprawnik.pl/zwrot-jednym-kliknieciem/); [wyniki wyszukiwania, w tym akademialtca i infor](https://akademialtca.pl/blog/od-dzis-obowiazkowy-zwrot-jednym-kliknieciem-co-grozi-sklepom-ktore-nie-sa-gotowe). Źródła są sprzeczne: Akademia LTCA pisze, że przepisy weszły w życie 19.06.2026 (bez numeru aktu), Infor pisze, że data krajowych przepisów nie jest znana — [Infor](https://www.infor.pl/prawo/konsument-i-umowy/7612423,zwroty-online-od-19-czerwca-2026-r-na-nowych-zasadach-co-zmienia-dyrektywa-ue-20232673.html). **Status niepotwierdzony; sprawdzić ISAP i wykaz prac legislacyjnych RCL.**
- Wymogi UX według LTCA: stała, łatwa do znalezienia opcja zwrotu; bez wymogu wydruku, zbędnych danych czy zdjęć; natychmiastowe potwierdzenie na trwałym nośniku z datą, godziną i numerem; sankcje do 10% obrotu i wydłużenie terminu do 12 miesięcy przy braku zgodnej ścieżki — [Akademia LTCA](https://akademialtca.pl/blog/od-dzis-obowiazkowy-zwrot-jednym-kliknieciem-co-grozi-sklepom-ktore-nie-sa-gotowe) [wtórne, sankcje i 12 miesięcy dotyczą ogólnych zasad, nie polskiej ustawy wdrażającej]
- Powiązany projekt UC142 (nowelizacja ustawy o ochronie konkurencji i konsumentów) ma dać UOKiK zakupy kontrolne, także zdalne — [wyniki wyszukiwania](https://akademialtca.pl/blog/uokik-jako-tajemniczy-klient-co-zmieni-projekt-dla-sklepow-internetowych) [niezweryfikowane]
- Polski Akt o Dostępności (ustawa z 26.04.2024): przepisy o e-commerce stosowane od 28.06.2025; mikroprzedsiębiorcy wyłączeni z wymogów dla usług (np. interfejs sklepu), ale nie dla produktów — [Shoper](https://www.shoper.pl/learn/artykul/polski-akt-o-dostepnosci-wplynie-na-sklepy-internetowe); [gov.pl](https://www.gov.pl/web/dostepnosc-cyfrowa/polski-akt-o-dostepnosci--uslugi-handlu-elektronicznego). Definicja mikroprzedsiębiorcy (progi) nie zweryfikowana w tekście ustawy.
- GPSR (rozp. 2023/988) stosowane od 13.12.2024; w ofercie online trzeba podać dane z art. 19 GPSR obok informacji z art. 12 ustawy o prawach konsumenta; sprzedawca sprowadzający towar spoza UE jest importerem; polska ustawa z 7.11.2025 o nadzorze nad bezpieczeństwem produktów weszła w życie 3.01.2026, z wyższymi karami i nakazami usunięcia treści z interfejsu — [prawo.pl](https://www.prawo.pl/biznes/rozporzadzenie-gpsr-obowiazki-dla-firm-i-sklepow-internetowych,530380.html); [prawo.pl o ustawie](https://www.prawo.pl/biznes/ustawa-gpsr-zmiany-w-nadzorze-nad-bezpieczenstwem-produktow,536366.html); [PARP](https://redakcja.parp.gov.pl/attachments/article/89363/PARP_GPSR_webinar_3009.pdf)

### Inferences
- Nawet bez polskiej ustawy bezpieczne jest wdrożenie widocznej funkcji odstąpienia w UI (przycisk widoczny z zamówienia i w stopce, forma elektroniczna bez logowania dla gości, potwierdzenie e-mailem z datą i godziną). Dyrektywa nie ma bezpośredniego skutku horyzontalnego wobec przedsiębiorcy w sporze prywatnym, ale to ryzyko reputacyjne i regulacyjne [wniosek własny].
- Sprzęt sportowy i outdoor może podlegać dodatkowym rygorom GPSR/PPE (np. kaski, uprzęże); trzeba dodać na karcie produktu dane producenta/osoby odpowiedzialnej, identyfikator partii i ostrzeżenia [wniosek własny].
- Dla własnej platformy luka to CMS: regulamin, polityka prywatności, polityka cookies, pouczenie o odstąpieniu i wzór formularza, informacje o reklamacjach i dane sprzedawcy wymagają stron treści lub konfigurowalnych bloków.

### Gaps (nie zweryfikowano w tym badaniu, wymaga sprawdzenia w ISAP/EUR-Lex/UOKiK)
- Dokładne brzmienie przycisku zamówienia (art. 8 ust. 2 ustawy o prawach konsumenta: „zamówienie z obowiązkiem zapłaty”), lista informacji przedumownych (art. 12), termin zwrotu 14 dni z kosztem dostawy (art. 33), potwierdzenie umowy na trwałym nośniku, rękojmia (odpowiedź na reklamację w 14 dni: art. 7a ustawy o prawach konsumenta) – znam je z wiedzy ogólnej, bez cytowanego źródła w tym badaniu.
- Omnibus (30 dni najniższej ceny, informacje o weryfikacji opinii, personalizacja cen), RODO i cookies (Prawo komunikacji elektronicznej 2024), DSA dla małych sklepów, likwidacja platformy ODR (wyszukiwanie nie potwierdziło daty 20.07.2025), BDO/opakowania – brak źródeł.

## 4. Fakturowanie i podatki

### Takeaway
Dla sprzedaży B2C wysyłkowej płaconej przelewem/bramką paragon z kasy zwykle nie jest wymagany, ale płatność przy pobraniu i odbiór osobisty komplikują sprawę. KSeF nie obejmuje faktur B2C obligatoryjnie, ale faktury dla firm (NIP) będą wymagały KSeF.

### Cited Findings
- KSeF – harmonogram: 1.02.2026 duże podmioty (obrót 2024 powyżej 200 mln zł), 1.04.2026 pozostali podatnicy VAT, 1.01.2027 najmniejsi (mikro, limit 10 000 zł/mc) – według Fakturowni 1.01.2027 jest „rozważane”; kary od 2027 (niektóre od lipca 2027) według innych źródeł — [Fakturownia](https://www.fakturownia.pl/krajowy-system-e-faktur/zmiany-w-harmonogramie-wdrozenia-ksef-co-to-oznacza-dla-polskich-przedsiebiorstw); [wyniki wyszukiwania (mBank, infakt, jpk.info.pl)](https://www.mbank.pl/artykuly/ksef-harmonogram/) [wtórne]
- Faktury B2C pozostają poza obowiązkowym KSeF, sprzedaż dla konsumentów nie wlicza się do limitu 10 000 zł (źródła zgodne, wtórne) — [jpk.info.pl](https://jpk.info.pl/faktury/e-faktura-ustrukturyzowana-ksef/dla-kogo/)
- Oficjalna strona KSeF (ostatnia zmiana 15.10.2026) odsyła do podstron „Konsumenci i osoby fizyczne” i „Do 10 000 zł miesięcznie”, ale ich treści nie pobrano — [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl/)
- Zwolnienie z kasy przy sprzedaży wysyłkowej: zależy od zapłaty w całości przelewem/na rachunek i identyfikacji nabywcy; gotówka (np. pobranie) i odbiór osobisty wyłączają zwolnienie — [poradnikprzedsiebiorcy.pl](https://poradnikprzedsiebiorcy.pl/-sprzedaz-przez-internet-a-kasa-fiskalna); [fakturownia.pl](https://fakturownia.pl/firma-w-praktyce/sprzedaz-internetowa-a-kasa-fiskalna-co-musisz-wiedziec) [wtórne; podstawa: rozporządzenie MF z 17.12.2024, Dz.U. 2024 poz. 1902, niezweryfikowane]

### Inferences
- Platforma musi mieć co najmniej: dane do faktury (NIP, firma) w checkout, wystawianie faktury na żądanie i integrację z systemem fakturowym (Fakturownia/wFirma/iFirma/inFakt) albo własny moduł; od 2027 faktury B2B w KSeF zwykle przez taką integrację.
- Pobranie i odbiór w punkcie mogą wymagać kasy/paragonu; to argument, by na start pominąć pobranie.

### Gaps
- Nie zweryfikowano: dokładnych dat i definicji KSeF w ustawie, VAT OSS i progu 10 000 EUR sprzedaży wysyłkowej, terminu wystawienia faktury, stosowania fakturowania przez sklepy w praktyce (integracje vs wbudowane).

## 5. Komunikacja transakcyjna

### Takeaway
Potwierdzenie zamówienia mailem już istnieje w platformie. Brakuje powiadomień o płatności, wysyłce z numerem śledzenia oraz potwierdzenia odstąpienia/zwrotu.

### Cited Findings
- Funkcja odstąpienia wymaga potwierdzenia na trwałym nośniku z datą, godziną i numerem — [Akademia LTCA](https://akademialtca.pl/blog/od-dzis-obowiazkowy-zwrot-jednym-kliknieciem-co-grozi-sklepom-ktore-nie-sa-gotowe)
- 88% kupujących uznaje dostawę w 12 h za silny motywator — [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers)

### Inferences
- Pozostałe e-maile (płatność, wysyłka z linkiem śledzenia, zwrot) wynikają z oczekiwań klienta i praktyki, nie ze zweryfikowanego przepisu w tym badaniu.

### Gaps
- Brak źródeł o oczekiwaniach co do SMS/powiadomień push i o ustawowych wymogach potwierdzenia umowy.

## 6. Rynek polskiego e-commerce

### Takeaway
Rynek jest duży i rośnie (BLIK online +18% wartościowo), ale dane o podziale platform i udziale Allegro nie zostały znalezione.

### Cited Findings
- Liczba e-sklepów: ok. 75 tys. według D&B przytaczanego przez PAP (dane starsze); ponad 2,4 tys. nowych sklepów w ostatnich 12 miesiącach (rp.pl) — [PAP](https://www.pap.pl/mediaroom/w-polsce-rejestrowanych-jest-srednio-20-nowych-sklepow-internetowych-dziennie); [rp.pl](https://pro.rp.pl/raporty-ekonomiczne/art43658281-przybylo-sklepow-w-polsce-po-raz-pierwszy-od-18-lat) [wtórne, nieznana data danych]
- Średnio 575 zł miesięcznie wydaje Polak online; 41% internautów kupuje na platformach zagranicznych (Temu, AliExpress, Amazon); 53% używa chatbotów/AI w zakupach — [Przelewy24](https://www.przelewy24.pl/en/news/ecommerce-2025-online-shopping-trends-polish-consumers) [wtórne]
- Przyczyny porzucania koszyków (globalne, Baymard, ze źródeł wtórnych): dodatkowe koszty 39–48%, wymuszone zakładanie konta ok. 18–26%, średni wskaźnik porzucenia ok. 70% — [Baymard](https://baymard.com/blog/reduce-cart-abandonment); [Clerk.io](https://clerk.io/blog/cart-abandonment-stats) [wtórne; nie dane polskie]

### Inferences
- Wymuszona rejestracja i niespodziewane koszty dostawy są potwierdzonymi barierami (dane ogólne); platforma ma checkout dla gości, co jest zaletą.

### Gaps
- Nie znaleziono: podziału SaaS/open source/własne, udziału Allegro w sprzedaży, typowego stosu narzędzi (BaseLinker, Ceneo, Google Merchant), powodów wyboru/opuszczania platform, polskich wskaźników porzuceń koszyka (poza fragmentami Gemius).

## 7. Listy kontrolne „przed startem” z autorytatywnych źródeł

### Takeaway
Nie znaleziono w tym badaniu kompletnej listy UOKiK/PARP/biznes.gov.pl/e-Izby. Warto je przeszukać osobno.

### Cited Findings
- PARP prowadzi materiały o GPSR (webinar) — [PARP](https://redakcja.parp.gov.pl/attachments/article/89363/PARP_GPSR_webinar_3009.pdf)
- gov.pl ma stronę o Polskim Akcie o Dostępności w e-commerce — [gov.pl](https://www.gov.pl/web/dostepnosc-cyfrowa/polski-akt-o-dostepnosci--uslugi-handlu-elektronicznego)

### Inferences
- Wstępna klasyfikacja do audytu (wniosek własny, do weryfikacji): **MUST przed pierwszą sprzedażą:** realny operator płatności z BLIK/przelewami/kartą, webhooki i zwroty przez API; metody dostawy z kosztami i wyborem Paczkomatu; regulamin i strony informacyjne (CMS), polityka prywatności i cookies; przycisk „zamówienie z obowiązkiem zapłaty”; formularz i funkcja odstąpienia; obsługa reklamacji; dane GPSR na karcie produktu; potwierdzenia e-mail (zamówienie, płatność, wysyłka, zwrot); dokumenty sprzedaży (faktura na żądanie/NIP). **Ważne dla wzrostu:** integracja fakturowa, BaseLinker/feed Ceneo i Google Merchant, BNPL, Apple/Google Pay, e-maile porzuconych koszyków, śledzenie przesyłek. **Opcjonalne:** pobranie, wiele przewoźników.

### Gaps
- Brak listy z UOKiK, PARP, biznes.gov.pl lub IGE do zacytowania.
