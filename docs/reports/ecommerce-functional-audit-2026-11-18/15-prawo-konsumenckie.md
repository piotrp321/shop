# Uzupełnienie: obowiązki konsumenckie sklepu B2C (towary fizyczne) w Polsce, stan na 18.11.2026

Uwaga ogólna: to nie jest porada prawna. Cytaty artykułów pochodzą z tekstów pobranych ze źródeł państwowych (ISAP/ELI API Sejmu, Dziennik Ustaw) i wyodrębnionych z PDF lokalnie. Tam, gdzie nie udało się pobrać tekstu aktu (EUR-Lex, część PDF), oznaczono to „[niezweryfikowane w tekście aktu]” i podano źródło wtórne. Tekst jednolity ustawy o prawach konsumenta użyty poniżej: Dz.U. 2026 poz. 1244 (obwieszczenie Marszałka Sejmu z 4.09.2026), stan na 29.10.2026 — PDF: [ISAP D20140827Lj.pdf](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf); metadane aktu: [api.sejm.gov.pl/eli/acts/DU/2014/827](https://api.sejm.gov.pl/eli/acts/DU/2014/827) (wskazują teksty jednolite m.in. DU/2026/1244, ostatnia zmiana w bazie 29.10.2026). Skrót „UPK” = ustawa z 30.05.2014 o prawach konsumenta.

## 1. Przycisk zamówienia (art. 17 UPK)

### Takeaway
Art. 17 ust. 3 UPK wymaga, by przycisk składania zamówienia był czytelnie oznaczony „zamówienie z obowiązkiem zapłaty” lub równoważnym jednoznacznym sformułowaniem; ust. 4 stanowi, że przy niespełnieniu wymogów z ust. 2 lub 3 umowa nie zostaje zawarta. Obowiązuje od 2014 r. (nie jest nowością) i jest bezwzględnie obowiązkowy dla checkoutu.

### Cited Findings
- Art. 17 ust. 1: przy umowie zawieranej na odległość środkami komunikacji elektronicznej, która nakłada obowiązek zapłaty, przedsiębiorca dostarcza „w sposób jasny i widoczny, bezpośrednio przed złożeniem zamówienia” informacje z art. 12 ust. 1 pkt 1, 5, 16 i 17 (główne cechy świadczenia, łączna cena z podatkami i kosztami transportu/dostawy, czas trwania umowy, minimalny czas zobowiązań); od 27.09.2027 dojdzie pkt 14a (gwarancja trwałości producenta) — [UPK, tekst jedn. Dz.U. 2026 poz. 1244](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 17 ust. 2: przedsiębiorca zapewnia, aby konsument w momencie składania zamówienia „wyraźnie potwierdził”, że wie, iż zamówienie pociąga za sobą obowiązek zapłaty — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 17 ust. 3: przycisk lub podobna funkcja oznaczone „w łatwo czytelny sposób słowami »zamówienie z obowiązkiem zapłaty« lub innego równoważnego jednoznacznego sformułowania” — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 17 ust. 4: „Jeżeli przedsiębiorca nie spełnia wymagań określonych w ust. 2 lub 3, umowa nie zostaje zawarta.” — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 18: na stronach handlu elektronicznego wskazuje się wyraźnie, najpóźniej na początku składania zamówienia, informacje o ograniczeniach dostarczania i akceptowanych sposobach płatności — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 24: ciężar dowodu spełnienia obowiązków informacyjnych z art. 12 spoczywa na przedsiębiorcy (czyli sklep powinien logować, jaka treść była pokazana w danym zamówieniu) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- UI: etykieta przycisku końcowego kroku checkoutu to np. „Zamawiam i płacę” NIE jest dosłownym wzorcem; bezpieczne jest „Zamówienie z obowiązkiem zapłaty”. „Równoważne jednoznaczne sformułowanie” zostawia margines, ale przy wątpliwościach lepiej użyć ustawowego brzmienia [wniosek własny]. Przycisk nie może mieć tylko ikony ani samego „Dalej”/„Zamawiam”.
- Przed przyciskiem muszą być widoczne: nazwa/cechy towaru, łączna cena z podatkami i kosztami dostawy (podsumowanie koszyka z dostawą), a dla zwykłej sprzedaży jednorazowej pkt 16–17 zwykle nie dotyczą. To wymaga funkcji „podsumowanie zamówienia z kosztem dostawy przed płatnością” (patrz sekcja 2).
- Skutek niezgodności: brak zawarcia umowy (art. 17 ust. 4) — sprzedawca nie ma skutecznego roszczenia o zapłatę/odbiór, a płatności trzeba byłoby zwrócić. Do tego mogą dojść działania UOKiK za nieuczciwą praktykę rynkową (zaniechanie wprowadzające w błąd; art. 6 ust. 2 UPNPR: za istotne uznaje się informacje, które przedsiębiorca musi podać na podstawie odrębnych przepisów) — [UPNPR, tekst jedn. Dz.U. 2023 poz. 845](https://api.sejm.gov.pl/eli/acts/DU/2023/845/text/T/D20230845L.pdf). Kwoty kar UOKiK nie weryfikowano.
- Dowód: warto zapisywać w zamówieniu wersję regulaminu i treść pokazanych informacji (art. 24).

### Gaps
- Nie sprawdzono orzecznictwa/decyzji UOKiK dotyczących konkretnych etykiet przycisku (np. „Kupuję i płacę”).

## 2. Informacje przedumowne (art. 12 UPK) — co wymaga funkcji platformy

### Takeaway
Art. 12 ust. 1 UPK ma 23 punkty; dla sklepu z towarami fizycznymi kluczowe są pkt 1–5, 7–12, 13–14 i 21. Dedykowanych funkcji wymagają: widoczne dane sprzedawcy, koszt dostawy i łączna cena przed zamówieniem, statyczne strony (regulamin, reklamacje, odstąpienie z wzorem formularza), oraz (od 27.09.2027) harmonizowana informacja o zgodności towaru z umową.

### Cited Findings
- Art. 12 ust. 1 (obowiązujące brzmienie) — informacje „najpóźniej w chwili wyrażenia przez konsumenta woli związania się umową”, „w sposób jasny i zrozumiały”: pkt 1 główne cechy świadczenia; pkt 2 dane identyfikujące (firma, organ rejestrujący, numer rejestracji); pkt 3 adres przedsiębiorstwa, e-mail, numer telefonu; pkt 3a dodatkowy środek komunikacji online (jeśli oferowany); pkt 4 adres do reklamacji, jeśli inny; pkt 5 łączna cena z podatkami oraz „opłaty za transport, dostarczenie, usługi pocztowe oraz inne koszty”, a gdy nie da się ich ustalić — informacja o obowiązku ich uiszczenia; pkt 5a indywidualne dostosowanie ceny na podstawie zautomatyzowanego podejmowania decyzji (jeśli stosowane); pkt 7 sposób i termin zapłaty; pkt 8 sposób i termin spełnienia świadczenia oraz „stosowana procedura rozpatrywania reklamacji”; pkt 9 sposób i termin wykonania prawa odstąpienia oraz wzór formularza odstąpienia (załącznik nr 2); pkt 10 koszty zwrotu towaru ponoszone przez konsumenta (także gdy towar nie może być odesłany pocztą); pkt 12 brak prawa odstąpienia lub okoliczności jego utraty; pkt 13 odpowiedzialność za zgodność świadczenia z umową; pkt 14 gwarancje i usługi posprzedażne; pkt 21 pozasądowe sposoby rozpatrywania reklamacji i dochodzenia roszczeń — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 14 ust. 2: informacje podaje się „w sposób odpowiadający rodzajowi użytego środka porozumiewania się na odległość, w sposób czytelny i wyrażonych prostym językiem” — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 22: informacje z art. 12 ust. 1 są integralną częścią umowy i zmienia się je tylko za wyraźnym porozumieniem stron — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 23: jeżeli przedsiębiorca nie spełnił obowiązków informacyjnych o opłatach dodatkowych lub innych kosztach (art. 12 ust. 1 pkt 5) albo o kosztach zwrotu towaru (pkt 10), konsument nie ponosi tych opłat i kosztów — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 29 ust. 1: brak poinformowania o prawie odstąpienia wydłuża je — prawo wygasa po 12 miesiącach od upływu zwykłego terminu 14 dni — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 34 ust. 4: konsument odpowiada za zmniejszenie wartości towaru przez nadmierne używanie, „chyba że przedsiębiorca nie poinformował konsumenta o prawie odstąpienia” zgodnie z art. 12 ust. 1 pkt 9 — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Zmiany z ustawy z 4.09.2026 (Dz.U. 2026 poz. 1353, ogłoszona 19.10.2026, wdraża dyrektywę 2024/825): art. 3 — „Ustawa wchodzi w życie z dniem 27 września 2027 r.”. Dotyczy m.in. nowego brzmienia art. 12 ust. 1 pkt 13 (konieczność wskazania głównych warunków odpowiedzialności za zgodność towaru, w tym okresu co najmniej 2 lat, „w widoczny sposób, z wykorzystaniem zharmonizowanej informacji”), pkt 14, 14a (gwarancja trwałości producenta), 20a, 22, 23 (ocena naprawialności, części zamienne, instrukcje napraw — jeśli udostępni producent; ust. 4) oraz art. 17 ust. 1 (dodany pkt 14a) i art. 7ac (zharmonizowane oznakowanie gwarancji trwałości) — [Dz.U. 2026 poz. 1353 (PDF z API Sejmu)](https://api.sejm.gov.pl/eli/acts/DU/2026/1353/text/O/D20261353.pdf); adnotacje o dacie 27.09.2027 w [tekście jedn. UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 12a UPK (dodatkowe informacje) dotyczy dostawcy internetowej platformy handlowej, nie sklepu własnego — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 19: gdy środek komunikacji ogranicza rozmiar informacji (np. ekran mobilny), przed zawarciem umowy podaje się co najmniej główne cechy, oznaczenie sprzedawcy, łączną cenę, prawo odstąpienia (reszta wg art. 14 ust. 2) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- Wymagane funkcje platformy (mapowanie): (a) dane sprzedawcy (pkt 2–4, 3a) — konfigurowalny blok/strona + stopka, widoczny w checkoucie; (b) koszt dostawy liczony i pokazany przed kliknięciem „zamówienie z obowiązkiem zapłaty” (pkt 5, art. 23 — bez tego sklep nie może obciążyć klienta kosztem, którego nie pokazał); (c) metody i terminy płatności i dostawy (pkt 7–8; art. 18 ograniczenia dostaw „najpóźniej na początku składania zamówienia”) — termin dostawy per metoda; (d) strony statyczne/CMS: regulamin, procedura reklamacyjna, pouczenie o odstąpieniu z wzorem formularza (załącznik nr 2), informacja o koszcie zwrotu (pkt 9–10), pozasądowe rozstrzyganie sporów (pkt 21), rękojmia/gwarancja (pkt 13–14); (e) wyjątki odstąpienia na karcie produktu, gdy dotyczy (pkt 12; np. towary na wymiar, zapieczętowane higieniczne).
- Dla sprzętu sportowego: art. 38 ust. 1 pkt 3 (towar nieprefabrykowany, wg specyfikacji konsumenta, np. dopasowany/personalizowany) i pkt 5 (zapieczętowane opakowania ze względów higienicznych) mogą mieć znaczenie przy personalizacji; to wymaga pola „wyłączone z odstąpienia” w modelu produktu [wniosek własny].
- Od 27.09.2027 produkt/kategoria będą potrzebować pola na „zharmonizowaną informację o zgodności towaru z umową” (okres ≥ 2 lata) oraz opcjonalnie na gwarancję trwałości producenta i dane o naprawialności. Do tego czasu: pkt 13 w obecnym brzmieniu = zwykła informacja o odpowiedzialności za zgodność z umową (rękojmia/odpowiedzialność sprzedawcy), bez harmonizowanej etykiety. Szczegółowy wzór harmonizowanej informacji (rozporządzenie wykonawcze UE) nie był weryfikowany.

### Gaps
- Dyrektywa 2024/825 wymaga według mojej wiedzy stosowania od 27.09.2026; polska ustawa ma datę 27.09.2027 — rozbieżność dat nie została wyjaśniona w źródłach (tekst polski jest autorytatywny dla Polski: art. 3 poz. 1353). Nie zweryfikowano w EUR-Lex.
- Szczegółowa treść zmian art. 7a (poz. 1353) nie została odczytana; w adnotacjach tekstu jednolitego jest tylko informacja o dacie 27.09.2027.

## 3. Potwierdzenie umowy na trwałym nośniku (art. 21 UPK)

### Takeaway
Art. 21 ust. 1 UPK: sprzedawca przekazuje potwierdzenie zawarcia umowy na odległość na trwałym nośniku (zwykle e-mail z treścią w treści lub PDF) „w rozsądnym czasie po jej zawarciu, najpóźniej w chwili dostarczenia towaru”. Potwierdzenie ma zawierać informacje z art. 12 ust. 1, chyba że były wcześniej dostarczone na trwałym nośniku.

### Cited Findings
- Art. 21 ust. 1: potwierdzenie obejmuje (1) informacje z art. 12 ust. 1, „chyba że przedsiębiorca dostarczył konsumentowi te informacje na trwałym nośniku przed zawarciem umowy”, oraz (2) informację o zgodzie konsumenta na dostarczenie treści cyfrowych w okolicznościach powodujących utratę prawa odstąpienia (dla towarów fizycznych nieistotne) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 30 ust. 4: przedsiębiorca „niezwłocznie” przesyła na trwałym nośniku potwierdzenie otrzymania oświadczenia o odstąpieniu złożonego drogą elektroniczną (art. 30 ust. 3) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 7a ust. 3: odpowiedź na reklamację na papierze lub innym trwałym nośniku — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- E-mail potwierdzający zamówienie (wysyłany bezpośrednio po zakupie): numer i data zamówienia; pozycje (cechy), ceny jednostkowe i łączna cena z VAT; koszt i metoda dostawy, termin dostawy; metoda i termin zapłaty; dane sprzedawcy (pkt 2–4); procedura reklamacyjna i informacje o rękojmi/gwarancji; pouczenie o odstąpieniu z terminem, sposobem, kosztami zwrotu i wzorem formularza (jako załącznik PDF lub w treści/odnośnik do treści, ale odnośnik do strony nie spełnia bezpiecznie wymogu trwałego nośnika — ryzyko interpretacyjne) oraz wyłączenia; informacja o ADR. Najbezpieczniej dołączyć regulamin (PDF) i pouczenie jako załączniki lub w treści [wniosek własny; art. 21 mówi o „informacjach z art. 12 ust. 1” bez wskazania techniki].
- Potwierdzenie można też rozdzielić: informacje przedumowne na trwałym nośniku przed zawarciem (np. regulamin w PDF wysłany po koszyku) nie wymagają powtórzenia — ale to komplikuje przepływ; prościej wysyłać całość w mailu po zamówieniu.

### Gaps
- Nie sprawdzono komentarzy UOKiK, czy odnośnik do regulaminu na stronie spełnia wymóg trwałego nośnika. [Znana z wiedzy ogólnej wykładnia: link do strony zmiennej nie jest trwałym nośnikiem — niezweryfikowane.]

## 4. Odstąpienie od umowy 14 dni (art. 27–38 UPK)

### Takeaway
Konsument ma 14 dni na odstąpienie bez podania przyczyny, od objęcia towaru w posiadanie (art. 27–28). Sklep zwraca wszystkie płatności (łącznie z kosztem dostarczenia) w ciągu 14 dni od otrzymania oświadczenia, tym samym sposobem płatności (art. 32); koszt dostawy zwraca tylko do najtańszego zwykłego sposobu oferowanego (art. 33). Koszt odesłania ponosi konsument, jeśli został o tym poinformowany (art. 34 ust. 2).

### Cited Findings
- Art. 27 ust. 1: 14 dni, „bez ponoszenia kosztów, z wyjątkiem kosztów określonych w art. 33, art. 34 ust. 2 i art. 35” — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 28 pkt 1: bieg terminu od objęcia towaru w posiadanie przez konsumenta lub wskazaną osobę trzecią inną niż przewoźnik; przy wielu towarach dostarczanych osobno — od objęcia ostatniego towaru/partii — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 30 ust. 1–2: oświadczenie o odstąpieniu (można na formularzu z załącznika nr 2); do zachowania terminu wystarcza wysłanie przed jego upływem — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 30 ust. 3 (dziś dobrowolne): „Jeżeli przedsiębiorca zapewnia możliwość złożenia oświadczenia o odstąpieniu od umowy drogą elektroniczną”, konsument może odstąpić przy użyciu wzoru formularza lub przez oświadczenie na stronie internetowej — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 31 ust. 1: odstąpienie = umowę uważa się za niezawartą — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 32 ust. 1: zwrot „niezwłocznie, nie później niż w terminie 14 dni od dnia otrzymania oświadczenia” wszystkich płatności „w tym koszty dostarczenia towaru”; ust. 2: tym samym sposobem zapłaty (chyba że konsument wyraźnie zgodził się na inny, bez kosztów); ust. 3: jeśli sklep nie zaproponował odbioru, może wstrzymać zwrot do otrzymania towaru lub dowodu odesłania (co nastąpi wcześniej) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 33: jeśli konsument wybrał dostawę inną niż „najtańszy zwykły sposób dostarczenia oferowany przez przedsiębiorcę”, sklep nie zwraca dodatkowych kosztów — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf). (Uwaga: w zadaniu wskazano art. 32 jako podstawę „najtańszego” kosztu; w tekście jednolitym limit najtańszej dostawy jest w art. 33, a 14-dniowy termin zwrotu w art. 32.)
- Art. 34 ust. 1: konsument odsyła towar niezwłocznie, nie później niż 14 dni od odstąpienia (chyba że sklep sam odbiera); ust. 2: konsument ponosi „tylko bezpośrednie koszty zwrotu”, chyba że sklep zgodził się je ponieść lub nie poinformował o konieczności ich poniesienia (art. 12 ust. 1 pkt 10, art. 23); ust. 4: odpowiedzialność za zmniejszenie wartości przez używanie ponad konieczne do sprawdzenia towaru — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 38 ust. 1 (wyjątki, wybrane dla towarów): pkt 3 towar nieprefabrykowany, wg specyfikacji konsumenta lub zindywidualizowany; pkt 4 szybko psujący się; pkt 5 zapieczętowane opakowanie niemożliwe do zwrotu ze względów higienicznych/ochrony zdrowia po otwarciu; pkt 6 towary nierozłącznie połączone z innymi; pkt 9 nagrania/programy w zapieczętowanym opakowaniu po otwarciu; pkt 10 dzienniki/czasopisma (poza prenumeratą) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Załącznik nr 2 UPK: wzór formularza odstąpienia (adresat — dane przedsiębiorcy; informacja o odstąpieniu od umowy sprzedaży towarów; data zawarcia umowy/odbioru; imię i nazwisko oraz adres konsumenta; podpis tylko w wersji papierowej; data) — [UPK, załącznik 2](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- Funkcje platformy: pole kosztu zwrotu w regulaminie i mailu (jeśli konsument ponosi); zwrot płatności przez API operatora tym samym sposobem (patrz poprzednie notatki), termin 14 dni liczony od otrzymania oświadczenia (nie od otrzymania paczki, o ile sklep nie wstrzymał zwrotu wg ust. 3); zwrot kosztu dostawy do poziomu najtańszej metody — system musi znać „najtańszą zwykłą metodę” i wyliczyć zwracaną kwotę dostawy; status „odstąpienie” w zamówieniu z datami.
- Gdy sklep nie poda w pouczeniu kosztów zwrotu, ponosi je sam (art. 34 ust. 2 + art. 23); gdy nie poinformuje o prawie odstąpienia — 12 miesięcy dodatkowo (art. 29).

### Gaps
- Nie weryfikowano wykładni UOKiK co do zwrotu kosztów dostawy przy częściowym zwrocie zamówienia.

## 5. Funkcja odstąpienia online (dyrektywa (UE) 2023/2673, art. 11a dyr. 2011/83/UE) — status w Polsce

### Takeaway
Na 18.11.2026 w Polsce NIE obowiązuje akt wdrażający art. 11a: ujednolicony tekst UPK (Dz.U. 2026 poz. 1244, stan na 29.10.2026) nie zawiera obowiązkowej funkcji odstąpienia; art. 30 ust. 3 UPK zostaje dobrowolny. Rządowy projekt UC82 wycofano (maj/czerwiec 2026), nowy projekt UC169 (nowelizacja UPK i ustawy o konsumenckiej pożyczce lombardowej) został opublikowany na RCL we wrześniu 2026 i według źródeł wtórnych jest na etapie konsultacji/uzgodnień; nie ma ustawy ani daty wejścia w życie. Dyrektywa wymagała stosowania od 19.06.2026, a Polska jest w zwłoce.

### Cited Findings
- Dyrektywa 2023/2673 (22.11.2023) dodaje m.in. art. 11a do dyrektywy 2011/83/UE; UOKiK na stronie BIP opisuje wymagania: przedsiębiorca oferujący umowy na odległość przez interfejs internetowy musi zapewnić funkcję odstąpienia oznaczoną „odstąp od umowy tutaj” lub równoważnie, stale dostępną i wyraźnie widoczną przez cały okres na odstąpienie; wymóg dotyczy wszystkich umów na odległość, nie tylko usług finansowych; strona z komunikatem z 26.11.2024 (zmiana 3.07.2026) wskazuje, że trwały prekonsultacje, bez projektu ustawy i harmonogramu — [UOKiK BIP](https://uokik.gov.pl/bip/implementacja-dyrektywy-parlamentu-europejskiego-i-rady-ue-2023-2673)
- Proces dwuetapowy (wypełnienie formularza, potem potwierdzenie woli) i brak bezpośredniej skuteczności dyrektywy wobec przedsiębiorców do czasu wejścia w życie przepisów krajowych — [PARP „One click return”](https://www.parp.gov.pl/component/content/article/91021:one-click-return-czyli-zwroty-w-e-commerce-po-nowemu) (opis z wyników wyszukiwania; strony nie udało się pobrać w całości) [wtórne/urzędowe]
- Obecny tekst jednolity UPK (stan 2026-10-29): art. 30 ust. 3 — możliwość elektronicznego oświadczenia jest dobrowolna („Jeżeli przedsiębiorca zapewnia…”); brak przepisu o obowiązkowej funkcji i o oznaczeniu „odstąp od umowy tutaj”; jedyna zmiana oczekująca na wejście w życie to ustawa z 4.09.2026 (poz. 1353) od 27.09.2027, dotycząca innych kwestii (dyrektywa 2024/825) — [UPK, tekst jedn. Dz.U. 2026 poz. 1244](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf); [Dz.U. 2026 poz. 1353](https://api.sejm.gov.pl/eli/acts/DU/2026/1353/text/O/D20261353.pdf)
- Projekt UC169 (Ministerstwo Finansów; nowelizacja UPK i ustawy o konsumenckiej pożyczce lombardowej): opublikowany na RCL; przekazany do konsultacji i uzgodnień we wrześniu 2026 (artykuł z 18.09.2026); Akademia LTCA (11.09.2026): projekt nie trafił jeszcze pod obrady Rady Ministrów, możliwe skierowanie w I kw. 2027, brak daty wejścia w życie w opisie — [Bezprawnik, 18.09.2026](https://bezprawnik.pl/nowy-obowiazek-dla-sklepow-internetowych/); [Akademia LTCA](https://akademialtca.pl/blog/odstap-od-umowy-tutaj-czyli-co-nowego-szykuje-ustawodawca-dla-sklepow-internetowych) [wtórne]
- UC82 wycofany/wstrzymany, nowa wersja zapowiedziana (stan na 12.06.2026) — [CMS Poland, wyniki wyszukiwania](https://cms.law/en/gbr/legal-updates/consumer-sales-in-2026-new-obligations-for-e-commerce-and-brick-and-mortar-shops-in-poland); [Bezprawnik](https://bezprawnik.pl/zwrot-jednym-kliknieciem/) [wtórne]
- Wymogi funkcji (zgodnie z opisem UOKiK/PARP): etykieta „odstąp od umowy tutaj” (lub równoważna), widoczna i dostępna przez cały okres odstąpienia; potwierdzenie na trwałym nośniku z treścią oraz datą i godziną (opis: [Akademia LTCA](https://akademialtca.pl/blog/od-dzis-obowiazkowy-zwrot-jednym-kliknieciem-co-grozi-sklepom-ktore-nie-sa-gotowe) [wtórne])

### Inferences
- Wiążące brzmienie art. 11a (w tym drugi przycisk „potwierdź odstąpienie” i dane do wypełnienia w funkcji — imię i nazwisko, identyfikacja umowy, środek do potwierdzenia) pochodzi z mojej znajomości dyrektywy i z opisu UOKiK; samego tekstu EUR-Lex nie udało się pobrać, więc szczegóły [niezweryfikowane w tekście aktu].
- Praktyczne podejście: traktować funkcję odstąpienia jako „mandatory-by-direction” (stan prawny: obowiązek krajowy jeszcze nie istnieje, ale ustawa jest oczekiwana, a zgodność z art. 30 ust. 3 UPK już dziś jest możliwa). Wdrożenie widocznej funkcji (przycisk w stopce i w koncie/zamówieniu, formularz dla gości, drugi krok potwierdzenia, e-mail z datą i godziną) kosztuje niewiele i jest zgodne z obecnym art. 30 ust. 3–4 UPK (obowiązek niezwłocznego potwierdzenia na trwałym nośniku jest już dziś w art. 30 ust. 4, jeśli sklep oferuje odstąpienie online). To jest wniosek własny, nie stanowisko UOKiK.
- Klasyfikacja do audytu: „Obowiązkowe od dnia wejścia w życie ustawy implementującej (brak daty; UC169)”; dla pierwszej sprzedaży „zalecane”, a obowiązkowe jest już dziś: formularz/wzór odstąpienia (art. 12 ust. 1 pkt 9) i, jeśli sklep oferuje odstąpienie online, potwierdzenie na trwałym nośniku (art. 30 ust. 4).

### Gaps
- Nie udało się wejść na stronę projektu UC169 w legislacja.rcl.gov.pl (strona dynamiczna; wyszukiwanie zwróciło tylko źródła wtórne), więc numer druku, data wpisu do wykazu prac i etap są niepotwierdzone w RCL. Sprawdzić: legislacja.gov.pl > wykaz prac legislacyjnych Rady Ministrów > UC169; sejm.gov.pl po skierowaniu do Sejmu.
- Nie znaleziono komunikatu UOKiK o egzekwowaniu funkcji odstąpienia przed wejściem w życie ustawy.
- Nie pobrano EUR-Lex: dokładnych sformułowań art. 11a i art. 2 dyr. 2023/2673 (data stosowania 19.06.2026 wynika z zadania i źródeł wtórnych).

## 6. Reklamacje z tytułu niezgodności towaru z umową (art. 7a, 43a–43g UPK)

### Takeaway
Od 1.01.2023 (nowy rozdział 5a UPK) sklep odpowiada za brak zgodności towaru z umową istniejący w chwili dostarczenia i ujawniony w ciągu 2 lat; konsument może żądać naprawy lub wymiany, a następnie obniżenia ceny lub odstąpienia. Na reklamację sklep odpowiada w 14 dni (brak odpowiedzi = uznanie reklamacji, art. 7a). Rozdział 5a wyłącza przepisy Kodeksu cywilnego o rękojmi dla umów z konsumentem.

### Cited Findings
- Art. 7a ust. 1–3: odpowiedź na reklamację w terminie 14 dni od dnia jej otrzymania; brak odpowiedzi = uznanie reklamacji; odpowiedź na papierze lub innym trwałym nośniku — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 43a ust. 1: do umów sprzedaży/dostawy z konsumentem nie stosuje się przepisów Kc o rękojmi (księga trzecia, tytuł XI, dział II) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 43c ust. 1: odpowiedzialność za brak zgodności „istniejący w chwili dostarczenia i ujawniony w ciągu dwóch lat”; domniemanie istnienia wady w chwili dostarczenia, jeśli ujawniła się w tym okresie; ust. 2: sprzedawca nie może powoływać się na upływ terminu, jeśli wadę podstępnie zataił — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 43d: konsument może żądać naprawy lub wymiany; sklep może zmienić sposób, gdy wybrany jest niemożliwy lub wymaga nadmiernych kosztów; naprawa/wymiana w rozsądnym czasie, koszty (w tym przesyłki) ponosi sklep; sklep odbiera towar na swój koszt (ust. 5); demontaż/ponowny montaż (ust. 6) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 43e: obniżenie ceny lub odstąpienie, gdy m.in. sprzedawca odmówił lub nie doprowadził do zgodności, wada występuje nadal po próbie, wada jest tak istotna, że uzasadnia obniżenie/odstąpienie od razu; zwrot ceny obniżonej w 14 dni od oświadczenia (ust. 3); przy odstąpieniu konsument zwraca towar na koszt sklepu, a sklep zwraca cenę w 14 dni od otrzymania towaru lub dowodu odesłania (ust. 6); zwrot tym samym sposobem płatności (ust. 7); wada nieistotna nie uzasadnia odstąpienia, domniemuje się jej istotność (ust. 4) — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Art. 43f: konsument może wstrzymać zapłatę ceny do wykonania obowiązków sprzedawcy; art. 43g: odstępstwo od warunków gwarancji z reklamy na niekorzyść konsumenta bezskuteczne — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)
- Obowiązek informacyjny: art. 12 ust. 1 pkt 4 (adres reklamacji), pkt 8 (procedura rozpatrywania reklamacji), pkt 13–14, pkt 21 — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- Operacyjnie sklep potrzebuje: formularz/adres e-mail do reklamacji (i rejestr zgłoszeń z datą wpływu), licznik 14 dni z alertem (automatyczne uznanie reklamacji po terminie), zapis odpowiedzi na trwałym nośniku (e-mail), obsługę wyniku (naprawa/wymiana — logistyka odbioru kurierem na koszt sklepu; obniżka ceny i zwrot w 14 dni; odstąpienie), integrację zwrotu pieniędzy z operatorem płatności, i stronę „Reklamacje i gwarancja” w CMS. 2-letni okres liczy się od dostarczenia (dla wad ujawnionych w tym czasie), nie od zakupu. W sprzęcie sportowym przy wadzie istotnej częste jest odstąpienie.
- Sklep nie może skrócić 2-letniego okresu w umowie z konsumentem [wniosek własny, nie zweryfikowano art. 43h–43i ani art. 385(1) Kc].

### Gaps
- Nie czytano szczegółowo art. 43b (kryteria zgodności) ani art. 43i; nie zweryfikowano przepisów przejściowych dla umów sprzed 1.01.2023.
- Data 1.01.2023 pochodzi z treści zadania i opisu UOKiK; nie wyodrębniono przepisu wprowadzającego (ustawa z 1.12.2022, Dz.U. 2022 poz. 2581, wskazana w przypisie ustawy o cenach) — [przypis do art. 4 ustawy o informowaniu o cenach](https://api.sejm.gov.pl/eli/acts/DU/2023/168/text/T/D20230168L.pdf).

## 7. Omnibus: najniższa cena z 30 dni, opinie

### Takeaway
Art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług wymaga, by w każdym przypadku informowania o obniżce ceny obok ceny obniżonej uwidoczniono najniższą cenę z 30 dni przed obniżką; obowiązuje od 1.01.2023. UOKiK (Wyjaśnienia 2023) szczegółowo opisuje prezentację: wszelkie procenty i „oszczędzasz” odnoszą się do tej najniższej ceny. Informacja o weryfikacji opinii wynika z art. 6 ust. 4 pkt 7 UPNPR, a twierdzenie o opiniach bez weryfikacji to praktyka zakazana w każdych okolicznościach (art. 7 pkt 25).

### Cited Findings
- Art. 4 ust. 2: „W każdym przypadku informowania o obniżeniu ceny towaru lub usługi obok informacji o obniżonej cenie uwidacznia się również informację o najniższej cenie tego towaru lub tej usługi, która obowiązywała w okresie 30 dni przed wprowadzeniem obniżki.” Ust. 3: gdy towar oferowany krócej niż 30 dni — najniższa cena od rozpoczęcia oferowania do obniżki. Ust. 4: towary szybko psujące się — cena sprzed pierwszej obniżki. Ust. 5: do reklamy stosuje się odpowiednio. Wprowadzone przez art. 6 ustawy z 1.12.2022 (Dz.U. 2022 poz. 2581), w mocy od 1.01.2023 — [tekst jedn. Dz.U. 2023 poz. 168 (obwieszczenie z 11.01.2023)](https://api.sejm.gov.pl/eli/acts/DU/2023/168/text/T/D20230168L.pdf)
- UOKiK: obowiązek dotyczy także sklepu internetowego; przy dwóch cenach podaje się cenę sprzedaży i najniższą cenę z 30 dni; „Wszelkie komunikaty podkreślające promocyjny charakter obniżki (»oszczędzasz«, »taniej o x zł«, »-20%«) muszą odnosić się do najniższej ceny z 30 dni przed obniżką”; cena regularna może być podana dodatkowo, a wartości mierzalne względem niej tylko obok odniesienia do najniższej ceny; nie więcej niż dwie–trzy ceny na wywieszce/stronie — [Wyjaśnienia Prezesa UOKiK „Informacja o obniżce ceny” (2023)](https://uokik.gov.pl/storage/archiwum/e6e552a1.pdf)
- UOKiK: stała kolejność prezentacji cen; w miejscach z ograniczoną przestrzenią (wyniki wyszukiwania, miniatury, aplikacja) dopuszczalne skrócenie do „najniższa cena”, ale pełne wyjaśnienie łatwo dostępne (np. na karcie produktu, tooltip) i z rozróżnieniem „30 dni” vs „od rozpoczęcia oferowania” — [Wyjaśnienia UOKiK](https://uokik.gov.pl/storage/archiwum/e6e552a1.pdf)
- UOKiK: kolejne obniżki — cena z okresu poprzedniej promocji jest punktem odniesienia dla kolejnych obniżek tego towaru w ciągu następnych 30 dni (także gdy nikt nie skorzystał z oferty); dla kodów rabatowych podawanych w sklepie informacja o najniższej cenie przy wszystkich produktach objętych kodem, a w przypadku kodów poza sklepem — najpóźniej bezpośrednio po wpisaniu kodu (karta produktu lub koszyk) — [Wyjaśnienia UOKiK](https://uokik.gov.pl/storage/archiwum/e6e552a1.pdf)
- UOKiK (zasady skrócone): najniższa cena z 30 dni i przy produktach oferowanych krócej niż 30 dni (formuła „od wprowadzenia”; decyzja DIH.3.154.2025 wg opisu wyszukiwarki) — [prawakonsumenta.uokik.gov.pl](https://prawakonsumenta.uokik.gov.pl/prawo-do-informacji/informacje-o-obnizkach-cen/); decyzja UOKiK DIH.3.154.2025 nie została odczytana — [decyzja (PDF)](https://decyzje.uokik.gov.pl/bp/dec_prez.nsf/0/022F10C7EF400695C1258DAA0021E106/$file/DECYZJA%20DIH.3.154.2025-utrzymanie%20w%20mocy%20-%20ceny.pdf) [niezweryfikowane]
- Opinie: art. 6 ust. 4 pkt 7 UPNPR (w znowelizowanym tekście numerowany „7)”) — jako istotna informacja w propozycji nabycia podaje się „informacje o tym, czy i w jaki sposób przedsiębiorca zapewnia, aby publikowane opinie pochodziły od konsumentów, którzy używali danego produktu lub go nabyli”, jeśli umożliwia dostęp do opinii; art. 7 pkt 25: twierdzenie, że opinie pochodzą od konsumentów, mimo że nie podjęto „uzasadnionych i proporcjonalnych kroków” weryfikacji — zawsze nieuczciwa praktyka; art. 7 pkt 26: zamieszczanie lub zlecanie fałszywych opinii — [UPNPR, tekst jedn. Dz.U. 2023 poz. 845](https://api.sejm.gov.pl/eli/acts/DU/2023/845/text/T/D20230845L.pdf) (numer ustępu dla pkt 7 wymaga potwierdzenia: tekst wskazuje na art. 6 ust. 4 po ust. 1–3)
- Od 27.09.2027 dojdzie nowelizacja UPNPR (poz. 1353, dyrektywa 2024/825: m.in. oznakowanie ekologiczne, gwarancje, aktualizacje oprogramowania) — [Dz.U. 2026 poz. 1353](https://api.sejm.gov.pl/eli/acts/DU/2026/1353/text/O/D20261353.pdf)
- Wcześniejsze ustalenia (Gaps poprzedniej notatki): obowiązek informacji o personalizacji ceny (art. 12 ust. 1 pkt 5a UPK) potwierdzony w tekście — [UPK](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU20140000827/U/D20140827Lj.pdf)

### Inferences
- Funkcje platformy: model cen z historią (zapis każdej zmiany ceny z datą), wyliczanie „najniższej ceny z 30 dni” (z okresem krótszym — od początku oferowania), blokada prezentacji przekreślonej/procentowej obniżki bez tej informacji, obsługa kodów rabatowych (informacja przy produktach objętych kodem, nie dopiero w koszyku — chyba że technicznie możliwa pierwsza ekspozycja po wpisaniu kodu), skrócone etykiety na listach. To wymaga domenowej logiki po stronie API (zgodnie z zasadą „nie umieszczaj logiki w froncie”).
- Opinie: jeśli sklep pokazuje opinie klientów, potrzebna jest weryfikacja zakupu (np. tylko klienci z opłaconym zamówieniem) i informacja o sposobie weryfikacji, albo zrezygnowanie z twierdzeń o pochodzeniu opinii; brak opinii = brak obowiązku.

### Gaps
- Nie sprawdzono wysokości kar (UOKiK do 10% obrotu wg Omnibusa; niezweryfikowane) ani aktualnej praktyki decyzyjnej z 2026 r.
- Komunikaty KE (wytyczne do dyrektywy 2019/2161) nie były czytane.

## 8. GPSR (rozporządzenie (UE) 2023/988, art. 19)

### Takeaway
Od 13.12.2024 oferta online produktu musi zawierać dane identyfikujące producenta (nazwa, nazwa handlowa/znak towarowy, adres pocztowy i e-mail), dane osoby odpowiedzialnej w UE (jeśli producent spoza UE), informacje identyfikujące produkt (w tym obraz, typ, numer partii/serii/modelu) oraz ostrzeżenia i informacje o bezpieczeństwie. Dotyczy wszystkich produktów, także zharmonizowanych (o ile przepisy sektorowe nie regulują tego inaczej).

### Cited Findings
- UOKiK: podmioty gospodarcze podają w internetowej ofercie produktu „te same informacje” co przy sprzedaży stacjonarnej, co najmniej dane identyfikujące produkt, dane producenta (lub osoby odpowiedzialnej) oraz informacje i ostrzeżenia dotyczące bezpieczeństwa; dotyczy produktów zharmonizowanych i niezharmonizowanych; platformy online muszą umożliwić sprzedawcom podanie tych informacji; towary wprowadzone do obrotu przed 13.12.2024 zgodne z dawnymi wymogami można sprzedawać dalej — [UOKiK, Obowiązki przedsiębiorców](https://uokik.gov.pl/bezpieczenstwo-produktow-obowiazki-przedsiebiorcow)
- Dane producenta: nazwa lub firma, zarejestrowana nazwa handlowa lub znak towarowy, adres pocztowy i elektroniczny; przy producencie spoza UE — dane osoby odpowiedzialnej; identyfikacja produktu: obraz, rodzaj, identyfikatory (model, typ, seria); ostrzeżenia i informacje o bezpieczeństwie mogą być przez link/PDF, jeśli łatwo dostępne — [prawo.pl o GPSR](https://www.prawo.pl/biznes/rozporzadzenie-gpsr-obowiazki-dla-firm-i-sklepow-internetowych,530380.html); [PARP „GPSR w praktyce”](https://www.parp.gov.pl/attachments/article/89363/PARP_GPSR_webinar_3009.pdf) (opis wynikowy) [wtórne]
- GPSR stosowane od 13.12.2024 — [UOKiK](https://uokik.gov.pl/bezpieczenstwo-produktow-obowiazki-przedsiebiorcow)
- Polska ustawa o nadzorze nad ogólnym bezpieczeństwem produktów (7.11.2025; w mocy od 3.01.2026) i kary: kara do 500 000 zł za niedopełnienie obowiązku przekazywania informacji o produkcie przy sprzedaży online — [PARP, „Bezpieczeństwo produktów konsumenckich”](https://www.parp.gov.pl/component/content/article/90288:bezpieczenstwo-produktow-konsumenckich-rozporzadzenie-gpsr-i-nowa-ustawa-o-nadzorze-nad-ogolnym-bezpieczenstwem-produktow); [prawo.pl o ustawie](https://www.prawo.pl/biznes/ustawa-gpsr-zmiany-w-nadzorze-nad-bezpieczenstwem-produktow,536366.html) [wtórne]

### Inferences
- Model produktu musi mieć pola: producent (nazwa, znak towarowy, adres, e-mail), osoba odpowiedzialna w UE (przy producencie spoza UE), identyfikator (model/partia/seria), zdjęcie, ostrzeżenia/instrukcja (tekst lub PDF) oraz walidację — brak pola uniemożliwia publikację oferty. Sprzęt outdoor/sportowy (kaski, uprzęże, sprzęt wspinaczkowy) może podlegać dodatkowo przepisom sektorowym (PPE), co wykracza poza zakres tego badania.

### Gaps
- Nie udało się pobrać tekstu art. 19 rozporządzenia z EUR-Lex (strona nie renderowała się w narzędziu) — brzmienie ust. 1–3 [niezweryfikowane w tekście aktu]; opis oparty na UOKiK i PARP/prawo.pl. Nie sprawdzono numeru ustawy krajowej (Dz.U.) ani dat wejścia w życie z ISAP.

## 9. RODO i cookies

### Takeaway
Cookies/identyfikatory nieistotne technicznie wymagają uprzedniej zgody na podstawie art. 399 PKE (ustawa z 12.07.2024, Dz.U. 2024 poz. 1221, ogłoszona 9.08.2024; w mocy od 10.11.2024 wg źródeł wtórnych); zgoda wg RODO (art. 400 PKE). Obsługa zamówienia opiera się na podstawie „wykonanie umowy”, marketing wymaga osobnej zgody (e-mail/SMS: PKE).

### Cited Findings
- Art. 399 ust. 1 PKE: przechowywanie lub dostęp do informacji w urządzeniu końcowym jest dozwolone pod warunkiem, że użytkownik zostanie uprzednio poinformowany o celu i możliwości określenia warunków przez ustawienia przeglądarki oraz wyrazi zgodę; ust. 3: warunków nie stosuje się, gdy jest to konieczne do transmisji komunikatu lub do świadczenia usługi żądanej przez użytkownika (cookies ściśle niezbędne, np. koszyk/sesja) — [PKE, Dz.U. 2024 poz. 1221](https://api.sejm.gov.pl/eli/acts/DU/2024/1221/text/O/D20241221.pdf)
- Art. 400 PKE: do zgody stosuje się odpowiednio przepisy o ochronie danych osobowych (ust. 1 zdanie pierwsze odczytano tylko częściowo) — [PKE](https://api.sejm.gov.pl/eli/acts/DU/2024/1221/text/O/D20241221.pdf); streszczenie: [wyniki wyszukiwania (eduverse)](https://edlaw.eduverse.com/docs/jurisdiction/nat/pl/pke)
- PKE obowiązuje od 10.11.2024 — wyniki wyszukiwania (komentarze kancelarii/Shoper) [wtórne]; tekst ustawy: art. 450 odsyła do przepisów wprowadzających — [PKE](https://api.sejm.gov.pl/eli/acts/DU/2024/1221/text/O/D20241221.pdf)

### Inferences
- Funkcje platformy: baner/centrum zgód z równorzędnym „odrzuć” i „akceptuj”, kategoryzacja skryptów (niezbędne vs analityka/marketing), blokada skryptów do czasu zgody, zapis dowodu zgody i możliwość jej wycofania; sesja koszyka jest „niezbędna” (art. 399 ust. 3 pkt 2).
- RODO (wiedza ogólna, niezweryfikowana w tekście): realizacja zamówienia — art. 6 ust. 1 lit. b; faktury/rachunkowość — lit. c; marketing e-mail/SMS — odrębna zgoda (PKE) lub uzasadniony interes przy marketingu własnych podobnych produktów (zasady PKE do sprawdzenia); prawa osób: dostęp, sprostowanie, usunięcie (art. 17; ograniczone obowiązkami podatkowymi), przenoszenie (art. 20). Wymaga to narzędzi: eksport danych klienta, anonimizacja konta/zamówień po okresie retencji, rejestr zgód, polityka prywatności (art. 13 RODO), umowy powierzenia z operatorami płatności/kurierami.

### Gaps
- Nie pobrano tekstu RODO ani wytycznych UODO/EROD o cookies; podział kompetencji UODO/UKE przy art. 399 PKE jest opisywany sprzecznie we źródłach wtórnych (Grant Thornton vs inne). Przepisy wprowadzające PKE (data 10.11.2024) nie zostały odczytane.

## 10. Europejski Akt o Dostępności — e-commerce w Polsce

### Takeaway
Ustawa z 26.04.2024 (Dz.U. 2024 poz. 731) obejmuje usługi handlu elektronicznego; w mocy od 28.06.2025. Art. 4 pkt 1 wyłącza usługi oferowane lub świadczone przez mikroprzedsiębiorców (czyli interfejs sklepu mikroprzedsiębiorcy nie podlega wymogom), ale ustawa nie wyłącza wprost produktów.

### Cited Findings
- Art. 88: ustawa wchodzi w życie 28.06.2025, z wyjątkiem art. 6 pkt 1, 2, 5–9 i art. 39 pkt 6 (po 14 dniach od ogłoszenia) — [Dz.U. 2024 poz. 731 (PDF)](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)
- Art. 3 ust. 2 pkt 6: „handlu elektronicznego” wśród usług objętych ustawą; art. 2 pkt 32 definicja: usługi „oferowane lub świadczone na odległość przez strony internetowe i urządzenia mobilne, drogą elektroniczną i na indywidualne żądanie konsumenta w celu zawarcia umowy” (numer w art. 3 może być ust. 2; w odczytanym fragmencie wymieniony po pkt 5 w katalogu usług) — [Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)
- Art. 4 pkt 1: „Przepisów ustawy nie stosuje się do: usług oferowanych lub świadczonych przez mikroprzedsiębiorców” — [Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)
- Art. 18: poza wymaganiami art. 12 usługi handlu elektronicznego muszą spełniać dodatkowe wymagania (m.in. dot. identyfikacji, uwierzytelniania, bezpieczeństwa i płatności) — [Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)
- Przejściowo: umowy usługowe zawarte przed 28.06.2025 mogą obowiązywać nie dłużej niż do 28.06.2030 — [Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf)
- Ustawa wskazuje ministra właściwego ds. informatyzacji jako organ dla usług handlu elektronicznego — [Dz.U. 2024 poz. 731](https://api.sejm.gov.pl/eli/acts/DU/2024/731/text/O/D20240731.pdf) (numer artykułu niepodany w odczytanym fragmencie)
- Wstępne źródło wtórne: Shoper i gov.pl potwierdzają stosowanie od 28.06.2025 i wyłączenie mikroprzedsiębiorców — [Shoper](https://www.shoper.pl/learn/artykul/polski-akt-o-dostepnosci-wplynie-na-sklepy-internetowe); [gov.pl](https://www.gov.pl/web/dostepnosc-cyfrowa/polski-akt-o-dostepnosci--uslugi-handlu-elektronicznego) (z poprzedniej notatki)

### Inferences
- Wyłączenie obejmuje tylko mikroprzedsiębiorców; sklep spoza tej kategorii (mały lub średni przedsiębiorca) musi zapewnić dostępny interfejs (zgodność z wymaganiami z art. 12 ustawy, w praktyce zbliżona do WCAG 2.1 AA wg norm zharmonizowanych — [niezweryfikowane]). Dla produktu platformy: dostępność (klawiatura, focus, etykiety, kontrast) to wymóg biznesowy zależny od statusu sprzedawcy; zgodna z listą jakości projektu.
- Definicja mikroprzedsiębiorcy: w odczytanym tekście ustawy nie znaleziono definicji; zwykle odsyła się do Prawa przedsiębiorców (mniej niż 10 pracowników i obrót/bilans ≤ 2 mln EUR) — [niezweryfikowane w tekście aktu].

### Gaps
- Nie sprawdzono art. 12 ani norm zharmonizowanych (EN 301 549) i zakresu deklaracji dostępności dla usług handlu elektronicznego, ani kar (art. 53 ff.).
- Nie zweryfikowano, czy ustawa wymaga publikacji „oświadczenia o dostępności” na stronie sklepu (wymóg zwykle występuje dla usługodawców, art. 17–18).

## Podsumowanie do klasyfikacji funkcji (wniosek własny, nie porada prawna)

| Obowiązek | Podstawa | Od kiedy | Status |
|---|---|---|---|
| Przycisk „zamówienie z obowiązkiem zapłaty”; potwierdzenie świadomości zapłaty | art. 17 ust. 2–4 UPK | od 25.12.2014 (UPK w mocy od tej daty wg [ELI](https://api.sejm.gov.pl/eli/acts/DU/2014/827)) | MANDATORY (skutek: umowa nie zostaje zawarta) |
| Koszt dostawy i cena łączna przed zamówieniem; dane sprzedawcy; strony prawne | art. 12 ust. 1 pkt 1–5, 7–14, 21; art. 17 ust. 1; art. 18; art. 23 UPK | obowiązuje | MANDATORY |
| Potwierdzenie umowy na trwałym nośniku | art. 21 ust. 1 UPK | obowiązuje | MANDATORY |
| 14 dni odstąpienia, wzór formularza, zwrot w 14 dni tym samym sposobem, najtańsza dostawa | art. 27–34, 38, zał. 2 UPK | obowiązuje | MANDATORY |
| Funkcja odstąpienia online („odstąp od umowy tutaj”) | art. 11a dyr. 2011/83/UE (dyr. 2023/2673) | UE: 19.06.2026; PL: brak ustawy (UC169 w przygotowaniu) | RECOMMENDED teraz, MANDATORY po wejściu ustawy |
| Reklamacje: odpowiedź w 14 dni, 2 lata, naprawa/wymiana/obniżka/odstąpienie | art. 7a, 43a–43g UPK | od 1.01.2023 | MANDATORY |
| Najniższa cena z 30 dni; informacja o weryfikacji opinii | art. 4 ust. 2–5 ustawy o informowaniu o cenach; art. 6 ust. 4 pkt 7, art. 7 pkt 25–26 UPNPR | od 1.01.2023 | MANDATORY (przy obniżkach / opiniach) |
| GPSR w ofercie online | art. 19 rozp. 2023/988 | od 13.12.2024 | MANDATORY (tekst art. 19 niezweryfikowany) |
| Zgoda na cookies nieistotne | art. 399–400 PKE | od 10.11.2024 (wtórne) | MANDATORY |
| Dostępność e-commerce | ustawa z 26.04.2024, art. 4 pkt 1 (wyłączenie mikro) | od 28.06.2025 | MANDATORY, chyba że sprzedawca to mikroprzedsiębiorca |
| Harmonizowana informacja o zgodności towaru, gwarancja trwałości | poz. 1353 (UPK art. 12 ust. 1 pkt 13, 14a) | od 27.09.2027 | PENDING (uchwalona, jeszcze nie w mocy) |
