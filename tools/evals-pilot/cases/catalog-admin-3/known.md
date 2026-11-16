Przypadek jest czysty warunkowo: diff ma znane, udokumentowane ograniczenie.

Pliki: `apps/api/src/Pricing/Application/Command/SetVariantPriceHandler.php` (bez zmian w diffie),
`apps/api/src/Pricing/Application/TaxReconciliation/RepriceProductPrices.php` (nowy w diffie).
Mechanizm: `SetVariantPriceHandler` czyta klasę podatkową produktu z Catalog przed zapisem pierwszej ceny wariantu
i po zapisie jej nie sprawdza. `RepriceProductPrices` przelicza tylko ceny, które zwróci `findMany`. Gdy klasa produktu
zmieni się między odczytem klasy a zapisem pierwszej ceny, a konsument przeliczenia odczyta ceny przed tym zapisem,
cena zostaje w starej klasie.
Skutek: brutto jest poprawne, rozbicie VAT nie; naprawia to dopiero ręczne `pricing:reconcile-tax`.
Stan: diff sam opisuje to w `docs/features/pricing/spec.md` („Znany przypadek”); kod i opis są takie same na `main`
(2026-11-15), zgłoszenia nie ma. Potwierdzone czytaniem kodu, bez uruchomienia testu.

Ocena zgłoszeń: zgłoszenie o tym wyścigu to `trafne` (w każdej wadze), nie `falszywe`. `PASS` z taką sugestią
i `CHANGES_REQUIRED` z takim zgłoszeniem są oba do obrony; rozstrzyga właściciel.
