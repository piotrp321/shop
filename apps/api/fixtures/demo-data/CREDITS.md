# Źródła słowników generatora danych demo

Słowniki w tym katalogu napisano na potrzeby tego projektu, z wyjątkiem słowników osób i kodów pocztowych, których źródła są w tabeli:

| Plik | Zawartość | Pochodzenie |
|---|---|---|
| `categories.yaml` | drzewo kategorii ogona katalogu (około 100 kategorii końcowych w działach z `fixtures/catalog.yaml`) | własne |
| `product-types.yaml` | typy produktów: osie wariantów, klasa podatkowa, przedział cen, grupa zdjęć, cechy do opisów | własne; przedziały cen to przybliżenia cen detalicznych, nie dane żadnego sklepu |
| `brands.yaml` | fikcyjne marki | wymyślone (losowe składanie sylab, jednorazowo), bez nazw istniejących firm |
| `models.yaml` | nazwy modeli | polskie nazwy geograficzne i przyrodnicze z numerami serii |
| `descriptions.yaml` | zakończenia opisów | własne |
| `photo-groups.yaml` | przypisanie grup zdjęć do plików z `fixtures/images` (profil `small`) | własne; zdjęcia: `apps/api/fixtures/images/CREDITS.md` (Unsplash License) |
| `profiles.yaml` | liczby profili, ziarno, rozmiar części | własne, według `docs/features/demo-data/spec.md` |
| `first-names.yaml` | imiona męskie i żeńskie (po 300 najczęstszych) z liczbą osób żyjących | GUS, rejestr PESEL, dane.gov.pl: „Lista imion występujących w rejestrze PESEL”, imię pierwsze, stan na 20.01.2026 (zasoby 1159669 i 1159670), licencja CC0 1.0; wpisy bez zmian, z wyjątkiem pominiętych zapisów ze znakami innymi niż litery |
| `surnames.yaml` | nazwiska męskie i żeńskie (po 1000 najczęstszych) z liczbą osób | GUS, rejestr PESEL, dane.gov.pl: „Nazwiska występujące w rejestrze PESEL”, nazwiska męskie stan na 22.01.2025 (zasób 63886, nowszej listy męskiej nie ma) i nazwiska żeńskie stan na 20.01.2026 (zasób 1148815), licencja CC0 1.0; wpisy bez zmian, z wyjątkiem pominiętych zapisów ze znakami innymi niż litery |
| `places.yaml` | miasta z wagą (w przybliżeniu liczba mieszkańców w tysiącach) i kodami pocztowymi, popularne nazwy ulic | wagi i ulice własne; kody pocztowe z punktów adresowych Państwowego Rejestru Granic (usługa GUGiK `services.gugik.gov.pl/uug`, 2026-11-18): każdy kod to kod istniejącego adresu w danym mieście (popularna ulica i numer) |
