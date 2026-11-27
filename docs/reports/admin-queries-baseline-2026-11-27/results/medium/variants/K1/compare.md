| przypadek K1 | zmienna kosztu (medium → server) | bufory medium → server | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms | P2 pamięć / dysk | obecne: mediana medium ms → server w pamięci ms |
|---|---|---|---|---|---|---|---|
| K1.small.cat.p1.name | products in the subtree 15 → 150 | 1509 → 15087 | 8.3 / 6.9 / 8.3 | 69 | 69 | ok / ok | 3067.9 → 30674 |
| K1.small.cat.p1.-updatedAt | products in the subtree 15 → 150 | 1510 → 15097 | 6.9 / 6.9 / 7.4 | 69 | 69 | ok / ok | 894.4 → 8942 |
| K1.small.cat.count | products in the subtree 15 → 150 | 1485 → 14847 | 6.8 / 6.8 / 6.9 | 68 | 68 | ok / ok | 435.4 → 4354 |
| K1.small.cat+brand.p1.name | products in the subtree 15 → 150 | 1509 → 15087 | 6.8 / 6.9 / 7.1 | 69 | 69 | ok / ok | - |
| K1.small.cat+brand.p1.-updatedAt | products in the subtree 15 → 150 | 1510 → 15097 | 6.9 / 6.8 / 7.0 | 68 | 68 | ok / ok | - |
| K1.small.cat+brand.count | products in the subtree 15 → 150 | 1487 → 14867 | 6.8 / 6.9 / 7.2 | 69 | 69 | ok / ok | - |
| K1.small.cat+drafts.p1.name | products in the subtree 15 → 150 | 1632 → 16317 | 9.7 / 9.4 / 9.7 | 94 | 94 | ok / ok | - |
| K1.small.cat+drafts.p1.-updatedAt | products in the subtree 15 → 150 | 1632 → 16317 | 9.4 / 9.4 / 10.0 | 94 | 94 | ok / ok | - |
| K1.small.cat+drafts.count | products in the subtree 15 → 150 | 1632 → 16317 | 9.1 / 9.3 / 9.5 | 93 | 93 | ok / ok | - |
| K1.small.cat+noimages.p1.name | products in the subtree 15 → 150 | 1483 → 14827 | 7.4 / 6.9 / 7.4 | 69 | 69 | ok / ok | - |
| K1.small.cat+noimages.p1.-updatedAt | products in the subtree 15 → 150 | 1490 → 14897 | 6.8 / 6.9 / 7.0 | 69 | 69 | ok / ok | - |
| K1.small.cat+noimages.count | products in the subtree 15 → 150 | 1483 → 14827 | 6.6 / 6.8 / 6.9 | 68 | 68 | ok / ok | - |
| K1.mid.cat.p1.name | products in the subtree 10585 → 105832 | 8657 → 86555 | 13.7 / 8.3 / 13.7 | 83 | 35169 | ok / NIE | 286.2 → 2862 |
| K1.mid.cat.p2.name | products in the subtree 10585 → 105832 | 8657 → 86555 | 8.5 / 8.3 / 8.5 | 83 | 35168 | ok / NIE | 6.1 → 61 |
| K1.mid.cat.p1.-updatedAt | products in the subtree 10585 → 105832 | 8676 → 86745 | 8.2 / 8.3 / 9.0 | 83 | 35263 | ok / NIE | 4.6 → 46 |
| K1.mid.cat.p2.-updatedAt | products in the subtree 10585 → 105832 | 8675 → 86735 | 10.0 / 8.3 / 10.0 | 83 | 35258 | ok / NIE | 4.4 → 44 |
| K1.mid.cat.count | products in the subtree 10585 → 105832 | 8866 → 88645 | 9.9 / 9.1 / 9.9 | 91 | 36221 | ok / NIE | 504.5 → 5044 |
| K1.mid.cat+brand.p1.name | products in the subtree 10585 → 105832 | 8661 → 86595 | 8.5 / 8.6 / 9.1 | 86 | 35191 | ok / NIE | - |
| K1.mid.cat+brand.p1.-updatedAt | products in the subtree 10585 → 105832 | 8864 → 88625 | 8.4 / 8.6 / 9.3 | 86 | 36207 | ok / NIE | - |
| K1.mid.cat+brand.count | products in the subtree 10585 → 105832 | 16185 → 161822 | 14.5 / 14.7 / 15.3 | 147 | 72867 | ok / NIE | - |
| K1.mid.cat+drafts.p1.name | products in the subtree 10585 → 105832 | 8987 → 89855 | 12.7 / 12.5 / 15.0 | 125 | 36860 | ok / NIE | - |
| K1.mid.cat+drafts.p1.-updatedAt | products in the subtree 10585 → 105832 | 8987 → 89855 | 12.1 / 12.4 / 12.9 | 124 | 36859 | ok / NIE | - |
| K1.mid.cat+drafts.count | products in the subtree 10585 → 105832 | 8971 → 89695 | 13.3 / 12.1 / 13.3 | 121 | 36776 | ok / NIE | - |
| K1.mid.cat+noimages.p1.name | products in the subtree 10585 → 105832 | 7014 → 70128 | 8.6 / 6.3 / 8.6 | 63 | 26935 | ok / NIE | - |
| K1.mid.cat+noimages.p1.-updatedAt | products in the subtree 10585 → 105832 | 7981 → 79796 | 7.7 / 6.4 / 7.7 | 64 | 31770 | ok / NIE | - |
| K1.mid.cat+noimages.count | products in the subtree 10585 → 105832 | 12236 → 122339 | 16.1 / 11.7 / 16.1 | 117 | 53095 | ok / NIE | - |
| K1.big-early.cat.p1.name | products in the subtree 15199 → 151964 | 10320 → 103182 | 16.1 / 10.1 / 16.9 | 101 | 43500 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p2.name | products in the subtree 15199 → 151964 | 10320 → 103182 | 9.2 / 9.6 / 9.9 | 96 | 43495 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p1.-updatedAt | products in the subtree 15199 → 151964 | 10314 → 103122 | 9.6 / 9.4 / 10.4 | 94 | 43463 | ok / NIE | 4.0 → 40 |
| K1.big-early.cat.p2.-updatedAt | products in the subtree 15199 → 151964 | 10315 → 103132 | 9.4 / 9.4 / 9.8 | 94 | 43468 | ok / NIE | 4.6 → 46 |
| K1.big-early.cat.count | products in the subtree 15199 → 151964 | 342 → 3419 | 2.8 / 1.4 / 2.8 | 14 | 14 | ok / ok | 360.7 → 3606 |
| K1.big-early.cat+brand.p1.name | products in the subtree 15199 → 151964 | 10572 → 105702 | 9.5 / 9.6 / 10.4 | 96 | 44755 | ok / NIE | - |
| K1.big-early.cat+brand.p1.-updatedAt | products in the subtree 15199 → 151964 | 10463 → 104612 | 9.7 / 10.5 / 11.2 | 105 | 44219 | ok / NIE | - |
| K1.big-early.cat+brand.count | products in the subtree 15199 → 151964 | 21522 → 215183 | 19.2 / 18.3 / 19.2 | 183 | 99582 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.name | products in the subtree 15199 → 151964 | 10722 → 107202 | 15.7 / 13.7 / 15.7 | 137 | 45546 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.-updatedAt | products in the subtree 15199 → 151964 | 10722 → 107202 | 13.6 / 13.5 / 14.2 | 135 | 45544 | ok / NIE | - |
| K1.big-early.cat+drafts.count | products in the subtree 15199 → 151964 | 10706 → 107042 | 13.0 / 13.6 / 14.5 | 136 | 45465 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.name | products in the subtree 15199 → 151964 | 9153 → 91514 | 8.8 / 7.2 / 8.8 | 72 | 37637 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.-updatedAt | products in the subtree 15199 → 151964 | 8991 → 89895 | 8.4 / 7.3 / 8.4 | 73 | 36828 | ok / NIE | - |
| K1.big-early.cat+noimages.count | products in the subtree 15199 → 151964 | 13543 → 135407 | 11.8 / 12.0 / 12.4 | 120 | 59631 | ok / NIE | - |
| K1.big-late.cat.p1.name | products in the subtree 22829 → 228251 | 6943 → 69418 | 16.1 / 10.2 / 16.9 | 102 | 26619 | ok / NIE | 356.3 → 3562 |
| K1.big-late.cat.p2.name | products in the subtree 22829 → 228251 | 6943 → 69418 | 10.4 / 10.4 / 11.4 | 104 | 26621 | ok / NIE | 3.7 → 37 |
| K1.big-late.cat.p1.-updatedAt | products in the subtree 22829 → 228251 | 6967 → 69658 | 10.1 / 10.0 / 11.4 | 100 | 26737 | ok / NIE | 3.0 → 30 |
| K1.big-late.cat.p2.-updatedAt | products in the subtree 22829 → 228251 | 6966 → 69648 | 10.0 / 10.2 / 10.4 | 102 | 26734 | ok / NIE | 2.6 → 26 |
| K1.big-late.cat.count | products in the subtree 22829 → 228251 | 552 → 5519 | 3.5 / 1.6 / 3.5 | 16 | 16 | ok / ok | 211.7 → 2117 |
| K1.big-late.cat+brand.p1.name | products in the subtree 22829 → 228251 | 6944 → 69428 | 10.5 / 10.4 / 11.2 | 104 | 26626 | ok / NIE | - |
| K1.big-late.cat+brand.p1.-updatedAt | products in the subtree 22829 → 228251 | 7167 → 71658 | 10.3 / 10.6 / 10.8 | 106 | 27743 | ok / NIE | - |
| K1.big-late.cat+brand.count | products in the subtree 22829 → 228251 | 25467 → 254626 | 26.0 / 24.4 / 26.0 | 244 | 119365 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.name | products in the subtree 22829 → 228251 | 13248 → 132457 | 40.5 / 15.8 / 40.5 | 158 | 58194 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.-updatedAt | products in the subtree 22829 → 228251 | 7705 → 77037 | 11.3 / 10.1 / 11.3 | 101 | 30427 | ok / NIE | - |
| K1.big-late.cat+drafts.count | products in the subtree 22829 → 228251 | 7575 → 75737 | 17.3 / 16.3 / 17.5 | 163 | 29839 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.name | products in the subtree 22829 → 228251 | 5934 → 59330 | 10.4 / 9.0 / 10.4 | 90 | 21563 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.-updatedAt | products in the subtree 22829 → 228251 | 6610 → 66089 | 10.6 / 8.8 / 10.6 | 88 | 24940 | ok / NIE | - |
| K1.big-late.cat+noimages.count | products in the subtree 22829 → 228251 | 10965 → 109631 | 25.0 / 13.8 / 25.0 | 138 | 46762 | ok / NIE | - |
| K1.department.cat.p1.name | products in the subtree 206849 → 2068134 | 169034 → 1690049 | 176.3 / 169.3 / 181.3 | 1692 | 838525 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p2.name | products in the subtree 206849 → 2068134 | 169035 → 1690059 | 178.7 / 178.6 / 186.8 | 1786 | 838624 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p1.-updatedAt | products in the subtree 206849 → 2068134 | 168985 → 1689560 | 169.5 / 169.8 / 186.7 | 1698 | 838286 | ok / NIE | 17.1 → 171 |
| K1.department.cat.p2.-updatedAt | products in the subtree 206849 → 2068134 | 168980 → 1689510 | 189.2 / 188.3 / 195.0 | 1883 | 838445 | ok / NIE | 16.9 → 169 |
| K1.department.cat.count | products in the subtree 206849 → 2068134 | 462 → 4619 | 3.4 / 2.0 / 3.4 | 20 | 20 | ok / ok | 66.5 → 664 |
| K1.department.cat+brand.p1.name | products in the subtree 206849 → 2068134 | 169420 → 1693909 | 198.1 / 192.4 / 205.0 | 1924 | 840686 | ok / NIE | - |
| K1.department.cat+brand.p1.-updatedAt | products in the subtree 206849 → 2068134 | 169261 → 1692319 | 200.5 / 192.0 / 200.5 | 1919 | 839887 | ok / NIE | - |
| K1.department.cat+brand.count | products in the subtree 206849 → 2068134 | 87062 → 870470 | 79.9 / 67.5 / 79.9 | 675 | 427718 | ok / NIE | - |
| K1.department.cat+drafts.p1.name | products in the subtree 206849 → 2068134 | 30218 → 302128 | 154.7 / 143.8 / 154.7 | 1438 | 144310 | ok / NIE | - |
| K1.department.cat+drafts.p1.-updatedAt | products in the subtree 206849 → 2068134 | 350379 → 3503188 | 703.5 / 1072.5 / 1210.8 | 10724 | 1754125 | NIE / NIE | - |
| K1.department.cat+drafts.count | products in the subtree 206849 → 2068134 | 33930 → 339242 | 96.3 / 110.1 / 128.8 | 1100 | 162529 | ok / NIE | - |
| K1.department.cat+noimages.p1.name | products in the subtree 206849 → 2068134 | 32725 → 327194 | 169.4 / 167.2 / 175.8 | 1672 | 160645 | ok / NIE | - |
| K1.department.cat+noimages.p1.-updatedAt | products in the subtree 206849 → 2068134 | 28248 → 282431 | 60.8 / 62.3 / 81.8 | 623 | 138189 | ok / NIE | - |
| K1.department.cat+noimages.count | products in the subtree 206849 → 2068134 | 43413 → 434055 | 166.7 / 168.5 / 183.0 | 1685 | 218272 | ok / NIE | - |
| K1.mostly-additional.cat.p1.name | products in the subtree 6689 → 66879 | 10958 → 109561 | 16.9 / 9.8 / 16.9 | 98 | 46687 | ok / NIE | 5.7 → 57 |
| K1.mostly-additional.cat.p2.name | products in the subtree 6689 → 66879 | 10958 → 109561 | 9.6 / 9.6 / 10.0 | 96 | 46684 | ok / NIE | 4.8 → 48 |
| K1.mostly-additional.cat.p1.-updatedAt | products in the subtree 6689 → 66879 | 10976 → 109741 | 9.3 / 9.8 / 10.9 | 98 | 46776 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.p2.-updatedAt | products in the subtree 6689 → 66879 | 10973 → 109711 | 10.2 / 9.5 / 11.1 | 95 | 46758 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.count | products in the subtree 6689 → 66879 | 11069 → 110671 | 9.7 / 9.7 / 9.9 | 97 | 47241 | ok / NIE | 517.3 → 5172 |
| K1.mostly-additional.cat+brand.p1.name | products in the subtree 6689 → 66879 | 10960 → 109581 | 9.7 / 9.7 / 9.9 | 97 | 46695 | ok / NIE | - |
| K1.mostly-additional.cat+brand.p1.-updatedAt | products in the subtree 6689 → 66879 | 11085 → 110831 | 9.6 / 9.8 / 10.4 | 98 | 47321 | ok / NIE | - |
| K1.mostly-additional.cat+brand.count | products in the subtree 6689 → 66879 | 14652 → 146495 | 13.6 / 12.6 / 13.6 | 126 | 65181 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.name | products in the subtree 6689 → 66879 | 11209 → 112071 | 12.9 / 12.7 / 14.1 | 127 | 47970 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.-updatedAt | products in the subtree 6689 → 66879 | 11209 → 112071 | 12.3 / 12.7 / 13.4 | 127 | 47970 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.count | products in the subtree 6689 → 66879 | 11193 → 111911 | 12.4 / 12.2 / 12.7 | 122 | 47885 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.name | products in the subtree 6689 → 66879 | 8782 → 87805 | 11.0 / 6.7 / 11.0 | 67 | 35778 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.-updatedAt | products in the subtree 6689 → 66879 | 9324 → 93224 | 7.1 / 6.5 / 7.2 | 65 | 38485 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.count | products in the subtree 6689 → 66879 | 12827 → 128248 | 19.4 / 12.3 / 19.4 | 123 | 56055 | ok / NIE | - |

K1: 82 przypadków, 1 powyżej 2000 ms w pamięci na server (SZACUNEK)
