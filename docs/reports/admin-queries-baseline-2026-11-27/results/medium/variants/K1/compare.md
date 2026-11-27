| przypadek K1 | zmienna kosztu (medium → server) | bufory medium → server | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms | P2 pamięć / dysk | obecne: mediana medium ms → server w pamięci ms |
|---|---|---|---|---|---|---|---|
| K1.small.cat.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 8.3 / 6.7 / 8.3 | 67 | 67 | ok / ok | 3067.9 → 30674 |
| K1.small.cat.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 6.7 / 6.9 / 7.0 | 69 | 69 | ok / ok | 894.4 → 8942 |
| K1.small.cat.count | products in the subtree 15 → 150 | 1478 → 14777 | 6.7 / 6.8 / 7.7 | 68 | 68 | ok / ok | 435.4 → 4354 |
| K1.small.cat+brand.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 7.0 / 6.9 / 7.5 | 69 | 69 | ok / ok | - |
| K1.small.cat+brand.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 7.9 / 7.2 / 7.9 | 72 | 72 | ok / ok | - |
| K1.small.cat+brand.count | products in the subtree 15 → 150 | 1480 → 14797 | 7.7 / 7.0 / 7.7 | 70 | 70 | ok / ok | - |
| K1.small.cat+drafts.p1.name | products in the subtree 15 → 150 | 1629 → 16287 | 10.8 / 9.6 / 10.8 | 96 | 96 | ok / ok | - |
| K1.small.cat+drafts.p1.-updatedAt | products in the subtree 15 → 150 | 1629 → 16287 | 9.4 / 9.4 / 9.6 | 94 | 94 | ok / ok | - |
| K1.small.cat+drafts.count | products in the subtree 15 → 150 | 1629 → 16287 | 10.2 / 10.3 / 10.5 | 103 | 103 | ok / ok | - |
| K1.small.cat+noimages.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 7.5 / 6.9 / 7.5 | 69 | 69 | ok / ok | - |
| K1.small.cat+noimages.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 7.3 / 7.0 / 7.8 | 70 | 70 | ok / ok | - |
| K1.small.cat+noimages.count | products in the subtree 15 → 150 | 1480 → 14797 | 6.8 / 6.9 / 7.2 | 69 | 69 | ok / ok | - |
| K1.mid.cat.p1.name | products in the subtree 10585 → 105832 | 8634 → 86325 | 14.1 / 8.4 / 14.1 | 84 | 35055 | ok / NIE | 286.2 → 2862 |
| K1.mid.cat.p2.name | products in the subtree 10585 → 105832 | 8634 → 86325 | 8.5 / 8.2 / 8.5 | 82 | 35053 | ok / NIE | 6.1 → 61 |
| K1.mid.cat.p1.-updatedAt | products in the subtree 10585 → 105832 | 8653 → 86515 | 8.3 / 8.2 / 8.7 | 82 | 35147 | ok / NIE | 4.6 → 46 |
| K1.mid.cat.p2.-updatedAt | products in the subtree 10585 → 105832 | 8652 → 86505 | 8.6 / 8.2 / 9.6 | 82 | 35143 | ok / NIE | 4.4 → 44 |
| K1.mid.cat.count | products in the subtree 10585 → 105832 | 7064 → 70628 | 9.3 / 7.6 / 9.3 | 76 | 27198 | ok / NIE | 504.5 → 5044 |
| K1.mid.cat+brand.p1.name | products in the subtree 10585 → 105832 | 8638 → 86365 | 8.6 / 9.2 / 10.0 | 92 | 35082 | ok / NIE | - |
| K1.mid.cat+brand.p1.-updatedAt | products in the subtree 10585 → 105832 | 8841 → 88395 | 9.3 / 9.6 / 10.4 | 96 | 36101 | ok / NIE | - |
| K1.mid.cat+brand.count | products in the subtree 10585 → 105832 | 16178 → 161752 | 18.9 / 14.3 / 19.8 | 143 | 72827 | ok / NIE | - |
| K1.mid.cat+drafts.p1.name | products in the subtree 10585 → 105832 | 8964 → 89625 | 12.7 / 12.3 / 13.1 | 123 | 36743 | ok / NIE | - |
| K1.mid.cat+drafts.p1.-updatedAt | products in the subtree 10585 → 105832 | 8964 → 89625 | 13.1 / 13.0 / 14.5 | 130 | 36750 | ok / NIE | - |
| K1.mid.cat+drafts.count | products in the subtree 10585 → 105832 | 8964 → 89625 | 12.0 / 13.2 / 13.7 | 132 | 36752 | ok / NIE | - |
| K1.mid.cat+noimages.p1.name | products in the subtree 10585 → 105832 | 6991 → 69898 | 9.3 / 6.4 / 9.3 | 64 | 26821 | ok / NIE | - |
| K1.mid.cat+noimages.p1.-updatedAt | products in the subtree 10585 → 105832 | 7958 → 79566 | 7.8 / 6.7 / 7.8 | 67 | 31658 | ok / NIE | - |
| K1.mid.cat+noimages.count | products in the subtree 10585 → 105832 | 11754 → 117520 | 21.1 / 14.6 / 21.1 | 146 | 50714 | ok / NIE | - |
| K1.big-early.cat.p1.name | products in the subtree 15199 → 151964 | 10297 → 102952 | 16.8 / 9.8 / 16.8 | 98 | 43382 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p2.name | products in the subtree 15199 → 151964 | 10297 → 102952 | 10.0 / 9.6 / 10.5 | 96 | 43380 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p1.-updatedAt | products in the subtree 15199 → 151964 | 10291 → 102892 | 9.1 / 9.5 / 9.9 | 95 | 43349 | ok / NIE | 4.0 → 40 |
| K1.big-early.cat.p2.-updatedAt | products in the subtree 15199 → 151964 | 10292 → 102902 | 9.8 / 9.5 / 10.6 | 95 | 43354 | ok / NIE | 4.6 → 46 |
| K1.big-early.cat.count | products in the subtree 15199 → 151964 | 342 → 3419 | 2.7 / 1.6 / 2.7 | 16 | 16 | ok / ok | 360.7 → 3606 |
| K1.big-early.cat+brand.p1.name | products in the subtree 15199 → 151964 | 10549 → 105472 | 9.7 / 10.6 / 11.3 | 106 | 44650 | ok / NIE | - |
| K1.big-early.cat+brand.p1.-updatedAt | products in the subtree 15199 → 151964 | 10440 → 104382 | 11.3 / 9.8 / 11.7 | 98 | 44097 | ok / NIE | - |
| K1.big-early.cat+brand.count | products in the subtree 15199 → 151964 | 21515 → 215113 | 18.8 / 19.5 / 20.4 | 195 | 99559 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.name | products in the subtree 15199 → 151964 | 10699 → 106972 | 15.6 / 14.4 / 15.6 | 144 | 45438 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.-updatedAt | products in the subtree 15199 → 151964 | 10699 → 106972 | 15.6 / 14.4 / 15.6 | 144 | 45438 | ok / NIE | - |
| K1.big-early.cat+drafts.count | products in the subtree 15199 → 151964 | 10699 → 106972 | 13.9 / 14.1 / 15.3 | 141 | 45435 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.name | products in the subtree 15199 → 151964 | 9130 → 91284 | 8.5 / 6.9 / 8.5 | 69 | 37519 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.-updatedAt | products in the subtree 15199 → 151964 | 8968 → 89665 | 8.4 / 7.4 / 8.4 | 74 | 36714 | ok / NIE | - |
| K1.big-early.cat+noimages.count | products in the subtree 15199 → 151964 | 13135 → 131327 | 14.0 / 13.5 / 14.4 | 134 | 57606 | ok / NIE | - |
| K1.big-late.cat.p1.name | products in the subtree 22829 → 228251 | 6920 → 69188 | 15.2 / 10.9 / 15.2 | 109 | 26511 | ok / NIE | 356.3 → 3562 |
| K1.big-late.cat.p2.name | products in the subtree 22829 → 228251 | 6920 → 69188 | 12.0 / 10.5 / 12.0 | 105 | 26507 | ok / NIE | 3.7 → 37 |
| K1.big-late.cat.p1.-updatedAt | products in the subtree 22829 → 228251 | 6944 → 69428 | 10.8 / 11.0 / 11.7 | 110 | 26632 | ok / NIE | 3.0 → 30 |
| K1.big-late.cat.p2.-updatedAt | products in the subtree 22829 → 228251 | 6943 → 69418 | 10.8 / 10.6 / 11.8 | 106 | 26624 | ok / NIE | 2.6 → 26 |
| K1.big-late.cat.count | products in the subtree 22829 → 228251 | 552 → 5519 | 3.4 / 1.9 / 3.4 | 19 | 19 | ok / ok | 211.7 → 2117 |
| K1.big-late.cat+brand.p1.name | products in the subtree 22829 → 228251 | 6921 → 69198 | 12.4 / 10.8 / 12.4 | 108 | 26516 | ok / NIE | - |
| K1.big-late.cat+brand.p1.-updatedAt | products in the subtree 22829 → 228251 | 7144 → 71428 | 11.9 / 11.3 / 13.2 | 113 | 27635 | ok / NIE | - |
| K1.big-late.cat+brand.count | products in the subtree 22829 → 228251 | 25460 → 254556 | 25.3 / 24.9 / 26.1 | 249 | 119335 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.name | products in the subtree 22829 → 228251 | 13245 → 132427 | 38.0 / 16.6 / 38.0 | 166 | 58188 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.-updatedAt | products in the subtree 22829 → 228251 | 7702 → 77007 | 11.8 / 10.5 / 11.8 | 105 | 30416 | ok / NIE | - |
| K1.big-late.cat+drafts.count | products in the subtree 22829 → 228251 | 7574 → 75727 | 11.1 / 10.2 / 11.1 | 102 | 29774 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.name | products in the subtree 22829 → 228251 | 5931 → 59300 | 10.6 / 8.5 / 10.6 | 85 | 21543 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.-updatedAt | products in the subtree 22829 → 228251 | 6607 → 66059 | 10.1 / 8.6 / 10.1 | 86 | 24923 | ok / NIE | - |
| K1.big-late.cat+noimages.count | products in the subtree 22829 → 228251 | 10849 → 108471 | 23.9 / 13.9 / 26.4 | 139 | 46183 | ok / NIE | - |
| K1.department.cat.p1.name | products in the subtree 206849 → 2068134 | 168938 → 1689090 | 187.8 / 176.3 / 191.6 | 1763 | 838116 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p2.name | products in the subtree 206849 → 2068134 | 168938 → 1689090 | 200.2 / 186.3 / 207.3 | 1863 | 838215 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p1.-updatedAt | products in the subtree 206849 → 2068134 | 168889 → 1688600 | 168.1 / 176.2 / 183.5 | 1762 | 837869 | ok / NIE | 17.1 → 171 |
| K1.department.cat.p2.-updatedAt | products in the subtree 206849 → 2068134 | 168882 → 1688530 | 191.0 / 189.1 / 200.6 | 1891 | 837963 | ok / NIE | 16.9 → 169 |
| K1.department.cat.count | products in the subtree 206849 → 2068134 | 462 → 4619 | 3.0 / 1.6 / 3.0 | 16 | 16 | ok / ok | 66.5 → 664 |
| K1.department.cat+brand.p1.name | products in the subtree 206849 → 2068134 | 169406 → 1693769 | 201.8 / 204.4 / 297.1 | 2043 | 840736 | NIE / NIE | - |
| K1.department.cat+brand.p1.-updatedAt | products in the subtree 206849 → 2068134 | 169247 → 1692179 | 199.3 / 199.8 / 208.2 | 1998 | 839895 | ok / NIE | - |
| K1.department.cat+brand.count | products in the subtree 206849 → 2068134 | 87062 → 870470 | 75.4 / 67.3 / 75.4 | 673 | 427716 | ok / NIE | - |
| K1.department.cat+drafts.p1.name | products in the subtree 206849 → 2068134 | 502410 → 5023237 | 1350.1 / 1264.2 / 1468.0 | 12640 | 2516066 | NIE / NIE | - |
| K1.department.cat+drafts.p1.-updatedAt | products in the subtree 206849 → 2068134 | 350376 → 3503158 | 941.6 / 709.7 / 1203.6 | 7096 | 1750482 | NIE / NIE | - |
| K1.department.cat+drafts.count | products in the subtree 206849 → 2068134 | 33924 → 339182 | 123.6 / 122.7 / 133.7 | 1226 | 162625 | ok / NIE | - |
| K1.department.cat+noimages.p1.name | products in the subtree 206849 → 2068134 | 28608 → 286031 | 99.9 / 106.9 / 118.4 | 1068 | 142039 | ok / NIE | - |
| K1.department.cat+noimages.p1.-updatedAt | products in the subtree 206849 → 2068134 | 28204 → 281992 | 104.1 / 105.1 / 138.3 | 1051 | 138207 | ok / NIE | - |
| K1.department.cat+noimages.count | products in the subtree 206849 → 2068134 | 43376 → 433685 | 184.6 / 217.0 / 271.8 | 2170 | 218688 | NIE / NIE | - |
| K1.mostly-additional.cat.p1.name | products in the subtree 6689 → 66879 | 10935 → 109331 | 18.0 / 9.2 / 18.0 | 92 | 46565 | ok / NIE | 5.7 → 57 |
| K1.mostly-additional.cat.p2.name | products in the subtree 6689 → 66879 | 10935 → 109331 | 8.8 / 9.6 / 11.2 | 96 | 46569 | ok / NIE | 4.8 → 48 |
| K1.mostly-additional.cat.p1.-updatedAt | products in the subtree 6689 → 66879 | 10953 → 109511 | 10.0 / 9.2 / 10.0 | 92 | 46655 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.p2.-updatedAt | products in the subtree 6689 → 66879 | 10950 → 109481 | 9.1 / 9.3 / 10.3 | 93 | 46641 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.count | products in the subtree 6689 → 66879 | 11062 → 110601 | 10.5 / 9.2 / 10.5 | 92 | 47200 | ok / NIE | 517.3 → 5172 |
| K1.mostly-additional.cat+brand.p1.name | products in the subtree 6689 → 66879 | 10937 → 109351 | 9.5 / 9.5 / 10.8 | 95 | 46579 | ok / NIE | - |
| K1.mostly-additional.cat+brand.p1.-updatedAt | products in the subtree 6689 → 66879 | 11062 → 110601 | 9.6 / 9.5 / 9.7 | 95 | 47203 | ok / NIE | - |
| K1.mostly-additional.cat+brand.count | products in the subtree 6689 → 66879 | 14645 → 146425 | 12.7 / 12.4 / 14.1 | 124 | 65145 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.name | products in the subtree 6689 → 66879 | 11186 → 111841 | 13.1 / 12.8 / 13.7 | 128 | 47856 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.-updatedAt | products in the subtree 6689 → 66879 | 11186 → 111841 | 13.0 / 12.2 / 13.5 | 122 | 47851 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.count | products in the subtree 6689 → 66879 | 11186 → 111841 | 13.2 / 12.1 / 13.2 | 121 | 47850 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.name | products in the subtree 6689 → 66879 | 8759 → 87575 | 11.2 / 6.9 / 11.2 | 69 | 35664 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.-updatedAt | products in the subtree 6689 → 66879 | 9301 → 92994 | 7.6 / 6.7 / 7.6 | 67 | 38372 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.count | products in the subtree 6689 → 66879 | 12820 → 128178 | 19.6 / 13.1 / 19.6 | 131 | 56028 | ok / NIE | - |

K1: 82 przypadków, 4 powyżej 2000 ms w pamięci na server (SZACUNEK)
