| fraza (trafień w katalogu / w dziale) | sort | obecne przed PhraseSearch: ms / bufory | PhraseSearch razem: ms / bufory | kroki (ms / bufory) | te same wiersze |
|---|---|---|---|---|---|
| `ravotar` (2980 / 0) | name | 19.1 / 15777 | 25.6 / 32287 | L0 walk B=20000: 11.9 / 17753; L0 candidates cap=10000: 13.7 / 14534 | tak |
| `ravotar` (2980 / 0) | -updatedAt | 18.3 / 15777 | 34.3 / 35152 | L0 walk B=20000: 21.7 / 20618; L0 candidates cap=10000: 12.6 / 14534 | tak |
| `snowboardowe` (12952 / 0) | name | 2119.1 / 1015752 | 109.9 / 53096 | L0 walk B=20000: 12.1 / 17753; L0 candidates cap=10000: 97.8 / 35343 | tak |
| `snowboardowe` (12952 / 0) | -updatedAt | 1009.5 / 1005934 | 143.7 / 57315 | L0 walk B=20000: 25.4 / 21972; L0 candidates cap=10000: 118.3 / 35343 | tak |
| `rowerowa` (60168 / 0) | name | 2318.1 / 1015752 | 394.4 / 94747 | L0 walk B=20000: 12.3 / 17753; L0 candidates cap=10000: 382.1 / 76994 | tak |
| `rowerowa` (60168 / 0) | -updatedAt | 996.1 / 1005934 | 348.5 / 106496 | L0 walk B=20000: 30.6 / 29502; L0 candidates cap=10000: 317.8 / 76994 | tak |
| `zupa` (8584 / 8584) | name | 18.2 / 9170 | 33.4 / 26923 | L0 walk B=20000: 12.3 / 17753; L0 candidates cap=10000: 21.2 / 9170 | tak |
| `zupa` (8584 / 8584) | -updatedAt | 16.6 / 9170 | 4.8 / 3459 | L0 walk B=20000: 4.8 / 3459 | tak |

Ekstrapolacja do `server` (SZACUNEK, koszt kandydatów liniowy w trafieniach katalogu, 10x): `snowboardowe` ok. 1,1 s (name) i 1,4 s (-updatedAt), mieści się w 2000 ms; `rowerowa` (60 168 trafień w katalogu, 0 w dziale) 394 ms (name) i 349 ms (-updatedAt) na `medium`, czyli ok. 3,9 s i 3,5 s na `server`, łamie P2. To klasa zasady 5 (rzadkie przecięcie: fraza bardzo częsta w katalogu, nieobecna w dziale); decyzja właściciela i pomiar na `server` w PERF-7-7.
