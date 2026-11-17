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
| `first-names.yaml` | imiona męskie i żeńskie (po około 100) z wagami | własne, **przybliżone**: lista popularnych polskich imion i wagi szacowane z pamięci, spisane bez dostępu do rejestru PESEL; nie pochodzą z GUS |
| `surnames.yaml` | nazwiska męskie i żeńskie (po 100) z wagami | własne, **przybliżone** jak wyżej; nie pochodzą z GUS |
| `places.yaml` | miasta z wagą (w przybliżeniu liczba mieszkańców w tysiącach) i kodami pocztowymi, popularne nazwy ulic | własne: kody pocztowe z zakresów kodów tych miast spisane z pamięci (format `NN-NNN` sprawdza loader), bez sprawdzenia każdego kodu w spisie Poczty Polskiej; nazwy ulic to popularne polskie nazwy |

Słowniki osób mają docelowo pochodzić z list GUS z rejestru PESEL na dane.gov.pl („Lista imion występujących w rejestrze
PESEL”, „Nazwiska męskie osób żyjących”, „Nazwiska żeńskie osób żyjących”; licencję i wymagany opis źródła trzeba
przepisać ze strony zbioru przy podmianie). Format plików jest formatem tych list (imię albo nazwisko i liczba osób, osobno
dla płci; wielkie litery z rejestru loader normalizuje), więc podmiana to wyłącznie zmiana treści `first-names.yaml`
i `surnames.yaml` (zmieni odcisk słowników, a więc identyfikatory kont) i wpis źródła w tej tabeli. Do tego czasu imiona
i nazwiska z wagami są przybliżeniem, nie danymi GUS: przy tworzeniu kroku kont środowisko nie miało dostępu do
dane.gov.pl.
