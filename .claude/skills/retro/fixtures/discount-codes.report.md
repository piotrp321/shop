# Raport epika discount-codes

- Status: DONE
- Gałąź epika: `epic/discount-codes`, start: `91b7ed0e70a5`, plan: `91b7ed0e70a5`
- Końcowy PR: https://github.com/piotrp321/shop/pull/329, CI: success
- Rundy QA: 4
- Czas: od 2026-11-08 20:23:01 do 2026-11-09 10:06:58, razem 13h 43m 57s, w tym praca wykonawcy 6h 54m 21s; aktywnie 13h 43m 57s (przerwy hosta 0h 00m 00s, cooldown limitu 0h 00m 00s)
- Próby: 14, w tym 1 nieudanych
- Interwencje człowieka: 2
- Interwencje operacyjne: 0 (zmiany konfiguracji: 0, edycje pliku stanu: 0)

Znaleziska Critical i Important rundy 1:
- F1 [Important] Fokus klawiatury ginie po zmianie strony listy kodów rabatowych (apps/admin/src/views/DiscountCodesView.vue), zadanie DISCOUNT-CODES-5
- F2 [Important] Fokus klawiatury ginie po zapisie formularza kodu rabatowego (apps/admin/src/views/DiscountCodeFormView.vue), zadanie DISCOUNT-CODES-5

Znaleziska Critical i Important rundy 2:
- U1 [Important] Fokus klawiatury ginie po ponowieniu wczytania listy kodów rabatowych (apps/admin/src/views/DiscountCodesView.vue), zadanie DISCOUNT-CODES-5

### Minor (tylko w raporcie, bez napraw)

- F3 Wyszukiwanie kodów samym niewidocznym znakiem daje błąd 400 w panelu (apps/admin/src/discount-codes/useDiscountCodeList.ts), runda 1
- F4 fokus ginie po ponowieniu listy kodów zakończonym odmową dostępu (apps/admin/src/views/DiscountCodesView.vue), runda 3
- F5 sygnatura create w specyfikacji kodów pomija enabled (docs/features/discount-codes/spec.md), runda 4
- F6 lista metod DiscountCodeUsageRepository w specyfikacji cen jest niepełna (docs/features/pricing/spec.md), runda 4

