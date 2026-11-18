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

## Zdjęcia puli demo (`photos.yaml`)

Zdjęcia pochodzą z Wikimedia Commons i są dostępne na licencji CC0 1.0 albo w domenie publicznej, więc wolno je kopiować, przerabiać i używać komercyjnie bez podawania autora. Autorzy są wymienieni dla porządku. Pliki przeskalowano do 1600 px na dłuższym boku; warianty (odbicie lustrzane, kadry 80%) powstały z tych samych zdjęć i mają w `photos.yaml` ten sam `sourceId`.

| Zdjęcie | Strona | Autor | Licencja |
|---|---|---|---|
| "Mountain Branch Trail Ruts" NTIR Sign near Fort Union National Monument (380d1aa59ba1476bb8e237948a83d1dc).JPG | https://commons.wikimedia.org/wiki/File:%22Mountain_Branch_Trail_Ruts%22_NTIR_Sign_near_Fort_Union_National_Monument_(380d1aa59ba1476bb8e237948a83d1dc).JPG | National Trails Office (US National Park Service) | Public domain |
| -mypubliclandsroadtrip 2016- Extreme Public Lands, Beck Lake Mountain Biking Area (27988711901).jpg | https://commons.wikimedia.org/wiki/File:-mypubliclandsroadtrip_2016-_Extreme_Public_Lands,_Beck_Lake_Mountain_Biking_Area_(27988711901).jpg | mypubliclands | Public domain |
| -mypubliclandsroadtrip 2016- Extreme Public Lands, California Wild and Scenic Rivers (27984891931).jpg | https://commons.wikimedia.org/wiki/File:-mypubliclandsroadtrip_2016-_Extreme_Public_Lands,_California_Wild_and_Scenic_Rivers_(27984891931).jpg | mypubliclands | Public domain |
| 0328Rear views of road cyclists with bicycle helmets during the COVID-19 pandemic 17.jpg | https://commons.wikimedia.org/wiki/File:0328Rear_views_of_road_cyclists_with_bicycle_helmets_during_the_COVID-19_pandemic_17.jpg | JFVelasquez Floro | CC0 1.0 |
| 0328Rear views of road cyclists with bicycle helmets during the COVID-19 pandemic 19.jpg | https://commons.wikimedia.org/wiki/File:0328Rear_views_of_road_cyclists_with_bicycle_helmets_during_the_COVID-19_pandemic_19.jpg | JFVelasquez Floro | CC0 1.0 |
| 038LLO7149 (51995119044).jpg | https://commons.wikimedia.org/wiki/File:038LLO7149_(51995119044).jpg | Koldo Zuazaga | CC0 1.0 |
| 038LLO7152 (51993843722).jpg | https://commons.wikimedia.org/wiki/File:038LLO7152_(51993843722).jpg | Koldo Zuazaga | CC0 1.0 |
| 070CBP 3648 (54248028468).jpg | https://commons.wikimedia.org/wiki/File:070CBP_3648_(54248028468).jpg | Koldo Zuazaga | CC0 1.0 |
| 1-1 AD dominates Commander’s Cup Swim Meet 131105-A-JV906-217.jpg | https://commons.wikimedia.org/wiki/File:1-1_AD_dominates_Commander%E2%80%99s_Cup_Swim_Meet_131105-A-JV906-217.jpg | Sgt. Edward Garibay | Public domain |
| 1005Santa Barbara and Poblacion, Baliuag, Bulacan 37.jpg | https://commons.wikimedia.org/wiki/File:1005Santa_Barbara_and_Poblacion,_Baliuag,_Bulacan_37.jpg | Judgefloro | CC0 1.0 |
| 10K mountain bike race over rough terrain.jpg | https://commons.wikimedia.org/wiki/File:10K_mountain_bike_race_over_rough_terrain.jpg | Shane T. McCoy | Public domain |
| 111 St NYC Marathon jeh.jpg | https://commons.wikimedia.org/wiki/File:111_St_NYC_Marathon_jeh.jpg | Jim.henderson | CC0 1.0 |
| 136 Memorial Workout- EOD remembers fallen (5890358).jpg | https://commons.wikimedia.org/wiki/File:136_Memorial_Workout-_EOD_remembers_fallen_(5890358).jpg | U.S. Air Force photo by Staff Sgt. Kirby Turbak | Public domain |
| 137th SOW services Airmen support 35th FSS mission while training (9268283).jpg | https://commons.wikimedia.org/wiki/File:137th_SOW_services_Airmen_support_35th_FSS_mission_while_training_(9268283).jpg | U.S. Air National Guard photo by Senior Airman Erika Chapa | Public domain |
| 160123-N-MC656-042 (24280962379).jpg | https://commons.wikimedia.org/wiki/File:160123-N-MC656-042_(24280962379).jpg | National Museum of the U.S. Navy | Public domain |
| 1643Sugar Baby Watermelon 20.jpg | https://commons.wikimedia.org/wiki/File:1643Sugar_Baby_Watermelon_20.jpg | JFVelasquez Floro | CC0 1.0 |
| 1643Sugar Baby Watermelon 22.jpg | https://commons.wikimedia.org/wiki/File:1643Sugar_Baby_Watermelon_22.jpg | JFVelasquez Floro | CC0 1.0 |
| 170209-N-PL946-047 (32496032650).jpg | https://commons.wikimedia.org/wiki/File:170209-N-PL946-047_(32496032650).jpg | Commander, U.S. Naval Forces Europe-Africa/U.S. 6th Fleet | Public domain |
| 170209-N-PL946-049 (32836320056).jpg | https://commons.wikimedia.org/wiki/File:170209-N-PL946-049_(32836320056).jpg | Commander, U.S. Naval Forces Europe-Africa/U.S. 6th Fleet | Public domain |
| 170730-fs-cherokee-lsc-016 37273210320 o (39963931464).jpg | https://commons.wikimedia.org/wiki/File:170730-fs-cherokee-lsc-016_37273210320_o_(39963931464).jpg | USDAgov | Public domain |
| 1810Photos taken during 2020 coronavirus pandemic in Baliuag 06.jpg | https://commons.wikimedia.org/wiki/File:1810Photos_taken_during_2020_coronavirus_pandemic_in_Baliuag_06.jpg | Judgefloro | CC0 1.0 |
| 1857Views of swimming boys in NIA irrigation canals from Bonga Mayor irrigation bridge 10.jpg | https://commons.wikimedia.org/wiki/File:1857Views_of_swimming_boys_in_NIA_irrigation_canals_from_Bonga_Mayor_irrigation_bridge_10.jpg | JFVelasquez Floro | CC0 1.0 |
| 200305 NVWG PORTLAND 0342 (53025965143).jpg | https://commons.wikimedia.org/wiki/File:200305_NVWG_PORTLAND_0342_(53025965143).jpg | U.S. Department of Veterans Affairs | Public domain |
| 2009-365-10 Sunset on the Hoop (3186039611).jpg | https://commons.wikimedia.org/wiki/File:2009-365-10_Sunset_on_the_Hoop_(3186039611).jpg | cogdogblog | CC0 1.0 |
| 2009-365-103 My New Fruit Craze (3441008910).jpg | https://commons.wikimedia.org/wiki/File:2009-365-103_My_New_Fruit_Craze_(3441008910).jpg | cogdogblog | CC0 1.0 |
| 2009-365-8 Oatmeal as Experimentation (3180105556).jpg | https://commons.wikimedia.org/wiki/File:2009-365-8_Oatmeal_as_Experimentation_(3180105556).jpg | cogdogblog | CC0 1.0 |
| 2011 UCI Mountain Bike and Trials World Championships - 01.JPG | https://commons.wikimedia.org/wiki/File:2011_UCI_Mountain_Bike_and_Trials_World_Championships_-_01.JPG | Shev123 | CC0 1.0 |
| 2014 10 30 Base Camp children's football Match-5.jpg (15672989515).jpg | https://commons.wikimedia.org/wiki/File:2014_10_30_Base_Camp_children%27s_football_Match-5.jpg_(15672989515).jpg | AMISOM Public Information | CC0 1.0 |
| 2014 10 30 Base Camp children's football Match-9.jpg (15487100269).jpg | https://commons.wikimedia.org/wiki/File:2014_10_30_Base_Camp_children%27s_football_Match-9.jpg_(15487100269).jpg | AMISOM Public Information | CC0 1.0 |
| 2014 Borneo Luyten-De-Hauwere-Canoe-01.jpg | https://commons.wikimedia.org/wiki/File:2014_Borneo_Luyten-De-Hauwere-Canoe-01.jpg | Denis Luyten | Public domain |
| 2014 Borneo Luyten-De-Hauwere-Canoe-02.jpg | https://commons.wikimedia.org/wiki/File:2014_Borneo_Luyten-De-Hauwere-Canoe-02.jpg | Denis Luyten | Public domain |
| 2014 Warrior Games swimming 140929-F-PD696-030.jpg | https://commons.wikimedia.org/wiki/File:2014_Warrior_Games_swimming_140929-F-PD696-030.jpg | Staff Sgt. Timothy Chacon | Public domain |
| 2014 Warrior Games swimming 140930-F-HF287-019.jpg | https://commons.wikimedia.org/wiki/File:2014_Warrior_Games_swimming_140930-F-HF287-019.jpg | Airman 1st Class Scott Jackson | Public domain |
| 2014 Warrior Games swimming 140930-F-HF287-026.jpg | https://commons.wikimedia.org/wiki/File:2014_Warrior_Games_swimming_140930-F-HF287-026.jpg | Airman 1st Class Scott Jackson | Public domain |
| 2014 Warrior Games swimming 140930-F-RN544-188.jpg | https://commons.wikimedia.org/wiki/File:2014_Warrior_Games_swimming_140930-F-RN544-188.jpg | Senior Airman Justyn Freeman | Public domain |
| 2014-365-281 Hoop Dreams Dreamt (15473474071).jpg | https://commons.wikimedia.org/wiki/File:2014-365-281_Hoop_Dreams_Dreamt_(15473474071).jpg | cogdogblog | CC0 1.0 |
| 2015-365-22 Dashed Hoop Dreams (15723496874).jpg | https://commons.wikimedia.org/wiki/File:2015-365-22_Dashed_Hoop_Dreams_(15723496874).jpg | cogdogblog | CC0 1.0 |
| 2017 Air Force Wounded Warrior Trials 170226-F-YM181-108.jpg | https://commons.wikimedia.org/wiki/File:2017_Air_Force_Wounded_Warrior_Trials_170226-F-YM181-108.jpg | Airman 1st Class Kevin Tanenbaum | Public domain |
| 2017 New York City Marathon – Allie Kieffer & Laurie Knowles (38205350891).jpg | https://commons.wikimedia.org/wiki/File:2017_New_York_City_Marathon_%E2%80%93_Allie_Kieffer_%26_Laurie_Knowles_(38205350891).jpg | Hakan Nizam | CC0 1.0 |
| 2017 Nuevo Laredo volleyball player for Irish Institute.jpg | https://commons.wikimedia.org/wiki/File:2017_Nuevo_Laredo_volleyball_player_for_Irish_Institute.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| 20180228-FS-LSC-0689 (27386242497).jpg | https://commons.wikimedia.org/wiki/File:20180228-FS-LSC-0689_(27386242497).jpg | Forest Service, USDA | Public domain |
| 20180228-FS-LSC-1456 (42210161692).jpg | https://commons.wikimedia.org/wiki/File:20180228-FS-LSC-1456_(42210161692).jpg | Forest Service, USDA | Public domain |
| 2019 European Mountain Bike Championships EggerG (48403224196).jpg | https://commons.wikimedia.org/wiki/File:2019_European_Mountain_Bike_Championships_EggerG_(48403224196).jpg | Lexware Mountainbike Team | CC0 1.0 |
| 2019 European Mountain Bike Championships HemmerlingT (48403518436).jpg | https://commons.wikimedia.org/wiki/File:2019_European_Mountain_Bike_Championships_HemmerlingT_(48403518436).jpg | Lexware Mountainbike Team | CC0 1.0 |
| 2019 European Mountain Bike Championships HemmerlingT (48403671002).jpg | https://commons.wikimedia.org/wiki/File:2019_European_Mountain_Bike_Championships_HemmerlingT_(48403671002).jpg | Lexware Mountainbike Team | CC0 1.0 |
| 2019 European Mountain Bike Championships KraussL (48403522216).jpg | https://commons.wikimedia.org/wiki/File:2019_European_Mountain_Bike_Championships_KraussL_(48403522216).jpg | Lexware Mountainbike Team | CC0 1.0 |
| 20191001-FS-UWC-EG-Powder Shot (48937591628).jpg | https://commons.wikimedia.org/wiki/File:20191001-FS-UWC-EG-Powder_Shot_(48937591628).jpg | Intermountain Forest Service, USDA Region 4 Photography | Public domain |
| 2020 07 04-15.20.05.543-CDT.jpg | https://commons.wikimedia.org/wiki/File:2020_07_04-15.20.05.543-CDT.jpg | K. Kirby Humboldt-Toiyabe National Forest U.S. Forest Service | Public domain |
| 2020-08-16 things places in south korea IMG 0120.jpg | https://commons.wikimedia.org/wiki/File:2020-08-16_things_places_in_south_korea_IMG_0120.jpg | Choi Kwang-mo | CC0 1.0 |
| 2020-08-16 things places in south korea IMG 0122.jpg | https://commons.wikimedia.org/wiki/File:2020-08-16_things_places_in_south_korea_IMG_0122.jpg | Choi Kwang-mo | CC0 1.0 |
| 2020-08-16 블루베리 스무디 IMG 0125.jpg | https://commons.wikimedia.org/wiki/File:2020-08-16_%EB%B8%94%EB%A3%A8%EB%B2%A0%EB%A6%AC_%EC%8A%A4%EB%AC%B4%EB%94%94_IMG_0125.jpg | Choi Kwang-mo | CC0 1.0 |
| 20230729-FS-AB-Hiking-Deer-Mountain-Traverse-0014 (53322256197).jpg | https://commons.wikimedia.org/wiki/File:20230729-FS-AB-Hiking-Deer-Mountain-Traverse-0014_(53322256197).jpg | Forest Service Alaska Region, USDA | Public domain |
| 2024 Comrades Marathon at Camperdown.jpg | https://commons.wikimedia.org/wiki/File:2024_Comrades_Marathon_at_Camperdown.jpg | Upjav | CC0 1.0 |
| 20240106 Vålerenga v Utleira.jpg | https://commons.wikimedia.org/wiki/File:20240106_V%C3%A5lerenga_v_Utleira.jpg | Blyngre | CC0 1.0 |
| 20240917 MonteAngone.jpg | https://commons.wikimedia.org/wiki/File:20240917_MonteAngone.jpg | Thomasox58 | CC0 1.0 |
| 20240917 Singiasco.jpg | https://commons.wikimedia.org/wiki/File:20240917_Singiasco.jpg | Thomasox58 | CC0 1.0 |
| 20240917 Suaisa.jpg | https://commons.wikimedia.org/wiki/File:20240917_Suaisa.jpg | Thomasox58 | CC0 1.0 |
| 2025-09-21 Moscow marathon, runners 01.jpg | https://commons.wikimedia.org/wiki/File:2025-09-21_Moscow_marathon,_runners_01.jpg | Retired electrician | CC0 1.0 |
| 2025-09-21 Moscow marathon, runners 13.jpg | https://commons.wikimedia.org/wiki/File:2025-09-21_Moscow_marathon,_runners_13.jpg | Retired electrician | CC0 1.0 |
| 2025-09-21 Moscow marathon, runners wide 04.jpg | https://commons.wikimedia.org/wiki/File:2025-09-21_Moscow_marathon,_runners_wide_04.jpg | Retired electrician | CC0 1.0 |
| 2025-09-21 Moscow marathon, runners wide 06.jpg | https://commons.wikimedia.org/wiki/File:2025-09-21_Moscow_marathon,_runners_wide_06.jpg | Retired electrician | CC0 1.0 |
| 20250416 Umbro ball.jpg | https://commons.wikimedia.org/wiki/File:20250416_Umbro_ball.jpg | Rakoon | CC0 1.0 |
| 22 MEU Cold Weather Training (7153036).jpg | https://commons.wikimedia.org/wiki/File:22_MEU_Cold_Weather_Training_(7153036).jpg | U.S. Marine Corps photo by Cpl. Yvonna Guyette | Public domain |
| 374 FSS fitness center refresh (8993213).jpg | https://commons.wikimedia.org/wiki/File:374_FSS_fitness_center_refresh_(8993213).jpg | U.S. Air Force photo by Senior Airman Samantha White | Public domain |
| 400 meter track at the 1984 Summer Olympics.JPEG | https://commons.wikimedia.org/wiki/File:400_meter_track_at_the_1984_Summer_Olympics.JPEG | Ken Hackman, U.S. Air Force | Public domain |
| 403CBP 4796 (54249709067).jpg | https://commons.wikimedia.org/wiki/File:403CBP_4796_(54249709067).jpg | Koldo Zuazaga | CC0 1.0 |
| 446 FSS provides reinforcements at Beale AFB 150423-F-KW318-051.jpg | https://commons.wikimedia.org/wiki/File:446_FSS_provides_reinforcements_at_Beale_AFB_150423-F-KW318-051.jpg | Staff Sgt. Brenda Davis | Public domain |
| 449th Air Expeditionary Group Personnel Participate in a Wellness Expo (9251898).jpg | https://commons.wikimedia.org/wiki/File:449th_Air_Expeditionary_Group_Personnel_Participate_in_a_Wellness_Expo_(9251898).jpg | U.S. Air National Guard photo by Staff Sgt. Kevin Ray Salvador | Public domain |
| 800 meter track at the 1984 Summer Olympics.jpg | https://commons.wikimedia.org/wiki/File:800_meter_track_at_the_1984_Summer_Olympics.jpg | Ken Hackman, U.S. Air Force | Public domain |
| A Basketball Hoop.jpg | https://commons.wikimedia.org/wiki/File:A_Basketball_Hoop.jpg | MarkBuckawicki | CC0 1.0 |
| A Family enjoys a canoe ride at an annual Cultural Festival. (51b81657-8365-4ad2-872b-170352a6e33c).JPG | https://commons.wikimedia.org/wiki/File:A_Family_enjoys_a_canoe_ride_at_an_annual_Cultural_Festival._(51b81657-8365-4ad2-872b-170352a6e33c).JPG | NPS Photo | Public domain |
| A group of kayaks resting on the shore and a people getting a safety talk before heading out to the sea caves. (1bdb50e6-9d44-44e8-919d-fd0226f2ba93).JPG | https://commons.wikimedia.org/wiki/File:A_group_of_kayaks_resting_on_the_shore_and_a_people_getting_a_safety_talk_before_heading_out_to_the_sea_caves._(1bdb50e6-9d44-44e8-919d-fd0226f2ba93).JPG | NPS Photo | Public domain |
| A match session with the Young Nigerian Footballers.jpg | https://commons.wikimedia.org/wiki/File:A_match_session_with_the_Young_Nigerian_Footballers.jpg | Mayor Daniel | CC0 1.0 |
| A mile race at Manchester Regional Arena.jpg | https://commons.wikimedia.org/wiki/File:A_mile_race_at_Manchester_Regional_Arena.jpg | King Februarius | CC0 1.0 |
| A pair of dumbbells.jpeg | https://commons.wikimedia.org/wiki/File:A_pair_of_dumbbells.jpeg | User:Dajo767 | Public domain |
| A warm Arctic Oven tent is welcome on a chilly day on a long camping trip (385fed29-1dd8-b71c-074b-264cdf3b82a5).jpg | https://commons.wikimedia.org/wiki/File:A_warm_Arctic_Oven_tent_is_welcome_on_a_chilly_day_on_a_long_camping_trip_(385fed29-1dd8-b71c-074b-264cdf3b82a5).jpg | NPS Photo | Public domain |
| A-21. Little Mountain Summit (Emigration Canyon Road) on the California and Mormon Pioneer National Historic Trails (2008) (7cd060ef-fd47-4f68-91ec-f8afb44ebbfa).jpg | https://commons.wikimedia.org/wiki/File:A-21._Little_Mountain_Summit_(Emigration_Canyon_Road)_on_the_California_and_Mormon_Pioneer_National_Historic_Trails_(2008)_(7cd060ef-fd47-4f68-91ec-f8afb44ebbfa).jpg | NPS Staff (Photo Credit: NPS) | Public domain |
| Aarhus, Denmark (Unsplash t1sTHYjSN10).jpg | https://commons.wikimedia.org/wiki/File:Aarhus,_Denmark_(Unsplash_t1sTHYjSN10).jpg | Kristian Olsen kristianegelund | CC0 1.0 |
| Aarhus, Denmark (Unsplash wmdcUQ0CJ4c).jpg | https://commons.wikimedia.org/wiki/File:Aarhus,_Denmark_(Unsplash_wmdcUQ0CJ4c).jpg | Kristian Olsen kristianegelund | CC0 1.0 |
| Abel tasman sea kayaking.jpg | https://commons.wikimedia.org/wiki/File:Abel_tasman_sea_kayaking.jpg | Tobias Thierer | Public domain |
| Acai Smoothie Bowls (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Acai_Smoothie_Bowls_(Unsplash).jpg | Brenda Godinez cravethebenefits | CC0 1.0 |
| Adidas soccer ball on a grass pitch (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Adidas_soccer_ball_on_a_grass_pitch_(Unsplash).jpg | Peter Glaser baraida | CC0 1.0 |
| Adrie and Evan Redman hiking up 1st Burroughs. (a258d4daf54342869660b855318651da).JPG | https://commons.wikimedia.org/wiki/File:Adrie_and_Evan_Redman_hiking_up_1st_Burroughs._(a258d4daf54342869660b855318651da).JPG | Steve Redman (MORA) | Public domain |
| Adventurous Mountain Hikes (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Adventurous_Mountain_Hikes_(Unsplash).jpg | Galen Crout galen_crout | CC0 1.0 |
| Aerial view of swimming pool complex in Giza, Egypt (1980s).jpg | https://commons.wikimedia.org/wiki/File:Aerial_view_of_swimming_pool_complex_in_Giza,_Egypt_(1980s).jpg | Salwa Farwaneh Dameh | CC0 1.0 |
| Air Force WCAP athlete sets sights on Paris Olympics (8088698).jpg | https://commons.wikimedia.org/wiki/File:Air_Force_WCAP_athlete_sets_sights_on_Paris_Olympics_(8088698).jpg | U.S. Space Force photo by Emily Peacock | Public domain |
| Airmen develop strong friendships through mountain biking (6765233).jpg | https://commons.wikimedia.org/wiki/File:Airmen_develop_strong_friendships_through_mountain_biking_(6765233).jpg | U.S. Air Force photo by Senior Airman Chanceler Nardone | Public domain |
| Alejandra Johnson jugadora mexicana de voleibol.jpg | https://commons.wikimedia.org/wiki/File:Alejandra_Johnson_jugadora_mexicana_de_voleibol.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Alejandra Johnson, Mexican volleyball player.jpg | https://commons.wikimedia.org/wiki/File:Alejandra_Johnson,_Mexican_volleyball_player.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Aletchgletcher Expedition.jpg | https://commons.wikimedia.org/wiki/File:Aletchgletcher_Expedition.jpg | Tomas Agustin Sanchez | CC0 1.0 |
| Aletchgletcher.jpg | https://commons.wikimedia.org/wiki/File:Aletchgletcher.jpg | Tomas Agustin Sanchez | CC0 1.0 |
| All-Marine Team swimming for success 150615-M-CJ278-090.jpg | https://commons.wikimedia.org/wiki/File:All-Marine_Team_swimming_for_success_150615-M-CJ278-090.jpg | Cpl. Owen Kimbrel | Public domain |
| AlpeCampo guidepost 2024-09-15.jpg | https://commons.wikimedia.org/wiki/File:AlpeCampo_guidepost_2024-09-15.jpg | Sborgolotto | CC0 1.0 |
| AlpeCortone guidepost 2024-09-15.jpg | https://commons.wikimedia.org/wiki/File:AlpeCortone_guidepost_2024-09-15.jpg | Sborgolotto | CC0 1.0 |
| Alta via meranese 2005-05.JPG | https://commons.wikimedia.org/wiki/File:Alta_via_meranese_2005-05.JPG | nieznany | Public domain |
| Aluminum Pot and Lid, Lockheed Sirius "Tingmissartoq", Lindbergh - DPLA - c2db2ffe9c603fe6192090efb3424f26 (page 1).jpg | https://commons.wikimedia.org/wiki/File:Aluminum_Pot_and_Lid,_Lockheed_Sirius_%22Tingmissartoq%22,_Lindbergh_-_DPLA_-_c2db2ffe9c603fe6192090efb3424f26_(page_1).jpg | nieznany | CC0 1.0 |
| Aluminum Pot and Lid, Lockheed Sirius "Tingmissartoq", Lindbergh - DPLA - c2db2ffe9c603fe6192090efb3424f26 (page 2).jpg | https://commons.wikimedia.org/wiki/File:Aluminum_Pot_and_Lid,_Lockheed_Sirius_%22Tingmissartoq%22,_Lindbergh_-_DPLA_-_c2db2ffe9c603fe6192090efb3424f26_(page_2).jpg | nieznany | CC0 1.0 |
| Andrea Valenzuela deportista Nuevo Laredo.jpg | https://commons.wikimedia.org/wiki/File:Andrea_Valenzuela_deportista_Nuevo_Laredo.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Angelo running.jpg | https://commons.wikimedia.org/wiki/File:Angelo_running.jpg | Astrid.krediet | CC0 1.0 |
| Angular Basketball Hoop.jpg | https://commons.wikimedia.org/wiki/File:Angular_Basketball_Hoop.jpg | MarkBuckawicki | CC0 1.0 |
| Anthony Camerieri USATF 5k Championships 2025.jpg | https://commons.wikimedia.org/wiki/File:Anthony_Camerieri_USATF_5k_Championships_2025.jpg | Photoguy1212 | CC0 1.0 |
| Apollo 15 commander Dave Scott geology training.jpg | https://commons.wikimedia.org/wiki/File:Apollo_15_commander_Dave_Scott_geology_training.jpg | NASA | Public domain |
| Appalachian Trail Blaze (50907882988).jpg | https://commons.wikimedia.org/wiki/File:Appalachian_Trail_Blaze_(50907882988).jpg | ShenandoahNPS | Public domain |
| Arctic camping in the snow.jpg | https://commons.wikimedia.org/wiki/File:Arctic_camping_in_the_snow.jpg | USFWSAlaska | Public domain |
| Arizona Kayaking (2847326216).jpg | https://commons.wikimedia.org/wiki/File:Arizona_Kayaking_(2847326216).jpg | cogdogblog | CC0 1.0 |
| Arizona National Scenic Trail hiking sign (28172758558).jpg | https://commons.wikimedia.org/wiki/File:Arizona_National_Scenic_Trail_hiking_sign_(28172758558).jpg | BLMArizona | Public domain |
| Arizona National Scenic Trail sign (28172760118).jpg | https://commons.wikimedia.org/wiki/File:Arizona_National_Scenic_Trail_sign_(28172760118).jpg | BLMArizona | Public domain |
| Arizona Snowbowl Grand Canyon Express Ski Lift Opening Celebration (31458524632).jpg | https://commons.wikimedia.org/wiki/File:Arizona_Snowbowl_Grand_Canyon_Express_Ski_Lift_Opening_Celebration_(31458524632).jpg | Coconino National Forest | CC0 1.0 |
| Armed Forces Entertainment brings All-Star Soccer Players to Wiesbaden (6862855).jpg | https://commons.wikimedia.org/wiki/File:Armed_Forces_Entertainment_brings_All-Star_Soccer_Players_to_Wiesbaden_(6862855).jpg | U.S. Army photo by Sgt. Amanda Baker | Public domain |
| Armed Forces Entertainment brings All-Star Soccer Players to Wiesbaden (6862863).jpg | https://commons.wikimedia.org/wiki/File:Armed_Forces_Entertainment_brings_All-Star_Soccer_Players_to_Wiesbaden_(6862863).jpg | U.S. Army photo by Sgt. Amanda Baker | Public domain |
| Asics GT-2160.jpg | https://commons.wikimedia.org/wiki/File:Asics_GT-2160.jpg | LeDroider | CC0 1.0 |
| Asics Gel-1130.jpg | https://commons.wikimedia.org/wiki/File:Asics_Gel-1130.jpg | LeDroider | CC0 1.0 |
| Asics Gel-NYC 2.jpg | https://commons.wikimedia.org/wiki/File:Asics_Gel-NYC_2.jpg | LeDroider | CC0 1.0 |
| Asics Gel-NYC.jpg | https://commons.wikimedia.org/wiki/File:Asics_Gel-NYC.jpg | LeDroider | CC0 1.0 |
| Ataturk watches swimmer.jpg | https://commons.wikimedia.org/wiki/File:Ataturk_watches_swimmer.jpg | Chidgk1 | CC0 1.0 |
| Athletics track at Spectrum Leisure Centre, Guildford, Surrey - May 2025 - 01.jpg | https://commons.wikimedia.org/wiki/File:Athletics_track_at_Spectrum_Leisure_Centre,_Guildford,_Surrey_-_May_2025_-_01.jpg | Mertbiol | CC0 1.0 |
| Athletics tracks finish line.jpg | https://commons.wikimedia.org/wiki/File:Athletics_tracks_finish_line.jpg | Petey21 | Public domain |
| August 2009, Tent in Daisetsuzan.jpg | https://commons.wikimedia.org/wiki/File:August_2009,_Tent_in_Daisetsuzan.jpg | Syced | CC0 1.0 |
| Aviano snowboarder 2.JPG | https://commons.wikimedia.org/wiki/File:Aviano_snowboarder_2.JPG | U.S. Air Force photo/Senior Airman Michael S. Dorus | Public domain |
| BLM and the Pacific Crest Trail Oregon (16792844060).jpg | https://commons.wikimedia.org/wiki/File:BLM_and_the_Pacific_Crest_Trail_Oregon_(16792844060).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Backbone Trail (40215228583).jpg | https://commons.wikimedia.org/wiki/File:Backbone_Trail_(40215228583).jpg | Santa Monica Mountains National Recreation Area | Public domain |
| Backbone Trail sign on Sandstone Peak Trail (52488905507).jpg | https://commons.wikimedia.org/wiki/File:Backbone_Trail_sign_on_Sandstone_Peak_Trail_(52488905507).jpg | Santa Monica Mountains National Recreation Area | Public domain |
| Backcountry campers skiing on road by Bijah Spring (756976fb-5edf-4d91-b1bf-7eb2ab49637a).jpg | https://commons.wikimedia.org/wiki/File:Backcountry_campers_skiing_on_road_by_Bijah_Spring_(756976fb-5edf-4d91-b1bf-7eb2ab49637a).jpg | Jacob W. Frank | Public domain |
| Backcountry skier winter snow HRVC NPS Photo 2-26-12 NPS - J Burger (9) (17186268919).jpg | https://commons.wikimedia.org/wiki/File:Backcountry_skier_winter_snow_HRVC_NPS_Photo_2-26-12_NPS_-_J_Burger_(9)_(17186268919).jpg | Olympic National Park | Public domain |
| Backcountry skiing in the Gallatin Mountains (42157157270).jpg | https://commons.wikimedia.org/wiki/File:Backcountry_skiing_in_the_Gallatin_Mountains_(42157157270).jpg | Yellowstone National Park | Public domain |
| Backcountry skiing on the Pacific Northwest Trail above Sherman Pass, Colville National Forest.jpg | https://commons.wikimedia.org/wiki/File:Backcountry_skiing_on_the_Pacific_Northwest_Trail_above_Sherman_Pass,_Colville_National_Forest.jpg | U.S. Forest Service- Pacific Northwest Region | Public domain |
| Backcounty Skiing in Gold Basin (11740700193).jpg | https://commons.wikimedia.org/wiki/File:Backcounty_Skiing_in_Gold_Basin_(11740700193).jpg | Intermountain Region US Forest Service | Public domain |
| Backcoutry campers ski-touring the Interior of the park (31018628663).jpg | https://commons.wikimedia.org/wiki/File:Backcoutry_campers_ski-touring_the_Interior_of_the_park_(31018628663).jpg | Yellowstone National Park | Public domain |
| Backpacker's tent 1.jpg | https://commons.wikimedia.org/wiki/File:Backpacker%27s_tent_1.jpg | User:Smack | Public domain |
| Backpackers in the Mountains (48920896812).jpg | https://commons.wikimedia.org/wiki/File:Backpackers_in_the_Mountains_(48920896812).jpg | GlacierNPS | Public domain |
| Backpacking (6ec76502-c3a9-46d5-8e6d-09095124dd67).JPG | https://commons.wikimedia.org/wiki/File:Backpacking_(6ec76502-c3a9-46d5-8e6d-09095124dd67).JPG | NPS Photo | Public domain |
| Backpacking Deer Mountain-Tongass-AMB-007 (52503134525).jpg | https://commons.wikimedia.org/wiki/File:Backpacking_Deer_Mountain-Tongass-AMB-007_(52503134525).jpg | Forest Service Alaska Region, USDA | Public domain |
| Backpacking gear backcountry hiking camping visitor hoh rainforest d archuleta march 05 2015 (22453621023).jpg | https://commons.wikimedia.org/wiki/File:Backpacking_gear_backcountry_hiking_camping_visitor_hoh_rainforest_d_archuleta_march_05_2015_(22453621023).jpg | Olympic National Park | Public domain |
| Backpacking in Grand Teton NP-NPS.jpg | https://commons.wikimedia.org/wiki/File:Backpacking_in_Grand_Teton_NP-NPS.jpg | National Park Service | Public domain |
| Backpakers hiking beach camping Zaidel 2013 credit required (22477556413).jpg | https://commons.wikimedia.org/wiki/File:Backpakers_hiking_beach_camping_Zaidel_2013_credit_required_(22477556413).jpg | Olympic National Park | Public domain |
| Baddha Padmasana, Heinz Grill 2026.jpg | https://commons.wikimedia.org/wiki/File:Baddha_Padmasana,_Heinz_Grill_2026.jpg | Alpine16 | CC0 1.0 |
| Bailey Hertenstein USATF 5k road championships 2025.jpg | https://commons.wikimedia.org/wiki/File:Bailey_Hertenstein_USATF_5k_road_championships_2025.jpg | Photoguy1212 | CC0 1.0 |
| Balzers11.jpg | https://commons.wikimedia.org/wiki/File:Balzers11.jpg | Thomaso58x | CC0 1.0 |
| Basketball Game Unites U.S., Iraqis DVIDS291316.jpg | https://commons.wikimedia.org/wiki/File:Basketball_Game_Unites_U.S.,_Iraqis_DVIDS291316.jpg | Spc. Jessica Luhrs-Stabile | Public domain |
| Basketball Hoop in Marin County.jpg | https://commons.wikimedia.org/wiki/File:Basketball_Hoop_in_Marin_County.jpg | Julianibarra | CC0 1.0 |
| Basketball exhibition game as part of the International Youth Day celebration in Kabul.jpg | https://commons.wikimedia.org/wiki/File:Basketball_exhibition_game_as_part_of_the_International_Youth_Day_celebration_in_Kabul.jpg | Sgt. Catherine Threat | Public domain |
| Basketball game 2323995612 8b3e3cd668 o.jpg | https://commons.wikimedia.org/wiki/File:Basketball_game_2323995612_8b3e3cd668_o.jpg | Batistaya | Public domain |
| Basketball game at Camp Lemonnier DVIDS164937.jpg | https://commons.wikimedia.org/wiki/File:Basketball_game_at_Camp_Lemonnier_DVIDS164937.jpg | Tech. Sgt. Dawn Price | Public domain |
| Basketball game at Camp Lemonnier DVIDS164938.jpg | https://commons.wikimedia.org/wiki/File:Basketball_game_at_Camp_Lemonnier_DVIDS164938.jpg | Tech. Sgt. Dawn Price | Public domain |
| Basketball hitting a basketball goal.jpg | https://commons.wikimedia.org/wiki/File:Basketball_hitting_a_basketball_goal.jpg | noahsilliman | CC0 1.0 |
| Basketball hoop.JPG | https://commons.wikimedia.org/wiki/File:Basketball_hoop.JPG | HTO | Public domain |
| Basketball on Camp Delaram II 120506-M-KH643-035.jpg | https://commons.wikimedia.org/wiki/File:Basketball_on_Camp_Delaram_II_120506-M-KH643-035.jpg | Staff Sgt. Raul Gonzalez | Public domain |
| Basketball through hoop.jpg | https://commons.wikimedia.org/wiki/File:Basketball_through_hoop.jpg | Airman 1st Class Kerelin Molina | Public domain |
| Beach asana class, Plage Pereire, Arcachon, 2015.jpg | https://commons.wikimedia.org/wiki/File:Beach_asana_class,_Plage_Pereire,_Arcachon,_2015.jpg | DimiTalen | CC0 1.0 |
| Beach volleyball @ Goshen.JPG | https://commons.wikimedia.org/wiki/File:Beach_volleyball_@_Goshen.JPG | Hochstetler51 (talk) (Uploads) | Public domain |
| Beaver influenced river with kayaking Adirondack NF NY Lauren Healey USFWS (55249209635).jpg | https://commons.wikimedia.org/wiki/File:Beaver_influenced_river_with_kayaking_Adirondack_NF_NY_Lauren_Healey_USFWS_(55249209635).jpg | USFWS Headquarters | Public domain |
| Berlin Marathon 2021 professional runners at Kaisereiche 17.jpg | https://commons.wikimedia.org/wiki/File:Berlin_Marathon_2021_professional_runners_at_Kaisereiche_17.jpg | Leonhard Lenz | CC0 1.0 |
| Berlin, Deutschland (Unsplash sX9 SHIqH4w).jpg | https://commons.wikimedia.org/wiki/File:Berlin,_Deutschland_(Unsplash_sX9_SHIqH4w).jpg | Marvin Ronsdorf marvin_ronsdorf | CC0 1.0 |
| Berlin-Marathon professional runners on Rheinstraße Berlin-Friedenau 2024-09-29 82.jpg | https://commons.wikimedia.org/wiki/File:Berlin-Marathon_professional_runners_on_Rheinstra%C3%9Fe_Berlin-Friedenau_2024-09-29_82.jpg | Leonhard Lenz | CC0 1.0 |
| Between Tête du Petit Torraz and Tête du Christomet, La Giettaz, 2025.jpg | https://commons.wikimedia.org/wiki/File:Between_T%C3%AAte_du_Petit_Torraz_and_T%C3%AAte_du_Christomet,_La_Giettaz,_2025.jpg | DimiTalen | CC0 1.0 |
| Big Horn Mountains Wilderness (9412521351).jpg | https://commons.wikimedia.org/wiki/File:Big_Horn_Mountains_Wilderness_(9412521351).jpg | Bureau of Land Management | Public domain |
| Bike helmet lamps jeh.jpg | https://commons.wikimedia.org/wiki/File:Bike_helmet_lamps_jeh.jpg | Jim.henderson | Public domain |
| Biking Going-to-the-Sun Road in Spring (50047389228).jpg | https://commons.wikimedia.org/wiki/File:Biking_Going-to-the-Sun_Road_in_Spring_(50047389228).jpg | GlacierNPS | Public domain |
| Biking Going-to-the-Sun Road in Spring (50048208467).jpg | https://commons.wikimedia.org/wiki/File:Biking_Going-to-the-Sun_Road_in_Spring_(50048208467).jpg | GlacierNPS | Public domain |
| Billy Goat A Trail Marker to right.jpg | https://commons.wikimedia.org/wiki/File:Billy_Goat_A_Trail_Marker_to_right.jpg | Bonnachoven | CC0 1.0 |
| Billy Goat C Trail (note blue blaze).jpg | https://commons.wikimedia.org/wiki/File:Billy_Goat_C_Trail_(note_blue_blaze).jpg | Bonnachoven | CC0 1.0 |
| Blaze post.jpg | https://commons.wikimedia.org/wiki/File:Blaze_post.jpg | BoFTA2023 | CC0 1.0 |
| Blind Island Kayak (21406918802).jpg | https://commons.wikimedia.org/wiki/File:Blind_Island_Kayak_(21406918802).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Blown (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Blown_(Unsplash).jpg | Sime Basioli simebasioli | CC0 1.0 |
| Boots of a man.jpg | https://commons.wikimedia.org/wiki/File:Boots_of_a_man.jpg | W.carter | CC0 1.0 |
| Boy Kicking Soccer Ball at Adirondack Camp in NY.jpg | https://commons.wikimedia.org/wiki/File:Boy_Kicking_Soccer_Ball_at_Adirondack_Camp_in_NY.jpg | ADKPhoto | CC0 1.0 |
| Boy Scouts earn merit badges with ASG-KU 170428-A-WA114-3196.jpg | https://commons.wikimedia.org/wiki/File:Boy_Scouts_earn_merit_badges_with_ASG-KU_170428-A-WA114-3196.jpg | Sgt. Tom Wade | Public domain |
| Boy and girl hiking along sunlit path in woods with walking sticks and backpack.jpg | https://commons.wikimedia.org/wiki/File:Boy_and_girl_hiking_along_sunlit_path_in_woods_with_walking_sticks_and_backpack.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Branäs skiing (3403036968).jpg | https://commons.wikimedia.org/wiki/File:Bran%C3%A4s_skiing_(3403036968).jpg | svensmail from Stockholm, Sweden | CC0 1.0 |
| Brendan Egan Soccer Player Summer2025.jpg | https://commons.wikimedia.org/wiki/File:Brendan_Egan_Soccer_Player_Summer2025.jpg | User76432 | CC0 1.0 |
| Brendan Martin at Berlin-Marathon 2025-09-21 01.jpg | https://commons.wikimedia.org/wiki/File:Brendan_Martin_at_Berlin-Marathon_2025-09-21_01.jpg | Leonhard Lenz | CC0 1.0 |
| Brendan Martin at Berlin-Marathon 2025-09-21 02.jpg | https://commons.wikimedia.org/wiki/File:Brendan_Martin_at_Berlin-Marathon_2025-09-21_02.jpg | Leonhard Lenz | CC0 1.0 |
| Brian Barraza USATF 5k 2025.jpg | https://commons.wikimedia.org/wiki/File:Brian_Barraza_USATF_5k_2025.jpg | Photoguy1212 | CC0 1.0 |
| Byhleguhre Byhlen 03.JPG | https://commons.wikimedia.org/wiki/File:Byhleguhre_Byhlen_03.JPG | J.-H. Janßen | CC0 1.0 |
| CJTF-HOA and Camp Lemonnier members participate in the Joint Warrior Competition 161119-Z-CT752-647.jpg | https://commons.wikimedia.org/wiki/File:CJTF-HOA_and_Camp_Lemonnier_members_participate_in_the_Joint_Warrior_Competition_161119-Z-CT752-647.jpg | Staff Sgt. Penny Snoozy | Public domain |
| California Trail Marker (54445961730).jpg | https://commons.wikimedia.org/wiki/File:California_Trail_Marker_(54445961730).jpg | BLM Nevada | Public domain |
| Camel Bicycle Light tent near Beryozovka River.jpg | https://commons.wikimedia.org/wiki/File:Camel_Bicycle_Light_tent_near_Beryozovka_River.jpg | Alexander Roumega | CC0 1.0 |
| Camelia Fallah Iranian football player (Women's football player) 2.jpg | https://commons.wikimedia.org/wiki/File:Camelia_Fallah_Iranian_football_player_(Women%27s_football_player)_2.jpg | Monaaphotos | CC0 1.0 |
| Camelia Fallah Iranian football player Female football 3 - 53672198666.jpg | https://commons.wikimedia.org/wiki/File:Camelia_Fallah_Iranian_football_player_Female_football_3_-_53672198666.jpg | Monaaphotos | CC0 1.0 |
| Camelia Fallah Iranian women's football soccer player - 53672645465.jpg | https://commons.wikimedia.org/wiki/File:Camelia_Fallah_Iranian_women%27s_football_soccer_player_-_53672645465.jpg | Monaaphotos | CC0 1.0 |
| Camping (06699db807444651965755a8e18094ac).JPG | https://commons.wikimedia.org/wiki/File:Camping_(06699db807444651965755a8e18094ac).JPG | Steve Redman (MORA) | Public domain |
| Camping Along Lake Powell (51277787710).jpg | https://commons.wikimedia.org/wiki/File:Camping_Along_Lake_Powell_(51277787710).jpg | USFWS Mountain Prairie | Public domain |
| Camping at Juanita Lake (9a14be10de1f4d3da2528299be3acbec).jpg | https://commons.wikimedia.org/wiki/File:Camping_at_Juanita_Lake_(9a14be10de1f4d3da2528299be3acbec).jpg | NPS Staff | Public domain |
| Camping by Barriere Lake, British Columbia - 20040801.jpg | https://commons.wikimedia.org/wiki/File:Camping_by_Barriere_Lake,_British_Columbia_-_20040801.jpg | Koppertone (Justin Kopp). | Public domain |
| Camping food outdoor.jpg | https://commons.wikimedia.org/wiki/File:Camping_food_outdoor.jpg | PXHERE | CC0 1.0 |
| Camping in Glacier Park (4419706203).jpg | https://commons.wikimedia.org/wiki/File:Camping_in_Glacier_Park_(4419706203).jpg | GlacierNPS | Public domain |
| Camping in San Gorgonio (16317876776).jpg | https://commons.wikimedia.org/wiki/File:Camping_in_San_Gorgonio_(16317876776).jpg | blmcalifornia | Public domain |
| Camping in monongahela national forest West Virginia - 3.jpg | https://commons.wikimedia.org/wiki/File:Camping_in_monongahela_national_forest_West_Virginia_-_3.jpg | pickupimage | CC0 1.0 |
| Camping lasy.jpg | https://commons.wikimedia.org/wiki/File:Camping_lasy.jpg | Mizrahim23 | CC0 1.0 |
| Camping on the Great Kobuk Sand Dunes is great, except for the sand that gets in EVERYTHING when the wind blows. Close view of (9316a08b-1dd8-b71c-07e2-01838a4d1981).jpg | https://commons.wikimedia.org/wiki/File:Camping_on_the_Great_Kobuk_Sand_Dunes_is_great,_except_for_the_sand_that_gets_in_EVERYTHING_when_the_wind_blows._Close_view_of_(9316a08b-1dd8-b71c-07e2-01838a4d1981).jpg | NPS Photo | Public domain |
| Camping tent on tarp.jpg | https://commons.wikimedia.org/wiki/File:Camping_tent_on_tarp.jpg | Eden Aviv | CC0 1.0 |
| Camping-Chair 001.jpg | https://commons.wikimedia.org/wiki/File:Camping-Chair_001.jpg | Ocdp | CC0 1.0 |
| Campù Inferiore, guidepost at beginning of hiking path 12.jpg | https://commons.wikimedia.org/wiki/File:Camp%C3%B9_Inferiore,_guidepost_at_beginning_of_hiking_path_12.jpg | Kaitu | CC0 1.0 |
| Canadian swimmer at 2019 international tournament.jpg | https://commons.wikimedia.org/wiki/File:Canadian_swimmer_at_2019_international_tournament.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Canadian swimmer.jpg | https://commons.wikimedia.org/wiki/File:Canadian_swimmer.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Canoe (125764667).jpeg | https://commons.wikimedia.org/wiki/File:Canoe_(125764667).jpeg | Michael Tighe | CC0 1.0 |
| Canoe Hawaii.jpg | https://commons.wikimedia.org/wiki/File:Canoe_Hawaii.jpg | DaisyField at English Wikipedia | Public domain |
| Canoe on a creek (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Canoe_on_a_creek_(Unsplash).jpg | Dan Chung dannayyyboi | CC0 1.0 |
| Canoe roque gageac.jpg | https://commons.wikimedia.org/wiki/File:Canoe_roque_gageac.jpg | Jebulon | CC0 1.0 |
| Canoeing across the river.jpeg | https://commons.wikimedia.org/wiki/File:Canoeing_across_the_river.jpeg | Grandmaster Huon | CC0 1.0 |
| Canoeing at Paris 2024 Olympics. Canoeing slalom qualif. 27th of july 2024 -1.jpg | https://commons.wikimedia.org/wiki/File:Canoeing_at_Paris_2024_Olympics._Canoeing_slalom_qualif._27th_of_july_2024_-1.jpg | Zen 38 | CC0 1.0 |
| Canoeing at Paris 2024 Olympics. Canoeing slalom qualif. 27th of july 2024 -2.jpg | https://commons.wikimedia.org/wiki/File:Canoeing_at_Paris_2024_Olympics._Canoeing_slalom_qualif._27th_of_july_2024_-2.jpg | Zen 38 | CC0 1.0 |
| Canoeing down the rapids (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Canoeing_down_the_rapids_(Unsplash).jpg | Felix Serre felix_serre | CC0 1.0 |
| Canoeing on river.jpg | https://commons.wikimedia.org/wiki/File:Canoeing_on_river.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Canoeing sport.jpg | https://commons.wikimedia.org/wiki/File:Canoeing_sport.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Canoeing the Mississippi River (6d212fb0-1dd8-b71b-0b6c-03008980c689).jpg | https://commons.wikimedia.org/wiki/File:Canoeing_the_Mississippi_River_(6d212fb0-1dd8-b71b-0b6c-03008980c689).jpg | NPS | Public domain |
| Carrauntoohil, Co Kerry, Ireland. ascent of Carrauntoohil.jpg | https://commons.wikimedia.org/wiki/File:Carrauntoohil,_Co_Kerry,_Ireland._ascent_of_Carrauntoohil.jpg | Ridiculopathy | CC0 1.0 |
| Celia Hunter on a kayaking trip in Southeast Alaska 1981 02.jpg | https://commons.wikimedia.org/wiki/File:Celia_Hunter_on_a_kayaking_trip_in_Southeast_Alaska_1981_02.jpg | Cbarlow | CC0 1.0 |
| Cereals-spelt-flakes-oatmeal-jar.jpg | https://commons.wikimedia.org/wiki/File:Cereals-spelt-flakes-oatmeal-jar.jpg | https://pixabay.com/users/congerdesign-509903/ | CC0 1.0 |
| Charity football match Legia - Den Haag 3.JPG | https://commons.wikimedia.org/wiki/File:Charity_football_match_Legia_-_Den_Haag_3.JPG | Albertyanks - Albert Jankowski | Public domain |
| Charity football match Legia - Den Haag 4.JPG | https://commons.wikimedia.org/wiki/File:Charity_football_match_Legia_-_Den_Haag_4.JPG | Albertyanks - Albert Jankowski | Public domain |
| Chasing the horizon.jpg | https://commons.wikimedia.org/wiki/File:Chasing_the_horizon.jpg | Patrick Fore | CC0 1.0 |
| Cherantha De Silva wins his race at the NCAA DIII Swimming Championships 2023.jpg | https://commons.wikimedia.org/wiki/File:Cherantha_De_Silva_wins_his_race_at_the_NCAA_DIII_Swimming_Championships_2023.jpg | Ceyanodes | CC0 1.0 |
| Chewy-Granola-Bar.jpg | https://commons.wikimedia.org/wiki/File:Chewy-Granola-Bar.jpg | Evan-Amos | CC0 1.0 |
| Child Care Recipes (Team Nutiriton) (20211231-FNS-UNC-0025).jpg | https://commons.wikimedia.org/wiki/File:Child_Care_Recipes_(Team_Nutiriton)_(20211231-FNS-UNC-0025).jpg | USDAgov | Public domain |
| Children playing football after school.jpg | https://commons.wikimedia.org/wiki/File:Children_playing_football_after_school.jpg | Samson Ssemakadde | CC0 1.0 |
| Choco date.jpg | https://commons.wikimedia.org/wiki/File:Choco_date.jpg | Swethasakthivel | CC0 1.0 |
| Coffee Station, Olsztyn, Poland (Unsplash iQISyMFR0RM).jpg | https://commons.wikimedia.org/wiki/File:Coffee_Station,_Olsztyn,_Poland_(Unsplash_iQISyMFR0RM).jpg | freestocks.org freestocks | CC0 1.0 |
| Colorful barbells on a shelf.jpg | https://commons.wikimedia.org/wiki/File:Colorful_barbells_on_a_shelf.jpg | Ser Amantio di Nicolao | CC0 1.0 |
| Community Yoga (47942354753).jpg | https://commons.wikimedia.org/wiki/File:Community_Yoga_(47942354753).jpg | Greenville Daily Photo | CC0 1.0 |
| Competitions everywhere.jpg | https://commons.wikimedia.org/wiki/File:Competitions_everywhere.jpg | MUGISHA Aime Kevin | CC0 1.0 |
| Connect Fitness Kingswood Campus.jpg | https://commons.wikimedia.org/wiki/File:Connect_Fitness_Kingswood_Campus.jpg | Jim Workout | Public domain |
| Contra, guidepost at beginning of hiking path 13.jpg | https://commons.wikimedia.org/wiki/File:Contra,_guidepost_at_beginning_of_hiking_path_13.jpg | Kaitu | CC0 1.0 |
| Copa Municipal de Futsal Edinho Watanabe (51671085920).jpg | https://commons.wikimedia.org/wiki/File:Copa_Municipal_de_Futsal_Edinho_Watanabe_(51671085920).jpg | ASCOM Prefeitura de Votuporanga | Public domain |
| Cornish Way & Clay Trails signpost, St Andrew's Road Nature Reserve, Par, Cornwall - February 2024.jpg | https://commons.wikimedia.org/wiki/File:Cornish_Way_%26_Clay_Trails_signpost,_St_Andrew%27s_Road_Nature_Reserve,_Par,_Cornwall_-_February_2024.jpg | Mutney | CC0 1.0 |
| Courtney Frerichs USATF 5k road championships 2025.jpg | https://commons.wikimedia.org/wiki/File:Courtney_Frerichs_USATF_5k_road_championships_2025.jpg | Photoguy1212 | CC0 1.0 |
| Crater Lake camp (40518615052).jpg | https://commons.wikimedia.org/wiki/File:Crater_Lake_camp_(40518615052).jpg | BLMIdaho | Public domain |
| Cross Country Skiing (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Cross_Country_Skiing_(Unsplash).jpg | Alec Moore alecmmoore | CC0 1.0 |
| Cross Country Skiing Belden Ave Chicago Feb 2 2011.JPG | https://commons.wikimedia.org/wiki/File:Cross_Country_Skiing_Belden_Ave_Chicago_Feb_2_2011.JPG | Victorgrigas (talk) (Uploads) | CC0 1.0 |
| Cross country ski trailhead (49525428813).jpg | https://commons.wikimedia.org/wiki/File:Cross_country_ski_trailhead_(49525428813).jpg | USFWS Mountain Prairie | Public domain |
| Cross country skier skiing Hurricane ridge winter (16682640473).jpg | https://commons.wikimedia.org/wiki/File:Cross_country_skier_skiing_Hurricane_ridge_winter_(16682640473).jpg | Olympic National Park | Public domain |
| Cross-country skiing (52065752002).jpg | https://commons.wikimedia.org/wiki/File:Cross-country_skiing_(52065752002).jpg | GrandTetonNPS | Public domain |
| Cross-country skiing (52066798416).jpg | https://commons.wikimedia.org/wiki/File:Cross-country_skiing_(52066798416).jpg | GrandTetonNPS | Public domain |
| Cross-country skiing on the TPR (52066791746).jpg | https://commons.wikimedia.org/wiki/File:Cross-country_skiing_on_the_TPR_(52066791746).jpg | GrandTetonNPS | Public domain |
| Cross-country skiing on the TPR (52066805413).jpg | https://commons.wikimedia.org/wiki/File:Cross-country_skiing_on_the_TPR_(52066805413).jpg | GrandTetonNPS | Public domain |
| Cross-country skiing with clear sky.jpg | https://commons.wikimedia.org/wiki/File:Cross-country_skiing_with_clear_sky.jpg | Martin Eklund | CC0 1.0 |
| Crosscountry skiers winter skiing snowshoeing (17346541916).jpg | https://commons.wikimedia.org/wiki/File:Crosscountry_skiers_winter_skiing_snowshoeing_(17346541916).jpg | Olympic National Park | Public domain |
| Cube acid 2011.JPG | https://commons.wikimedia.org/wiki/File:Cube_acid_2011.JPG | JHenryW | Public domain |
| Cuidepost Supillette, 1584 m, left points to Northwest.jpg | https://commons.wikimedia.org/wiki/File:Cuidepost_Supillette,_1584_m,_left_points_to_Northwest.jpg | Vmicho | CC0 1.0 |
| Custom Blend of Grains, Nuts, and Seeds for Homemade Porridge Flour.jpg | https://commons.wikimedia.org/wiki/File:Custom_Blend_of_Grains,_Nuts,_and_Seeds_for_Homemade_Porridge_Flour.jpg | Uthman salma | CC0 1.0 |
| Cyclists on Park Boulevard (51194319164).jpg | https://commons.wikimedia.org/wiki/File:Cyclists_on_Park_Boulevard_(51194319164).jpg | Joshua Tree National Park | Public domain |
| Cyclists step on their pedals and accelerate at the starting lane of the 7-Eleven Trail 2018 (711-trail).jpg | https://commons.wikimedia.org/wiki/File:Cyclists_step_on_their_pedals_and_accelerate_at_the_starting_lane_of_the_7-Eleven_Trail_2018_(711-trail).jpg | PNA photo by Joey O. Razon | Public domain |
| Cyklosmerovnik Hlohovec vyhliadka Sianec.jpg | https://commons.wikimedia.org/wiki/File:Cyklosmerovnik_Hlohovec_vyhliadka_Sianec.jpg | Szombat78 | CC0 1.0 |
| D-6. The Pony Express National Back Country Byway Visitor Information Site on the Pony Express National Historic Trail (2008) (f64d8437-bef3-4166-adb4-69a99797731f).jpg | https://commons.wikimedia.org/wiki/File:D-6._The_Pony_Express_National_Back_Country_Byway_Visitor_Information_Site_on_the_Pony_Express_National_Historic_Trail_(2008)_(f64d8437-bef3-4166-adb4-69a99797731f).jpg | NPS | Public domain |
| D-BW-Bodenseekreis - Jubiläumsweg - Wegzeichen gross.jpg | https://commons.wikimedia.org/wiki/File:D-BW-Bodenseekreis_-_Jubil%C3%A4umsweg_-_Wegzeichen_gross.jpg | ANKAWÜ | Public domain |
| DJ Alleyoop Col du tit sint bernard.jpg | https://commons.wikimedia.org/wiki/File:DJ_Alleyoop_Col_du_tit_sint_bernard.jpg | LanaVDS | CC0 1.0 |
| Daibutsu trail signpost, Kamakura.jpg | https://commons.wikimedia.org/wiki/File:Daibutsu_trail_signpost,_Kamakura.jpg | DimiTalen | CC0 1.0 |
| Daniel W. Schnurrenberger in the kayak competition at the 1984 Summer Olympics..JPEG | https://commons.wikimedia.org/wiki/File:Daniel_W._Schnurrenberger_in_the_kayak_competition_at_the_1984_Summer_Olympics..JPEG | Cecil Stack | Public domain |
| Daolasa ski and ride.JPG | https://commons.wikimedia.org/wiki/File:Daolasa_ski_and_ride.JPG | abxbay | Public domain |
| Davy Crockett Explorer Canoes.JPG | https://commons.wikimedia.org/wiki/File:Davy_Crockett_Explorer_Canoes.JPG | SolarSurfer | Public domain |
| Dawn Burrell is running (2).JPEG | https://commons.wikimedia.org/wiki/File:Dawn_Burrell_is_running_(2).JPEG | TSGT Rick Sforza | Public domain |
| Day Hiking in Covington Area (52013987901).jpg | https://commons.wikimedia.org/wiki/File:Day_Hiking_in_Covington_Area_(52013987901).jpg | Joshua Tree National Park | Public domain |
| Day hiking Sepulcher Mountain (18612672972).jpg | https://commons.wikimedia.org/wiki/File:Day_hiking_Sepulcher_Mountain_(18612672972).jpg | Yellowstone National Park from Yellowstone NP, USA | Public domain |
| Day-hiking in Denali (452e09a2-adf4-48e2-a2d7-b58b5592c6e0).jpg | https://commons.wikimedia.org/wiki/File:Day-hiking_in_Denali_(452e09a2-adf4-48e2-a2d7-b58b5592c6e0).jpg | Jacob W. Frank | Public domain |
| Defense.gov photo essay 070402-D-1142M-025.jpg | https://commons.wikimedia.org/wiki/File:Defense.gov_photo_essay_070402-D-1142M-025.jpg | William D. Moss | Public domain |
| Defense.gov photo essay 070403-D-1142M-024.jpg | https://commons.wikimedia.org/wiki/File:Defense.gov_photo_essay_070403-D-1142M-024.jpg | William D. Moss | Public domain |
| Diadora CAMARO M2 sneaker.jpg | https://commons.wikimedia.org/wiki/File:Diadora_CAMARO_M2_sneaker.jpg | WorldTravleerAndPhotoTaker | CC0 1.0 |
| Dickinson Law Yoga Offering.jpg | https://commons.wikimedia.org/wiki/File:Dickinson_Law_Yoga_Offering.jpg | Jeremy Hess Photography | CC0 1.0 |
| Diepoldsau near Zoll, small guidepost for hiking abd biking.jpg | https://commons.wikimedia.org/wiki/File:Diepoldsau_near_Zoll,_small_guidepost_for_hiking_abd_biking.jpg | Vmicho | CC0 1.0 |
| Dinner is being prepared.jpg | https://commons.wikimedia.org/wiki/File:Dinner_is_being_prepared.jpg | Vsatinet | CC0 1.0 |
| Directional signpost pointing towards Moccasin Lake in Moccasin Lake Nature Park, Clearwater, Florida, 19 March 2024.jpg | https://commons.wikimedia.org/wiki/File:Directional_signpost_pointing_towards_Moccasin_Lake_in_Moccasin_Lake_Nature_Park,_Clearwater,_Florida,_19_March_2024.jpg | MatthewHoobin | CC0 1.0 |
| Dirt trail through dry grassland on the Santa Rosa Plateau, California.jpg | https://commons.wikimedia.org/wiki/File:Dirt_trail_through_dry_grassland_on_the_Santa_Rosa_Plateau,_California.jpg | Z3lvs | CC0 1.0 |
| Disneyland-DCEC-canoes.jpg | https://commons.wikimedia.org/wiki/File:Disneyland-DCEC-canoes.jpg | Jonnyboyca. | Public domain |
| Dispersed Camping at Bald Mountain (50534679031).jpg | https://commons.wikimedia.org/wiki/File:Dispersed_Camping_at_Bald_Mountain_(50534679031).jpg | blmcalifornia | Public domain |
| Domenici Fitness Center 140826-F-ZB149-001.jpg | https://commons.wikimedia.org/wiki/File:Domenici_Fitness_Center_140826-F-ZB149-001.jpg | Airman 1st Class Aaron Montoya | Public domain |
| Dona (beach soccer).jpg | https://commons.wikimedia.org/wiki/File:Dona_(beach_soccer).jpg | Voltmetro | CC0 1.0 |
| Dooagh Trail marker.jpg | https://commons.wikimedia.org/wiki/File:Dooagh_Trail_marker.jpg | A.-K. D. | CC0 1.0 |
| Dooagh Trail old marker.jpg | https://commons.wikimedia.org/wiki/File:Dooagh_Trail_old_marker.jpg | A.-K. D. | CC0 1.0 |
| Dooagh triple trail marker.jpg | https://commons.wikimedia.org/wiki/File:Dooagh_triple_trail_marker.jpg | A.-K. D. | CC0 1.0 |
| Downhill Skiing, Lookout Pass (40749760352).jpg | https://commons.wikimedia.org/wiki/File:Downhill_Skiing,_Lookout_Pass_(40749760352).jpg | Forest Service Northern Region from Missoula, MT, USA | Public domain |
| Downhill Skiing, Lookout Pass (40749762032).jpg | https://commons.wikimedia.org/wiki/File:Downhill_Skiing,_Lookout_Pass_(40749762032).jpg | Forest Service Northern Region from Missoula, MT, USA | Public domain |
| Downhill-Racer.jpg | https://commons.wikimedia.org/wiki/File:Downhill-Racer.jpg | Ingo Kappherr | Public domain |
| Drena 2.jpg | https://commons.wikimedia.org/wiki/File:Drena_2.jpg | Thomasox58 | CC0 1.0 |
| Drena 5.jpg | https://commons.wikimedia.org/wiki/File:Drena_5.jpg | Thomasox58 | CC0 1.0 |
| Dumbell aerobics.jpg | https://commons.wikimedia.org/wiki/File:Dumbell_aerobics.jpg | Amanda Mills, USCDCP | Public domain |
| E4 route marker Bulgaria.JPG | https://commons.wikimedia.org/wiki/File:E4_route_marker_Bulgaria.JPG | Preslav | Public domain |
| East Applegate Ridge Trail (40237273790).jpg | https://commons.wikimedia.org/wiki/File:East_Applegate_Ridge_Trail_(40237273790).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| East Applegate Ridge Trail (42000618662).jpg | https://commons.wikimedia.org/wiki/File:East_Applegate_Ridge_Trail_(42000618662).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| East Applegate Ridge Trail Sign (40237272940).jpg | https://commons.wikimedia.org/wiki/File:East_Applegate_Ridge_Trail_Sign_(40237272940).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| Eight Dollar Mountain & Jeffrey Pine Loop Trail (32260255893).jpg | https://commons.wikimedia.org/wiki/File:Eight_Dollar_Mountain_%26_Jeffrey_Pine_Loop_Trail_(32260255893).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| Eight Dollar Mountain & Jeffrey Pine Loop Trail (33075776165).jpg | https://commons.wikimedia.org/wiki/File:Eight_Dollar_Mountain_%26_Jeffrey_Pine_Loop_Trail_(33075776165).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| Elroy Municipal Swimming Pool.jpg | https://commons.wikimedia.org/wiki/File:Elroy_Municipal_Swimming_Pool.jpg | Wadesuhr | CC0 1.0 |
| Equipe de volleyball Trappeurs de Marie-Victorin 2023 1.jpg | https://commons.wikimedia.org/wiki/File:Equipe_de_volleyball_Trappeurs_de_Marie-Victorin_2023_1.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Eric Musselman on sideline during Nevada at Air Force basketball game, March 7, 2019.jpg | https://commons.wikimedia.org/wiki/File:Eric_Musselman_on_sideline_during_Nevada_at_Air_Force_basketball_game,_March_7,_2019.jpg | Bill Evans, United States Air Force Academy | Public domain |
| Eutal4.jpg | https://commons.wikimedia.org/wiki/File:Eutal4.jpg | Thomasox58 | CC0 1.0 |
| Euthal7.jpg | https://commons.wikimedia.org/wiki/File:Euthal7.jpg | Thomasox58 | CC0 1.0 |
| Evan and Adrie Redman backpacking near Snag Mountain, on PCT north of White Pass. (4473a4c930f24fbcb5f6ce3964ed5184).JPG | https://commons.wikimedia.org/wiki/File:Evan_and_Adrie_Redman_backpacking_near_Snag_Mountain,_on_PCT_north_of_White_Pass._(4473a4c930f24fbcb5f6ce3964ed5184).JPG | Steve Redman (MORA) | Public domain |
| Evan and Adrie Redman on 1st Burroughs Mountain trail, looking down on Frozen Lake. (578f41c080a249788e110ade41356062).JPG | https://commons.wikimedia.org/wiki/File:Evan_and_Adrie_Redman_on_1st_Burroughs_Mountain_trail,_looking_down_on_Frozen_Lake._(578f41c080a249788e110ade41356062).JPG | Steve Redman (MORA) | Public domain |
| Everything the light touches (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Everything_the_light_touches_(Unsplash).jpg | Cody Black cblack09 | CC0 1.0 |
| Exercise equipment (rubber ball, light-weight dumbbells, jump rope).jpg | https://commons.wikimedia.org/wiki/File:Exercise_equipment_(rubber_ball,_light-weight_dumbbells,_jump_rope).jpg | CDC/ Debora Cartagena | Public domain |
| F6 Camp, Lake Fryxell 2006 01.jpg | https://commons.wikimedia.org/wiki/File:F6_Camp,_Lake_Fryxell_2006_01.jpg | Emily Stone, NSF | Public domain |
| FC Liefering gegen SV Horn (29. August 2014) 12.JPG | https://commons.wikimedia.org/wiki/File:FC_Liefering_gegen_SV_Horn_(29._August_2014)_12.JPG | Werner100359 | CC0 1.0 |
| FKNMS kayaker stewardship (50093889558).jpg | https://commons.wikimedia.org/wiki/File:FKNMS_kayaker_stewardship_(50093889558).jpg | National Marine Sanctuaries | Public domain |
| Female kayaker paddles.jpg | https://commons.wikimedia.org/wiki/File:Female_kayaker_paddles.jpg | Laubenstein Ronald, U.S. Fish and Wildlife Service | Public domain |
| Fingerpost Podebrady.jpg | https://commons.wikimedia.org/wiki/File:Fingerpost_Podebrady.jpg | Perituss | CC0 1.0 |
| Fingerpost in Stanmer, Brighton, UK.jpg | https://commons.wikimedia.org/wiki/File:Fingerpost_in_Stanmer,_Brighton,_UK.jpg | Andy Li | CC0 1.0 |
| Finish Tour de Pologne 2009.jpg | https://commons.wikimedia.org/wiki/File:Finish_Tour_de_Pologne_2009.jpg | Przystanis at Polish Wikipedia | Public domain |
| Fishing and Boating (20230416-FS-PJK-8054).jpg | https://commons.wikimedia.org/wiki/File:Fishing_and_Boating_(20230416-FS-PJK-8054).jpg | Forest Service Photography | Public domain |
| Fitness Center Equipment.jpg | https://commons.wikimedia.org/wiki/File:Fitness_Center_Equipment.jpg | Teddyhtsai | CC0 1.0 |
| Fitness Center, TWA Hotel, John F. Kennedy International Airport, Jamaica, Queens, New York City, NY - 48581237157.jpg | https://commons.wikimedia.org/wiki/File:Fitness_Center,_TWA_Hotel,_John_F._Kennedy_International_Airport,_Jamaica,_Queens,_New_York_City,_NY_-_48581237157.jpg | w_lemay | CC0 1.0 |
| Fitness Center, TWA Hotel, John F. Kennedy International Airport, Jamaica, Queens, New York City, NY.jpg | https://commons.wikimedia.org/wiki/File:Fitness_Center,_TWA_Hotel,_John_F._Kennedy_International_Airport,_Jamaica,_Queens,_New_York_City,_NY.jpg | w_lemay | CC0 1.0 |
| Fitness center showcases new functional fitness box (4384841).jpg | https://commons.wikimedia.org/wiki/File:Fitness_center_showcases_new_functional_fitness_box_(4384841).jpg | U.S. Air Force photo by Senior Airman Arielle Vasquez | Public domain |
| Flickr - Official U.S. Navy Imagery - A New Zealand sailor participating in the Rim of the Pacific 2012 exercise, paddles his kayak during a kayak race..jpg | https://commons.wikimedia.org/wiki/File:Flickr_-_Official_U.S._Navy_Imagery_-_A_New_Zealand_sailor_participating_in_the_Rim_of_the_Pacific_2012_exercise,_paddles_his_kayak_during_a_kayak_race..jpg | Official Navy Page from United States of America Mass Communication Specialist 1s/U.S. Navy | Public domain |
| Flickr - Official U.S. Navy Imagery - A U.S. Navy veteran and 2012 U.S. Paralympic road cyclist rides past fans..jpg | https://commons.wikimedia.org/wiki/File:Flickr_-_Official_U.S._Navy_Imagery_-_A_U.S._Navy_veteran_and_2012_U.S._Paralympic_road_cyclist_rides_past_fans..jpg | Official Navy Page from United States of America | Public domain |
| Flickr - Official U.S. Navy Imagery - A U.S. Navy veteran and 2012 U.S. Paralympic road cyclist, rides his first lap..jpg | https://commons.wikimedia.org/wiki/File:Flickr_-_Official_U.S._Navy_Imagery_-_A_U.S._Navy_veteran_and_2012_U.S._Paralympic_road_cyclist,_rides_his_first_lap..jpg | Official Navy Page from United States of America | Public domain |
| FloridaTrailSignpost.JPG | https://commons.wikimedia.org/wiki/File:FloridaTrailSignpost.JPG | Brickman1000 Brickman Way | Public domain |
| Fly, Shoes, Fly (2188875452).jpg | https://commons.wikimedia.org/wiki/File:Fly,_Shoes,_Fly_(2188875452).jpg | cogdogblog | CC0 1.0 |
| Fläsch1.jpg | https://commons.wikimedia.org/wiki/File:Fl%C3%A4sch1.jpg | Thomaso58x | CC0 1.0 |
| Fogrey-Raad-ny-Foillan.jpg | https://commons.wikimedia.org/wiki/File:Fogrey-Raad-ny-Foillan.jpg | Shimmin Beg | Public domain |
| Football items signed by famous players, at Soccer Shop Kamo in Harajuku (67101).jpg | https://commons.wikimedia.org/wiki/File:Football_items_signed_by_famous_players,_at_Soccer_Shop_Kamo_in_Harajuku_(67101).jpg | Syced | CC0 1.0 |
| Football items signed by famous players, at Soccer Shop Kamo in Harajuku.jpg | https://commons.wikimedia.org/wiki/File:Football_items_signed_by_famous_players,_at_Soccer_Shop_Kamo_in_Harajuku.jpg | Syced | CC0 1.0 |
| Football kick-off.jpg | https://commons.wikimedia.org/wiki/File:Football_kick-off.jpg | Digitura | CC0 1.0 |
| Football match among young female Nigerians.jpg | https://commons.wikimedia.org/wiki/File:Football_match_among_young_female_Nigerians.jpg | Mayor Daniel | CC0 1.0 |
| Football match at Porvorim Rd, Saligao, Goa.jpg | https://commons.wikimedia.org/wiki/File:Football_match_at_Porvorim_Rd,_Saligao,_Goa.jpg | Lalitsaraswat at English Wikipedia | Public domain |
| Football on fall ground (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Football_on_fall_ground_(Unsplash).jpg | C MA chancema | CC0 1.0 |
| Football stadium early 1980s.jpg | https://commons.wikimedia.org/wiki/File:Football_stadium_early_1980s.jpg | Digitura | CC0 1.0 |
| Forest trail in Põhja-Kõrvemaa, 2011-01.jpg | https://commons.wikimedia.org/wiki/File:Forest_trail_in_P%C3%B5hja-K%C3%B5rvemaa,_2011-01.jpg | Hannu | Public domain |
| Forest trail in Põhja-Kõrvemaa, 2011-09.jpg | https://commons.wikimedia.org/wiki/File:Forest_trail_in_P%C3%B5hja-K%C3%B5rvemaa,_2011-09.jpg | Hannu | Public domain |
| Forest trail in Põhja-Kõrvemaa, May 2010.jpg | https://commons.wikimedia.org/wiki/File:Forest_trail_in_P%C3%B5hja-K%C3%B5rvemaa,_May_2010.jpg | Hannu | Public domain |
| Former Uganda soccer players.jpg | https://commons.wikimedia.org/wiki/File:Former_Uganda_soccer_players.jpg | Samson Ssemakadde | CC0 1.0 |
| Forno-AlpeCortone guidepost 2024-09-15.jpg | https://commons.wikimedia.org/wiki/File:Forno-AlpeCortone_guidepost_2024-09-15.jpg | Sborgolotto | CC0 1.0 |
| Freestanding basketball net (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Freestanding_basketball_net_(Unsplash).jpg | Andy Hu humadethis | CC0 1.0 |
| Futsal DVIDS249733.jpg | https://commons.wikimedia.org/wiki/File:Futsal_DVIDS249733.jpg | U.S. Navy photo by Petty Officer 1st Class Josh Huebner/Released | Public domain |
| Futsal DVIDS249734.jpg | https://commons.wikimedia.org/wiki/File:Futsal_DVIDS249734.jpg | LCC 19 | Public domain |
| GD 廣東 Guangdong 廣州 Guangzhou NEWORLD Plaza mall shop gym fitness June 2025 R12S 01.jpg | https://commons.wikimedia.org/wiki/File:GD_%E5%BB%A3%E6%9D%B1_Guangdong_%E5%BB%A3%E5%B7%9E_Guangzhou_NEWORLD_Plaza_mall_shop_gym_fitness_June_2025_R12S_01.jpg | MeiOLA 2290 WMENSZ | CC0 1.0 |
| GT Zaskar 2012.JPG | https://commons.wikimedia.org/wiki/File:GT_Zaskar_2012.JPG | Rudolphs no uno | CC0 1.0 |
| Galaxy Smoothie Bowl (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Galaxy_Smoothie_Bowl_(Unsplash).jpg | Brenda Godinez cravethebenefits | CC0 1.0 |
| Gardada3.jpg | https://commons.wikimedia.org/wiki/File:Gardada3.jpg | Thomasox58 | CC0 1.0 |
| Gardada5.jpg | https://commons.wikimedia.org/wiki/File:Gardada5.jpg | Thomasox58 | CC0 1.0 |
| Gate and public bridleway signpost at the north east end of Devil's Dyke 2025-08-26.jpg | https://commons.wikimedia.org/wiki/File:Gate_and_public_bridleway_signpost_at_the_north_east_end_of_Devil%27s_Dyke_2025-08-26.jpg | Andy Li | CC0 1.0 |
| Gate and signpost between South Downs Way and Big Bottom 2025-09-08.jpg | https://commons.wikimedia.org/wiki/File:Gate_and_signpost_between_South_Downs_Way_and_Big_Bottom_2025-09-08.jpg | Andy Li | CC0 1.0 |
| Gemünden-Wehr04.jpg | https://commons.wikimedia.org/wiki/File:Gem%C3%BCnden-Wehr04.jpg | Prankster | Public domain |
| Get-a-Ride Madeira Mountain Bike Coastal View.jpg | https://commons.wikimedia.org/wiki/File:Get-a-Ride_Madeira_Mountain_Bike_Coastal_View.jpg | Getaridemadeira | CC0 1.0 |
| Get-a-Ride Madeira Mountain Bike.jpg | https://commons.wikimedia.org/wiki/File:Get-a-Ride_Madeira_Mountain_Bike.jpg | Getaridemadeira | CC0 1.0 |
| Gfp-weight.jpg | https://commons.wikimedia.org/wiki/File:Gfp-weight.jpg | Yinan Chen | Public domain |
| Giant bike that Alexander Vinokourov used 20060203.jpg | https://commons.wikimedia.org/wiki/File:Giant_bike_that_Alexander_Vinokourov_used_20060203.jpg | No machine-readable author provided. Mikelo assumed (based on copyright claims). | Public domain |
| Gila Box Riparian National Conservation Area (55125828488).jpg | https://commons.wikimedia.org/wiki/File:Gila_Box_Riparian_National_Conservation_Area_(55125828488).jpg | BLMArizona | Public domain |
| Girls playing basketball.jpg | https://commons.wikimedia.org/wiki/File:Girls_playing_basketball.jpg | miller644 | Public domain |
| Glenner01.jpg | https://commons.wikimedia.org/wiki/File:Glenner01.jpg | Sabine Hohn-Braun | Public domain |
| Gordale camping site.JPG | https://commons.wikimedia.org/wiki/File:Gordale_camping_site.JPG | Immanuel Giel | Public domain |
| Got my running chip tied up for tomorrow's marathon (http-www.rnraz.com-). I am hoping this is a fast chip! (2188087409).jpg | https://commons.wikimedia.org/wiki/File:Got_my_running_chip_tied_up_for_tomorrow%27s_marathon_(http-www.rnraz.com-)._I_am_hoping_this_is_a_fast_chip!_(2188087409).jpg | cogdogblog | CC0 1.0 |
| Grand Canyon - Parashant National Monument (26632035271).jpg | https://commons.wikimedia.org/wiki/File:Grand_Canyon_-_Parashant_National_Monument_(26632035271).jpg | Bureau of Land Management (BLM) Arizona | Public domain |
| Grand Staircase-Escalante National Monument- River Kayaking (53128999608).jpg | https://commons.wikimedia.org/wiki/File:Grand_Staircase-Escalante_National_Monument-_River_Kayaking_(53128999608).jpg | BLMUtah | Public domain |
| Grandstand Swimming Pool.jpg | https://commons.wikimedia.org/wiki/File:Grandstand_Swimming_Pool.jpg | Cali2025 | CC0 1.0 |
| Gravel trail through the spring woods, Quebec City.jpg | https://commons.wikimedia.org/wiki/File:Gravel_trail_through_the_spring_woods,_Quebec_City.jpg | Wilfredor | CC0 1.0 |
| Grayback Mountain (15203482793).jpg | https://commons.wikimedia.org/wiki/File:Grayback_Mountain_(15203482793).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Green smoothie bowls with yogurt swirls (Unsplash po5MkrkMxyo).jpg | https://commons.wikimedia.org/wiki/File:Green_smoothie_bowls_with_yogurt_swirls_(Unsplash_po5MkrkMxyo).jpg | Brenda Godinez cravethebenefits | CC0 1.0 |
| Group Hiking on Munch's Coulee National Hiking Trail (5220995838).jpg | https://commons.wikimedia.org/wiki/File:Group_Hiking_on_Munch%27s_Coulee_National_Hiking_Trail_(5220995838).jpg | USFWS Mountain Prairie | Public domain |
| Group of environmental education science teachers take the tour by canoe on lake.jpg | https://commons.wikimedia.org/wiki/File:Group_of_environmental_education_science_teachers_take_the_tour_by_canoe_on_lake.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Group of people enjoying a canoe tour.jpg | https://commons.wikimedia.org/wiki/File:Group_of_people_enjoying_a_canoe_tour.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Guide post at les Fours saddle.jpg | https://commons.wikimedia.org/wiki/File:Guide_post_at_les_Fours_saddle.jpg | PtiBzh | CC0 1.0 |
| Guidepost Bocca d'Erna 1291.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Bocca_d%27Erna_1291.jpg | Kaitu | CC0 1.0 |
| Guidepost Bonacina 330 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Bonacina_330_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Cascina Monte Gregorio (Cremella).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Cascina_Monte_Gregorio_(Cremella).jpg | Kaitu | CC0 1.0 |
| Guidepost Costacurta 300 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Costacurta_300_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Eichholz (506 m) Bern.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Eichholz_(506_m)_Bern.jpg | Vmicho | CC0 1.0 |
| Guidepost Grunenmatt, left points to West.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Grunenmatt,_left_points_to_West.jpg | Vmicho | CC0 1.0 |
| Guidepost Höhi, 804 m, left points to north.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_H%C3%B6hi,_804_m,_left_points_to_north.jpg | Vmicho | CC0 1.0 |
| Guidepost La Berra (Chalet), 1630 m, left points to Northeast.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_La_Berra_(Chalet),_1630_m,_left_points_to_Northeast.jpg | Vmicho | CC0 1.0 |
| Guidepost Les Revers, 970 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Les_Revers,_970_m.jpg | Vmicho | CC0 1.0 |
| Guidepost Madonna della Rovinata 414 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Madonna_della_Rovinata_414_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Maggianico 225 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Maggianico_225_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Morti dell'Avello (Bulciago).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Morti_dell%27Avello_(Bulciago).jpg | Kaitu | CC0 1.0 |
| Guidepost Moutier, 529 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Moutier,_529_m.jpg | Vmicho | CC0 1.0 |
| Guidepost Passo del Lupo 500m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Passo_del_Lupo_500m.jpg | Kaitu | CC0 1.0 |
| Guidepost Pavillon.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Pavillon.jpg | Andreas Binder | CC0 1.0 |
| Guidepost RifCrosta-Cistella.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_RifCrosta-Cistella.jpg | Sborgolotto | CC0 1.0 |
| Guidepost Rifugio Fonteghi.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Rifugio_Fonteghi.jpg | 501ghost | CC0 1.0 |
| Guidepost Sant'Egidio 372 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Sant%27Egidio_372_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Schneelahnerhütte.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Schneelahnerh%C3%BCtte.jpg | Andreas Binder | CC0 1.0 |
| Guidepost Sogno 774 m.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Sogno_774_m.jpg | Kaitu | CC0 1.0 |
| Guidepost Traithenkessel.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_Traithenkessel.jpg | Andreas Binder | CC0 1.0 |
| Guidepost along Sentiero del Vallone at junction with path to Ca' Rovellasca.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_along_Sentiero_del_Vallone_at_junction_with_path_to_Ca%27_Rovellasca.jpg | Kaitu | CC0 1.0 |
| Guidepost along VML1 at Colma del Bugone.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_along_VML1_at_Colma_del_Bugone.jpg | Kaitu | CC0 1.0 |
| Guidepost along Via all'Approdo (Faggeto Lario).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_along_Via_all%27Approdo_(Faggeto_Lario).jpg | Kaitu | CC0 1.0 |
| Guidepost along the road between Versasio and Deviscio, at junction with path to Malnago.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_along_the_road_between_Versasio_and_Deviscio,_at_junction_with_path_to_Malnago.jpg | Kaitu | CC0 1.0 |
| Guidepost and street sign on Via Roano (Porlezza).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_and_street_sign_on_Via_Roano_(Porlezza).jpg | Kaitu | CC0 1.0 |
| Guidepost at Colma Murelli.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_at_Colma_Murelli.jpg | Kaitu | CC0 1.0 |
| Guidepost at Rifugio Binate.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_at_Rifugio_Binate.jpg | Kaitu | CC0 1.0 |
| Guidepost between Costa and Rifugio Stoppani, at junction with path 904.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Costa_and_Rifugio_Stoppani,_at_junction_with_path_904.jpg | Kaitu | CC0 1.0 |
| Guidepost between Deviscio and Costa, at junction with path to Ferrata Gamma.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Deviscio_and_Costa,_at_junction_with_path_to_Ferrata_Gamma.jpg | Kaitu | CC0 1.0 |
| Guidepost between Malnago and Deviscio, at junction with path to Somasca.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Malnago_and_Deviscio,_at_junction_with_path_to_Somasca.jpg | Kaitu | CC0 1.0 |
| Guidepost between Malnago and Deviscio, at junction with path to Versasio.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Malnago_and_Deviscio,_at_junction_with_path_to_Versasio.jpg | Kaitu | CC0 1.0 |
| Guidepost between Palazzetto and Piano Fieno.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Palazzetto_and_Piano_Fieno.jpg | Kaitu | CC0 1.0 |
| Guidepost between Passone and Passonino.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Passone_and_Passonino.jpg | Kaitu | CC0 1.0 |
| Guidepost between Vicerola and Monte Mudarga 03.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_between_Vicerola_and_Monte_Mudarga_03.jpg | Kaitu | CC0 1.0 |
| Guidepost bivaccoGiovanniLeoni.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_bivaccoGiovanniLeoni.jpg | Sborgolotto | CC0 1.0 |
| Guidepost by Lecco Maggianico train station.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_by_Lecco_Maggianico_train_station.jpg | Kaitu | CC0 1.0 |
| Guidepost by Passo di Nava, at junction between hiking path 14 and path 19,.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_by_Passo_di_Nava,_at_junction_between_hiking_path_14_and_path_19,.jpg | Kaitu | CC0 1.0 |
| Guidepost by Rifugio Antonio Stoppani.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_by_Rifugio_Antonio_Stoppani.jpg | Kaitu | CC0 1.0 |
| Guidepost by Sass Quader.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_by_Sass_Quader.jpg | Kaitu | CC0 1.0 |
| Guidepost by lavoir of Camporeso.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_by_lavoir_of_Camporeso.jpg | Kaitu | CC0 1.0 |
| Guidepost in Camporeso.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Camporeso.jpg | Kaitu | CC0 1.0 |
| Guidepost in Carenno, at crossing of 811 and 817.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Carenno,_at_crossing_of_811_and_817.jpg | Kaitu | CC0 1.0 |
| Guidepost in Duello.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Duello.jpg | Kaitu | CC0 1.0 |
| Guidepost in Linzanico, beginning of hiking trail 5B.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Linzanico,_beginning_of_hiking_trail_5B.jpg | Kaitu | CC0 1.0 |
| Guidepost in Malnago, via Poggi, by the church.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Malnago,_via_Poggi,_by_the_church.jpg | Kaitu | CC0 1.0 |
| Guidepost in Masone (Barzio).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Masone_(Barzio).jpg | Kaitu | CC0 1.0 |
| Guidepost in Mater (Cerano d'Intelvi).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Mater_(Cerano_d%27Intelvi).jpg | Kaitu | CC0 1.0 |
| Guidepost in Pequeno (Barzio).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Pequeno_(Barzio).jpg | Kaitu | CC0 1.0 |
| Guidepost in Piazza Carrobbio (Introbio), along the Acquaduro stream.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Piazza_Carrobbio_(Introbio),_along_the_Acquaduro_stream.jpg | Kaitu | CC0 1.0 |
| Guidepost in Piazza Carrobbio (Introbio).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Piazza_Carrobbio_(Introbio).jpg | Kaitu | CC0 1.0 |
| Guidepost in Piazza Indipendenza a Viconago.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Piazza_Indipendenza_a_Viconago.jpg | Kaitu | CC0 1.0 |
| Guidepost in Piazza della Chiesa in Domaso.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Piazza_della_Chiesa_in_Domaso.jpg | Kaitu | CC0 1.0 |
| Guidepost in Preguda.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Preguda.jpg | Kaitu | CC0 1.0 |
| Guidepost in Saina along Via Saina.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_in_Saina_along_Via_Saina.jpg | Kaitu | CC0 1.0 |
| Guidepost of path 801, at Croce di Vicerola - front.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_of_path_801,_at_Croce_di_Vicerola_-_front.jpg | Kaitu | CC0 1.0 |
| Guidepost on Sentiero della Valle 01.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_on_Sentiero_della_Valle_01.jpg | Kaitu | CC0 1.0 |
| Guidepost on Strada carrozzabile per Pagnano (Asso).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_on_Strada_carrozzabile_per_Pagnano_(Asso).jpg | Kaitu | CC0 1.0 |
| Guidepost on Via Biancolina (Barzio).jpg | https://commons.wikimedia.org/wiki/File:Guidepost_on_Via_Biancolina_(Barzio).jpg | Kaitu | CC0 1.0 |
| Guidepost on path between Baite di Faggio and La Piazza (Moggio), near Torrente Valle del Faggio.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_on_path_between_Baite_di_Faggio_and_La_Piazza_(Moggio),_near_Torrente_Valle_del_Faggio.jpg | Kaitu | CC0 1.0 |
| Guidepost over Begna.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_over_Begna.jpg | Kaitu | CC0 1.0 |
| Guidepost to Crotto La Rovinata, at the back of sanctuary.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_to_Crotto_La_Rovinata,_at_the_back_of_sanctuary.jpg | Kaitu | CC0 1.0 |
| Guidepost to Magnodeno, at Croce di Vicerola.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_to_Magnodeno,_at_Croce_di_Vicerola.jpg | Kaitu | CC0 1.0 |
| Guidepost to Piazzola, in Duello.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_to_Piazzola,_in_Duello.jpg | Kaitu | CC0 1.0 |
| Guidepost to Piazzola, in locality Croce dell'Uomo.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_to_Piazzola,_in_locality_Croce_dell%27Uomo.jpg | Kaitu | CC0 1.0 |
| Guidepost Äserer.jpg | https://commons.wikimedia.org/wiki/File:Guidepost_%C3%84serer.jpg | Andreas Binder | CC0 1.0 |
| Gym at Universal Ai University.jpg | https://commons.wikimedia.org/wiki/File:Gym_at_Universal_Ai_University.jpg | ManoBV16 | CC0 1.0 |
| Gym workout machines.jpg | https://commons.wikimedia.org/wiki/File:Gym_workout_machines.jpg | Samson Ssemakadde | CC0 1.0 |
| H 20231228 Cedar Mountain Wilderness sign far (53438510290).jpg | https://commons.wikimedia.org/wiki/File:H_20231228_Cedar_Mountain_Wilderness_sign_far_(53438510290).jpg | BLMUtah | Public domain |
| HAVO 20190420 Hikers Floor Kilauea Iki Trail JWei (47616960932).jpg | https://commons.wikimedia.org/wiki/File:HAVO_20190420_Hikers_Floor_Kilauea_Iki_Trail_JWei_(47616960932).jpg | Hawai‘i Volcanoes NPS | Public domain |
| HAVO 20201221 Crater Rim Trail hikers VHouse JWei (51112443388).jpg | https://commons.wikimedia.org/wiki/File:HAVO_20201221_Crater_Rim_Trail_hikers_VHouse_JWei_(51112443388).jpg | Hawai‘i Volcanoes NPS | Public domain |
| HIHWNMS - Paddle boarder (34169067320).jpg | https://commons.wikimedia.org/wiki/File:HIHWNMS_-_Paddle_boarder_(34169067320).jpg | National Marine Sanctuaries | Public domain |
| HIKING ON BALD MOUNTAIN TRAIL MT HOOD (25005236642).jpg | https://commons.wikimedia.org/wiki/File:HIKING_ON_BALD_MOUNTAIN_TRAIL_MT_HOOD_(25005236642).jpg | U.S. Forest Service- Pacific Northwest Region | Public domain |
| HK HFC 杏花邨 Hang Fa Chuen Paradis Mall 商場 shop sport shoe June 2024 R12S 06.jpg | https://commons.wikimedia.org/wiki/File:HK_HFC_%E6%9D%8F%E8%8A%B1%E9%82%A8_Hang_Fa_Chuen_Paradis_Mall_%E5%95%86%E5%A0%B4_shop_sport_shoe_June_2024_R12S_06.jpg | RPSIXMEUNLAI HOOZ | CC0 1.0 |
| HK QB 鰂魚涌 Quarry Bay 英皇道 1026-1056 King's Road 東匯坊 East Pavilion Mall shop 百佳超市 ParknShop Supermarket oatmeal plastic bottles November 2024 R12S.jpg | https://commons.wikimedia.org/wiki/File:HK_QB_%E9%B0%82%E9%AD%9A%E6%B6%8C_Quarry_Bay_%E8%8B%B1%E7%9A%87%E9%81%93_1026-1056_King%27s_Road_%E6%9D%B1%E5%8C%AF%E5%9D%8A_East_Pavilion_Mall_shop_%E7%99%BE%E4%BD%B3%E8%B6%85%E5%B8%82_ParknShop_Supermarket_oatmeal_plastic_bottles_November_2024_R12S.jpg | Gosu MSAIO WIEEH 02 | CC0 1.0 |
| Halasana Sergey Kukalev 2010 19 Sept.jpg | https://commons.wikimedia.org/wiki/File:Halasana_Sergey_Kukalev_2010_19_Sept.jpg | English: Andrew Krizhanovsky Русский: Андрей Крижановский | Public domain |
| Halasana advanced variation Yoga Plough Pose.jpg | https://commons.wikimedia.org/wiki/File:Halasana_advanced_variation_Yoga_Plough_Pose.jpg | Alpine16 | CC0 1.0 |
| Half Marathons Italy (Modena) in 2026.315.jpg | https://commons.wikimedia.org/wiki/File:Half_Marathons_Italy_(Modena)_in_2026.315.jpg | CAPTAIN RAJU | CC0 1.0 |
| Handball at the 1988 Summer Olympics - Women's Tournament.jpg | https://commons.wikimedia.org/wiki/File:Handball_at_the_1988_Summer_Olympics_-_Women%27s_Tournament.jpg | Ken Hackman, U.S. Air Force | Public domain |
| Handball golakeeper.ahcp.00.jpg | https://commons.wikimedia.org/wiki/File:Handball_golakeeper.ahcp.00.jpg | Marciljoni | CC0 1.0 |
| Hannah Run Trail Marker (22158601824).jpg | https://commons.wikimedia.org/wiki/File:Hannah_Run_Trail_Marker_(22158601824).jpg | Shenandoah National Park from Virginia | Public domain |
| Harrison Barnes dunk USA Basketball Blue vs White game Las Vegas 2013.jpg | https://commons.wikimedia.org/wiki/File:Harrison_Barnes_dunk_USA_Basketball_Blue_vs_White_game_Las_Vegas_2013.jpg | U.S. Air Force photo by Senior Airman Daniel Hughes | Public domain |
| Hatha Yoga, Halasana, Zhengzhou, China.JPG | https://commons.wikimedia.org/wiki/File:Hatha_Yoga,_Halasana,_Zhengzhou,_China.JPG | Flora-Victoria | CC0 1.0 |
| Hatha Yoga, Pawanmuktasana, Zhengzhou, China.JPG | https://commons.wikimedia.org/wiki/File:Hatha_Yoga,_Pawanmuktasana,_Zhengzhou,_China.JPG | Flora-Victoria | CC0 1.0 |
| Healthy Breakfast (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Healthy_Breakfast_(Unsplash).jpg | Federica Diliberto federyka | CC0 1.0 |
| Healthy Trendy Brunch (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Healthy_Trendy_Brunch_(Unsplash).jpg | Jannis Brandt _jannis | CC0 1.0 |
| HeavenlySkiResortSkiWithARanger-LakeTahoeBMU-PRW-025 (52707338329).jpg | https://commons.wikimedia.org/wiki/File:HeavenlySkiResortSkiWithARanger-LakeTahoeBMU-PRW-025_(52707338329).jpg | Region 5 Photography | Public domain |
| Highline Canal at Nepal St.jpg | https://commons.wikimedia.org/wiki/File:Highline_Canal_at_Nepal_St.jpg | Nolabob | CC0 1.0 |
| Hike - Cedar Mountains WSA (30795216831).jpg | https://commons.wikimedia.org/wiki/File:Hike_-_Cedar_Mountains_WSA_(30795216831).jpg | BLMUtah | Public domain |
| Hike on top of a mountain near Longyearbyen, Svalbard.JPG | https://commons.wikimedia.org/wiki/File:Hike_on_top_of_a_mountain_near_Longyearbyen,_Svalbard.JPG | Al83tito | CC0 1.0 |
| Hiker at Barker Dam (49902719327).jpg | https://commons.wikimedia.org/wiki/File:Hiker_at_Barker_Dam_(49902719327).jpg | Joshua Tree National Park | Public domain |
| Hiker at Ryan Mountain (28305686159).jpg | https://commons.wikimedia.org/wiki/File:Hiker_at_Ryan_Mountain_(28305686159).jpg | Joshua Tree National Park | Public domain |
| Hiker at Ryan Mountain Trailhead (40053538582).jpg | https://commons.wikimedia.org/wiki/File:Hiker_at_Ryan_Mountain_Trailhead_(40053538582).jpg | Joshua Tree National Park | Public domain |
| Hiker at the summit of Ryan Mountain (38295653594).jpg | https://commons.wikimedia.org/wiki/File:Hiker_at_the_summit_of_Ryan_Mountain_(38295653594).jpg | Joshua Tree National Park | Public domain |
| Hiker on Dome Mountain looking at Electric Peak (47995555663).jpg | https://commons.wikimedia.org/wiki/File:Hiker_on_Dome_Mountain_looking_at_Electric_Peak_(47995555663).jpg | Yellowstone National Park | Public domain |
| Hiker on Yanert 17b Easement trail (51780730838).jpg | https://commons.wikimedia.org/wiki/File:Hiker_on_Yanert_17b_Easement_trail_(51780730838).jpg | BLM Alaska | Public domain |
| Hiker on Yanert 17b Easement trail (51781112354).jpg | https://commons.wikimedia.org/wiki/File:Hiker_on_Yanert_17b_Easement_trail_(51781112354).jpg | BLM Alaska | Public domain |
| Hiker on a steel bridge (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiker_on_a_steel_bridge_(Unsplash).jpg | Christian Joudrey cjoudrey | CC0 1.0 |
| Hiker on the Fortynine Palms Trail (50699662533).jpg | https://commons.wikimedia.org/wiki/File:Hiker_on_the_Fortynine_Palms_Trail_(50699662533).jpg | Joshua Tree National Park | Public domain |
| Hiker with a large backpack (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiker_with_a_large_backpack_(Unsplash).jpg | Jake Melara jakemelara | CC0 1.0 |
| Hikers & flowers on the Beaver Ponds Trail (33797582205).jpg | https://commons.wikimedia.org/wiki/File:Hikers_%26_flowers_on_the_Beaver_Ponds_Trail_(33797582205).jpg | Yellowstone National Park | Public domain |
| Hikers along a trail (30911360600).jpg | https://commons.wikimedia.org/wiki/File:Hikers_along_a_trail_(30911360600).jpg | Joshua Tree National Park | Public domain |
| Hikers at peak (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hikers_at_peak_(Unsplash).jpg | Mathias Jensen mathiasjensen | CC0 1.0 |
| Hikers crossing patch of snow on the Mt. Washburn trail (ab45324d-42b0-46b2-ab0f-08625f103765).jpg | https://commons.wikimedia.org/wiki/File:Hikers_crossing_patch_of_snow_on_the_Mt._Washburn_trail_(ab45324d-42b0-46b2-ab0f-08625f103765).jpg | NPS/Jim Peaco | Public domain |
| Hikers enjoy a hike on a wild trail.jpg | https://commons.wikimedia.org/wiki/File:Hikers_enjoy_a_hike_on_a_wild_trail.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Hikers hurricane hill mountains fall cbubar 2015 (22477985993).jpg | https://commons.wikimedia.org/wiki/File:Hikers_hurricane_hill_mountains_fall_cbubar_2015_(22477985993).jpg | Olympic National Park | Public domain |
| Hikers on Mastodon Peak Trail (26006704102).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Mastodon_Peak_Trail_(26006704102).jpg | Joshua Tree National Park | Public domain |
| Hikers on Mt. Washburn trail (19946580905).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Mt._Washburn_trail_(19946580905).jpg | Yellowstone National Park from Yellowstone NP, USA | Public domain |
| Hikers on Ryan Mountain (37896951252).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Ryan_Mountain_(37896951252).jpg | Joshua Tree National Park | Public domain |
| Hikers on Skull Rock Trail (31215629542).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Skull_Rock_Trail_(31215629542).jpg | Joshua Tree National Park | Public domain |
| Hikers on Trail (11424869575).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Trail_(11424869575).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Hikers on Willow Hole trail (49794582288).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Willow_Hole_trail_(49794582288).jpg | Joshua Tree National Park | Public domain |
| Hikers on Willow Hole trail (49795109021).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_Willow_Hole_trail_(49795109021).jpg | Joshua Tree National Park | Public domain |
| Hikers on a trail (35797190204).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_a_trail_(35797190204).jpg | Joshua Tree National Park | Public domain |
| Hikers on the Beaver Ponds Trail (33797594535).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Beaver_Ponds_Trail_(33797594535).jpg | Yellowstone National Park | Public domain |
| Hikers on the Lost Palms Trail (23450293166).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Lost_Palms_Trail_(23450293166).jpg | Joshua Tree National Park | Public domain |
| Hikers on the Ridge Trail (27706806144).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Ridge_Trail_(27706806144).jpg | Shenandoah National Park from Virginia | Public domain |
| Hikers on the Skull Rock Trail (31323508636).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Skull_Rock_Trail_(31323508636).jpg | Joshua Tree National Park | Public domain |
| Hikers on the Trail (48920878477).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Trail_(48920878477).jpg | GlacierNPS | Public domain |
| Hikers on the Wolverine Creek Trail (48689160002).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Wolverine_Creek_Trail_(48689160002).jpg | Yellowstone National Park | Public domain |
| Hikers on the Yellowstone River Trail (36399360893).jpg | https://commons.wikimedia.org/wiki/File:Hikers_on_the_Yellowstone_River_Trail_(36399360893).jpg | Yellowstone National Park | Public domain |
| Hikers trek through the brush along the retracement trail on the Unicoi Turnpike Trail at Tellico Plains, Tennessee (90ffc31f-0114-441e-8699-5c1e32dec98e).JPG | https://commons.wikimedia.org/wiki/File:Hikers_trek_through_the_brush_along_the_retracement_trail_on_the_Unicoi_Turnpike_Trail_at_Tellico_Plains,_Tennessee_(90ffc31f-0114-441e-8699-5c1e32dec98e).JPG | National Trails Office (US National Park Service) | Public domain |
| Hiking (115a3feab5c94ade9b4a1a926ed650d9).JPG | https://commons.wikimedia.org/wiki/File:Hiking_(115a3feab5c94ade9b4a1a926ed650d9).JPG | Steve Redman (MORA) | Public domain |
| Hiking (308087e751b647718b7e6a1e0bc42409).JPG | https://commons.wikimedia.org/wiki/File:Hiking_(308087e751b647718b7e6a1e0bc42409).JPG | Steve Redman (MORA) | Public domain |
| Hiking (eefd39fb477c4239b4c3a20325c1d3b3).JPG | https://commons.wikimedia.org/wiki/File:Hiking_(eefd39fb477c4239b4c3a20325c1d3b3).JPG | Steve Redman (MORA) | Public domain |
| Hiking Alone (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_Alone_(Unsplash).jpg | Martin Klausen martinklausen | CC0 1.0 |
| Hiking Along the East Applegate Ridge Trail (40237270420).jpg | https://commons.wikimedia.org/wiki/File:Hiking_Along_the_East_Applegate_Ridge_Trail_(40237270420).jpg | BLM Oregon &amp; Washington | Public domain |
| Hiking Boots (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_Boots_(Unsplash).jpg | Joshua Reddekopp joshuaryanphoto | CC0 1.0 |
| Hiking Gear Cypress Falls (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_Gear_Cypress_Falls_(Unsplash).jpg | Andrew Ly nineteen | CC0 1.0 |
| Hiking a Jesenice mountain (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_a_Jesenice_mountain_(Unsplash).jpg | Ales Krivec aleskrivec | CC0 1.0 |
| Hiking above Lake McDonald (44772233745).jpg | https://commons.wikimedia.org/wiki/File:Hiking_above_Lake_McDonald_(44772233745).jpg | GlacierNPS | Public domain |
| Hiking along Glacier Creek in Denali's backcountry on June 13, 2019. (94bac903-2122-46ea-af76-4091b7c3e9ed).JPG | https://commons.wikimedia.org/wiki/File:Hiking_along_Glacier_Creek_in_Denali%27s_backcountry_on_June_13,_2019._(94bac903-2122-46ea-af76-4091b7c3e9ed).JPG | NPS Photo / Emily Mesner | Public domain |
| Hiking along Glacier Creek in Denali's backcountry on June 13, 2019. (e9086caf-8d0c-484f-99e1-089a92e3c044).JPG | https://commons.wikimedia.org/wiki/File:Hiking_along_Glacier_Creek_in_Denali%27s_backcountry_on_June_13,_2019._(e9086caf-8d0c-484f-99e1-089a92e3c044).JPG | NPS Photo / Emily Mesner | Public domain |
| Hiking along the Savage Alpine Trail on May 25, 2019. (f508205e-7ed5-4d59-a267-2ec4922e53e0).JPG | https://commons.wikimedia.org/wiki/File:Hiking_along_the_Savage_Alpine_Trail_on_May_25,_2019._(f508205e-7ed5-4d59-a267-2ec4922e53e0).JPG | NPS Photo / Emily Mesner | Public domain |
| Hiking alpine backpacking steep lake ice WIC NPS Photo (22739811740).jpg | https://commons.wikimedia.org/wiki/File:Hiking_alpine_backpacking_steep_lake_ice_WIC_NPS_Photo_(22739811740).jpg | Olympic National Park | Public domain |
| Hiking and camping between Esja and Thingvellir.jpg | https://commons.wikimedia.org/wiki/File:Hiking_and_camping_between_Esja_and_Thingvellir.jpg | Syced | CC0 1.0 |
| Hiking at Lulin Front Mountain - 2026 (IMGP1343).jpg | https://commons.wikimedia.org/wiki/File:Hiking_at_Lulin_Front_Mountain_-_2026_(IMGP1343).jpg | 小 葵 | CC0 1.0 |
| Hiking at Lulin Front Mountain - 2026 (IMGP1344).jpg | https://commons.wikimedia.org/wiki/File:Hiking_at_Lulin_Front_Mountain_-_2026_(IMGP1344).jpg | 小 葵 | CC0 1.0 |
| Hiking down Hellroaring Mountain (27570748707).jpg | https://commons.wikimedia.org/wiki/File:Hiking_down_Hellroaring_Mountain_(27570748707).jpg | Yellowstone National Park | Public domain |
| Hiking down the Côtes Souveraines, Huez and Villard-Reculas, 2026.jpg | https://commons.wikimedia.org/wiki/File:Hiking_down_the_C%C3%B4tes_Souveraines,_Huez_and_Villard-Reculas,_2026.jpg | DimiTalen | CC0 1.0 |
| Hiking down the Sepulcher Mountain Trail (48080335967).jpg | https://commons.wikimedia.org/wiki/File:Hiking_down_the_Sepulcher_Mountain_Trail_(48080335967).jpg | Yellowstone National Park | Public domain |
| Hiking in Lacoste (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_in_Lacoste_(Unsplash).jpg | Andrew Milko a_m | CC0 1.0 |
| Hiking in Stara Planina, Bulgaria.jpg | https://commons.wikimedia.org/wiki/File:Hiking_in_Stara_Planina,_Bulgaria.jpg | Gerovitus91 | Public domain |
| Hiking in a Smokey Cloud (48964bcc-96e4-4c72-a5ac-167dc94a0d03).JPG | https://commons.wikimedia.org/wiki/File:Hiking_in_a_Smokey_Cloud_(48964bcc-96e4-4c72-a5ac-167dc94a0d03).JPG | NPS Photo / Emily Mesner | Public domain |
| Hiking in the East Applegate Ridge Trail (42000620692).jpg | https://commons.wikimedia.org/wiki/File:Hiking_in_the_East_Applegate_Ridge_Trail_(42000620692).jpg | BLM Oregon &amp; Washington | Public domain |
| Hiking in the Swiss Alps (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_in_the_Swiss_Alps_(Unsplash).jpg | René Reichelt rene_reichelt | CC0 1.0 |
| Hiking on Cathedral Mountain on July 26, 2019. (266df4cf-61e7-4357-8906-0cd5efa1eab9).JPG | https://commons.wikimedia.org/wiki/File:Hiking_on_Cathedral_Mountain_on_July_26,_2019._(266df4cf-61e7-4357-8906-0cd5efa1eab9).JPG | NPS Photo / Emily Mesner | Public domain |
| Hiking on a snowy mountain (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_on_a_snowy_mountain_(Unsplash).jpg | Ezra Jeffrey emcomeau | CC0 1.0 |
| Hiking on rugged rocks (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_on_rugged_rocks_(Unsplash).jpg | Jake Ingle ingle_jake | CC0 1.0 |
| Hiking signpost, Route des Alpages, Villard-Reculas, 2026.jpg | https://commons.wikimedia.org/wiki/File:Hiking_signpost,_Route_des_Alpages,_Villard-Reculas,_2026.jpg | DimiTalen | CC0 1.0 |
| Hiking the Lateral Moraine of the Kennicott Glacier (2) - Donoho Basin (21598193075).jpg | https://commons.wikimedia.org/wiki/File:Hiking_the_Lateral_Moraine_of_the_Kennicott_Glacier_(2)_-_Donoho_Basin_(21598193075).jpg | National Park Service, Alaska Region | Public domain |
| Hiking the Medea Creek Trail (27162954115).jpg | https://commons.wikimedia.org/wiki/File:Hiking_the_Medea_Creek_Trail_(27162954115).jpg | Santa Monica Mountains National Recreation Area | Public domain |
| Hiking through mountain forests (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hiking_through_mountain_forests_(Unsplash).jpg | Jake Ingle ingle_jake | CC0 1.0 |
| Hiking trail on Angel Island 1.jpg | https://commons.wikimedia.org/wiki/File:Hiking_trail_on_Angel_Island_1.jpg | Mx. Granger | CC0 1.0 |
| Hiking trail to Gaustatoppen, Hjartdal, 2006.jpg | https://commons.wikimedia.org/wiki/File:Hiking_trail_to_Gaustatoppen,_Hjartdal,_2006.jpg | DimiTalen | CC0 1.0 |
| Hiking up the Sepulcher Mountain Trail (48080272393).jpg | https://commons.wikimedia.org/wiki/File:Hiking_up_the_Sepulcher_Mountain_Trail_(48080272393).jpg | Yellowstone National Park | Public domain |
| Hiking up the mountan.jpg | https://commons.wikimedia.org/wiki/File:Hiking_up_the_mountan.jpg | Ardvac | CC0 1.0 |
| Hiking with a Ranger (11426015686).jpg | https://commons.wikimedia.org/wiki/File:Hiking_with_a_Ranger_(11426015686).jpg | Santa Monica Mountains National Recreation Area | Public domain |
| Hillary trail marker.jpg | https://commons.wikimedia.org/wiki/File:Hillary_trail_marker.jpg | Sonia | Public domain |
| Hodge Podge Camping Meal (2810679454).jpg | https://commons.wikimedia.org/wiki/File:Hodge_Podge_Camping_Meal_(2810679454).jpg | cogdogblog | CC0 1.0 |
| Hoop Dream (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hoop_Dream_(Unsplash).jpg | Mark Solarski marksolarski | CC0 1.0 |
| Houston run mirrored in Afghanistan DVIDS362298.jpg | https://commons.wikimedia.org/wiki/File:Houston_run_mirrored_in_Afghanistan_DVIDS362298.jpg | Senior Airman Courtney Witt | Public domain |
| Hundsham, Buchkirchen, Austria (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Hundsham,_Buchkirchen,_Austria_(Unsplash).jpg | Thomas Schweighofer thomasschweighoferdotnet | CC0 1.0 |
| Hyde Park fingerpost 2011 1.jpg | https://commons.wikimedia.org/wiki/File:Hyde_Park_fingerpost_2011_1.jpg | Perituss | CC0 1.0 |
| Hyde Park fingerpost 2011 2.jpg | https://commons.wikimedia.org/wiki/File:Hyde_Park_fingerpost_2011_2.jpg | Perituss | CC0 1.0 |
| IND0440 (55042229027).jpg | https://commons.wikimedia.org/wiki/File:IND0440_(55042229027).jpg | Koldo Zuazaga | CC0 1.0 |
| ISS Phase One Activities and Manufacturing in Russia, France and Italy - Shepherd works out with dumbbells.jpg | https://commons.wikimedia.org/wiki/File:ISS_Phase_One_Activities_and_Manufacturing_in_Russia,_France_and_Italy_-_Shepherd_works_out_with_dumbbells.jpg | NASA | Public domain |
| Imani-bahati-unpaired-gray-nike-running-shoe.jpg | https://commons.wikimedia.org/wiki/File:Imani-bahati-unpaired-gray-nike-running-shoe.jpg | Imani Bahati https://www.imani-bht.com/ | CC0 1.0 |
| Indian River Lagoon National Scenic Byway - Kayaking on the Indian River Lagoon - NARA - 7719515.jpg | https://commons.wikimedia.org/wiki/File:Indian_River_Lagoon_National_Scenic_Byway_-_Kayaking_on_the_Indian_River_Lagoon_-_NARA_-_7719515.jpg | Unknown authorUnknown author or not provided | Public domain |
| Inflatable kayak at Manning River.jpg | https://commons.wikimedia.org/wiki/File:Inflatable_kayak_at_Manning_River.jpg | JaredMcKenzie | CC0 1.0 |
| Inn-River01.jpg | https://commons.wikimedia.org/wiki/File:Inn-River01.jpg | Prankster | CC0 1.0 |
| Innocent Smoothie.jpg | https://commons.wikimedia.org/wiki/File:Innocent_Smoothie.jpg | Mx. Granger | CC0 1.0 |
| Inter schools swimming competition in Kampala.jpg | https://commons.wikimedia.org/wiki/File:Inter_schools_swimming_competition_in_Kampala.jpg | Samson Ssemakadde | CC0 1.0 |
| Inter schools swimming competition.jpg | https://commons.wikimedia.org/wiki/File:Inter_schools_swimming_competition.jpg | Samson Ssemakadde | CC0 1.0 |
| Inter-schools swimming competition.jpg | https://commons.wikimedia.org/wiki/File:Inter-schools_swimming_competition.jpg | Samson Ssemakadde | CC0 1.0 |
| International Yoga Day 2018.jpg | https://commons.wikimedia.org/wiki/File:International_Yoga_Day_2018.jpg | Wann Majaw | CC0 1.0 |
| Invictus Games The Hague - Swimming (7148304).jpg | https://commons.wikimedia.org/wiki/File:Invictus_Games_The_Hague_-_Swimming_(7148304).jpg | U.S. Air Force photo by Master Sgt. Carlin Leslie | Public domain |
| Invictus Games The Hague - Swimming (7148309).jpg | https://commons.wikimedia.org/wiki/File:Invictus_Games_The_Hague_-_Swimming_(7148309).jpg | U.S. Air Force photo by Master Sgt. Carlin Leslie | Public domain |
| Iteen soccer Academy Uganda.jpg | https://commons.wikimedia.org/wiki/File:Iteen_soccer_Academy_Uganda.jpg | Wasswajimmieh | CC0 1.0 |
| JBER stand-up paddle boarding class 150622-F-WT808-054.jpg | https://commons.wikimedia.org/wiki/File:JBER_stand-up_paddle_boarding_class_150622-F-WT808-054.jpg | Airman Christopher Morales | Public domain |
| JBER stand-up paddle boarding class 150622-F-WT808-186.jpg | https://commons.wikimedia.org/wiki/File:JBER_stand-up_paddle_boarding_class_150622-F-WT808-186.jpg | Airman Christopher Morales | Public domain |
| Jalur Pendakian Bumiluhur.jpg | https://commons.wikimedia.org/wiki/File:Jalur_Pendakian_Bumiluhur.jpg | Ardiishere | CC0 1.0 |
| Jennifer Pallian 2016-10-07 (Unsplash DfbrRpHTLy0).jpg | https://commons.wikimedia.org/wiki/File:Jennifer_Pallian_2016-10-07_(Unsplash_DfbrRpHTLy0).jpg | Jennifer Pallian foodess | CC0 1.0 |
| Jersey Town Criterium 2012 09.jpg | https://commons.wikimedia.org/wiki/File:Jersey_Town_Criterium_2012_09.jpg | Man vyi | Public domain |
| Joe Coombs Canoe Slalom Race Cardiff.jpg | https://commons.wikimedia.org/wiki/File:Joe_Coombs_Canoe_Slalom_Race_Cardiff.jpg | Joe Coombs | CC0 1.0 |
| Jogger-jogging-sport-marathon (23698806383).jpg | https://commons.wikimedia.org/wiki/File:Jogger-jogging-sport-marathon_(23698806383).jpg | www.Pixel.la Free Stock Photos | CC0 1.0 |
| Joint Task Force and Naval Station Guantanamo Start Their Morning Open Water Swim DVIDS228136.jpg | https://commons.wikimedia.org/wiki/File:Joint_Task_Force_and_Naval_Station_Guantanamo_Start_Their_Morning_Open_Water_Swim_DVIDS228136.jpg | Petty Officer 1st Class Edward Flynn | Public domain |
| Josette Andrews USATF 5k Road Championships 2025.jpg | https://commons.wikimedia.org/wiki/File:Josette_Andrews_USATF_5k_Road_Championships_2025.jpg | Photoguy1212 | CC0 1.0 |
| Jumping in the water (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Jumping_in_the_water_(Unsplash).jpg | Marco Sartori marcosartorinet | CC0 1.0 |
| Just past the opening in the fence, the small trailhead sign for Manville trail can be seen. (97bb455e-9742-430a-9a93-4083093eb009).jpg | https://commons.wikimedia.org/wiki/File:Just_past_the_opening_in_the_fence,_the_small_trailhead_sign_for_Manville_trail_can_be_seen._(97bb455e-9742-430a-9a93-4083093eb009).jpg | NPS | Public domain |
| KHS Alite100.JPG | https://commons.wikimedia.org/wiki/File:KHS_Alite100.JPG | Fletcher at English Wikipedia | Public domain |
| Kampala MTN marathon runners.jpg | https://commons.wikimedia.org/wiki/File:Kampala_MTN_marathon_runners.jpg | Samson Ssemakadde | CC0 1.0 |
| Kasey Knevelbaard USATF 5k Championships 2025.jpg | https://commons.wikimedia.org/wiki/File:Kasey_Knevelbaard_USATF_5k_Championships_2025.jpg | Photoguy1212 | CC0 1.0 |
| Kayak (153577793).jpeg | https://commons.wikimedia.org/wiki/File:Kayak_(153577793).jpeg | Spasibomusic | CC0 1.0 |
| Kayak Rotankid.jpg | https://commons.wikimedia.org/wiki/File:Kayak_Rotankid.jpg | Oxabilan | CC0 1.0 |
| Kayak Trip (5167209307).jpg | https://commons.wikimedia.org/wiki/File:Kayak_Trip_(5167209307).jpg | U.S. Fish and Wildlife Service Headquarters | Public domain |
| Kayak in the Beaumont Unit (fe48247c-5c09-476f-887b-111336408a44).JPG | https://commons.wikimedia.org/wiki/File:Kayak_in_the_Beaumont_Unit_(fe48247c-5c09-476f-887b-111336408a44).JPG | NPS Photo | Public domain |
| Kayak tour on Yellowstone Lake (e2c11780-f266-4891-ac47-04875206774b).jpg | https://commons.wikimedia.org/wiki/File:Kayak_tour_on_Yellowstone_Lake_(e2c11780-f266-4891-ac47-04875206774b).jpg | NPS/Diane Renkin | Public domain |
| Kayak training in front of HDWSA Building.JPG | https://commons.wikimedia.org/wiki/File:Kayak_training_in_front_of_HDWSA_Building.JPG | User:Romana Kakar | Public domain |
| Kayak-Valdes-BC.jpg | https://commons.wikimedia.org/wiki/File:Kayak-Valdes-BC.jpg | EC | Public domain |
| Kayak-sunset-bay-OR.jpg | https://commons.wikimedia.org/wiki/File:Kayak-sunset-bay-OR.jpg | GaylaLin | Public domain |
| Kayak-sunset-bay2-OR.jpg | https://commons.wikimedia.org/wiki/File:Kayak-sunset-bay2-OR.jpg | GaylaLin | Public domain |
| Kayaker and canoers on the Loue along a river beach, Cléron, 2026.jpg | https://commons.wikimedia.org/wiki/File:Kayaker_and_canoers_on_the_Loue_along_a_river_beach,_Cl%C3%A9ron,_2026.jpg | DimiTalen | CC0 1.0 |
| Kayaker at Cronan Ranch (52900398859).jpg | https://commons.wikimedia.org/wiki/File:Kayaker_at_Cronan_Ranch_(52900398859).jpg | blmcalifornia | Public domain |
| Kayaker at Cronan Ranch (52900630790).jpg | https://commons.wikimedia.org/wiki/File:Kayaker_at_Cronan_Ranch_(52900630790).jpg | blmcalifornia | Public domain |
| Kayaker on the river.jpg | https://commons.wikimedia.org/wiki/File:Kayaker_on_the_river.jpg | Dan Cox, U.S. Fish and Wildlife Service | Public domain |
| Kayakers floating under the new bridge (53878614241).jpg | https://commons.wikimedia.org/wiki/File:Kayakers_floating_under_the_new_bridge_(53878614241).jpg | Forest Service - Northern Region | Public domain |
| Kayaking (23405843114).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_(23405843114).jpg | USFWSAlaska | Public domain |
| Kayaking around Kelor Island.jpg | https://commons.wikimedia.org/wiki/File:Kayaking_around_Kelor_Island.jpg | Phetoy6 | CC0 1.0 |
| Kayaking in Alaska P1010034.JPG | https://commons.wikimedia.org/wiki/File:Kayaking_in_Alaska_P1010034.JPG | NancyHeise | Public domain |
| Kayaking in Florida at sunset.jpg | https://commons.wikimedia.org/wiki/File:Kayaking_in_Florida_at_sunset.jpg | U.S. Fish and Wildlife Service Southeast Region | Public domain |
| Kayaking lesse.jpg | https://commons.wikimedia.org/wiki/File:Kayaking_lesse.jpg | No machine-readable author provided. Penelopeia~commonswiki assumed (based on copyright claims). | Public domain |
| Kayaking on codorus.jpg | https://commons.wikimedia.org/wiki/File:Kayaking_on_codorus.jpg | James Neff | Public domain |
| Kayaking on the Charles River in Eastern Massachusetts. (7189708949).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_on_the_Charles_River_in_Eastern_Massachusetts._(7189708949).jpg | U. S. Fish and Wildlife Service - Northeast Region | Public domain |
| Kayaking on the Susquehanna River at dawn. (d443f372-86e0-4330-beb0-5d1be4c3ddda).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_on_the_Susquehanna_River_at_dawn._(d443f372-86e0-4330-beb0-5d1be4c3ddda).jpg | NPS | Public domain |
| Kayaking the North Toe River (5762323243).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_the_North_Toe_River_(5762323243).jpg | U.S. Fish and Wildlife Service Southeast Region | Public domain |
| Kayaking to jobsite (8078842616).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_to_jobsite_(8078842616).jpg | Forest Service Alaska Region, USDA | Public domain |
| Kayaking to jobsite (8078842746).jpg | https://commons.wikimedia.org/wiki/File:Kayaking_to_jobsite_(8078842746).jpg | Forest Service Alaska Region, USDA | Public domain |
| Kayking the LA River June 2024.jpg | https://commons.wikimedia.org/wiki/File:Kayking_the_LA_River_June_2024.jpg | Blervis | CC0 1.0 |
| Kazan 2015 - Water polo - Men - Bronze medal match - 046.JPG | https://commons.wikimedia.org/wiki/File:Kazan_2015_-_Water_polo_-_Men_-_Bronze_medal_match_-_046.JPG | Voltmetro | CC0 1.0 |
| Kebo Brook Trail and Kebo Mountain Trail junction (fb2dc7c1-e25c-4660-afec-3fcf225c0d40).JPG | https://commons.wikimedia.org/wiki/File:Kebo_Brook_Trail_and_Kebo_Mountain_Trail_junction_(fb2dc7c1-e25c-4660-afec-3fcf225c0d40).JPG | NPS Photo | Public domain |
| Kick scooters in the Czech Republic IMG 8607.JPG | https://commons.wikimedia.org/wiki/File:Kick_scooters_in_the_Czech_Republic_IMG_8607.JPG | 01x07x2022000 | CC0 1.0 |
| Kids football tournament in Kampala.jpg | https://commons.wikimedia.org/wiki/File:Kids_football_tournament_in_Kampala.jpg | Samson Ssemakadde | CC0 1.0 |
| Kiwi Smoothie.jpg | https://commons.wikimedia.org/wiki/File:Kiwi_Smoothie.jpg | rawpixel.com | CC0 1.0 |
| LDP-260A-01.jpg | https://commons.wikimedia.org/wiki/File:LDP-260A-01.jpg | Pedser | CC0 1.0 |
| LRMC Yoga Class (8727547).jpg | https://commons.wikimedia.org/wiki/File:LRMC_Yoga_Class_(8727547).jpg | Travis Jones | Public domain |
| LRMC Yoga Class (8727548).jpg | https://commons.wikimedia.org/wiki/File:LRMC_Yoga_Class_(8727548).jpg | Travis Jones | Public domain |
| La Madre Mountains Wilderness Sign on White Rock Mountain Loop Trail (54090825326).jpg | https://commons.wikimedia.org/wiki/File:La_Madre_Mountains_Wilderness_Sign_on_White_Rock_Mountain_Loop_Trail_(54090825326).jpg | BLM Nevada | Public domain |
| Lagna-hiking-guidepost-001.jpg | https://commons.wikimedia.org/wiki/File:Lagna-hiking-guidepost-001.jpg | Gubaer | CC0 1.0 |
| Lagna-hiking-guidepost-002.jpg | https://commons.wikimedia.org/wiki/File:Lagna-hiking-guidepost-002.jpg | Gubaer | CC0 1.0 |
| Lake Fryxell Camp 2010 02.jpg | https://commons.wikimedia.org/wiki/File:Lake_Fryxell_Camp_2010_02.jpg | Peter Rejcek, NSF | Public domain |
| Laurel Highlands Forest Trail (436c32e7-1dd8-b71b-0b6c-b8d2c173ea54).jpg | https://commons.wikimedia.org/wiki/File:Laurel_Highlands_Forest_Trail_(436c32e7-1dd8-b71b-0b6c-b8d2c173ea54).jpg | NPS; Larcom | Public domain |
| Laís Estevam, Tchula (Thiellen Goncalves) e Maike (Maike Lizabet Weber) brigando por uma bola no Brasileirão Feminino de 2023.jpg | https://commons.wikimedia.org/wiki/File:La%C3%ADs_Estevam,_Tchula_(Thiellen_Goncalves)_e_Maike_(Maike_Lizabet_Weber)_brigando_por_uma_bola_no_Brasileir%C3%A3o_Feminino_de_2023.jpg | SOCCER DIGITAL | Public domain |
| Left turn blue trail blaze.jpg | https://commons.wikimedia.org/wiki/File:Left_turn_blue_trail_blaze.jpg | Simplificationalizer | CC0 1.0 |
| Leon (199558783).jpeg | https://commons.wikimedia.org/wiki/File:Leon_(199558783).jpeg | Elisa Frediani | CC0 1.0 |
| Les altères.jpg | https://commons.wikimedia.org/wiki/File:Les_alt%C3%A8res.jpg | Merstel007 | CC0 1.0 |
| Lets Go Outside! Connecting People With Nature (7490619386).jpg | https://commons.wikimedia.org/wiki/File:Lets_Go_Outside!_Connecting_People_With_Nature_(7490619386).jpg | USFWS Mountain-Prairie | Public domain |
| Levi North Ski Slope and Lift.jpg | https://commons.wikimedia.org/wiki/File:Levi_North_Ski_Slope_and_Lift.jpg | DanielMichaelPerry | CC0 1.0 |
| Life (225755921).jpeg | https://commons.wikimedia.org/wiki/File:Life_(225755921).jpeg | Juri Vsivtsev | CC0 1.0 |
| Likya Yürüyüşü.jpg | https://commons.wikimedia.org/wiki/File:Likya_Y%C3%BCr%C3%BCy%C3%BC%C5%9F%C3%BC.jpg | İlginince | CC0 1.0 |
| Litchfield Community Greenway green blaze 093.JPG | https://commons.wikimedia.org/wiki/File:Litchfield_Community_Greenway_green_blaze_093.JPG | Pedro Xing | CC0 1.0 |
| Little River Canyon National Preserve is home to world class whitewater kayaking - US Olympic team members have trained on this (8c8383fc-6412-47e4-a323-478fa0e3679f).jpg | https://commons.wikimedia.org/wiki/File:Little_River_Canyon_National_Preserve_is_home_to_world_class_whitewater_kayaking_-_US_Olympic_team_members_have_trained_on_this_(8c8383fc-6412-47e4-a323-478fa0e3679f).jpg | NPS | Public domain |
| Logan High School, Logan, Ohio.jpg | https://commons.wikimedia.org/wiki/File:Logan_High_School,_Logan,_Ohio.jpg | Dan Keck | CC0 1.0 |
| Lone kayaker on resurrection bay.jpg | https://commons.wikimedia.org/wiki/File:Lone_kayaker_on_resurrection_bay.jpg | Laubenstein Ronald, U.S. Fish and Wildlife Service | Public domain |
| Lower Salmon River (52275221539).jpg | https://commons.wikimedia.org/wiki/File:Lower_Salmon_River_(52275221539).jpg | BLMIdaho | Public domain |
| Lyon 2e - Passerelle Paul Couturier - Lyon Kayak 2019 (1).jpg | https://commons.wikimedia.org/wiki/File:Lyon_2e_-_Passerelle_Paul_Couturier_-_Lyon_Kayak_2019_(1).jpg | Romainbehar | CC0 1.0 |
| M.Frey.jpg | https://commons.wikimedia.org/wiki/File:M.Frey.jpg | Ingo Kappherr | CC0 1.0 |
| MARSOC enablers participate in Tactical Skills Package 150514-M-LS286-163.jpg | https://commons.wikimedia.org/wiki/File:MARSOC_enablers_participate_in_Tactical_Skills_Package_150514-M-LS286-163.jpg | Sgt. Donovan Lee | Public domain |
| MKAB service members compete in a charity basketball game in Romania (5974652).jpg | https://commons.wikimedia.org/wiki/File:MKAB_service_members_compete_in_a_charity_basketball_game_in_Romania_(5974652).jpg | U.S. Army photo by Pfc. Michael Ybarra | Public domain |
| MLC's swimming pool.jpg | https://commons.wikimedia.org/wiki/File:MLC%27s_swimming_pool.jpg | Feorein | CC0 1.0 |
| MOX 4607 (54661166393).jpg | https://commons.wikimedia.org/wiki/File:MOX_4607_(54661166393).jpg | Koldo Zuazaga | CC0 1.0 |
| MRF-D 24 3- U S Marines, ADF compete in friendly basketball game (8367580).jpg | https://commons.wikimedia.org/wiki/File:MRF-D_24_3-_U_S_Marines,_ADF_compete_in_friendly_basketball_game_(8367580).jpg | U.S. Marine Corps photo by Cpl. Manuel Rivera | Public domain |
| MSR WindPro portable stove.jpg | https://commons.wikimedia.org/wiki/File:MSR_WindPro_portable_stove.jpg | No machine-readable author provided. Fxer~commonswiki assumed (based on copyright claims). | Public domain |
| Machines found in the Gym at Kabojja.jpg | https://commons.wikimedia.org/wiki/File:Machines_found_in_the_Gym_at_Kabojja.jpg | Samson Ssemakadde | CC0 1.0 |
| Madison Recreational River, Montana (35845574853).jpg | https://commons.wikimedia.org/wiki/File:Madison_Recreational_River,_Montana_(35845574853).jpg | mypubliclands | Public domain |
| Maienfeld11.jpg | https://commons.wikimedia.org/wiki/File:Maienfeld11.jpg | Thomaso58x | CC0 1.0 |
| Maintaining a healthy lifestyle.jpg | https://commons.wikimedia.org/wiki/File:Maintaining_a_healthy_lifestyle.jpg | Keyidin28 | CC0 1.0 |
| Maj. Lucy Carillo leads a yoga class at the coalition compound gym in Southwest Asia, 2013.jpg | https://commons.wikimedia.org/wiki/File:Maj._Lucy_Carillo_leads_a_yoga_class_at_the_coalition_compound_gym_in_Southwest_Asia,_2013.jpg | US Air Force from USA | Public domain |
| Mammoth Hot Springs Campground (d6931858-f3c8-4906-9fe4-bef6c3cbbac9).jpg | https://commons.wikimedia.org/wiki/File:Mammoth_Hot_Springs_Campground_(d6931858-f3c8-4906-9fe4-bef6c3cbbac9).jpg | Neal Herbert | Public domain |
| Man on road bike (23394858165).jpg | https://commons.wikimedia.org/wiki/File:Man_on_road_bike_(23394858165).jpg | StockyPics | CC0 1.0 |
| Man running down side of road (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Man_running_down_side_of_road_(Unsplash).jpg | Tikkho Maciel tikkho | CC0 1.0 |
| Maneuvering through mountains 120624-F-CC568-268.jpg | https://commons.wikimedia.org/wiki/File:Maneuvering_through_mountains_120624-F-CC568-268.jpg | Airman 1st Class Alystria Maurer | Public domain |
| Mango Smoothie.JPG | https://commons.wikimedia.org/wiki/File:Mango_Smoothie.JPG | Miansari66 | CC0 1.0 |
| Manila, Philippines COMREL basketball game (8824395).jpg | https://commons.wikimedia.org/wiki/File:Manila,_Philippines_COMREL_basketball_game_(8824395).jpg | U.S. Navy photo by Petty Officer 2nd Class Ange-Olivier Clement | Public domain |
| Marathon runner, 34th Marine Corps Marathon (2009) 091025-M-1318S-1219 (4044126881).jpg | https://commons.wikimedia.org/wiki/File:Marathon_runner,_34th_Marine_Corps_Marathon_(2009)_091025-M-1318S-1219_(4044126881).jpg | Marines from Arlington, VA, United States | Public domain |
| Marc Esposito competes in the freestyle swimming event during the inaugural Warrior Games at the Olympic Training Center in Colorado Springs, Colo., May 12, 2010 100512-F-QE915-191.jpg | https://commons.wikimedia.org/wiki/File:Marc_Esposito_competes_in_the_freestyle_swimming_event_during_the_inaugural_Warrior_Games_at_the_Olympic_Training_Center_in_Colorado_Springs,_Colo.,_May_12,_2010_100512-F-QE915-191.jpg | Staff Sgt. Desiree N. Palacios | Public domain |
| Marines at Virginia Wesleyan University Women's Basketball Game (6).jpg | https://commons.wikimedia.org/wiki/File:Marines_at_Virginia_Wesleyan_University_Women%27s_Basketball_Game_(6).jpg | Sgt. Alexa M. Hernandez | Public domain |
| Marines motivating ALHS football team.jpg | https://commons.wikimedia.org/wiki/File:Marines_motivating_ALHS_football_team.jpg | Sgt. Taylor Morton | Public domain |
| Mariscal Canyon tight squeeze.jpg | https://commons.wikimedia.org/wiki/File:Mariscal_Canyon_tight_squeeze.jpg | NPS Photo | Public domain |
| Marugame athletic field 201501.JPG | https://commons.wikimedia.org/wiki/File:Marugame_athletic_field_201501.JPG | Waka77 | CC0 1.0 |
| Mary Mountain Trailhead (849e148a-b5bf-4f52-805a-421b8f94376d).jpg | https://commons.wikimedia.org/wiki/File:Mary_Mountain_Trailhead_(849e148a-b5bf-4f52-805a-421b8f94376d).jpg | NPS/Diane Renkin | Public domain |
| Maudslay running trail 1.JPG | https://commons.wikimedia.org/wiki/File:Maudslay_running_trail_1.JPG | Botteville | Public domain |
| Maze Loop Trail Sign (53256370140).jpg | https://commons.wikimedia.org/wiki/File:Maze_Loop_Trail_Sign_(53256370140).jpg | Joshua Tree National Park | Public domain |
| MazzaDell'Inferno guidepost 2024-09-15.jpg | https://commons.wikimedia.org/wiki/File:MazzaDell%27Inferno_guidepost_2024-09-15.jpg | Sborgolotto | CC0 1.0 |
| Meanwood Trail Sign2.jpg | https://commons.wikimedia.org/wiki/File:Meanwood_Trail_Sign2.jpg | Chemical Engineer | Public domain |
| Medaling is Just Finishing (2098637636).jpg | https://commons.wikimedia.org/wiki/File:Medaling_is_Just_Finishing_(2098637636).jpg | cogdogblog | CC0 1.0 |
| Members of Team Navy participate in the Navy Wounded Warrior Walter Reed Adaptive Sports training camp. (35154949931).jpg | https://commons.wikimedia.org/wiki/File:Members_of_Team_Navy_participate_in_the_Navy_Wounded_Warrior_Walter_Reed_Adaptive_Sports_training_camp._(35154949931).jpg | Official U.S. Navy Page from United States of America MC2 Charlotte C. Oliver/U.S. Navy | Public domain |
| Men's race Grote Prijs USA Import during Potjesmarkt, Schellebelle, 2014.jpg | https://commons.wikimedia.org/wiki/File:Men%27s_race_Grote_Prijs_USA_Import_during_Potjesmarkt,_Schellebelle,_2014.jpg | DimiTalen | CC0 1.0 |
| Mexican Mountain Wilderness sign (53660960789).jpg | https://commons.wikimedia.org/wiki/File:Mexican_Mountain_Wilderness_sign_(53660960789).jpg | BLMUtah | Public domain |
| Mikita Chyzhyk.jpg | https://commons.wikimedia.org/wiki/File:Mikita_Chyzhyk.jpg | SubMarcusXL | CC0 1.0 |
| Milk porridge 2.jpg | https://commons.wikimedia.org/wiki/File:Milk_porridge_2.jpg | Ajumeoni | CC0 1.0 |
| Monterey Bay kayaking tour April 2018.jpg | https://commons.wikimedia.org/wiki/File:Monterey_Bay_kayaking_tour_April_2018.jpg | Presidio of MontereyJoseph Kumzak, Presidio of Monterey Public Affairs | Public domain |
| Moscow 2025-08-10 bicycle race, riders 008 BMC,Specialized.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_008_BMC,Specialized.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 009 BMC,Specialized.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_009_BMC,Specialized.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 020 Twitter.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_020_Twitter.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 095 Canyon,Argon18.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_095_Canyon,Argon18.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 100 Canyon.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_100_Canyon.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 101 Canyon.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_101_Canyon.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 127 Scott.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_127_Scott.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 128 Scott.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_128_Scott.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 136 Argon18.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_136_Argon18.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 148 Argon18,Merida.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_148_Argon18,Merida.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 151 Argon18.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_151_Argon18.jpg | Retired electrician | CC0 1.0 |
| Moscow 2025-08-10 bicycle race, riders 167 Focus.jpg | https://commons.wikimedia.org/wiki/File:Moscow_2025-08-10_bicycle_race,_riders_167_Focus.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - costumes - bunnies 04.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_costumes_-_bunnies_04.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - costumes - bunnies 21.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_costumes_-_bunnies_21.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - costumes 06.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_costumes_06.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - group 05.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_group_05.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - orange vest 20.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_orange_vest_20.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - orange vest 29.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_orange_vest_29.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - orange vest 76.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_orange_vest_76.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - pink 10.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_pink_10.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders 16.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_16.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders 36.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_36.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders 92.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_92.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders A0.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_A0.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders A4.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_A4.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 paddleboarding event - riders B9.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_paddleboarding_event_-_riders_B9.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 running event 04.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_running_event_04.jpg | Retired electrician | CC0 1.0 |
| Moscow, 2025-07-13 running event 05.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_2025-07-13_running_event_05.jpg | Retired electrician | CC0 1.0 |
| Moscow, Ovchinninkovskaya Embankment, running event July 2025 01.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_Ovchinninkovskaya_Embankment,_running_event_July_2025_01.jpg | Retired electrician | CC0 1.0 |
| Moscow, Sadovnicheskaya Embankment, running event July 2025 04.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_Sadovnicheskaya_Embankment,_running_event_July_2025_04.jpg | Retired electrician | CC0 1.0 |
| Moscow, beware of Spiderman, Nov 2025 03.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_beware_of_Spiderman,_Nov_2025_03.jpg | Retired electrician | CC0 1.0 |
| Moscow, cyclists, Apr 2026 01.jpg | https://commons.wikimedia.org/wiki/File:Moscow,_cyclists,_Apr_2026_01.jpg | Retired electrician | CC0 1.0 |
| Motto1.jpg | https://commons.wikimedia.org/wiki/File:Motto1.jpg | Thomasox58 | CC0 1.0 |
| Mountain Bike Munich 2022 BrandlM (52299378818).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Bike_Munich_2022_BrandlM_(52299378818).jpg | Lexware Mountainbike Team | CC0 1.0 |
| Mountain Bike Munich 2022 BrandlM (52299870000).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Bike_Munich_2022_BrandlM_(52299870000).jpg | Lexware Mountainbike Team | CC0 1.0 |
| Mountain Biking (51847242395).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Biking_(51847242395).jpg | Joshua Tree National Park | Public domain |
| Mountain Biking at McCoy Flats (33293078111).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Biking_at_McCoy_Flats_(33293078111).jpg | BLMUtah | Public domain |
| Mountain Biking on the Dakota Prairie Grasslands (53214240920).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Biking_on_the_Dakota_Prairie_Grasslands_(53214240920).jpg | Forest Service - Northern Region | Public domain |
| Mountain Biking the White Rim Road (8044052425).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Biking_the_White_Rim_Road_(8044052425).jpg | CanyonlandsNPS | Public domain |
| Mountain Biking the White Rim Road (8044061144).jpg | https://commons.wikimedia.org/wiki/File:Mountain_Biking_the_White_Rim_Road_(8044061144).jpg | CanyonlandsNPS | Public domain |
| Mountain West Conference Outdoor Track and Field Championships, Women's 800m, 2013.jpg | https://commons.wikimedia.org/wiki/File:Mountain_West_Conference_Outdoor_Track_and_Field_Championships,_Women%27s_800m,_2013.jpg | US Air Force from USA | Public domain |
| Mountain bike across a road (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Mountain_bike_across_a_road_(Unsplash).jpg | Eddy Lackmann eddyray | CC0 1.0 |
| Mountain biking at Lake Meredith NRA. (60cb22dd-1dd8-b71b-0be8-a41ce2ce7c6e).jpg | https://commons.wikimedia.org/wiki/File:Mountain_biking_at_Lake_Meredith_NRA._(60cb22dd-1dd8-b71b-0be8-a41ce2ce7c6e).jpg | NPS | Public domain |
| Mountain biking in New Mexico (35427407166).jpg | https://commons.wikimedia.org/wiki/File:Mountain_biking_in_New_Mexico_(35427407166).jpg | mypubliclands | Public domain |
| Mountain biking in Topanga State Park (47127851402).jpg | https://commons.wikimedia.org/wiki/File:Mountain_biking_in_Topanga_State_Park_(47127851402).jpg | Santa Monica Mountains National Recreation Area | Public domain |
| Mountain biking in mud (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Mountain_biking_in_mud_(Unsplash).jpg | Thomas Schweighofer thomasschweighoferdotnet | CC0 1.0 |
| Mountain biking with a view in the Redding Field Office (33618102908).jpg | https://commons.wikimedia.org/wiki/File:Mountain_biking_with_a_view_in_the_Redding_Field_Office_(33618102908).jpg | blmcalifornia | Public domain |
| Mountain biking.JPG | https://commons.wikimedia.org/wiki/File:Mountain_biking.JPG | Gpavic | Public domain |
| Mountain climbing crew.jpg | https://commons.wikimedia.org/wiki/File:Mountain_climbing_crew.jpg | Angell Robert, U.S. Fish and Wildlife Service | Public domain |
| Mountain edge.jpg | https://commons.wikimedia.org/wiki/File:Mountain_edge.jpg | Paolo Neo | Public domain |
| Mountain hiking sunset follow @kalenemsley on ig (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Mountain_hiking_sunset_follow_@kalenemsley_on_ig_(Unsplash).jpg | Kalen Emsley kalenemsley | CC0 1.0 |
| Mountain of the Rogue Trail System (32692218360).jpg | https://commons.wikimedia.org/wiki/File:Mountain_of_the_Rogue_Trail_System_(32692218360).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| Mountain of the Rogue Trail System in Oregon (20318218984).jpg | https://commons.wikimedia.org/wiki/File:Mountain_of_the_Rogue_Trail_System_in_Oregon_(20318218984).jpg | mypubliclands | Public domain |
| Mountain of the Rogue Trail System in Oregon (20914572306).jpg | https://commons.wikimedia.org/wiki/File:Mountain_of_the_Rogue_Trail_System_in_Oregon_(20914572306).jpg | mypubliclands | Public domain |
| Mountainbike.Iceland.jpg | https://commons.wikimedia.org/wiki/File:Mountainbike.Iceland.jpg | Berserkur | CC0 1.0 |
| Mountaineering Man Trail Path Mountains.jpg | https://commons.wikimedia.org/wiki/File:Mountaineering_Man_Trail_Path_Mountains.jpg | Hermann | CC0 1.0 |
| Mt Baker Ski Area Chair 5 and Gabl's run.jpg | https://commons.wikimedia.org/wiki/File:Mt_Baker_Ski_Area_Chair_5_and_Gabl%27s_run.jpg | SherpardH1239a | CC0 1.0 |
| Mt hamilton road cyclist.jpg | https://commons.wikimedia.org/wiki/File:Mt_hamilton_road_cyclist.jpg | Original uploader was Sweetnsourbkr at en.wikipedia | Public domain |
| Mtn Biking Green Pond Trail ET5A7663 edited (30009863105).jpg | https://commons.wikimedia.org/wiki/File:Mtn_Biking_Green_Pond_Trail_ET5A7663_edited_(30009863105).jpg | Intermountain Forest Service, USDA Region 4 Photography | Public domain |
| Mugerere Men's football tournament.jpg | https://commons.wikimedia.org/wiki/File:Mugerere_Men%27s_football_tournament.jpg | Samson Ssemakadde | CC0 1.0 |
| Mule Creek Trail Head (11407310863).jpg | https://commons.wikimedia.org/wiki/File:Mule_Creek_Trail_Head_(11407310863).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Murrisk Loop trail marker 1.jpg | https://commons.wikimedia.org/wiki/File:Murrisk_Loop_trail_marker_1.jpg | A.-K. D. | CC0 1.0 |
| Murrisk Loop trail marker 2.jpg | https://commons.wikimedia.org/wiki/File:Murrisk_Loop_trail_marker_2.jpg | A.-K. D. | CC0 1.0 |
| MyPlate gov Cultural Food (20241025-USDA-FNS-UNK-0076).jpg | https://commons.wikimedia.org/wiki/File:MyPlate_gov_Cultural_Food_(20241025-USDA-FNS-UNK-0076).jpg | USDAgov | Public domain |
| NYC 2113 Marathon thunder nearing Court Square IRT jeh.jpg | https://commons.wikimedia.org/wiki/File:NYC_2113_Marathon_thunder_nearing_Court_Square_IRT_jeh.jpg | Jim.henderson | CC0 1.0 |
| NYC Half Marathon passes PABT jeh.jpg | https://commons.wikimedia.org/wiki/File:NYC_Half_Marathon_passes_PABT_jeh.jpg | Jim.henderson | CC0 1.0 |
| Naisten 400 m aidat.jpg | https://commons.wikimedia.org/wiki/File:Naisten_400_m_aidat.jpg | Lumijaguaari | Public domain |
| Nature Landscape Tent Camp Climb Hike Trek.jpg | https://commons.wikimedia.org/wiki/File:Nature_Landscape_Tent_Camp_Climb_Hike_Trek.jpg | StockSnap | CC0 1.0 |
| Navy Medicine Runners participate in 200 mile relay (9180517).jpg | https://commons.wikimedia.org/wiki/File:Navy_Medicine_Runners_participate_in_200_mile_relay_(9180517).jpg | Douglas Stutz | Public domain |
| Navy Region Singapore welcomes USS Carl Vinson Carrier Strike Group (CSG-1) 170406-N-QL164-006.jpg | https://commons.wikimedia.org/wiki/File:Navy_Region_Singapore_welcomes_USS_Carl_Vinson_Carrier_Strike_Group_(CSG-1)_170406-N-QL164-006.jpg | Marc Ayalin | Public domain |
| Navy volleyball vs Lafayette 05.jpg | https://commons.wikimedia.org/wiki/File:Navy_volleyball_vs_Lafayette_05.jpg | United States Naval Academy Photo Archive | Public domain |
| Nerima Sports Ground Park athletic field 2023-09-24.jpg | https://commons.wikimedia.org/wiki/File:Nerima_Sports_Ground_Park_athletic_field_2023-09-24.jpg | Asanagi | CC0 1.0 |
| Nesjavellir hiking trail (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Nesjavellir_hiking_trail_(Unsplash).jpg | Gemma Evans stayandroam | CC0 1.0 |
| New Balance 1906R.jpg | https://commons.wikimedia.org/wiki/File:New_Balance_1906R.jpg | LeDroider | CC0 1.0 |
| New Joe's Campground Sign with mountain background (53586028523).jpg | https://commons.wikimedia.org/wiki/File:New_Joe%27s_Campground_Sign_with_mountain_background_(53586028523).jpg | BLMUtah | Public domain |
| Nike Running Shoes Not Dissected Reference.jpg | https://commons.wikimedia.org/wiki/File:Nike_Running_Shoes_Not_Dissected_Reference.jpg | Yottanesia | CC0 1.0 |
| Ninerbike1.JPG | https://commons.wikimedia.org/wiki/File:Ninerbike1.JPG | Rfattori | Public domain |
| Nivia Shastra 2 0 FIFA Approved Official Ball for Hero ISL.jpg | https://commons.wikimedia.org/wiki/File:Nivia_Shastra_2_0_FIFA_Approved_Official_Ball_for_Hero_ISL.jpg | Snappingmonk | CC0 1.0 |
| Nomad(byDankaBobulska).JPG | https://commons.wikimedia.org/wiki/File:Nomad(byDankaBobulska).JPG | Danka Bobulska | CC0 1.0 |
| North Country National Scenic Trail (a4a79d82-4176-4600-8458-e5de57620787).jpg | https://commons.wikimedia.org/wiki/File:North_Country_National_Scenic_Trail_(a4a79d82-4176-4600-8458-e5de57620787).jpg | Victoria Stauffenberg | Public domain |
| North Maricopa Mountains Wilderness sign in theSonoran Desert National Monument (53723979265).jpg | https://commons.wikimedia.org/wiki/File:North_Maricopa_Mountains_Wilderness_sign_in_theSonoran_Desert_National_Monument_(53723979265).jpg | BLMArizona | Public domain |
| North Umpqua Wild and Scenic River (19704551319).jpg | https://commons.wikimedia.org/wiki/File:North_Umpqua_Wild_and_Scenic_River_(19704551319).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| North Umpqua Wild and Scenic River (19864983246).jpg | https://commons.wikimedia.org/wiki/File:North_Umpqua_Wild_and_Scenic_River_(19864983246).jpg | Bureau of Land Management Oregon and Washington | Public domain |
| Now Stretching, One (7067455549).jpg | https://commons.wikimedia.org/wiki/File:Now_Stretching,_One_(7067455549).jpg | Marines from Arlington, VA, United States | Public domain |
| Oatmeal (1).jpg | https://commons.wikimedia.org/wiki/File:Oatmeal_(1).jpg | Renee Comet (Photographer) | Public domain |
| Oatmeal food.jpg | https://commons.wikimedia.org/wiki/File:Oatmeal_food.jpg | John Harvey | Public domain |
| Oatmeal with a cherry compote.jpg | https://commons.wikimedia.org/wiki/File:Oatmeal_with_a_cherry_compote.jpg | Chandapiwa Malema | CC0 1.0 |
| Oatmeal with butternut.jpg | https://commons.wikimedia.org/wiki/File:Oatmeal_with_butternut.jpg | Chandapiwa Malema | CC0 1.0 |
| Oatmeal yogurt bars.jpg | https://commons.wikimedia.org/wiki/File:Oatmeal_yogurt_bars.jpg | Fumikas Sagisavas | CC0 1.0 |
| Oats (Alabama Extension).jpg | https://commons.wikimedia.org/wiki/File:Oats_(Alabama_Extension).jpg | Alabama Extension | CC0 1.0 |
| Oats, Raisins and Cinnamon.jpg | https://commons.wikimedia.org/wiki/File:Oats,_Raisins_and_Cinnamon.jpg | Alabama Extension | CC0 1.0 |
| Ocean Kayak Trident 13, pic1.JPG | https://commons.wikimedia.org/wiki/File:Ocean_Kayak_Trident_13,_pic1.JPG | Alf van Beem | CC0 1.0 |
| Ocean Kayak Trident 13, pic2.JPG | https://commons.wikimedia.org/wiki/File:Ocean_Kayak_Trident_13,_pic2.JPG | Alf van Beem | CC0 1.0 |
| Oceanside yoga (Unsplash) (cropped).jpg | https://commons.wikimedia.org/wiki/File:Oceanside_yoga_(Unsplash)_(cropped).jpg | Matthew Kane matthewkane | CC0 1.0 |
| Oceanside yoga (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Oceanside_yoga_(Unsplash).jpg | Matthew Kane matthewkane | CC0 1.0 |
| Oconaluftee River Trail sign, summer 2018--Chalice Keith (43953632655).jpg | https://commons.wikimedia.org/wiki/File:Oconaluftee_River_Trail_sign,_summer_2018--Chalice_Keith_(43953632655).jpg | Great Smoky Mountains National Park from Gatlinburg, TN | Public domain |
| Off-Trail Hiking (a3cd1816-f4e8-4ece-a508-2e409998e2f5).JPG | https://commons.wikimedia.org/wiki/File:Off-Trail_Hiking_(a3cd1816-f4e8-4ece-a508-2e409998e2f5).JPG | NPS Photo | Public domain |
| Old basketball court (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Old_basketball_court_(Unsplash).jpg | Tanja Heffner tanjaheffner | CC0 1.0 |
| Olivone4.jpg | https://commons.wikimedia.org/wiki/File:Olivone4.jpg | Thomasox58 | CC0 1.0 |
| On-Off switch (50210742113).jpg | https://commons.wikimedia.org/wiki/File:On-Off_switch_(50210742113).jpg | Region 5 Photography | Public domain |
| On-Off switch (50211260631).jpg | https://commons.wikimedia.org/wiki/File:On-Off_switch_(50211260631).jpg | Region 5 Photography | Public domain |
| One of three cooking shelters at the Brooks Camp Campground - NPS Photo-L Law (51889425156).jpg | https://commons.wikimedia.org/wiki/File:One_of_three_cooking_shelters_at_the_Brooks_Camp_Campground_-_NPS_Photo-L_Law_(51889425156).jpg | KatmaiNPS | Public domain |
| One on one basketball game, Crombie Park, 2014 09 18 (2) (15384060216).jpg | https://commons.wikimedia.org/wiki/File:One_on_one_basketball_game,_Crombie_Park,_2014_09_18_(2)_(15384060216).jpg | booledozer | Public domain |
| Opacum Land Trust and blue trail marker, Claire Birtz Wildlife Sanctuary.jpg | https://commons.wikimedia.org/wiki/File:Opacum_Land_Trust_and_blue_trail_marker,_Claire_Birtz_Wildlife_Sanctuary.jpg | Peter Cooper Jr. | CC0 1.0 |
| Orange tent on reindeer moss field.jpg | https://commons.wikimedia.org/wiki/File:Orange_tent_on_reindeer_moss_field.jpg | Vsatinet | CC0 1.0 |
| Orange trapezoid marker for Evergreen Trail, Capen Hill 2026-02-08.jpg | https://commons.wikimedia.org/wiki/File:Orange_trapezoid_marker_for_Evergreen_Trail,_Capen_Hill_2026-02-08.jpg | Peter Cooper Jr. | CC0 1.0 |
| P082810PS-0278 (4998520785).jpg | https://commons.wikimedia.org/wiki/File:P082810PS-0278_(4998520785).jpg | The White House from Washington, DC | Public domain |
| PEC 8970 (55043833466).jpg | https://commons.wikimedia.org/wiki/File:PEC_8970_(55043833466).jpg | Koldo Zuazaga | CC0 1.0 |
| Pacific Partnership 2022 participate in basketball game (7408154).jpg | https://commons.wikimedia.org/wiki/File:Pacific_Partnership_2022_participate_in_basketball_game_(7408154).jpg | U.S. Navy C-LGWP by Petty Officer 2nd Class Drace Wilson | Public domain |
| Pacific Partnership 2022 participate in basketball game (7408161).jpg | https://commons.wikimedia.org/wiki/File:Pacific_Partnership_2022_participate_in_basketball_game_(7408161).jpg | U.S. Navy photo by Petty Officer 2nd Class Drace Wilson | Public domain |
| Pacing the Force- Fostering Fitness and Fellowship (8504509).jpg | https://commons.wikimedia.org/wiki/File:Pacing_the_Force-_Fostering_Fitness_and_Fellowship_(8504509).jpg | U.S. Air Force photo by Senior Airman Raya Feltner | Public domain |
| Paddle board race 150124-F-BD983-060.jpg | https://commons.wikimedia.org/wiki/File:Paddle_board_race_150124-F-BD983-060.jpg | Kemberly Groue | Public domain |
| Paddleboarding at Trincomalee (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Paddleboarding_at_Trincomalee_(Unsplash).jpg | Aditya Siva msaditya9 | CC0 1.0 |
| Paddling the Falls rapids (38757512235).jpg | https://commons.wikimedia.org/wiki/File:Paddling_the_Falls_rapids_(38757512235).jpg | BLM Alaska | Public domain |
| Padmasana in Halasana.jpg | https://commons.wikimedia.org/wiki/File:Padmasana_in_Halasana.jpg | Alpine16 | CC0 1.0 |
| Pair of 8kg dumbbells (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Pair_of_8kg_dumbbells_(Unsplash).jpg | Cyril Saulnier c_reel | CC0 1.0 |
| Palo segnavia sentiero 127 Montemaggiore Valle Benedetta, Collesalvetti.jpg | https://commons.wikimedia.org/wiki/File:Palo_segnavia_sentiero_127_Montemaggiore_Valle_Benedetta,_Collesalvetti.jpg | Marcxosm | CC0 1.0 |
| Paralympics 120908-A-SR101-254.jpg | https://commons.wikimedia.org/wiki/File:Paralympics_120908-A-SR101-254.jpg | Sgt. Tyrone Marshall | Public domain |
| Parc national d'Opémican - Camping de la Pointe-Opémican - Site 27.jpg | https://commons.wikimedia.org/wiki/File:Parc_national_d%27Op%C3%A9mican_-_Camping_de_la_Pointe-Op%C3%A9mican_-_Site_27.jpg | Quesadillor | CC0 1.0 |
| Park Ranger in Desert Tortoise Pose (50696199372).jpg | https://commons.wikimedia.org/wiki/File:Park_Ranger_in_Desert_Tortoise_Pose_(50696199372).jpg | Joshua Tree National Park | Public domain |
| Park Ranger in Downward Coyote Yoga Pose (50696210552).jpg | https://commons.wikimedia.org/wiki/File:Park_Ranger_in_Downward_Coyote_Yoga_Pose_(50696210552).jpg | Joshua Tree National Park | Public domain |
| Park Ranger in Joshua Tree Yoga Pose (50695377468).jpg | https://commons.wikimedia.org/wiki/File:Park_Ranger_in_Joshua_Tree_Yoga_Pose_(50695377468).jpg | Joshua Tree National Park | Public domain |
| Park Ranger in Joshua Tree Yoga Pose (50695393088).jpg | https://commons.wikimedia.org/wiki/File:Park_Ranger_in_Joshua_Tree_Yoga_Pose_(50695393088).jpg | Joshua Tree National Park | Public domain |
| Partido NBA -i---i- (31445390924).jpg | https://commons.wikimedia.org/wiki/File:Partido_NBA_-i---i-_(31445390924).jpg | Gobierno CDMX | CC0 1.0 |
| Partido NBA -i---i- (31910570250).jpg | https://commons.wikimedia.org/wiki/File:Partido_NBA_-i---i-_(31910570250).jpg | Gobierno CDMX | CC0 1.0 |
| Path and Trail Marker (53787344810).jpg | https://commons.wikimedia.org/wiki/File:Path_and_Trail_Marker_(53787344810).jpg | BLMUtah | Public domain |
| Paulista Feminino Final Santos 0x1 Palmeiras - Crédito Fabio Giannelli (Bruna Calderan).jpg | https://commons.wikimedia.org/wiki/File:Paulista_Feminino_Final_Santos_0x1_Palmeiras_-_Cr%C3%A9dito_Fabio_Giannelli_(Bruna_Calderan).jpg | SOCCER DIGITAL | Public domain |
| Peloton au Tro Bro Leon.jpg | https://commons.wikimedia.org/wiki/File:Peloton_au_Tro_Bro_Leon.jpg | Romscape | CC0 1.0 |
| Pemandangan Puncak dari Pos 5.jpg | https://commons.wikimedia.org/wiki/File:Pemandangan_Puncak_dari_Pos_5.jpg | Ardiishere | CC0 1.0 |
| People backpacking hiking slope flowers BBaccus NPS photo 2008 (22914374772).jpg | https://commons.wikimedia.org/wiki/File:People_backpacking_hiking_slope_flowers_BBaccus_NPS_photo_2008_(22914374772).jpg | Olympic National Park | Public domain |
| People bike the park road in Denali on June 11, 2019. (1a9fd1bd-75a6-4e32-915f-ed3a026289ef).JPG | https://commons.wikimedia.org/wiki/File:People_bike_the_park_road_in_Denali_on_June_11,_2019._(1a9fd1bd-75a6-4e32-915f-ed3a026289ef).JPG | NPS Photo / Emily Mesner | Public domain |
| People bike the park road in Denali on June 11, 2019. (36ae84b3-426f-4057-b0c0-bf4081ccacb6).JPG | https://commons.wikimedia.org/wiki/File:People_bike_the_park_road_in_Denali_on_June_11,_2019._(36ae84b3-426f-4057-b0c0-bf4081ccacb6).JPG | NPS Photo / Emily Mesner | Public domain |
| People canoeing in wild nature.jpg | https://commons.wikimedia.org/wiki/File:People_canoeing_in_wild_nature.jpg | David Menke | Public domain |
| People canoeing on water.jpg | https://commons.wikimedia.org/wiki/File:People_canoeing_on_water.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| People hiking on Burroughs Mountain and Wonderland trails near Frozen Lake. (45ce5397e27a42acbb4807f0afb7e411).JPG | https://commons.wikimedia.org/wiki/File:People_hiking_on_Burroughs_Mountain_and_Wonderland_trails_near_Frozen_Lake._(45ce5397e27a42acbb4807f0afb7e411).JPG | Steve Redman (MORA) | Public domain |
| People snowshoe winter lunch backpacking NPS Photo (22938955511).jpg | https://commons.wikimedia.org/wiki/File:People_snowshoe_winter_lunch_backpacking_NPS_Photo_(22938955511).jpg | Olympic National Park | Public domain |
| People swimming. (33688487392).jpg | https://commons.wikimedia.org/wiki/File:People_swimming._(33688487392).jpg | cotterillmike | CC0 1.0 |
| Percorso di canoa slalom sul Brenta a Valstagna.jpg | https://commons.wikimedia.org/wiki/File:Percorso_di_canoa_slalom_sul_Brenta_a_Valstagna.jpg | N.Fioretti at it.wikipedia | Public domain |
| Perkemahan di daerah sungai.jpg | https://commons.wikimedia.org/wiki/File:Perkemahan_di_daerah_sungai.jpg | Dicky Herlambang | CC0 1.0 |
| Person hiking on trail - Lula Lake Land Trust (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Person_hiking_on_trail_-_Lula_Lake_Land_Trust_(Unsplash).jpg | Chad Madden chadmadden | CC0 1.0 |
| Philsports arena futsal configuration.jpg | https://commons.wikimedia.org/wiki/File:Philsports_arena_futsal_configuration.jpg | Hariboneagle927 | CC0 1.0 |
| Piana di Valsecca signpost.jpg | https://commons.wikimedia.org/wiki/File:Piana_di_Valsecca_signpost.jpg | Omaha619 | CC0 1.0 |
| Pilates Wunda Chair.jpg | https://commons.wikimedia.org/wiki/File:Pilates_Wunda_Chair.jpg | Mrs. Ruti Shafir | CC0 1.0 |
| Pilates reformer.jpg | https://commons.wikimedia.org/wiki/File:Pilates_reformer.jpg | Maddi Bazzocco | CC0 1.0 |
| Pistes et sautoir au gymnase du collège Anne Frank à Miribel (décembre 2020).jpg | https://commons.wikimedia.org/wiki/File:Pistes_et_sautoir_au_gymnase_du_coll%C3%A8ge_Anne_Frank_%C3%A0_Miribel_(d%C3%A9cembre_2020).jpg | Benoît Prieur | CC0 1.0 |
| Plagne Centre, with Plagne Aime 2000 in the background, La Plagne, 2017.jpg | https://commons.wikimedia.org/wiki/File:Plagne_Centre,_with_Plagne_Aime_2000_in_the_background,_La_Plagne,_2017.jpg | DimiTalen | CC0 1.0 |
| Plagnettes ski lift and Dents du Peigne, 2018.jpg | https://commons.wikimedia.org/wiki/File:Plagnettes_ski_lift_and_Dents_du_Peigne,_2018.jpg | DimiTalen | CC0 1.0 |
| Players and referees before a football match.jpg | https://commons.wikimedia.org/wiki/File:Players_and_referees_before_a_football_match.jpg | Samson Ssemakadde | CC0 1.0 |
| Players shake hands before a football match.jpg | https://commons.wikimedia.org/wiki/File:Players_shake_hands_before_a_football_match.jpg | Samson Ssemakadde | CC0 1.0 |
| PonteVaelntino2.jpg | https://commons.wikimedia.org/wiki/File:PonteVaelntino2.jpg | Thomasox58 | CC0 1.0 |
| Porridge-muesli-fruits.jpg | https://commons.wikimedia.org/wiki/File:Porridge-muesli-fruits.jpg | braetschit | CC0 1.0 |
| Portable gas stove and frying pan.jpg | https://commons.wikimedia.org/wiki/File:Portable_gas_stove_and_frying_pan.jpg | Itai | Public domain |
| Prelomlenie.jpg | https://commons.wikimedia.org/wiki/File:Prelomlenie.jpg | ivtorov | CC0 1.0 |
| President Ford skiing - NARA - 6829599.jpg | https://commons.wikimedia.org/wiki/File:President_Ford_skiing_-_NARA_-_6829599.jpg | David Hume Kennerly | Public domain |
| President Gerald R. Ford Skiing at Vail, Colorado - NARA - 6829599.jpg | https://commons.wikimedia.org/wiki/File:President_Gerald_R._Ford_Skiing_at_Vail,_Colorado_-_NARA_-_6829599.jpg | Unknown authorUnknown author or not provided | Public domain |
| President St marathon jeh.jpg | https://commons.wikimedia.org/wiki/File:President_St_marathon_jeh.jpg | Jim.henderson | CC0 1.0 |
| Professional Football Player Sofiane El Ftouhi.jpg | https://commons.wikimedia.org/wiki/File:Professional_Football_Player_Sofiane_El_Ftouhi.jpg | Footballtalent10 | CC0 1.0 |
| Professional runners at Berlin-Marathon 2025-09-21 01.jpg | https://commons.wikimedia.org/wiki/File:Professional_runners_at_Berlin-Marathon_2025-09-21_01.jpg | Leonhard Lenz | CC0 1.0 |
| Professional runners at Berlin-Marathon 2025-09-21 22.jpg | https://commons.wikimedia.org/wiki/File:Professional_runners_at_Berlin-Marathon_2025-09-21_22.jpg | Leonhard Lenz | CC0 1.0 |
| Prugiasco2.jpg | https://commons.wikimedia.org/wiki/File:Prugiasco2.jpg | Thomasox58 | CC0 1.0 |
| Public bridleway and public footpath signpost on the path between Saddlescombe Road and Devil's Dyke 2025-08-26.jpg | https://commons.wikimedia.org/wiki/File:Public_bridleway_and_public_footpath_signpost_on_the_path_between_Saddlescombe_Road_and_Devil%27s_Dyke_2025-08-26.jpg | Andy Li | CC0 1.0 |
| Puma Suede.jpg | https://commons.wikimedia.org/wiki/File:Puma_Suede.jpg | LeDroider | CC0 1.0 |
| Purdy Fitness Center NOFFS Zone (9781609).jpg | https://commons.wikimedia.org/wiki/File:Purdy_Fitness_Center_NOFFS_Zone_(9781609).jpg | U.S. Navy photo by James Kimber | Public domain |
| Puscaghia distances à pied.jpg | https://commons.wikimedia.org/wiki/File:Puscaghia_distances_%C3%A0_pied.jpg | Cardioceras | CC0 1.0 |
| Quaker apples & cinnamon instant oatmeal 1.jpg | https://commons.wikimedia.org/wiki/File:Quaker_apples_%26_cinnamon_instant_oatmeal_1.jpg | Mx. Granger | CC0 1.0 |
| Quaker apples & cinnamon instant oatmeal 2.jpg | https://commons.wikimedia.org/wiki/File:Quaker_apples_%26_cinnamon_instant_oatmeal_2.jpg | Mx. Granger | CC0 1.0 |
| RAF Croughton National Veterans Suicide Awareness Day event (8673929).jpg | https://commons.wikimedia.org/wiki/File:RAF_Croughton_National_Veterans_Suicide_Awareness_Day_event_(8673929).jpg | U.S. Air Force photo by Airman Adam Enbal | Public domain |
| RAF Mildenhall hosts AIRCOM Volleyball Championships (6113229).jpg | https://commons.wikimedia.org/wiki/File:RAF_Mildenhall_hosts_AIRCOM_Volleyball_Championships_(6113229).jpg | U.S. Air Force photo by Senior Airman Benjamin Cooper | Public domain |
| RAF Mildenhall spouses master resiliency, mindfulness, yoga (5032915).jpg | https://commons.wikimedia.org/wiki/File:RAF_Mildenhall_spouses_master_resiliency,_mindfulness,_yoga_(5032915).jpg | U.S. Air Force photo by Airman 1st Class Brandon Esau | Public domain |
| RAF Mildenhall spouses master resiliency, mindfulness, yoga (5032918).jpg | https://commons.wikimedia.org/wiki/File:RAF_Mildenhall_spouses_master_resiliency,_mindfulness,_yoga_(5032918).jpg | U.S. Air Force photo by Airman 1st Class Brandon Esau | Public domain |
| Ramstein’s Southside Fitness Center to reopen for active duty personnel (6235332).jpg | https://commons.wikimedia.org/wiki/File:Ramstein%E2%80%99s_Southside_Fitness_Center_to_reopen_for_active_duty_personnel_(6235332).jpg | U.S. Air Force photo by Senior Airman Elizabeth Baker | Public domain |
| Ranger-led canoe tour on Little Beaver Lake (2f521698-e5bd-4dc2-b3ba-852b5aa914d3).jpg | https://commons.wikimedia.org/wiki/File:Ranger-led_canoe_tour_on_Little_Beaver_Lake_(2f521698-e5bd-4dc2-b3ba-852b5aa914d3).jpg | NPS | Public domain |
| Rangers working backpacking mountain people (23048788506).jpg | https://commons.wikimedia.org/wiki/File:Rangers_working_backpacking_mountain_people_(23048788506).jpg | Olympic National Park | Public domain |
| Ready For The Hike (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Ready_For_The_Hike_(Unsplash).jpg | Patrick Hendry worldsbetweenlines | CC0 1.0 |
| Recreation (20231103-FS-BC-025).jpg | https://commons.wikimedia.org/wiki/File:Recreation_(20231103-FS-BC-025).jpg | Forest Service Photography | Public domain |
| Recreation backpacking hiking Bald mountain trail Mt Hood Wilderness, Mt Hood National Forest (36296955176).jpg | https://commons.wikimedia.org/wiki/File:Recreation_backpacking_hiking_Bald_mountain_trail_Mt_Hood_Wilderness,_Mt_Hood_National_Forest_(36296955176).jpg | U.S. Forest Service- Pacific Northwest Region | Public domain |
| Recreational skiers in the mountains take breaks and enjoy skiing in the regions.jpg | https://commons.wikimedia.org/wiki/File:Recreational_skiers_in_the_mountains_take_breaks_and_enjoy_skiing_in_the_regions.jpg | U.S. Fish and Wildlife Service | Public domain |
| Red Mountain Trail No. 159 (28775990906).jpg | https://commons.wikimedia.org/wiki/File:Red_Mountain_Trail_No._159_(28775990906).jpg | Coconino National Forest | CC0 1.0 |
| Red Rock mountain biking (24994417442).jpg | https://commons.wikimedia.org/wiki/File:Red_Rock_mountain_biking_(24994417442).jpg | BLM Nevada | Public domain |
| Red Rock trail running (25050993693).jpg | https://commons.wikimedia.org/wiki/File:Red_Rock_trail_running_(25050993693).jpg | BLM Nevada | Public domain |
| Red water swimming pool (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Red_water_swimming_pool_(Unsplash).jpg | Caroline Bertolini caroline_bertolini | CC0 1.0 |
| Referee during a football match.jpg | https://commons.wikimedia.org/wiki/File:Referee_during_a_football_match.jpg | Samson Ssemakadde | CC0 1.0 |
| Refugee football tournament 098.jpg | https://commons.wikimedia.org/wiki/File:Refugee_football_tournament_098.jpg | Samson Ssemakadde | CC0 1.0 |
| Refugee football tournament.jpg | https://commons.wikimedia.org/wiki/File:Refugee_football_tournament.jpg | Samson Ssemakadde | CC0 1.0 |
| Register's Annual Great Bicycle Ride Across Iowa 120717-F-HK400-005.jpg | https://commons.wikimedia.org/wiki/File:Register%27s_Annual_Great_Bicycle_Ride_Across_Iowa_120717-F-HK400-005.jpg | Airman 1st Class Zachary Hada | Public domain |
| Researchers hiking in the Pinto Mountain area (51194412714).jpg | https://commons.wikimedia.org/wiki/File:Researchers_hiking_in_the_Pinto_Mountain_area_(51194412714).jpg | Joshua Tree National Park | Public domain |
| Researchers hiking in the Pinto Mountain area (51194420519).jpg | https://commons.wikimedia.org/wiki/File:Researchers_hiking_in_the_Pinto_Mountain_area_(51194420519).jpg | Joshua Tree National Park | Public domain |
| Ri Secco.jpg | https://commons.wikimedia.org/wiki/File:Ri_Secco.jpg | Thomasox58 | CC0 1.0 |
| River Kayak.jpg | https://commons.wikimedia.org/wiki/File:River_Kayak.jpg | MarkBuckawicki | CC0 1.0 |
| River Run - Sevier River (30767754252).jpg | https://commons.wikimedia.org/wiki/File:River_Run_-_Sevier_River_(30767754252).jpg | BLMUtah | Public domain |
| Rivers United vs Katsina United match at Adokiye Amiesimaka Stadium during a rainy evening, Port Harcourt (2026) 01.jpg | https://commons.wikimedia.org/wiki/File:Rivers_United_vs_Katsina_United_match_at_Adokiye_Amiesimaka_Stadium_during_a_rainy_evening,_Port_Harcourt_(2026)_01.jpg | ABORISADEADETONA | CC0 1.0 |
| Rivers United vs Katsina United match at Adokiye Amiesimaka Stadium during a rainy evening, Port Harcourt (2026) 02.jpg | https://commons.wikimedia.org/wiki/File:Rivers_United_vs_Katsina_United_match_at_Adokiye_Amiesimaka_Stadium_during_a_rainy_evening,_Port_Harcourt_(2026)_02.jpg | ABORISADEADETONA | CC0 1.0 |
| Road bike race on sunny day (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Road_bike_race_on_sunny_day_(Unsplash).jpg | Robert Calin velobar_plus | CC0 1.0 |
| Roque-Gageac Dordogne Gabares Canoës.jpg | https://commons.wikimedia.org/wiki/File:Roque-Gageac_Dordogne_Gabares_Cano%C3%ABs.jpg | Jebulon | CC0 1.0 |
| Rosita Sanchez Mexican volleyball player.jpg | https://commons.wikimedia.org/wiki/File:Rosita_Sanchez_Mexican_volleyball_player.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Rosita Sanchez jugadora de voleibol de Nuevo Laredo voleando.jpg | https://commons.wikimedia.org/wiki/File:Rosita_Sanchez_jugadora_de_voleibol_de_Nuevo_Laredo_voleando.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Rozcestník Wegweiser Fingerpost near Jetřichovice.jpg | https://commons.wikimedia.org/wiki/File:Rozcestn%C3%ADk_Wegweiser_Fingerpost_near_Jet%C5%99ichovice.jpg | Peter Maiwald | CC0 1.0 |
| Runners at the 500 Festival Mini-Marathon.jpg | https://commons.wikimedia.org/wiki/File:Runners_at_the_500_Festival_Mini-Marathon.jpg | Photoguy1212 | CC0 1.0 |
| Runners at the Manchester Regional Arena.jpg | https://commons.wikimedia.org/wiki/File:Runners_at_the_Manchester_Regional_Arena.jpg | King Februarius | CC0 1.0 |
| Runners in a race, Vieux-Limoilou.jpg | https://commons.wikimedia.org/wiki/File:Runners_in_a_race,_Vieux-Limoilou.jpg | Wilfredor | CC0 1.0 |
| Runners in the 2026 Comrades Marathon near Cato Ridge.jpg | https://commons.wikimedia.org/wiki/File:Runners_in_the_2026_Comrades_Marathon_near_Cato_Ridge.jpg | Upjav | CC0 1.0 |
| Runners make their way down a running trail during an Air Force birthday 5K run, 2014, Moody Air Force Base.jpg | https://commons.wikimedia.org/wiki/File:Runners_make_their_way_down_a_running_trail_during_an_Air_Force_birthday_5K_run,_2014,_Moody_Air_Force_Base.jpg | US Air Force from USA | Public domain |
| Running the Avalanche Trail (37397606580).jpg | https://commons.wikimedia.org/wiki/File:Running_the_Avalanche_Trail_(37397606580).jpg | GlacierNPS | Public domain |
| SEH 4507 (55043167439).jpg | https://commons.wikimedia.org/wiki/File:SEH_4507_(55043167439).jpg | Koldo Zuazaga | CC0 1.0 |
| SEM 1227 (55046227786).jpg | https://commons.wikimedia.org/wiki/File:SEM_1227_(55046227786).jpg | Koldo Zuazaga | CC0 1.0 |
| SOT kayak.JPG | https://commons.wikimedia.org/wiki/File:SOT_kayak.JPG | SeppVei | Public domain |
| STRONG & KIND Hickory smoked almond protein bar.jpg | https://commons.wikimedia.org/wiki/File:STRONG_%26_KIND_Hickory_smoked_almond_protein_bar.jpg | Mx. Granger | CC0 1.0 |
| SUP in Port Melbourne (51541407471).jpg | https://commons.wikimedia.org/wiki/File:SUP_in_Port_Melbourne_(51541407471).jpg | Alan Travers from Melbourne, Australia | CC0 1.0 |
| SUP on Lake McDonald 7 15 16 (28298012226).jpg | https://commons.wikimedia.org/wiki/File:SUP_on_Lake_McDonald_7_15_16_(28298012226).jpg | GlacierNPS | Public domain |
| SV Linx SV Waldhof Hans Weber Stadion.JPG | https://commons.wikimedia.org/wiki/File:SV_Linx_SV_Waldhof_Hans_Weber_Stadion.JPG | Der pate | Public domain |
| SWV Wegweiser OGAC019.jpg | https://commons.wikimedia.org/wiki/File:SWV_Wegweiser_OGAC019.jpg | Karthoo | CC0 1.0 |
| SWV Wegweiser OGBP106 Lettstädter Höhe Stein 77.jpg | https://commons.wikimedia.org/wiki/File:SWV_Wegweiser_OGBP106_Lettst%C3%A4dter_H%C3%B6he_Stein_77.jpg | Karthoo | CC0 1.0 |
| SWV Wegweiser OGGE017 Vordere Halde (1).jpg | https://commons.wikimedia.org/wiki/File:SWV_Wegweiser_OGGE017_Vordere_Halde_(1).jpg | Karthoo | CC0 1.0 |
| Safford-Morenci Trailhead East sign (54972401021).jpg | https://commons.wikimedia.org/wiki/File:Safford-Morenci_Trailhead_East_sign_(54972401021).jpg | BLMArizona | Public domain |
| Safford-Morenci Trailhead West sign (54972658364).jpg | https://commons.wikimedia.org/wiki/File:Safford-Morenci_Trailhead_West_sign_(54972658364).jpg | BLMArizona | Public domain |
| Sagamihara sports-2.JPG | https://commons.wikimedia.org/wiki/File:Sagamihara_sports-2.JPG | Waka77 | CC0 1.0 |
| SanPietro1.jpg | https://commons.wikimedia.org/wiki/File:SanPietro1.jpg | Thomasox58 | CC0 1.0 |
| Sandy Ridge Trails in Oregon (20707231319).jpg | https://commons.wikimedia.org/wiki/File:Sandy_Ridge_Trails_in_Oregon_(20707231319).jpg | mypubliclands | Public domain |
| Sarenne piste along the river, Le Freney-d'Oisans, 2026 (2).jpg | https://commons.wikimedia.org/wiki/File:Sarenne_piste_along_the_river,_Le_Freney-d%27Oisans,_2026_(2).jpg | DimiTalen | CC0 1.0 |
| School swimming competitions at Kampala International School.jpg | https://commons.wikimedia.org/wiki/File:School_swimming_competitions_at_Kampala_International_School.jpg | Samson Ssemakadde | CC0 1.0 |
| Schwändital3.jpg | https://commons.wikimedia.org/wiki/File:Schw%C3%A4ndital3.jpg | Thomasox58 | CC0 1.0 |
| Sea Kayak Kealakekua.jpg | https://commons.wikimedia.org/wiki/File:Sea_Kayak_Kealakekua.jpg | User:Surfsupusa | Public domain |
| Sea kayak hawaii.JPG | https://commons.wikimedia.org/wiki/File:Sea_kayak_hawaii.JPG | Surfsupusa at English Wikipedia | Public domain |
| Sea kayaking 110710-F-LY131-002.jpg | https://commons.wikimedia.org/wiki/File:Sea_kayaking_110710-F-LY131-002.jpg | Steven White | Public domain |
| Secretary Kerry Approaches a Group of Student Soccer Players as He Walks Across the Rice University Campus in Houston (26667574895).jpg | https://commons.wikimedia.org/wiki/File:Secretary_Kerry_Approaches_a_Group_of_Student_Soccer_Players_as_He_Walks_Across_the_Rice_University_Campus_in_Houston_(26667574895).jpg | U.S. Department of State from United States | Public domain |
| Segnavia CAI Umbria 659C-10a.jpg | https://commons.wikimedia.org/wiki/File:Segnavia_CAI_Umbria_659C-10a.jpg | Arcanma | CC0 1.0 |
| Sendai Athletic Field 1.JPG | https://commons.wikimedia.org/wiki/File:Sendai_Athletic_Field_1.JPG | wAKA77 | Public domain |
| Sendai Athletic Field 2.JPG | https://commons.wikimedia.org/wiki/File:Sendai_Athletic_Field_2.JPG | wAKA77 | Public domain |
| Sentida.jpg | https://commons.wikimedia.org/wiki/File:Sentida.jpg | Thomasox58 | CC0 1.0 |
| Sheep Camp warming shelter (0ae56ba6-1dd8-b71b-0b21-bb46d296fa12).jpg | https://commons.wikimedia.org/wiki/File:Sheep_Camp_warming_shelter_(0ae56ba6-1dd8-b71b-0b21-bb46d296fa12).jpg | NPS photo | Public domain |
| Shiretoko sea kayak.JPG | https://commons.wikimedia.org/wiki/File:Shiretoko_sea_kayak.JPG | Soica2001 (talk) | Public domain |
| Shova.JPG | https://commons.wikimedia.org/wiki/File:Shova.JPG | Joe Pineapples | Public domain |
| Sias Swimming Pool.jpg | https://commons.wikimedia.org/wiki/File:Sias_Swimming_Pool.jpg | Gary Todd | CC0 1.0 |
| Sign along the Arizona National Scenic Trail - 28172759548.jpg | https://commons.wikimedia.org/wiki/File:Sign_along_the_Arizona_National_Scenic_Trail_-_28172759548.jpg | Bureau of Land Management (BLM) Arizona | Public domain |
| Sign along the Arizona National Scenic Trail.jpg | https://commons.wikimedia.org/wiki/File:Sign_along_the_Arizona_National_Scenic_Trail.jpg | Bureau of Land Management (BLM) Arizona | Public domain |
| Sign for Copley Mountain (42463157181).jpg | https://commons.wikimedia.org/wiki/File:Sign_for_Copley_Mountain_(42463157181).jpg | blmcalifornia | Public domain |
| Sign for Copley Mountain (42463159531).jpg | https://commons.wikimedia.org/wiki/File:Sign_for_Copley_Mountain_(42463159531).jpg | blmcalifornia | Public domain |
| Signpost 657x615 Obertraun Rakousko.jpg | https://commons.wikimedia.org/wiki/File:Signpost_657x615_Obertraun_Rakousko.jpg | Mojmir Churavy | CC0 1.0 |
| Signpost on South Downs Way near Clayton tumuli 2025-09-08.jpg | https://commons.wikimedia.org/wiki/File:Signpost_on_South_Downs_Way_near_Clayton_tumuli_2025-09-08.jpg | Andy Li | CC0 1.0 |
| Signpost on the Nymans Woodland Walks 2026-07-31.jpg | https://commons.wikimedia.org/wiki/File:Signpost_on_the_Nymans_Woodland_Walks_2026-07-31.jpg | Andy Li | CC0 1.0 |
| Signpost with distance back to Island Explorer Bus Stop (45734f74-9c2a-4099-bc3b-4d35de937ff3).JPG | https://commons.wikimedia.org/wiki/File:Signpost_with_distance_back_to_Island_Explorer_Bus_Stop_(45734f74-9c2a-4099-bc3b-4d35de937ff3).JPG | NPS Photo | Public domain |
| Signpost with footpath markers in Coney Wood, Brighton 2025-04-13.jpg | https://commons.wikimedia.org/wiki/File:Signpost_with_footpath_markers_in_Coney_Wood,_Brighton_2025-04-13.jpg | Andy Li | CC0 1.0 |
| Signposts-3087.jpg | https://commons.wikimedia.org/wiki/File:Signposts-3087.jpg | Hans Braxmeier | CC0 1.0 |
| Sinh tố bơ.jpg | https://commons.wikimedia.org/wiki/File:Sinh_t%E1%BB%91_b%C6%A1.jpg | Hoangkid (thảo luận) | Public domain |
| Sinlahekin Fire Tent City.jpg | https://commons.wikimedia.org/wiki/File:Sinlahekin_Fire_Tent_City.jpg | U.S. Forest Service | Public domain |
| Ski competition in Ura-Taro, Sugadairakogen (19450).jpg | https://commons.wikimedia.org/wiki/File:Ski_competition_in_Ura-Taro,_Sugadairakogen_(19450).jpg | Syced | CC0 1.0 |
| Ski freeride.jpg | https://commons.wikimedia.org/wiki/File:Ski_freeride.jpg | Philippe rameau at fr.wikipedia | Public domain |
| Ski lift people on snow mountains (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Ski_lift_people_on_snow_mountains_(Unsplash).jpg | Urban Sanden urre | CC0 1.0 |
| Ski resort Boí-Taüll.jpg | https://commons.wikimedia.org/wiki/File:Ski_resort_Bo%C3%AD-Ta%C3%BCll.jpg | nieznany | Public domain |
| Ski school children skiing in front of bar Le Village Igloo, La Rosière, 2024.jpg | https://commons.wikimedia.org/wiki/File:Ski_school_children_skiing_in_front_of_bar_Le_Village_Igloo,_La_Rosi%C3%A8re,_2024.jpg | DimiTalen | CC0 1.0 |
| Ski slope along Lac Carrelet, L'Alpette, Oz, 2026.jpg | https://commons.wikimedia.org/wiki/File:Ski_slope_along_Lac_Carrelet,_L%27Alpette,_Oz,_2026.jpg | DimiTalen | CC0 1.0 |
| Ski tour (15758697728).jpg | https://commons.wikimedia.org/wiki/File:Ski_tour_(15758697728).jpg | USFWS Headquarters | Public domain |
| Ski with a Ranger February 2017 (27083892339).jpg | https://commons.wikimedia.org/wiki/File:Ski_with_a_Ranger_February_2017_(27083892339).jpg | Pacific Southwest Region 5 | Public domain |
| Skier and snowboarder come down Aiguille slope, Saint-Sorlin-d'Arves, 2023.jpg | https://commons.wikimedia.org/wiki/File:Skier_and_snowboarder_come_down_Aiguille_slope,_Saint-Sorlin-d%27Arves,_2023.jpg | DimiTalen | CC0 1.0 |
| Skiing (13172786504).jpg | https://commons.wikimedia.org/wiki/File:Skiing_(13172786504).jpg | LassenNPS | Public domain |
| Skiing (20a84535-646a-4ce3-8d32-50aaf0d95c12).JPG | https://commons.wikimedia.org/wiki/File:Skiing_(20a84535-646a-4ce3-8d32-50aaf0d95c12).JPG | NPS Photo | Public domain |
| Skiing Etna nord, Linguaglossa.JPG | https://commons.wikimedia.org/wiki/File:Skiing_Etna_nord,_Linguaglossa.JPG | Nasoallinsu | CC0 1.0 |
| Skiing between the snow-topped trees (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Skiing_between_the_snow-topped_trees_(Unsplash).jpg | Les Anderson lesanderson | CC0 1.0 |
| Skiing down Grande Épaule, Saint-Gervais-les-Bains, 2025.jpg | https://commons.wikimedia.org/wiki/File:Skiing_down_Grande_%C3%89paule,_Saint-Gervais-les-Bains,_2025.jpg | DimiTalen | CC0 1.0 |
| Skiing down the Faisan slope, La Rosière, 2024.jpg | https://commons.wikimedia.org/wiki/File:Skiing_down_the_Faisan_slope,_La_Rosi%C3%A8re,_2024.jpg | DimiTalen | CC0 1.0 |
| Skiing on Blacktail Deer Plateau (1418f124-93ca-48a9-be6e-aba9e6886c7c).jpg | https://commons.wikimedia.org/wiki/File:Skiing_on_Blacktail_Deer_Plateau_(1418f124-93ca-48a9-be6e-aba9e6886c7c).jpg | Neal Herbert | Public domain |
| Skiing on Virginia Cascades Drive (16157212187).jpg | https://commons.wikimedia.org/wiki/File:Skiing_on_Virginia_Cascades_Drive_(16157212187).jpg | Yellowstone National Park from Yellowstone NP, USA | Public domain |
| Skiing on the creek. (45965126074).jpg | https://commons.wikimedia.org/wiki/File:Skiing_on_the_creek._(45965126074).jpg | William Mewes from Oakville Ontario, Canada | CC0 1.0 |
| Skiing the Belette slope above Col des Embrasures, La Rosière, 2024.jpg | https://commons.wikimedia.org/wiki/File:Skiing_the_Belette_slope_above_Col_des_Embrasures,_La_Rosi%C3%A8re,_2024.jpg | DimiTalen | CC0 1.0 |
| Skiing the Bunsen Peak Road Ski Trail (3) (39449577760).jpg | https://commons.wikimedia.org/wiki/File:Skiing_the_Bunsen_Peak_Road_Ski_Trail_(3)_(39449577760).jpg | Yellowstone National Park | Public domain |
| Skiing the Sheepeater Ski Trail through Gardners Hole (2) (26387057027).jpg | https://commons.wikimedia.org/wiki/File:Skiing_the_Sheepeater_Ski_Trail_through_Gardners_Hole_(2)_(26387057027).jpg | Yellowstone National Park | Public domain |
| Skiing the Sheepeater Ski Trail through Gardners Hole (41258766351).jpg | https://commons.wikimedia.org/wiki/File:Skiing_the_Sheepeater_Ski_Trail_through_Gardners_Hole_(41258766351).jpg | Yellowstone National Park | Public domain |
| Skiing towards Val Thorens from the south, 1998.jpg | https://commons.wikimedia.org/wiki/File:Skiing_towards_Val_Thorens_from_the_south,_1998.jpg | DimiTalen | CC0 1.0 |
| Skitour Sierra Nevada Spain spring.jpg | https://commons.wikimedia.org/wiki/File:Skitour_Sierra_Nevada_Spain_spring.jpg | Simon Höger | CC0 1.0 |
| Skitour Sierra Nevada Spain.jpg | https://commons.wikimedia.org/wiki/File:Skitour_Sierra_Nevada_Spain.jpg | Simon Höger | CC0 1.0 |
| Skyttis athletics tracks.jpg | https://commons.wikimedia.org/wiki/File:Skyttis_athletics_tracks.jpg | Petey21 | Public domain |
| Sligo Way trailmarker.jpg | https://commons.wikimedia.org/wiki/File:Sligo_Way_trailmarker.jpg | A.-K. D. | CC0 1.0 |
| Slovak hiking signpost - intersection just above Tery's Hut in High tatras.jpg | https://commons.wikimedia.org/wiki/File:Slovak_hiking_signpost_-_intersection_just_above_Tery%27s_Hut_in_High_tatras.jpg | User: (WT-shared) Scharla at wts wikivoyage | Public domain |
| Smoothie bowlin (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Smoothie_bowlin_(Unsplash).jpg | Ben Neale ben_neale | CC0 1.0 |
| Smuz Lab signature smoothie at Smuz Cafe Budapest.jpg | https://commons.wikimedia.org/wiki/File:Smuz_Lab_signature_smoothie_at_Smuz_Cafe_Budapest.jpg | Smuzcafe | CC0 1.0 |
| Snow Time Happy Time.jpg | https://commons.wikimedia.org/wiki/File:Snow_Time_Happy_Time.jpg | Wendelin Jacober | CC0 1.0 |
| Snowboarder at Mammoth.jpg | https://commons.wikimedia.org/wiki/File:Snowboarder_at_Mammoth.jpg | Carol M. Highsmith | Public domain |
| Snowboarder at resort (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Snowboarder_at_resort_(Unsplash).jpg | Joshua Reddekopp joshuaryanphoto | CC0 1.0 |
| Snowboarder on a cloudy hill (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Snowboarder_on_a_cloudy_hill_(Unsplash).jpg | Boba Jovanovic bboba | CC0 1.0 |
| Snowboardeur freestyle.jpg | https://commons.wikimedia.org/wiki/File:Snowboardeur_freestyle.jpg | Francois Boulianne | Public domain |
| Snowshoeing ski sport.jpg | https://commons.wikimedia.org/wiki/File:Snowshoeing_ski_sport.jpg | Jay Summet | Public domain |
| Soccer ball on ground.jpg | https://commons.wikimedia.org/wiki/File:Soccer_ball_on_ground.jpg | Petey21 | CC0 1.0 |
| Soccer goalkeeper.jpg | https://commons.wikimedia.org/wiki/File:Soccer_goalkeeper.jpg | Master Sgt. Lance Cheung of U.S. Air Force | Public domain |
| South Downs Way and Public Bridleway signpost 2025-08-16.jpg | https://commons.wikimedia.org/wiki/File:South_Downs_Way_and_Public_Bridleway_signpost_2025-08-16.jpg | Andy Li | CC0 1.0 |
| South Downs Way fingerpost at Saddlescombe 2025-08-26.jpg | https://commons.wikimedia.org/wiki/File:South_Downs_Way_fingerpost_at_Saddlescombe_2025-08-26.jpg | Andy Li | CC0 1.0 |
| South Fork of the American River (27805325551).jpg | https://commons.wikimedia.org/wiki/File:South_Fork_of_the_American_River_(27805325551).jpg | blmcalifornia | Public domain |
| Sports shoes.jpg | https://commons.wikimedia.org/wiki/File:Sports_shoes.jpg | Nicole Aspinall | CC0 1.0 |
| SpringTrailsMaint (51971232893).jpg | https://commons.wikimedia.org/wiki/File:SpringTrailsMaint_(51971232893).jpg | Forest Service - Northern Region | Public domain |
| Start of a trail, Great Smoky Mountains National Park, Cataloochee.jpg | https://commons.wikimedia.org/wiki/File:Start_of_a_trail,_Great_Smoky_Mountains_National_Park,_Cataloochee.jpg | Washedwithblood7 (talk) | Public domain |
| Starting line (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Starting_line_(Unsplash).jpg | Kolleen Gladden rockthechaos | CC0 1.0 |
| Sterling Mine Ditch Trail (50857671317).jpg | https://commons.wikimedia.org/wiki/File:Sterling_Mine_Ditch_Trail_(50857671317).jpg | BLM Oregon &amp; Washington | Public domain |
| Sterling Mine Ditch Trail (50857675712).jpg | https://commons.wikimedia.org/wiki/File:Sterling_Mine_Ditch_Trail_(50857675712).jpg | BLM Oregon &amp; Washington | Public domain |
| Stohánek rozcestník.jpg | https://commons.wikimedia.org/wiki/File:Stoh%C3%A1nek_rozcestn%C3%ADk.jpg | Zákupák | Public domain |
| Studen1&2.jpg | https://commons.wikimedia.org/wiki/File:Studen1%262.jpg | Thomasox58 | CC0 1.0 |
| Studen1&2Brücke.jpg | https://commons.wikimedia.org/wiki/File:Studen1%262Br%C3%BCcke.jpg | Thomasox58 | CC0 1.0 |
| StudenEuthal.jpg | https://commons.wikimedia.org/wiki/File:StudenEuthal.jpg | Thomasox58 | CC0 1.0 |
| StudenEuthal3&3 1.jpg | https://commons.wikimedia.org/wiki/File:StudenEuthal3%263_1.jpg | Thomasox58 | CC0 1.0 |
| StudenEuthal3.jpg | https://commons.wikimedia.org/wiki/File:StudenEuthal3.jpg | Thomasox58 | CC0 1.0 |
| StudenNachBrücke.jpg | https://commons.wikimedia.org/wiki/File:StudenNachBr%C3%BCcke.jpg | Thomasox58 | CC0 1.0 |
| StudenVorBrücke.jpg | https://commons.wikimedia.org/wiki/File:StudenVorBr%C3%BCcke.jpg | Thomasox58 | CC0 1.0 |
| Subsitutition in a football game.jpg | https://commons.wikimedia.org/wiki/File:Subsitutition_in_a_football_game.jpg | Samson Ssemakadde | CC0 1.0 |
| Summer Fun (251917443).jpeg | https://commons.wikimedia.org/wiki/File:Summer_Fun_(251917443).jpeg | Scott Martin | CC0 1.0 |
| Summer aquathlon brings friendly competition DVIDS447660.jpg | https://commons.wikimedia.org/wiki/File:Summer_aquathlon_brings_friendly_competition_DVIDS447660.jpg | Lance Cpl. Matthew Manning | Public domain |
| Summit Trail summer hiking (54401460400).jpg | https://commons.wikimedia.org/wiki/File:Summit_Trail_summer_hiking_(54401460400).jpg | BLM Alaska | Public domain |
| Sunrise hiking views (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Sunrise_hiking_views_(Unsplash).jpg | Aneta Ivanova anetaivanova | CC0 1.0 |
| Sunset kayak (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Sunset_kayak_(Unsplash).jpg | Patrick Fore patrickian4 | CC0 1.0 |
| Surf Paddle Board.jpg | https://commons.wikimedia.org/wiki/File:Surf_Paddle_Board.jpg | Video Girl | CC0 1.0 |
| Swimming For Gold at The 2014 Warrior Games 140930-M-QA203-004.jpg | https://commons.wikimedia.org/wiki/File:Swimming_For_Gold_at_The_2014_Warrior_Games_140930-M-QA203-004.jpg | Cpl. Jessica Quezada | Public domain |
| Swimming For Gold at The 2014 Warrior Games 140930-M-QA203-008.jpg | https://commons.wikimedia.org/wiki/File:Swimming_For_Gold_at_The_2014_Warrior_Games_140930-M-QA203-008.jpg | Cpl. Jessica Quezada | Public domain |
| Swimming Pool Covering.jpeg | https://commons.wikimedia.org/wiki/File:Swimming_Pool_Covering.jpeg | Abbasidaniyal | CC0 1.0 |
| Swimming bath.jpg | https://commons.wikimedia.org/wiki/File:Swimming_bath.jpg | Paolo Neo | Public domain |
| Swimming competition 2019.jpg | https://commons.wikimedia.org/wiki/File:Swimming_competition_2019.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Swimming gala competitions.jpg | https://commons.wikimedia.org/wiki/File:Swimming_gala_competitions.jpg | Samson Ssemakadde | CC0 1.0 |
| Swimming gala competitions2.jpg | https://commons.wikimedia.org/wiki/File:Swimming_gala_competitions2.jpg | Samson Ssemakadde | CC0 1.0 |
| Swimming heats at Invictus Games 2016 160507-D-BB251-001.jpg | https://commons.wikimedia.org/wiki/File:Swimming_heats_at_Invictus_Games_2016_160507-D-BB251-001.jpg | Roger Wollenberg | Public domain |
| Swimming pool underwater 1.JPG | https://commons.wikimedia.org/wiki/File:Swimming_pool_underwater_1.JPG | Steven Lek | Public domain |
| Swimming pools in Bangladesh 03.jpg | https://commons.wikimedia.org/wiki/File:Swimming_pools_in_Bangladesh_03.jpg | Kryesmin | CC0 1.0 |
| Swiss Hiking Network - Signpost - Monte Caslano 510 m.jpg | https://commons.wikimedia.org/wiki/File:Swiss_Hiking_Network_-_Signpost_-_Monte_Caslano_510_m.jpg | Kaitu | CC0 1.0 |
| Séance de sport en salle et natation - team building 04.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_04.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 09.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_09.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 13.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_13.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 14.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_14.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 28.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_28.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 34.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_34.jpg | Abiba Pauline | CC0 1.0 |
| Séance de sport en salle et natation - team building 38.jpg | https://commons.wikimedia.org/wiki/File:S%C3%A9ance_de_sport_en_salle_et_natation_-_team_building_38.jpg | Abiba Pauline | CC0 1.0 |
| Tab Man By Fount.JPG | https://commons.wikimedia.org/wiki/File:Tab_Man_By_Fount.JPG | Paul Thomson | Public domain |
| Tajaun Levy - 2010 Annual CIP Kayaking trip (4755268200).jpg | https://commons.wikimedia.org/wiki/File:Tajaun_Levy_-_2010_Annual_CIP_Kayaking_trip_(4755268200).jpg | U. S. Fish and Wildlife Service - Northeast Region | Public domain |
| Taking in a view like this one of Indian Bread Rocks in the Dos Cabezas Mountains near Bowie (53822228343).jpg | https://commons.wikimedia.org/wiki/File:Taking_in_a_view_like_this_one_of_Indian_Bread_Rocks_in_the_Dos_Cabezas_Mountains_near_Bowie_(53822228343).jpg | BLMArizona | Public domain |
| Team Bengal Beast.jpg | https://commons.wikimedia.org/wiki/File:Team_Bengal_Beast.jpg | Team Bengal Beast | CC0 1.0 |
| Team Mildenhall joins local community for sports exchange (9682950).jpg | https://commons.wikimedia.org/wiki/File:Team_Mildenhall_joins_local_community_for_sports_exchange_(9682950).jpg | U.S. Air Force photo by Staff Sgt. Jason Cochran | Public domain |
| Team U S Invictus Games - Swimming (8021737).jpg | https://commons.wikimedia.org/wiki/File:Team_U_S_Invictus_Games_-_Swimming_(8021737).jpg | Cpl. Oneg Plisner | Public domain |
| Telemark skiing Lyngen Alpen Arnoya skitor.jpg | https://commons.wikimedia.org/wiki/File:Telemark_skiing_Lyngen_Alpen_Arnoya_skitor.jpg | Funcarver116 | CC0 1.0 |
| Tent camping Lake crescent campground orange nps photo (22350549763).jpg | https://commons.wikimedia.org/wiki/File:Tent_camping_Lake_crescent_campground_orange_nps_photo_(22350549763).jpg | Olympic National Park | Public domain |
| Tent camping at Hurricane River Campground (8ab7ecf4-55e8-462b-a159-b0ce7ade5a0d).JPG | https://commons.wikimedia.org/wiki/File:Tent_camping_at_Hurricane_River_Campground_(8ab7ecf4-55e8-462b-a159-b0ce7ade5a0d).JPG | NPS | Public domain |
| Tent rigid poles.jpg | https://commons.wikimedia.org/wiki/File:Tent_rigid_poles.jpg | nieznany | Public domain |
| Tente de sans-abri derrière l'auditorium de Lyon (mai 2019).jpg | https://commons.wikimedia.org/wiki/File:Tente_de_sans-abri_derri%C3%A8re_l%27auditorium_de_Lyon_(mai_2019).jpg | Benoît Prieur | CC0 1.0 |
| Tents at Ullu-Tau alpine camp.jpg | https://commons.wikimedia.org/wiki/File:Tents_at_Ullu-Tau_alpine_camp.jpg | Alexander Roumega | CC0 1.0 |
| The Colors of Yoga (146496cd-e3a8-f28d-7c28-cdc3a049b014).jpg | https://commons.wikimedia.org/wiki/File:The_Colors_of_Yoga_(146496cd-e3a8-f28d-7c28-cdc3a049b014).jpg | NPS | Public domain |
| The National Trust Devil's Dyke signpost and a fingerpost 2025-08-16.jpg | https://commons.wikimedia.org/wiki/File:The_National_Trust_Devil%27s_Dyke_signpost_and_a_fingerpost_2025-08-16.jpg | Andy Li | CC0 1.0 |
| The Pacific Crest Trail (PCT) in southern Oregon (23145698444).jpg | https://commons.wikimedia.org/wiki/File:The_Pacific_Crest_Trail_(PCT)_in_southern_Oregon_(23145698444).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| The Pacific Crest Trail (PCT) in southern Oregon (23747717006).jpg | https://commons.wikimedia.org/wiki/File:The_Pacific_Crest_Trail_(PCT)_in_southern_Oregon_(23747717006).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| The Photographers Backpack (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:The_Photographers_Backpack_(Unsplash).jpg | Alexander Andrews alex_andrews | CC0 1.0 |
| The Views From Your Private Yoga Studio (194407539).jpeg | https://commons.wikimedia.org/wiki/File:The_Views_From_Your_Private_Yoga_Studio_(194407539).jpeg | Bali Real Estate | CC0 1.0 |
| The bicep curl weight station is 1 of 24 stations that US Air Force (USAF) personnel can utilize during a Combat Fitness Circuit Training Class held at the Royal Air Force (RAF) Mil - DPLA - 8280e49e95b19cdf04b9672f5f8856cf.jpeg | https://commons.wikimedia.org/wiki/File:The_bicep_curl_weight_station_is_1_of_24_stations_that_US_Air_Force_(USAF)_personnel_can_utilize_during_a_Combat_Fitness_Circuit_Training_Class_held_at_the_Royal_Air_Force_(RAF)_Mil_-_DPLA_-_8280e49e95b19cdf04b9672f5f8856cf.jpeg | Department of Defense. American Forces Information Service. Defense Visual Information Center. 1994 | Public domain |
| The calm beneath the water 161117-F-QF982-210.jpg | https://commons.wikimedia.org/wiki/File:The_calm_beneath_the_water_161117-F-QF982-210.jpg | Tech. Sgt. Joshua Garcia | Public domain |
| The technical bench for the home team.jpg | https://commons.wikimedia.org/wiki/File:The_technical_bench_for_the_home_team.jpg | Samson Ssemakadde | CC0 1.0 |
| The trail sign to Big Hole Lookout, with a trail running through conifer forests and a carpet of forest vegetation (50969923531).jpg | https://commons.wikimedia.org/wiki/File:The_trail_sign_to_Big_Hole_Lookout,_with_a_trail_running_through_conifer_forests_and_a_carpet_of_forest_vegetation_(50969923531).jpg | Forest Service - Northern Region | Public domain |
| The view of Derwentwater Lake from the Cat Bells Hike near Keswick.jpg | https://commons.wikimedia.org/wiki/File:The_view_of_Derwentwater_Lake_from_the_Cat_Bells_Hike_near_Keswick.jpg | Stch2022 | CC0 1.0 |
| The-us-armed-forces-mens-basketball-team-plays-china-e9f540-1024.jpg | https://commons.wikimedia.org/wiki/File:The-us-armed-forces-mens-basketball-team-plays-china-e9f540-1024.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class Ian Carver | Public domain |
| Thomas peterson tour de pologne 2012.JPG | https://commons.wikimedia.org/wiki/File:Thomas_peterson_tour_de_pologne_2012.JPG | Zyxist | CC0 1.0 |
| Three protein bars, two cut in half.jpg | https://commons.wikimedia.org/wiki/File:Three_protein_bars,_two_cut_in_half.jpg | Mx. Granger | CC0 1.0 |
| Three protein bars.jpg | https://commons.wikimedia.org/wiki/File:Three_protein_bars.jpg | Mx. Granger | CC0 1.0 |
| Thunderbirds of the 45th Infantry Brigade Combat Team hosts the sixth annual fallen heroes half marathon 161007-A-TY047-201.jpg | https://commons.wikimedia.org/wiki/File:Thunderbirds_of_the_45th_Infantry_Brigade_Combat_Team_hosts_the_sixth_annual_fallen_heroes_half_marathon_161007-A-TY047-201.jpg | Capt. Josh Page | Public domain |
| Tim Gouw 2016-03-14 (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Tim_Gouw_2016-03-14_(Unsplash).jpg | Tim Gouw punttim | CC0 1.0 |
| To Neptune and Back- SBD 1 Opens Solar System Trail (9572286).jpg | https://commons.wikimedia.org/wiki/File:To_Neptune_and_Back-_SBD_1_Opens_Solar_System_Trail_(9572286).jpg | U.S. Space Force SBD1 by Senior Airman Ryan Quijas | Public domain |
| Top station of TKD du Torraz, Tête du Torraz, La Giettaz, 2025.jpg | https://commons.wikimedia.org/wiki/File:Top_station_of_TKD_du_Torraz,_T%C3%AAte_du_Torraz,_La_Giettaz,_2025.jpg | DimiTalen | CC0 1.0 |
| Torneio Regional de Natação Pré-mirim a Sênior - 48670387788.jpg | https://commons.wikimedia.org/wiki/File:Torneio_Regional_de_Nata%C3%A7%C3%A3o_Pr%C3%A9-mirim_a_S%C3%AAnior_-_48670387788.jpg | ASCOM - Prefeitura de Votuporanga | Public domain |
| Touchpoint 3 FMWR Torii Gym 23 JUL 2024 13 (8562476).jpg | https://commons.wikimedia.org/wiki/File:Touchpoint_3_FMWR_Torii_Gym_23_JUL_2024_13_(8562476).jpg | U.S. Army USAG-O by Ichiro Tokashiki | Public domain |
| TourDeFrance 2005 07 09.jpg | https://commons.wikimedia.org/wiki/File:TourDeFrance_2005_07_09.jpg | Vzach | Public domain |
| TourDeFrance 2005-07-08.JPG | https://commons.wikimedia.org/wiki/File:TourDeFrance_2005-07-08.JPG | nieznany | Public domain |
| Touring by canoe - DPLA - b3bc922ccd1c557a29048a076e285111.jpg | https://commons.wikimedia.org/wiki/File:Touring_by_canoe_-_DPLA_-_b3bc922ccd1c557a29048a076e285111.jpg | Department of the Interior. U.S. Fish and Wildlife Service. National Conservation Training Center. 10/1997-8888 | Public domain |
| Tourists swimming at Victoria Falls.jpg | https://commons.wikimedia.org/wiki/File:Tourists_swimming_at_Victoria_Falls.jpg | Original uploader was Ian Restall at en.wikipedia | Public domain |
| Toyohashi City Athletic Field 2.jpg | https://commons.wikimedia.org/wiki/File:Toyohashi_City_Athletic_Field_2.jpg | Lombroso | Public domain |
| Toyokawa City Athletic Field (2017-05-28) 2.jpg | https://commons.wikimedia.org/wiki/File:Toyokawa_City_Athletic_Field_(2017-05-28)_2.jpg | Lombroso | CC0 1.0 |
| Trail Head (4857138829).jpg | https://commons.wikimedia.org/wiki/File:Trail_Head_(4857138829).jpg | USFWS Mountain-Prairie | Public domain |
| Trail Marker and Terrain (53785833007).jpg | https://commons.wikimedia.org/wiki/File:Trail_Marker_and_Terrain_(53785833007).jpg | BLMUtah | Public domain |
| Trail Running Parallel to Warm Springs Creek.jpg | https://commons.wikimedia.org/wiki/File:Trail_Running_Parallel_to_Warm_Springs_Creek.jpg | Z3lvs | CC0 1.0 |
| Trail marker (53629925123).jpg | https://commons.wikimedia.org/wiki/File:Trail_marker_(53629925123).jpg | BLMUtah | Public domain |
| Trail marker Summit Loop.jpg | https://commons.wikimedia.org/wiki/File:Trail_marker_Summit_Loop.jpg | A.-K. D. | CC0 1.0 |
| Trail marker at Howrey Island (52133715797).jpg | https://commons.wikimedia.org/wiki/File:Trail_marker_at_Howrey_Island_(52133715797).jpg | blm_mtdks | Public domain |
| Trail signboard at Sano Deurali in Shivapuri Nagarjun National Park.jpg | https://commons.wikimedia.org/wiki/File:Trail_signboard_at_Sano_Deurali_in_Shivapuri_Nagarjun_National_Park.jpg | Krishna k. sahh | CC0 1.0 |
| Transcaucasian Trail Blaze.jpg | https://commons.wikimedia.org/wiki/File:Transcaucasian_Trail_Blaze.jpg | Xiramon | CC0 1.0 |
| Treisberg, Skipiste.JPG | https://commons.wikimedia.org/wiki/File:Treisberg,_Skipiste.JPG | Karsten Ratzke | CC0 1.0 |
| Triban RC520 Decathlon road bike.jpg | https://commons.wikimedia.org/wiki/File:Triban_RC520_Decathlon_road_bike.jpg | Moklys | CC0 1.0 |
| Trilhas de Mountain Bike no Município de São Sebastião do Alto-RJ.jpg | https://commons.wikimedia.org/wiki/File:Trilhas_de_Mountain_Bike_no_Munic%C3%ADpio_de_S%C3%A3o_Sebasti%C3%A3o_do_Alto-RJ.jpg | Pedrinhoapq | CC0 1.0 |
| Tropical Smoothie Cafe - Island Green smoothie, June 2018.jpg | https://commons.wikimedia.org/wiki/File:Tropical_Smoothie_Cafe_-_Island_Green_smoothie,_June_2018.jpg | Thomson200 | CC0 1.0 |
| Tropicalsmoothie.JPG | https://commons.wikimedia.org/wiki/File:Tropicalsmoothie.JPG | Hmvb | Public domain |
| Tsaatan tent.jpg | https://commons.wikimedia.org/wiki/File:Tsaatan_tent.jpg | Bernard Gagnon | CC0 1.0 |
| Turisticka trasa Housina.jpg | https://commons.wikimedia.org/wiki/File:Turisticka_trasa_Housina.jpg | Eliška Otčenášková | CC0 1.0 |
| Turistický rozcestník, Zvánovice.jpg | https://commons.wikimedia.org/wiki/File:Turistick%C3%BD_rozcestn%C3%ADk,_Zv%C3%A1novice.jpg | Iamfromczech | CC0 1.0 |
| Two Clif Bars.jpg | https://commons.wikimedia.org/wiki/File:Two_Clif_Bars.jpg | Mx. Granger | CC0 1.0 |
| Two LUNA Bars.jpg | https://commons.wikimedia.org/wiki/File:Two_LUNA_Bars.jpg | Mx. Granger | CC0 1.0 |
| Two environmental education science teachers enjoying the canoe tour on the refuge.jpg | https://commons.wikimedia.org/wiki/File:Two_environmental_education_science_teachers_enjoying_the_canoe_tour_on_the_refuge.jpg | Hillebrand Steve, U.S. Fish and Wildlife Service | Public domain |
| Two hikers cliff top (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Two_hikers_cliff_top_(Unsplash).jpg | Austin Neill arstyy | CC0 1.0 |
| UAR 7825 (53912274421).jpg | https://commons.wikimedia.org/wiki/File:UAR_7825_(53912274421).jpg | Koldo Zuazaga | CC0 1.0 |
| UEFA Euro 2024 Match Ball Fussballliebe.jpg | https://commons.wikimedia.org/wiki/File:UEFA_Euro_2024_Match_Ball_Fussballliebe.jpg | Bildersindtoll | CC0 1.0 |
| UPO 5863 (54010340987).jpg | https://commons.wikimedia.org/wiki/File:UPO_5863_(54010340987).jpg | Koldo Zuazaga | CC0 1.0 |
| UPO 6396 (54012836343).jpg | https://commons.wikimedia.org/wiki/File:UPO_6396_(54012836343).jpg | Koldo Zuazaga | CC0 1.0 |
| US Navy 020914-N-0000X-001 Armed Forces Volleyball Championship.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_020914-N-0000X-001_Armed_Forces_Volleyball_Championship.jpg | U.S. Navy photo by Wayne Smith. | Public domain |
| US Navy 040302-N-5328N-005 Naval Junior Reserve Officers Training Corps (NJROTC) cadets from Boca Raton Community High School, Fla., stretch before a track and field event.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_040302-N-5328N-005_Naval_Junior_Reserve_Officers_Training_Corps_(NJROTC)_cadets_from_Boca_Raton_Community_High_School,_Fla.,_stretch_before_a_track_and_field_event.jpg | U.S. Navy photo by Gary Nichols. | Public domain |
| US Navy 041009-N-1810F-044 Journalist 1st Class Lizandra Murray and Chief Petty Officer Mark Rinschler, both assigned to Naval Media Center, Washington, D.C., maneuver their Duckie whitewater boat down rapids.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_041009-N-1810F-044_Journalist_1st_Class_Lizandra_Murray_and_Chief_Petty_Officer_Mark_Rinschler,_both_assigned_to_Naval_Media_Center,_Washington,_D.C.,_maneuver_their_Duckie_whitewater_boat_down_rapids.jpg | U.S. Navy photo by Photographer's Mate 3rd Class Todd Frantom | Public domain |
| US Navy 041009-N-1810F-048 Two Sailors assigned to Naval Submarine Torpedo Facility, Yorktown, Va., maneuver their Duckie whitewater boat down rapids.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_041009-N-1810F-048_Two_Sailors_assigned_to_Naval_Submarine_Torpedo_Facility,_Yorktown,_Va.,_maneuver_their_Duckie_whitewater_boat_down_rapids.jpg | U.S. Navy photo by Photographer's Mate 3rd Class Todd Frantom | Public domain |
| US Navy 061031-N-5758H-260 Sailors participate in the 800-meter buddy tow swim during the rescue swimmer competition for Surface Line Week 2006.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_061031-N-5758H-260_Sailors_participate_in_the_800-meter_buddy_tow_swim_during_the_rescue_swimmer_competition_for_Surface_Line_Week_2006.jpg | U.S. Navy photo by Mass Communication Specialist Seamen Kenneth R. Hendrix | Public domain |
| US Navy 070227-N-0998G-003 Operations Specialist 3rd Class Greg Ivy, a native of Sacramento, Calif., incline presses two 30-pound dumbbells as Quartermaster Seaman Julian Marulanda, a native of Bridgeport, Conn., acts as spotte.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_070227-N-0998G-003_Operations_Specialist_3rd_Class_Greg_Ivy,_a_native_of_Sacramento,_Calif.,_incline_presses_two_30-pound_dumbbells_as_Quartermaster_Seaman_Julian_Marulanda,_a_native_of_Bridgeport,_Conn.,_acts_as_spotte.jpg | U.S. Navy photo by Mass Communication Specialist Seaman Jeffery J. Gabriel Jr. | Public domain |
| US Navy 070525-N-1251W-001 U.S. Navy Sailors compete against Japan Maritime Self Defense Force (JMSDF) Sailors during the 20th annual USN-JMSDF Olympics.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_070525-N-1251W-001_U.S._Navy_Sailors_compete_against_Japan_Maritime_Self_Defense_Force_(JMSDF)_Sailors_during_the_20th_annual_USN-JMSDF_Olympics.jpg | U.S. Navy photo by Mass Communication Specialist Seaman Matthew R. White | Public domain |
| US Navy 080112-N-8933S-219 Lt. Cmdr. Thomas C. Kait, executive officer of the dock landing ship USS Fort McHenry (LSD 43), spikes during a volleyball game against the Vieille Gloire (Old Glory) team a local Gabonese volleyball.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_080112-N-8933S-219_Lt._Cmdr._Thomas_C._Kait,_executive_officer_of_the_dock_landing_ship_USS_Fort_McHenry_(LSD_43),_spikes_during_a_volleyball_game_against_the_Vieille_Gloire_(Old_Glory)_team_a_local_Gabonese_volleyball.jpg | U.S. Navy photo by Mass Communication Specialist 2nd Class RJ Stratchko | Public domain |
| US Navy 091018-N-7881L-350 A Sailor assigned to the amphibious assault ship USS Bonhomme Richard (LHD 6) and also on the ship's soccer team plays soccer with a member of the Under-Seventeen Timor-Leste National Soccer Team at D.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_091018-N-7881L-350_A_Sailor_assigned_to_the_amphibious_assault_ship_USS_Bonhomme_Richard_(LHD_6)_and_also_on_the_ship%27s_soccer_team_plays_soccer_with_a_member_of_the_Under-Seventeen_Timor-Leste_National_Soccer_Team_at_D.jpg | U.S. Navy photo by Mass Communication Specialist Seaman Apprentice Wyatt Lehman | Public domain |
| US Navy 100306-N-5319A-035 Ten-year-old Joseph Camano performs stretching exercises before a routine physical therapy session with Occupational Therapist Judy Anderson at the Diane Epplein ^ Assoc. Pediatric Therapy facility.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100306-N-5319A-035_Ten-year-old_Joseph_Camano_performs_stretching_exercises_before_a_routine_physical_therapy_session_with_Occupational_Therapist_Judy_Anderson_at_the_Diane_Epplein_%5E_Assoc._Pediatric_Therapy_facility.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class Brien Aho | Public domain |
| US Navy 100511-N-6932B-001 Navy competitors swim warm-up laps while practicing for upcoming swimming competitions at the inaugural Warrior Games in Colorado Springs, Colo.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100511-N-6932B-001_Navy_competitors_swim_warm-up_laps_while_practicing_for_upcoming_swimming_competitions_at_the_inaugural_Warrior_Games_in_Colorado_Springs,_Colo.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class R. Jason Brunson | Public domain |
| US Navy 100511-N-6932B-111 Lt. Melanie Monts de Oca, a Navy competitor, dives off a platform while practicing for her upcoming swimming competitions.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100511-N-6932B-111_Lt._Melanie_Monts_de_Oca,_a_Navy_competitor,_dives_off_a_platform_while_practicing_for_her_upcoming_swimming_competitions.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class R. Jason Brunson | Public domain |
| US Navy 100512-N-6932B-560 Navy competitor Lt. Melanie Monts de Oca pulls ahead of the competition during a preliminary swimming event at the inaugural Warrior Games.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100512-N-6932B-560_Navy_competitor_Lt._Melanie_Monts_de_Oca_pulls_ahead_of_the_competition_during_a_preliminary_swimming_event_at_the_inaugural_Warrior_Games.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class R. Jason Brunson | Public domain |
| US Navy 100611-N-0995C-160 Ship's Serviceman Seaman Chika Ndubizu assigned to the guided-missle frigate USS Vandegrift (FFG 48) works to keep possession of the ball.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100611-N-0995C-160_Ship%27s_Serviceman_Seaman_Chika_Ndubizu_assigned_to_the_guided-missle_frigate_USS_Vandegrift_(FFG_48)_works_to_keep_possession_of_the_ball.jpg | U.S. Navy photo by Mass Communication Specialist 2nd Class Eric J. Cutright | Public domain |
| US Navy 100913-N-3215T-149 American professional iron man athlete Karl Gillingham spots Hospital Corpsman 2nd Class Ardinis Strickland.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_100913-N-3215T-149_American_professional_iron_man_athlete_Karl_Gillingham_spots_Hospital_Corpsman_2nd_Class_Ardinis_Strickland.jpg | U.S. Navy photo by Mass Communication Specialist 1st Class Brock A. Taylor | Public domain |
| US Navy 101103-N-5503T-039 Certified yoga instructor Katherine Alfredo leads Sailors and Marines in a yoga class in the forecastle of the aircraft.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_101103-N-5503T-039_Certified_yoga_instructor_Katherine_Alfredo_leads_Sailors_and_Marines_in_a_yoga_class_in_the_forecastle_of_the_aircraft.jpg | U.S. Navy photo by Mass Communication Specialist 3rd Class Alexander Tidd | Public domain |
| US Navy 101122-N-7948R-111 Chief Warrant Officer Jose Martinez performs dumbbell chest presses in the gymnasium aboard the amphibious dock landing.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_101122-N-7948R-111_Chief_Warrant_Officer_Jose_Martinez_performs_dumbbell_chest_presses_in_the_gymnasium_aboard_the_amphibious_dock_landing.jpg | U.S. Navy photo by Mass Communication Specialist 2nd Class Michael Russell | Public domain |
| US Navy 120213-N-ZT599-068 Chieko Koymama, a yoga instructor with morale, welfare and recreation conducts a yoga class in the hangar bay aboard the.jpg | https://commons.wikimedia.org/wiki/File:US_Navy_120213-N-ZT599-068_Chieko_Koymama,_a_yoga_instructor_with_morale,_welfare_and_recreation_conducts_a_yoga_class_in_the_hangar_bay_aboard_the.jpg | U.S. Navy Photo by Mass Communication Specialist Seaman Recruit Brian Abel | Public domain |
| US swimmer Susan Rapp.JPEG | https://commons.wikimedia.org/wiki/File:US_swimmer_Susan_Rapp.JPEG | Don Sutherland, U.S. Air Force | Public domain |
| US, Kenya unite for football match, strengthen partnership (8910110).jpg | https://commons.wikimedia.org/wiki/File:US,_Kenya_unite_for_football_match,_strengthen_partnership_(8910110).jpg | U.S. Air Force photo by Tech. Sgt. Jana Somero | Public domain |
| US-Azerbaijian basketball game DVIDS395414.jpg | https://commons.wikimedia.org/wiki/File:US-Azerbaijian_basketball_game_DVIDS395414.jpg | Staff Sgt. Stacy Moless | Public domain |
| USFWS kayak siletz (23554916850).jpg | https://commons.wikimedia.org/wiki/File:USFWS_kayak_siletz_(23554916850).jpg | USFWS - Pacific Region | Public domain |
| USMC-100507-M-4150N-310.jpg | https://commons.wikimedia.org/wiki/File:USMC-100507-M-4150N-310.jpg | Lance Cpl. M. C. Nerl | Public domain |
| USMC-110929-M-FY706-001.jpg | https://commons.wikimedia.org/wiki/File:USMC-110929-M-FY706-001.jpg | Lance Cpl. Mondo Lescaud | Public domain |
| USMC-110929-M-FY706-002.jpg | https://commons.wikimedia.org/wiki/File:USMC-110929-M-FY706-002.jpg | Lance Cpl. Mondo Lescaud | Public domain |
| USMC-111007-M-UV915-234.jpg | https://commons.wikimedia.org/wiki/File:USMC-111007-M-UV915-234.jpg | nieznany | Public domain |
| USMC-120113-M-IY869-001.jpg | https://commons.wikimedia.org/wiki/File:USMC-120113-M-IY869-001.jpg | nieznany | Public domain |
| USMC-120113-M-IY869-005.jpg | https://commons.wikimedia.org/wiki/File:USMC-120113-M-IY869-005.jpg | nieznany | Public domain |
| USMC-120616-M-YE622-013.jpg | https://commons.wikimedia.org/wiki/File:USMC-120616-M-YE622-013.jpg | Lance Cpl. Benjamin Pryer | Public domain |
| USMC-120616-M-YE622-088.jpg | https://commons.wikimedia.org/wiki/File:USMC-120616-M-YE622-088.jpg | Lance Cpl. Benjamin Pryer | Public domain |
| USMC-120821-M-LD192-003.jpg | https://commons.wikimedia.org/wiki/File:USMC-120821-M-LD192-003.jpg | Lance Cpl. Sarah Wolff-Diaz | Public domain |
| USS Peleliu 121207-M-YG378-049.jpg | https://commons.wikimedia.org/wiki/File:USS_Peleliu_121207-M-YG378-049.jpg | Cpl. John Robbart III | Public domain |
| USS Theodore Roosevelt operations 150920-N-CH038-050.jpg | https://commons.wikimedia.org/wiki/File:USS_Theodore_Roosevelt_operations_150920-N-CH038-050.jpg | Sept. 20, 2015 | Public domain |
| UTRGV @ Air Force soccer (37406455546).jpg | https://commons.wikimedia.org/wiki/File:UTRGV_@_Air_Force_soccer_(37406455546).jpg | United States Air Force Academy | Public domain |
| Underneath Basketball Hoop.JPG | https://commons.wikimedia.org/wiki/File:Underneath_Basketball_Hoop.JPG | Owen Yin | CC0 1.0 |
| V 20231228 Cedar Mountain Wilderness Hastings Cutoff sign (53437168752).jpg | https://commons.wikimedia.org/wiki/File:V_20231228_Cedar_Mountain_Wilderness_Hastings_Cutoff_sign_(53437168752).jpg | BLMUtah | Public domain |
| V26 Cold Weather Casualty Evacuation (8793091).jpg | https://commons.wikimedia.org/wiki/File:V26_Cold_Weather_Casualty_Evacuation_(8793091).jpg | U.S. Marine Corps photo by Lance Cpl. Adam Trump | Public domain |
| VOL 3469 (54637120360).jpg | https://commons.wikimedia.org/wiki/File:VOL_3469_(54637120360).jpg | Koldo Zuazaga | CC0 1.0 |
| Varzob, Jasmin swimming pool.jpg | https://commons.wikimedia.org/wiki/File:Varzob,_Jasmin_swimming_pool.jpg | Nikolamikovic82 | CC0 1.0 |
| Vella 25 2.jpg | https://commons.wikimedia.org/wiki/File:Vella_25_2.jpg | Thomasox58 | CC0 1.0 |
| Veslepiggen.jpg | https://commons.wikimedia.org/wiki/File:Veslepiggen.jpg | Sondrekv | Public domain |
| View of the outdoor swimming pool at De Warande, Wetteren, 2024.jpg | https://commons.wikimedia.org/wiki/File:View_of_the_outdoor_swimming_pool_at_De_Warande,_Wetteren,_2024.jpg | DimiTalen | CC0 1.0 |
| Views along the Mary Mountain Trail- hiking to the east (55367555925).jpg | https://commons.wikimedia.org/wiki/File:Views_along_the_Mary_Mountain_Trail-_hiking_to_the_east_(55367555925).jpg | YellowstoneNPS | Public domain |
| Visitor hiking Lake View Trail near Katherine Landing (15c577da-cce7-4057-9c85-9b077a6f2358).jpg | https://commons.wikimedia.org/wiki/File:Visitor_hiking_Lake_View_Trail_near_Katherine_Landing_(15c577da-cce7-4057-9c85-9b077a6f2358).jpg | Andrew Cattoir (Lake Mead National Recreation Area) | Public domain |
| Visitor wildflowers mountain backpacking backcountry summer alpine NPS photo (17184221860).jpg | https://commons.wikimedia.org/wiki/File:Visitor_wildflowers_mountain_backpacking_backcountry_summer_alpine_NPS_photo_(17184221860).jpg | Olympic National Park | Public domain |
| Vivek Shahmrugasana.jpg | https://commons.wikimedia.org/wiki/File:Vivek_Shahmrugasana.jpg | LYUvivekkarangiya | CC0 1.0 |
| Vivek dhanurasan.jpg | https://commons.wikimedia.org/wiki/File:Vivek_dhanurasan.jpg | LYUvivekkarangiya | CC0 1.0 |
| Viviane Hara Desafio Rural MTB (5) (52775915893).jpg | https://commons.wikimedia.org/wiki/File:Viviane_Hara_Desafio_Rural_MTB_(5)_(52775915893).jpg | ASCOM Prefeitura de Votuporanga | Public domain |
| Volleyball game Nuevo Laredo.jpg | https://commons.wikimedia.org/wiki/File:Volleyball_game_Nuevo_Laredo.jpg | Miguel Angel Omaña Rojas | CC0 1.0 |
| Vtt raid edhec.JPG | https://commons.wikimedia.org/wiki/File:Vtt_raid_edhec.JPG | Loutreraideuse | CC0 1.0 |
| Vzw Durme guided morning hike along Broekse Vaart, Berlare, 2014.jpg | https://commons.wikimedia.org/wiki/File:Vzw_Durme_guided_morning_hike_along_Broekse_Vaart,_Berlare,_2014.jpg | DimiTalen | CC0 1.0 |
| WPAFB Hosts 2016 Air Force Marathon 160917-F-AV193-1080.jpg | https://commons.wikimedia.org/wiki/File:WPAFB_Hosts_2016_Air_Force_Marathon_160917-F-AV193-1080.jpg | Wesley Farnsworth | Public domain |
| Wackernheim - Mehrere Wanderwegmarkierungen am Neuweg.jpg | https://commons.wikimedia.org/wiki/File:Wackernheim_-_Mehrere_Wanderwegmarkierungen_am_Neuweg.jpg | ManuelB701 | CC0 1.0 |
| Wakiso Secondary schools soccer competitions.jpg | https://commons.wikimedia.org/wiki/File:Wakiso_Secondary_schools_soccer_competitions.jpg | Samson Ssemakadde | CC0 1.0 |
| Wakiso Senior schools soccer competitions.jpg | https://commons.wikimedia.org/wiki/File:Wakiso_Senior_schools_soccer_competitions.jpg | Samson Ssemakadde | CC0 1.0 |
| Walking and Cycling River Mountains Loop Trail (8f60626f-7e8c-4ed2-8851-0387903a1600).jpg | https://commons.wikimedia.org/wiki/File:Walking_and_Cycling_River_Mountains_Loop_Trail_(8f60626f-7e8c-4ed2-8851-0387903a1600).jpg | Andrew Cattoir (Lake Mead National Recreation Area) | Public domain |
| Wanderwegweiser fingerpost am Felsenkeller Bielatal.jpg | https://commons.wikimedia.org/wiki/File:Wanderwegweiser_fingerpost_am_Felsenkeller_Bielatal.jpg | Peter Maiwald | CC0 1.0 |
| Wanderwegzeichen Gnalp – WAF 300 J FL.jpg | https://commons.wikimedia.org/wiki/File:Wanderwegzeichen_Gnalp_%E2%80%93_WAF_300_J_FL.jpg | albinfo | CC0 1.0 |
| Warfit Run (2685974).jpg | https://commons.wikimedia.org/wiki/File:Warfit_Run_(2685974).jpg | U.S. Space Force 50SW by Christopher Dewitt | Public domain |
| Warrior Games bicycle racing 140929-D-DB155-014.jpg | https://commons.wikimedia.org/wiki/File:Warrior_Games_bicycle_racing_140929-D-DB155-014.jpg | EJ Hersom | Public domain |
| Warriors posed to break Yoga record at Al Udeid Air Base 150711-F-BN304-040.jpg | https://commons.wikimedia.org/wiki/File:Warriors_posed_to_break_Yoga_record_at_Al_Udeid_Air_Base_150711-F-BN304-040.jpg | Staff Sgt. Alex Montes | Public domain |
| Warriors posed to break Yoga record at Al Udeid Air Base 150711-F-BN304-189.jpg | https://commons.wikimedia.org/wiki/File:Warriors_posed_to_break_Yoga_record_at_Al_Udeid_Air_Base_150711-F-BN304-189.jpg | Staff Sgt. Alex Montes | Public domain |
| Watch me lift (5048014).jpg | https://commons.wikimedia.org/wiki/File:Watch_me_lift_(5048014).jpg | U.S. Air Force photo by Airman 1st Class Jovante Johnson | Public domain |
| Watmough Bay Kayak (15517582517).jpg | https://commons.wikimedia.org/wiki/File:Watmough_Bay_Kayak_(15517582517).jpg | Bureau of Land Management Oregon and Washington from Portland, America | Public domain |
| Waymark post on the path between Poynings and Devil's Dyke hillfort 2025-08-16.jpg | https://commons.wikimedia.org/wiki/File:Waymark_post_on_the_path_between_Poynings_and_Devil%27s_Dyke_hillfort_2025-08-16.jpg | Andy Li | CC0 1.0 |
| Weathered outdoor basketball hoop in a public park.jpg | https://commons.wikimedia.org/wiki/File:Weathered_outdoor_basketball_hoop_in_a_public_park.jpg | JaredMcKenzie | CC0 1.0 |
| Wegweiser Wuhletal-Wanderweg Großer Ahrensfelder Berg Berlin-Marzahn 02.jpg | https://commons.wikimedia.org/wiki/File:Wegweiser_Wuhletal-Wanderweg_Gro%C3%9Fer_Ahrensfelder_Berg_Berlin-Marzahn_02.jpg | Singlespeedfahrer | CC0 1.0 |
| Wegweiser fingerpost Malá Pravčická Brána.jpg | https://commons.wikimedia.org/wiki/File:Wegweiser_fingerpost_Mal%C3%A1_Prav%C4%8Dick%C3%A1_Br%C3%A1na.jpg | Peter Maiwald | CC0 1.0 |
| Wegweiser fingerpost Pod Šaunštejnem.jpg | https://commons.wikimedia.org/wiki/File:Wegweiser_fingerpost_Pod_%C5%A0aun%C5%A1tejnem.jpg | Peter Maiwald | CC0 1.0 |
| Westerdam fitness center.JPG | https://commons.wikimedia.org/wiki/File:Westerdam_fitness_center.JPG | Barek | Public domain |
| Westhighlandway.jpg | https://commons.wikimedia.org/wiki/File:Westhighlandway.jpg | self | Public domain |
| Wheelchair Basketball Game 141008-F-EI321-292.jpg | https://commons.wikimedia.org/wiki/File:Wheelchair_Basketball_Game_141008-F-EI321-292.jpg | Alex Lloyd | Public domain |
| Wheelchair basketball game 141008-F-EI321-342.jpg | https://commons.wikimedia.org/wiki/File:Wheelchair_basketball_game_141008-F-EI321-342.jpg | Alex Lloyd | Public domain |
| White and yellow camping tent in the middle of forest.jpg | https://commons.wikimedia.org/wiki/File:White_and_yellow_camping_tent_in_the_middle_of_forest.jpg | Andrew Scofield | CC0 1.0 |
| White arrow on blue circle trail marker, Hammond Woods, Charlton MA 2025-12-04.jpg | https://commons.wikimedia.org/wiki/File:White_arrow_on_blue_circle_trail_marker,_Hammond_Woods,_Charlton_MA_2025-12-04.jpg | Peter Cooper Jr. | CC0 1.0 |
| White trail marker, Opacum Woods 2025-09-13.jpg | https://commons.wikimedia.org/wiki/File:White_trail_marker,_Opacum_Woods_2025-09-13.jpg | Peter Cooper Jr. | CC0 1.0 |
| Whitewater kayaker classIV.jpg | https://commons.wikimedia.org/wiki/File:Whitewater_kayaker_classIV.jpg | nieznany | Public domain |
| Whitmire vs Mid-Carolina High School Football Game Sept. 4, 2026.jpg | https://commons.wikimedia.org/wiki/File:Whitmire_vs_Mid-Carolina_High_School_Football_Game_Sept._4,_2026.jpg | Thomas Kirchel | CC0 1.0 |
| Wiki for Senior Citizens Network Photowalk 20.jpg | https://commons.wikimedia.org/wiki/File:Wiki_for_Senior_Citizens_Network_Photowalk_20.jpg | Onyinyeonuoha | CC0 1.0 |
| Wiki for Senior Citizens Network Photowalk 28.jpg | https://commons.wikimedia.org/wiki/File:Wiki_for_Senior_Citizens_Network_Photowalk_28.jpg | Onyinyeonuoha | CC0 1.0 |
| Winter backcountry camping tent snow NPS Photo (22982845151).jpg | https://commons.wikimedia.org/wiki/File:Winter_backcountry_camping_tent_snow_NPS_Photo_(22982845151).jpg | Olympic National Park | Public domain |
| Winthrop basketball huddle.jpg | https://commons.wikimedia.org/wiki/File:Winthrop_basketball_huddle.jpg | HechoMan2 | CC0 1.0 |
| Woman walks through wood doors (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Woman_walks_through_wood_doors_(Unsplash).jpg | Dan Gold danielcgold | CC0 1.0 |
| Woman yoga pose sunrise (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Woman_yoga_pose_sunrise_(Unsplash).jpg | Andressa Voltolini desnorteeu | CC0 1.0 |
| Woman-731887.jpg | https://commons.wikimedia.org/wiki/File:Woman-731887.jpg | stokpic | CC0 1.0 |
| Womens pink running shoes (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Womens_pink_running_shoes_(Unsplash).jpg | Hunter Johnson hjohnson02 | CC0 1.0 |
| Wooden canoe sharbot lake ontario.jpg | https://commons.wikimedia.org/wiki/File:Wooden_canoe_sharbot_lake_ontario.jpg | nieznany | Public domain |
| Worn snow-dusted red square Woodland Walk trail marker, Capen Hill.jpg | https://commons.wikimedia.org/wiki/File:Worn_snow-dusted_red_square_Woodland_Walk_trail_marker,_Capen_Hill.jpg | Peter Cooper Jr. | CC0 1.0 |
| Wounded Warrior training camp begins 150418-F-OC707-012.jpg | https://commons.wikimedia.org/wiki/File:Wounded_Warrior_training_camp_begins_150418-F-OC707-012.jpg | Samuel King Jr. | Public domain |
| YOGA Kolkata.jpg | https://commons.wikimedia.org/wiki/File:YOGA_Kolkata.jpg | Susovan Chakraborty5 | CC0 1.0 |
| Yellowstone Shortline Trail marker 3W6A3962 (53057542070).jpg | https://commons.wikimedia.org/wiki/File:Yellowstone_Shortline_Trail_marker_3W6A3962_(53057542070).jpg | Forest Service - Northern Region | Public domain |
| Yoga - Desolation Canyon (30847432286).jpg | https://commons.wikimedia.org/wiki/File:Yoga_-_Desolation_Canyon_(30847432286).jpg | BLMUtah | Public domain |
| Yoga CDMX DSC0821 Yoga (35123726656).jpg | https://commons.wikimedia.org/wiki/File:Yoga_CDMX_DSC0821_Yoga_(35123726656).jpg | Gobierno CDMX | CC0 1.0 |
| Yoga CDMX DSC0844 (35032895131).jpg | https://commons.wikimedia.org/wiki/File:Yoga_CDMX_DSC0844_(35032895131).jpg | Gobierno CDMX | CC0 1.0 |
| Yoga CDMX DSC0846 (35163662125).jpg | https://commons.wikimedia.org/wiki/File:Yoga_CDMX_DSC0846_(35163662125).jpg | Gobierno CDMX | CC0 1.0 |
| Yoga Class on NAS Sigonella (7128207).jpg | https://commons.wikimedia.org/wiki/File:Yoga_Class_on_NAS_Sigonella_(7128207).jpg | U.S. Navy photo by Megan Mills | Public domain |
| Yoga Class on NAS Sigonella (7128208).jpg | https://commons.wikimedia.org/wiki/File:Yoga_Class_on_NAS_Sigonella_(7128208).jpg | U.S. Navy photo by Megan Mills | Public domain |
| Yoga at Your Park (1fe0c5d7-4c1d-48c5-a121-fddc1b3961de).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(1fe0c5d7-4c1d-48c5-a121-fddc1b3961de).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (769083a3-0735-4ad9-8248-b6aaf9b95670).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(769083a3-0735-4ad9-8248-b6aaf9b95670).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (797ca5c6-3681-4379-a207-dd7c03bb0af0).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(797ca5c6-3681-4379-a207-dd7c03bb0af0).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (7f05c684-0a86-4683-84e3-b63d885ba73e).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(7f05c684-0a86-4683-84e3-b63d885ba73e).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (98f6457f-c326-4bee-bfd6-11044dc228f3).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(98f6457f-c326-4bee-bfd6-11044dc228f3).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (9c742c09-7237-43b7-be32-6805ae040638).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(9c742c09-7237-43b7-be32-6805ae040638).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park (f7f2960d-c205-496f-bf97-77f884b43779).jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_(f7f2960d-c205-496f-bf97-77f884b43779).jpg | Mary O'Neill | Public domain |
| Yoga at Your Park - Bidalasana.jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_-_Bidalasana.jpg | Mary O'Neill | Public domain |
| Yoga at Your Park - Bitilasana.jpg | https://commons.wikimedia.org/wiki/File:Yoga_at_Your_Park_-_Bitilasana.jpg | Mary O'Neill | Public domain |
| Yoga class 140321-N-XS649-040.jpg | https://commons.wikimedia.org/wiki/File:Yoga_class_140321-N-XS649-040.jpg | U.S. Navy photo by Mass Communication Specialist 3rd Class Justin Peeler/Released | Public domain |
| Yoga in the beach (Unsplash).jpg | https://commons.wikimedia.org/wiki/File:Yoga_in_the_beach_(Unsplash).jpg | bady qb bady | CC0 1.0 |
| Yoga under the banyan tree, GTMO residents realize the benefits of an age-old practice in beautiful Cuba 140326-A-ZI015-084.jpg | https://commons.wikimedia.org/wiki/File:Yoga_under_the_banyan_tree,_GTMO_residents_realize_the_benefits_of_an_age-old_practice_in_beautiful_Cuba_140326-A-ZI015-084.jpg | Sgt. Kenneth Tucceri | Public domain |
| Yoga with Apoorva.jpg | https://commons.wikimedia.org/wiki/File:Yoga_with_Apoorva.jpg | Punith Tech | CC0 1.0 |
| Yoga, A quiet escape for healthcare professionals 140624-F-LX971-020.jpg | https://commons.wikimedia.org/wiki/File:Yoga,_A_quiet_escape_for_healthcare_professionals_140624-F-LX971-020.jpg | Master Sgt. Cohen Young | Public domain |
| Yoga4Love Freedom Gratitude.jpg | https://commons.wikimedia.org/wiki/File:Yoga4Love_Freedom_Gratitude.jpg | Yoga4love | Public domain |
| Yokosuka activity DVIDS363058.jpg | https://commons.wikimedia.org/wiki/File:Yokosuka_activity_DVIDS363058.jpg | Seaman Charles Oki | Public domain |
| Youth Rider on the Trail (51142362410).jpg | https://commons.wikimedia.org/wiki/File:Youth_Rider_on_the_Trail_(51142362410).jpg | BLMUtah | Public domain |
| Youth Riders on the Trail (51140585472).jpg | https://commons.wikimedia.org/wiki/File:Youth_Riders_on_the_Trail_(51140585472).jpg | BLMUtah | Public domain |
| Youth Riders on the Trail (51141262161).jpg | https://commons.wikimedia.org/wiki/File:Youth_Riders_on_the_Trail_(51141262161).jpg | BLMUtah | Public domain |
| Youth-soccer-indiana.jpg | https://commons.wikimedia.org/wiki/File:Youth-soccer-indiana.jpg | Tysto | Public domain |
| Зеленогорск Прибой 51 км. На 2м км..JPG | https://commons.wikimedia.org/wiki/File:%D0%97%D0%B5%D0%BB%D0%B5%D0%BD%D0%BE%D0%B3%D0%BE%D1%80%D1%81%D0%BA_%D0%9F%D1%80%D0%B8%D0%B1%D0%BE%D0%B9_51_%D0%BA%D0%BC._%D0%9D%D0%B0_2%D0%BC_%D0%BA%D0%BC..JPG | Пётр Иванов | CC0 1.0 |
| Поляна для отдыха (98353).jpg | https://commons.wikimedia.org/wiki/File:%D0%9F%D0%BE%D0%BB%D1%8F%D0%BD%D0%B0_%D0%B4%D0%BB%D1%8F_%D0%BE%D1%82%D0%B4%D1%8B%D1%85%D0%B0_(98353).jpg | AdAstraPerVitae | CC0 1.0 |
| תמונה 2.JPG | https://commons.wikimedia.org/wiki/File:%D7%AA%D7%9E%D7%95%D7%A0%D7%94_2.JPG | Ilyamarcus | Public domain |
| “Hike to Kriváň” within High Tatras, Slovakia (8042135).jpg | https://commons.wikimedia.org/wiki/File:%E2%80%9CHike_to_Kriv%C3%A1%C5%88%E2%80%9D_within_High_Tatras,_Slovakia_(8042135).jpg | U.S. Army 343MPAD by Master Sgt. Matthew Siemion | Public domain |
