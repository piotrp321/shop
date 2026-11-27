| przypadek K1 | zmienna kosztu (medium → server) | bufory medium → server | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms | P2 pamięć / dysk | obecne: mediana medium ms → server w pamięci ms |
|---|---|---|---|---|---|---|---|
| K1.small.cat.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 9.9 / 7.2 / 9.9 | 72 | 72 | ok / ok | 3067.9 → 30674 |
| K1.small.cat.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 7.5 / 7.2 / 7.8 | 72 | 72 | ok / ok | 894.4 → 8942 |
| K1.small.cat.count | products in the subtree 15 → 150 | 1478 → 14777 | 7.6 / 7.2 / 7.7 | 72 | 72 | ok / ok | 435.4 → 4354 |
| K1.small.cat+brand.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 7.5 / 7.2 / 7.6 | 72 | 72 | ok / ok | - |
| K1.small.cat+brand.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 7.8 / 7.4 / 8.1 | 74 | 74 | ok / ok | - |
| K1.small.cat+brand.count | products in the subtree 15 → 150 | 1480 → 14797 | 7.1 / 6.9 / 7.8 | 69 | 69 | ok / ok | - |
| K1.small.cat+drafts.p1.name | products in the subtree 15 → 150 | 1629 → 16287 | 11.1 / 9.6 / 11.1 | 96 | 96 | ok / ok | - |
| K1.small.cat+drafts.p1.-updatedAt | products in the subtree 15 → 150 | 1629 → 16287 | 9.5 / 9.7 / 9.8 | 97 | 97 | ok / ok | - |
| K1.small.cat+drafts.count | products in the subtree 15 → 150 | 1629 → 16287 | 9.2 / 9.4 / 11.2 | 94 | 94 | ok / ok | - |
| K1.small.cat+noimages.p1.name | products in the subtree 15 → 150 | 1486 → 14857 | 6.9 / 6.9 / 7.1 | 69 | 69 | ok / ok | - |
| K1.small.cat+noimages.p1.-updatedAt | products in the subtree 15 → 150 | 1487 → 14867 | 6.6 / 6.7 / 7.1 | 67 | 67 | ok / ok | - |
| K1.small.cat+noimages.count | products in the subtree 15 → 150 | 1480 → 14797 | 6.9 / 6.8 / 7.6 | 68 | 68 | ok / ok | - |
| K1.mid.cat.p1.name | products in the subtree 10585 → 105832 | 8634 → 86325 | 14.8 / 8.5 / 14.8 | 85 | 35055 | ok / NIE | 286.2 → 2862 |
| K1.mid.cat.p2.name | products in the subtree 10585 → 105832 | 8634 → 86325 | 8.0 / 8.2 / 9.2 | 82 | 35053 | ok / NIE | 6.1 → 61 |
| K1.mid.cat.p1.-updatedAt | products in the subtree 10585 → 105832 | 8653 → 86515 | 8.4 / 8.4 / 9.4 | 84 | 35150 | ok / NIE | 4.6 → 46 |
| K1.mid.cat.p2.-updatedAt | products in the subtree 10585 → 105832 | 8652 → 86505 | 8.5 / 9.9 / 26.7 | 99 | 35159 | ok / NIE | 4.4 → 44 |
| K1.mid.cat.count | products in the subtree 10585 → 105832 | 7314 → 73127 | 8.9 / 8.4 / 9.8 | 84 | 28455 | ok / NIE | 504.5 → 5044 |
| K1.mid.cat+brand.p1.name | products in the subtree 10585 → 105832 | 8638 → 86365 | 8.5 / 9.2 / 10.1 | 92 | 35083 | ok / NIE | - |
| K1.mid.cat+brand.p1.-updatedAt | products in the subtree 10585 → 105832 | 8841 → 88395 | 8.3 / 9.4 / 10.3 | 94 | 36099 | ok / NIE | - |
| K1.mid.cat+brand.count | products in the subtree 10585 → 105832 | 16178 → 161752 | 15.0 / 14.5 / 15.3 | 145 | 72829 | ok / NIE | - |
| K1.mid.cat+drafts.p1.name | products in the subtree 10585 → 105832 | 8964 → 89625 | 13.4 / 12.5 / 13.4 | 125 | 36746 | ok / NIE | - |
| K1.mid.cat+drafts.p1.-updatedAt | products in the subtree 10585 → 105832 | 8964 → 89625 | 11.9 / 12.0 / 13.2 | 120 | 36740 | ok / NIE | - |
| K1.mid.cat+drafts.count | products in the subtree 10585 → 105832 | 8964 → 89625 | 11.7 / 12.3 / 13.2 | 123 | 36744 | ok / NIE | - |
| K1.mid.cat+noimages.p1.name | products in the subtree 10585 → 105832 | 6991 → 69898 | 8.5 / 6.6 / 8.5 | 66 | 26823 | ok / NIE | - |
| K1.mid.cat+noimages.p1.-updatedAt | products in the subtree 10585 → 105832 | 7958 → 79566 | 7.9 / 6.5 / 7.9 | 65 | 31656 | ok / NIE | - |
| K1.mid.cat+noimages.count | products in the subtree 10585 → 105832 | 11754 → 117520 | 20.0 / 13.5 / 20.0 | 135 | 50703 | ok / NIE | - |
| K1.big-early.cat.p1.name | products in the subtree 15199 → 151964 | 10297 → 102952 | 15.2 / 9.8 / 15.2 | 98 | 43382 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p2.name | products in the subtree 15199 → 151964 | 10297 → 102952 | 9.5 / 9.4 / 9.6 | 94 | 43378 | ok / NIE | 0.2 → 2 |
| K1.big-early.cat.p1.-updatedAt | products in the subtree 15199 → 151964 | 10291 → 102892 | 9.5 / 9.5 / 11.3 | 95 | 43349 | ok / NIE | 4.0 → 40 |
| K1.big-early.cat.p2.-updatedAt | products in the subtree 15199 → 151964 | 10292 → 102902 | 11.0 / 10.6 / 11.5 | 106 | 43365 | ok / NIE | 4.6 → 46 |
| K1.big-early.cat.count | products in the subtree 15199 → 151964 | 342 → 3419 | 2.6 / 1.6 / 2.6 | 16 | 16 | ok / ok | 360.7 → 3606 |
| K1.big-early.cat+brand.p1.name | products in the subtree 15199 → 151964 | 10549 → 105472 | 9.6 / 9.7 / 10.3 | 97 | 44641 | ok / NIE | - |
| K1.big-early.cat+brand.p1.-updatedAt | products in the subtree 15199 → 151964 | 10440 → 104382 | 10.2 / 9.8 / 10.4 | 98 | 44097 | ok / NIE | - |
| K1.big-early.cat+brand.count | products in the subtree 15199 → 151964 | 21515 → 215113 | 18.9 / 18.2 / 18.9 | 182 | 99547 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.name | products in the subtree 15199 → 151964 | 10699 → 106972 | 15.2 / 14.1 / 15.2 | 141 | 45435 | ok / NIE | - |
| K1.big-early.cat+drafts.p1.-updatedAt | products in the subtree 15199 → 151964 | 10699 → 106972 | 13.7 / 13.6 / 14.0 | 136 | 45430 | ok / NIE | - |
| K1.big-early.cat+drafts.count | products in the subtree 15199 → 151964 | 10699 → 106972 | 13.6 / 14.0 / 14.3 | 140 | 45434 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.name | products in the subtree 15199 → 151964 | 9130 → 91284 | 9.0 / 7.5 / 9.0 | 75 | 37526 | ok / NIE | - |
| K1.big-early.cat+noimages.p1.-updatedAt | products in the subtree 15199 → 151964 | 8968 → 89665 | 7.9 / 7.1 / 7.9 | 71 | 36712 | ok / NIE | - |
| K1.big-early.cat+noimages.count | products in the subtree 15199 → 151964 | 13135 → 131327 | 13.8 / 13.2 / 14.1 | 132 | 57604 | ok / NIE | - |
| K1.big-late.cat.p1.name | products in the subtree 22829 → 228251 | 6920 → 69188 | 15.7 / 10.7 / 15.7 | 107 | 26509 | ok / NIE | 356.3 → 3562 |
| K1.big-late.cat.p2.name | products in the subtree 22829 → 228251 | 6920 → 69188 | 11.4 / 10.7 / 14.8 | 107 | 26509 | ok / NIE | 3.7 → 37 |
| K1.big-late.cat.p1.-updatedAt | products in the subtree 22829 → 228251 | 6944 → 69428 | 10.3 / 10.4 / 10.8 | 104 | 26626 | ok / NIE | 3.0 → 30 |
| K1.big-late.cat.p2.-updatedAt | products in the subtree 22829 → 228251 | 6943 → 69418 | 10.3 / 10.5 / 11.4 | 105 | 26622 | ok / NIE | 2.6 → 26 |
| K1.big-late.cat.count | products in the subtree 22829 → 228251 | 552 → 5519 | 3.6 / 1.9 / 3.6 | 19 | 19 | ok / ok | 211.7 → 2117 |
| K1.big-late.cat+brand.p1.name | products in the subtree 22829 → 228251 | 6921 → 69198 | 10.3 / 10.9 / 11.5 | 109 | 26517 | ok / NIE | - |
| K1.big-late.cat+brand.p1.-updatedAt | products in the subtree 22829 → 228251 | 7144 → 71428 | 10.4 / 11.0 / 12.0 | 110 | 27631 | ok / NIE | - |
| K1.big-late.cat+brand.count | products in the subtree 22829 → 228251 | 25460 → 254556 | 25.4 / 24.4 / 25.4 | 244 | 119330 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.name | products in the subtree 22829 → 228251 | 13245 → 132427 | 38.1 / 16.6 / 38.1 | 166 | 58187 | ok / NIE | - |
| K1.big-late.cat+drafts.p1.-updatedAt | products in the subtree 22829 → 228251 | 7702 → 77007 | 10.5 / 10.1 / 10.5 | 101 | 30412 | ok / NIE | - |
| K1.big-late.cat+drafts.count | products in the subtree 22829 → 228251 | 7574 → 75727 | 11.1 / 10.3 / 11.1 | 103 | 29774 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.name | products in the subtree 22829 → 228251 | 5931 → 59300 | 10.4 / 8.5 / 10.4 | 85 | 21543 | ok / NIE | - |
| K1.big-late.cat+noimages.p1.-updatedAt | products in the subtree 22829 → 228251 | 6607 → 66059 | 9.9 / 8.6 / 9.9 | 86 | 24923 | ok / NIE | - |
| K1.big-late.cat+noimages.count | products in the subtree 22829 → 228251 | 10849 → 108471 | 28.4 / 16.7 / 28.4 | 167 | 46210 | ok / NIE | - |
| K1.department.cat.p1.name | products in the subtree 206849 → 2068134 | 168938 → 1689090 | 189.5 / 178.1 / 189.5 | 1781 | 838134 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p2.name | products in the subtree 206849 → 2068134 | 168938 → 1689090 | 195.9 / 187.2 / 201.0 | 1872 | 838224 | ok / NIE | 0.2 → 2 |
| K1.department.cat.p1.-updatedAt | products in the subtree 206849 → 2068134 | 168889 → 1688600 | 179.0 / 178.0 / 183.5 | 1780 | 837888 | ok / NIE | 17.1 → 171 |
| K1.department.cat.p2.-updatedAt | products in the subtree 206849 → 2068134 | 168882 → 1688530 | 199.5 / 206.3 / 264.6 | 2062 | 838135 | NIE / NIE | 16.9 → 169 |
| K1.department.cat.count | products in the subtree 206849 → 2068134 | 462 → 4619 | 3.1 / 1.7 / 3.1 | 17 | 17 | ok / ok | 66.5 → 664 |
| K1.department.cat+brand.p1.name | products in the subtree 206849 → 2068134 | 169406 → 1693769 | 194.2 / 200.7 / 209.8 | 2007 | 840699 | NIE / NIE | - |
| K1.department.cat+brand.p1.-updatedAt | products in the subtree 206849 → 2068134 | 169247 → 1692179 | 199.0 / 198.4 / 203.4 | 1984 | 839882 | ok / NIE | - |
| K1.department.cat+brand.count | products in the subtree 206849 → 2068134 | 87062 → 870470 | 74.8 / 66.2 / 74.8 | 662 | 427705 | ok / NIE | - |
| K1.department.cat+drafts.p1.name | products in the subtree 206849 → 2068134 | 30215 → 302098 | 235.8 / 233.7 / 252.3 | 2337 | 145194 | NIE / NIE | - |
| K1.department.cat+drafts.p1.-updatedAt | products in the subtree 206849 → 2068134 | 350376 → 3503158 | 629.2 / 803.6 / 1040.0 | 8034 | 1751421 | NIE / NIE | - |
| K1.department.cat+drafts.count | products in the subtree 206849 → 2068134 | 33924 → 339182 | 107.3 / 113.6 / 125.9 | 1136 | 162535 | ok / NIE | - |
| K1.department.cat+noimages.p1.name | products in the subtree 206849 → 2068134 | 28608 → 286031 | 183.2 / 185.5 / 208.8 | 1854 | 142825 | ok / NIE | - |
| K1.department.cat+noimages.p1.-updatedAt | products in the subtree 206849 → 2068134 | 28204 → 281992 | 181.8 / 190.8 / 200.6 | 1908 | 139090 | ok / NIE | - |
| K1.department.cat+noimages.count | products in the subtree 206849 → 2068134 | 43376 → 433685 | 203.9 / 194.1 / 284.5 | 1940 | 218458 | ok / NIE | - |
| K1.mostly-additional.cat.p1.name | products in the subtree 6689 → 66879 | 10935 → 109331 | 18.8 / 9.2 / 18.8 | 92 | 46566 | ok / NIE | 5.7 → 57 |
| K1.mostly-additional.cat.p2.name | products in the subtree 6689 → 66879 | 10935 → 109331 | 9.9 / 9.2 / 9.9 | 92 | 46566 | ok / NIE | 4.8 → 48 |
| K1.mostly-additional.cat.p1.-updatedAt | products in the subtree 6689 → 66879 | 10953 → 109511 | 9.1 / 9.3 / 10.8 | 93 | 46657 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.p2.-updatedAt | products in the subtree 6689 → 66879 | 10950 → 109481 | 9.3 / 9.0 / 9.4 | 90 | 46638 | ok / NIE | 5.9 → 59 |
| K1.mostly-additional.cat.count | products in the subtree 6689 → 66879 | 11062 → 110601 | 9.7 / 9.3 / 9.7 | 93 | 47201 | ok / NIE | 517.3 → 5172 |
| K1.mostly-additional.cat+brand.p1.name | products in the subtree 6689 → 66879 | 10937 → 109351 | 9.4 / 9.5 / 10.3 | 95 | 46578 | ok / NIE | - |
| K1.mostly-additional.cat+brand.p1.-updatedAt | products in the subtree 6689 → 66879 | 11062 → 110601 | 9.8 / 9.4 / 9.8 | 94 | 47203 | ok / NIE | - |
| K1.mostly-additional.cat+brand.count | products in the subtree 6689 → 66879 | 14645 → 146425 | 12.5 / 12.5 / 13.1 | 125 | 65145 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.name | products in the subtree 6689 → 66879 | 11186 → 111841 | 13.3 / 12.4 / 13.9 | 124 | 47853 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.p1.-updatedAt | products in the subtree 6689 → 66879 | 11186 → 111841 | 12.3 / 12.7 / 16.8 | 127 | 47855 | ok / NIE | - |
| K1.mostly-additional.cat+drafts.count | products in the subtree 6689 → 66879 | 11186 → 111841 | 12.2 / 12.1 / 12.8 | 121 | 47850 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.name | products in the subtree 6689 → 66879 | 8759 → 87575 | 11.0 / 7.1 / 11.0 | 71 | 35666 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.p1.-updatedAt | products in the subtree 6689 → 66879 | 9301 → 92994 | 7.4 / 6.5 / 7.4 | 65 | 38370 | ok / NIE | - |
| K1.mostly-additional.cat+noimages.count | products in the subtree 6689 → 66879 | 12820 → 128178 | 19.7 / 12.3 / 19.7 | 123 | 56020 | ok / NIE | - |

K1: 82 przypadków, 4 powyżej 2000 ms w pamięci na server (SZACUNEK)
