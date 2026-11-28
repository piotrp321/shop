| przypadek | zmienna kosztu (medium → server) | bufory small / medium / server | rows scanned medium | czas small mediana ms | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms |
|---|---|---|---|---|---|---|---|
| P-default.p1.name | page 25 → 25 | 23 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| P-default.count | page 25 → 25 | 7 / 44 / 44 | 10001 | 0.2 | 0.9 / 0.8 / 0.9 | 1 | 1 |
| P-cat-small.p1.name | products in the subtree 15 → 150 | 2120 / 1014124 / 10139497 | 1000551 | 1.2 | 2801.7 / 3067.9 / 3131.9 | 30674 | 5092230 |
| P-cat-small.p1.-updatedAt | products in the subtree 15 → 150 | 775 / 1004352 / 10041794 | 1000551 | 0.9 | 819.5 / 894.4 / 965.8 | 8942 | 5021647 |
| P-cat-small.count | products in the subtree 15 → 150 | 681 / 318648 / 3185932 | 1000551 | 1.0 | 423.2 / 435.4 / 467.8 | 4354 | 1589128 |
| P-cat-mid.p1.name | products in the subtree 10585 → 105832 | 2120 / 90151 / 901355 | 90083 | 1.3 | 267.5 / 286.2 / 361.9 | 2862 | 445347 |
| P-cat-mid.count | products in the subtree 10585 → 105832 | 681 / 310108 / 3100547 | 947828 | 1.1 | 489.3 / 504.5 / 629.0 | 5044 | 1547126 |
| P-cat-big-early.p1.name | products in the subtree 15199 → 151964 | 1447 / 34 / 340 | 206 | 1.0 | 0.3 / 0.2 / 0.3 | 2 | 2 |
| P-cat-big-early.count | products in the subtree 15199 → 151964 | 681 / 117854 / 1178337 | 658653 | 1.3 | 380.8 / 360.7 / 432.5 | 3606 | 584583 |
| P-cat-big-late.p1.name | products in the subtree 22829 → 228251 | 799 / 108416 / 1083974 | 107720 | 0.9 | 331.8 / 356.3 / 442.7 | 3562 | 537357 |
| P-cat-big-late.count | products in the subtree 22829 → 228251 | 681 / 219287 / 2192493 | 437372 | 1.3 | 338.2 / 211.7 / 350.0 | 2117 | 1090171 |
| P-cat-department.p1.name | products in the subtree 206849 → 2068134 | 149 / 34 / 340 | 206 | 0.5 | 0.2 / 0.2 / 0.2 | 2 | 2 |
| P-cat-department.p1.-updatedAt | products in the subtree 206849 → 2068134 | 110 / 1590 / 15897 | 200668 | 0.3 | 19.2 / 17.1 / 19.2 | 171 | 171 |
| P-cat-department.count | products in the subtree 206849 → 2068134 | 681 / 10131 / 101293 | 248704 | 1.4 | 62.7 / 66.5 / 74.1 | 664 | 3981 |
| P-cat-mostly-additional.p1.name | products in the subtree 6689 → 66879 | 2120 / 3728 / 37274 | 5253 | 1.6 | 13.1 / 5.7 / 13.1 | 57 | 10502 |
| P-cat-mostly-additional.count | products in the subtree 6689 → 66879 | 681 / 319859 / 3198040 | 1002975 | 1.0 | 510.6 / 517.3 / 593.8 | 5172 | 1596000 |
| P-term-none.p1.name | hits 0 → 0 | 15 / 19 / 190 | 0 | 0.1 | 0.2 / 0.1 / 0.2 | 1 | 1 |
| P-term-rare.p1.name | hits 2980 → 29795 | 29 / 3228 / 32274 | 2980 | 0.1 | 16.0 / 7.6 / 16.0 | 76 | 8021 |
| P-term-type-late.p1.name | hits 8584 → 85825 | 25 / 8447 / 84455 | 8584 | 0.1 | 48.7 / 18.7 / 48.7 | 187 | 34222 |
| P-term-brand-mid.p1.name | hits 17200 → 171970 | 60 / 607790 / 6076855 | 599513 | 0.3 | 2507.4 / 2422.9 / 2540.7 | 24225 | 3054460 |
| P-term-brand-common.p1.name | hits 60168 → 601577 | 69 / 232318 / 2322781 | 229152 | 0.2 | 875.6 / 870.1 / 1027.6 | 8700 | 1161898 |
| P-term-very-common-a.p1.name | hits 119269 → 119269 | 41 / 281 / 281 | 275 | 0.1 | 0.7 / 0.6 / 0.7 | 1 | 1 |
| P-term-very-common-a.count | hits 119269 → 119269 | 36 / 18384 / 18384 | 21663 | 1.0 | 14.8 / 13.6 / 14.8 | 14 | 14 |
| P-term-two-rare.p1.name | hits 0 → 0 | 673 / 217218 / 2171807 | 1000191 | 1.5 | 1457.5 / 1452.9 / 1502.1 | 14527 | 1100390 |
| P-term-two-rare.count | hits 0 → 0 | 36 / 847300 / 8471544 | 1000191 | 1.0 | 283.0 / 238.7 / 299.6 | 2387 | 4229966 |
| P-term-two-common.p1.name | hits 258773 → 258773 | 68 / 418 / 418 | 410 | 0.2 | 2.1 / 0.6 / 2.1 | 1 | 1 |
| P-term-punct-1.p1.name | hits 0 → 0 | 673 / 217218 / 2171807 | 1000191 | 1.8 | 1423.4 / 1431.0 / 1501.6 | 14308 | 1100171 |
| P-term-punct-1.count | hits 0 → 0 | 36 / 847327 / 8471814 | 1000191 | 1.0 | 289.4 / 241.5 / 289.4 | 2415 | 4230129 |
| P-term-punct-3.p1.name | hits 0 → 0 | 332 / 157665 / 1576379 | 660920 | 0.8 | 648.3 / 652.0 / 717.6 | 6519 | 794673 |
| P-term-polish-lower.p1.name | hits 10067 → 100653 | 36 / 10033 / 100313 | 10067 | 0.2 | 45.3 / 21.9 / 45.3 | 219 | 42183 |
| P-combo-small-cat+common.p1.name | hits 0 → 0 | 2120 / 1014124 / 10139497 | 1000551 | 1.9 | 3868.7 / 4088.5 / 4110.8 | 40878 | 5102434 |
| P-combo-mid-cat+brand.p1.name | hits 0 → 0 | 2120 / 1015194 / 10150195 | 1002435 | 2.0 | 3487.7 / 3675.4 / 3764.7 | 36747 | 5103653 |
| P-combo-drafts.p1.name | hits 20108 → 20108 | 27 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| P-combo-no-images.p1.name | hits 20003 → 20003 | 59 / 1087 / 1087 | 1071 | 0.2 | 4.1 / 1.3 / 4.1 | 1 | 1 |
| P-combo-no-images+brand.p1.name | hits 1239 → 12388 | 59 / 234041 / 2340008 | 230854 | 0.2 | 1013.0 / 946.6 / 1014.1 | 9464 | 1171276 |
| P-combo-no-images+dept.count | hits 4172 → 41713 | 67 / 26155 / 261505 | 220555 | 0.3 | 98.4 / 130.8 / 161.4 | 1307 | 132010 |
| O-default.p1.-placedAt | page 25 → 25 | 84 / 110 / 110 | 83 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| O-frag-punct-1.p1 | hits 0 → 0 | 291 / 76159 / 380795 | 999999 | 3.1 | 295.5 / 300.1 / 333.7 | 1501 | 183706 |
| O-frag-punct-1.count | hits 0 → 0 | 285 / 56170 / 280850 | 999999 | 3.7 | 254.5 / 242.1 / 254.5 | 1210 | 11541 |
| O-frag-punct-3.count | hits 36835 → 184175 | 238 / 13291 / 66455 | 29060 | 0.7 | 60.0 / 51.7 / 60.0 | 258 | 25294 |
| O-frag-common-surname.p1 | hits 5368 → 5368 | 249 / 523 / 523 | 5916 | 0.6 | 5.4 / 4.9 / 5.4 | 5 | 5 |
| O-frag-none.p1 | hits 0 → 0 | 15 / 19 / 95 | 0 | 0.1 | 0.2 / 0.1 / 0.2 | 1 | 1 |
| C-default.p1.-lastOrderAt | page 25 → 25 | 28 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| C-frag-punct-1.p1 | hits 0 → 0 | 38 / 3690 / 32946 | 98571 | 0.5 | 27.4 / 25.1 / 27.4 | 224 | 871 |
| C-frag-common-surname.p1 | hits 234 → 2089 | 38 / 255 / 2277 | 234 | 0.5 | 1.7 / 0.9 / 1.7 | 8 | 8 |
| X-discount-codes | active uses of the codes 70210 → 351050 | 43 / 122 / 610 | 70227 | 0.1 | 3.6 / 3.1 / 3.6 | 16 | 16 |
| X-category-counts | categories x bound 1001 180 → 180 | 785 / 2159 / 2159 | 105502 | 0.5 | 15.2 / 10.3 / 15.2 | 10 | 10 |
| X-sitemap-boundaries-M0 | products 1000191 → 10000191 | 24 / 840453 / 8403086 | 2000382 | 0.4 | 254.5 / 225.2 / 254.5 | 2252 | 4195603 |
