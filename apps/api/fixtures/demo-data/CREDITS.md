# Źródła słowników generatora danych demo

Słowniki w tym katalogu napisano na potrzeby tego projektu i nie pochodzą z żadnego zewnętrznego źródła:

| Plik | Zawartość | Pochodzenie |
|---|---|---|
| `categories.yaml` | drzewo kategorii ogona katalogu (około 100 kategorii końcowych w działach z `fixtures/catalog.yaml`) | własne |
| `product-types.yaml` | typy produktów: osie wariantów, klasa podatkowa, przedział cen, grupa zdjęć, cechy do opisów | własne; przedziały cen to przybliżenia cen detalicznych, nie dane żadnego sklepu |
| `brands.yaml` | fikcyjne marki | wymyślone (losowe składanie sylab, jednorazowo), bez nazw istniejących firm |
| `models.yaml` | nazwy modeli | polskie nazwy geograficzne i przyrodnicze z numerami serii |
| `descriptions.yaml` | zakończenia opisów | własne |
| `photo-groups.yaml` | przypisanie grup zdjęć do plików z `fixtures/images` (profil `small`) | własne; zdjęcia: `apps/api/fixtures/images/CREDITS.md` (Unsplash License) |
| `profiles.yaml` | liczby profili, ziarno, rozmiar części | własne, według `docs/features/demo-data/spec.md` |

Dane osób (imiona, nazwiska, miasta, ulice) z list częstości GUS (dane.gov.pl) dołoży krok kont, wraz z opisem źródeł
i licencji w tym pliku.
