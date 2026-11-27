| case | cost variable (value medium → server) | scale | buffers small / medium / server | rows scanned medium / server | median ms small / medium (first, max) | server memory ms | server disk ms | P2 mem / disk |
|---|---|---|---|---|---|---|---|---|
| P-default.p1.name | page 25 → 25 | const ×1.0 | 23 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-default.p2.name | page 25 → 25 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-default.p1.-updatedAt | page 25 → 25 | const ×1.0 | 22 / 26 / 26 | 26.0 / 26 | 0.0 / 0.0 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-default.p2.-updatedAt | page 25 → 25 | const ×1.0 | 20 / 26 / 26 | 26.0 / 26 | 0.0 / 0.0 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-default.count | page 25 → 25 | const ×1.0 | 7 / 44 / 44 | 10001.0 / 10001 | 0.2 / 0.8 (0.9, 0.9) | 1 | 1 | ok / ok |
| P-default.deep.name | page 25 → 25 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-cat-small.p1.name | products in the subtree 15 → 150 | products ×10.0 | 2120 / 1014124 / 10139497 | 1000551.0 / 10003790 | 1.2 / 3067.9 (2801.7, 3131.9) | 30674 | 5092230 | NO / NO |
| P-cat-small.p1.-updatedAt | products in the subtree 15 → 150 | products ×10.0 | 775 / 1004352 / 10041794 | 1000551.0 / 10003790 | 0.9 / 894.4 (819.5, 965.8) | 8942 | 5021647 | NO / NO |
| P-cat-small.count | products in the subtree 15 → 150 | products ×10.0 | 681 / 318648 / 3185932 | 1000551.0 / 10003790 | 1.0 / 435.4 (423.2, 467.8) | 4354 | 1589128 | NO / NO |
| P-cat-mid.p1.name | products in the subtree 10585 → 105832 | products ×10.0 | 2120 / 90151 / 901355 | 90083.0 / 900675 | 1.3 / 286.2 (267.5, 361.9) | 2862 | 445347 | NO / NO |
| P-cat-mid.p2.name | products in the subtree 10585 → 105832 | products ×10.0 | - / 3684 / 36834 | 4807.0 / 48062 | - / 6.1 (16.0, 16.0) | 61 | 10286 | ok / NO |
| P-cat-mid.p1.-updatedAt | products in the subtree 10585 → 105832 | products ×10.0 | 775 / 3696 / 36954 | 4863.0 / 48622 | 1.0 / 4.6 (8.6, 8.6) | 46 | 10330 | ok / NO |
| P-cat-mid.p2.-updatedAt | products in the subtree 10585 → 105832 | products ×10.0 | - / 4070 / 40693 | 5223.0 / 52221 | - / 4.4 (6.9, 6.9) | 44 | 12199 | ok / NO |
| P-cat-mid.count | products in the subtree 10585 → 105832 | products ×10.0 | 681 / 310108 / 3100547 | 947828.0 / 9476651 | 1.1 / 504.5 (489.3, 629.0) | 5044 | 1547126 | NO / NO |
| P-cat-big-early.p1.name | products in the subtree 15199 → 151964 | products ×10.0 | 1447 / 34 / 340 | 206.0 / 2060 | 1.0 / 0.2 (0.3, 0.3) | 2 | 2 | ok / ok |
| P-cat-big-early.p2.name | products in the subtree 15199 → 151964 | products ×10.0 | 690 / 34 / 340 | 206.0 / 2060 | 0.6 / 0.2 (0.3, 0.3) | 2 | 2 | ok / ok |
| P-cat-big-early.p1.-updatedAt | products in the subtree 15199 → 151964 | products ×10.0 | 457 / 2356 / 23556 | 3848.0 / 38473 | 0.6 / 4.0 (6.0, 6.0) | 40 | 3626 | ok / NO |
| P-cat-big-early.p2.-updatedAt | products in the subtree 15199 → 151964 | products ×10.0 | 343 / 3594 / 35934 | 5059.0 / 50581 | 0.7 / 4.6 (5.6, 5.6) | 46 | 9821 | ok / NO |
| P-cat-big-early.count | products in the subtree 15199 → 151964 | products ×10.0 | 681 / 117854 / 1178337 | 658653.0 / 6585398 | 1.3 / 360.7 (380.8, 432.5) | 3606 | 584583 | NO / NO |
| P-cat-big-late.p1.name | products in the subtree 22829 → 228251 | products ×10.0 | 799 / 108416 / 1083974 | 107720.0 / 1077015 | 0.9 / 356.3 (331.8, 442.7) | 3562 | 537357 | NO / NO |
| P-cat-big-late.p2.name | products in the subtree 22829 → 228251 | products ×10.0 | 1338 / 2207 / 22066 | 2978.0 / 29775 | 1.2 / 3.7 (10.2, 10.2) | 37 | 2878 | ok / NO |
| P-cat-big-late.p1.-updatedAt | products in the subtree 22829 → 228251 | products ×10.0 | 533 / 1885 / 18847 | 2690.0 / 26895 | 0.8 / 3.0 (5.3, 5.3) | 30 | 1261 | ok / ok |
| P-cat-big-late.p2.-updatedAt | products in the subtree 22829 → 228251 | products ×10.0 | 264 / 2081 / 20806 | 2866.0 / 28655 | 0.6 / 2.6 (3.5, 3.5) | 26 | 2237 | ok / NO |
| P-cat-big-late.count | products in the subtree 22829 → 228251 | products ×10.0 | 681 / 219287 / 2192493 | 437372.0 / 4372968 | 1.3 / 211.7 (338.2, 350.0) | 2117 | 1090171 | NO / NO |
| P-cat-department.p1.name | products in the subtree 206849 → 2068134 | products ×10.0 | 149 / 34 / 340 | 206.0 / 2060 | 0.5 / 0.2 (0.2, 0.2) | 2 | 2 | ok / ok |
| P-cat-department.p2.name | products in the subtree 206849 → 2068134 | products ×10.0 | 212 / 34 / 340 | 206.0 / 2060 | 0.4 / 0.2 (0.2, 0.2) | 2 | 2 | ok / ok |
| P-cat-department.p1.-updatedAt | products in the subtree 206849 → 2068134 | products ×10.0 | 110 / 1590 / 15897 | 200668.0 / 2006335 | 0.3 / 17.1 (19.2, 19.2) | 171 | 171 | ok / ok |
| P-cat-department.p2.-updatedAt | products in the subtree 206849 → 2068134 | products ×10.0 | 44 / 1509 / 15087 | 200579.0 / 2005445 | 0.2 / 16.9 (16.6, 17.4) | 169 | 169 | ok / ok |
| P-cat-department.count | products in the subtree 206849 → 2068134 | products ×10.0 | 681 / 10131 / 101293 | 248704.0 / 2486613 | 1.4 / 66.5 (62.7, 74.1) | 664 | 3981 | ok / NO |
| P-cat-mostly-additional.p1.name | products in the subtree 6689 → 66879 | products ×10.0 | 2120 / 3728 / 37274 | 5253.0 / 52521 | 1.6 / 5.7 (13.1, 13.1) | 57 | 10502 | ok / NO |
| P-cat-mostly-additional.p2.name | products in the subtree 6689 → 66879 | products ×10.0 | - / 3076 / 30755 | 4609.0 / 46082 | - / 4.8 (10.4, 10.4) | 48 | 7233 | ok / NO |
| P-cat-mostly-additional.p1.-updatedAt | products in the subtree 6689 → 66879 | products ×10.0 | 775 / 5502 / 55011 | 7063.0 / 70618 | 1.0 / 5.9 (7.2, 7.2) | 59 | 19372 | ok / NO |
| P-cat-mostly-additional.p2.-updatedAt | products in the subtree 6689 → 66879 | products ×10.0 | - / 5860 / 58590 | 7399.0 / 73977 | - / 5.9 (8.9, 8.9) | 59 | 21162 | ok / NO |
| P-cat-mostly-additional.count | products in the subtree 6689 → 66879 | products ×10.0 | 681 / 319859 / 3198040 | 1002975.0 / 10028026 | 1.0 / 517.3 (510.6, 593.8) | 5172 | 1596000 | NO / NO |
| P-term-none.p1.name | hits 0 → 0 | products ×10.0 | 15 / 19 / 190 | 0.0 / 0 | 0.1 / 0.1 (0.2, 0.2) | 1 | 1 | ok / ok |
| P-term-none.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 15 / 19 / 190 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| P-term-none.count | hits 0 → 0 | products ×10.0 | 9 / 13 / 130 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| P-term-rare.p1.name | hits 2980 → 29795 | products ×10.0 | 29 / 3228 / 32274 | 2980.0 / 29795 | 0.1 / 7.6 (16.0, 16.0) | 76 | 8021 | ok / NO |
| P-term-rare.p2.name | hits 2980 → 29795 | products ×10.0 | - / 3228 / 32274 | 2980.0 / 29795 | - / 7.7 (7.8, 8.1) | 77 | 8022 | ok / NO |
| P-term-rare.p1.-updatedAt | hits 2980 → 29795 | products ×10.0 | 29 / 3228 / 32274 | 2980.0 / 29795 | 0.1 / 7.1 (6.8, 7.3) | 71 | 8017 | ok / NO |
| P-term-rare.p2.-updatedAt | hits 2980 → 29795 | products ×10.0 | - / 3228 / 32274 | 2980.0 / 29795 | - / 7.1 (6.8, 7.8) | 71 | 8016 | ok / NO |
| P-term-rare.count | hits 2980 → 29795 | products ×10.0 | 23 / 3222 / 32214 | 2980.0 / 29795 | 0.1 / 7.0 (6.7, 10.4) | 69 | 7985 | ok / NO |
| P-term-very-common-a.p1.name | hits 119269 → 119269 | const ×1.0 | 41 / 281 / 281 | 275.0 / 275 | 0.1 / 0.6 (0.7, 0.7) | 1 | 1 | ok / ok |
| P-term-very-common-a.p2.name | hits 119269 → 119269 | const ×1.0 | 151 / 168 / 168 | 163.0 / 163 | 0.3 / 0.4 (0.4, 0.4) | 0 | 0 | ok / ok |
| P-term-very-common-a.p1.-updatedAt | hits 119269 → 119269 | const ×1.0 | 237 / 312 / 312 | 331.0 / 331 | 0.4 / 0.5 (0.7, 0.7) | 1 | 1 | ok / ok |
| P-term-very-common-a.p2.-updatedAt | hits 119269 → 119269 | const ×1.0 | 90 / 158 / 158 | 153.0 / 153 | 0.2 / 0.3 (0.4, 0.4) | 0 | 0 | ok / ok |
| P-term-very-common-a.count | hits 119269 → 119269 | const ×1.0 | 36 / 18384 / 18384 | 21663.0 / 21663 | 1.0 / 13.6 (14.8, 14.8) | 14 | 14 | ok / ok |
| P-term-very-common-b.p1.name | hits 459399 → 459399 | const ×1.0 | 135 / 354 / 354 | 347.0 / 347 | 0.3 / 0.7 (0.7, 0.7) | 1 | 1 | ok / ok |
| P-term-very-common-b.p2.name | hits 459399 → 459399 | const ×1.0 | 79 / 285 / 285 | 278.0 / 278 | 0.2 / 0.6 (0.6, 0.7) | 1 | 1 | ok / ok |
| P-term-very-common-b.p1.-updatedAt | hits 459399 → 459399 | const ×1.0 | 27 / 31 / 31 | 32.0 / 32 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-term-very-common-b.p2.-updatedAt | hits 459399 → 459399 | const ×1.0 | 33 / 45 / 45 | 48.0 / 48 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-term-very-common-b.count | hits 459399 → 4593200 | products ×10.0 | 36 / 62175 / 621643 | 73288.0 / 732754 | 1.0 / 46.0 (48.5, 48.5) | 460 | 303090 | ok / NO |
| P-term-two-rare.p1.name | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.5 / 1452.9 (1457.5, 1502.1) | 14527 | 1100390 | NO / NO |
| P-term-two-rare.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.5 / 1460.4 (1435.2, 1511.8) | 14601 | 1100464 | NO / NO |
| P-term-two-rare.count | hits 0 → 0 | products ×10.0 | 36 / 847300 / 8471544 | 1000191.0 / 10000191 | 1.0 / 238.7 (283.0, 299.6) | 2387 | 4229966 | NO / NO |
| P-term-two-other.p1.name | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.5 / 1459.1 (1445.5, 1496.4) | 14588 | 1100451 | NO / NO |
| P-term-two-other.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.6 / 1440.5 (1504.3, 1504.3) | 14402 | 1100266 | NO / NO |
| P-term-two-other.count | hits 0 → 0 | products ×10.0 | 36 / 847320 / 8471744 | 1000191.0 / 10000191 | 1.0 / 242.2 (282.4, 282.4) | 2422 | 4230102 | NO / NO |
| P-term-two-common.p1.name | hits 258773 → 258773 | const ×1.0 | 68 / 418 / 418 | 410.0 / 410 | 0.2 / 0.6 (2.1, 2.1) | 1 | 1 | ok / ok |
| P-term-two-common.p2.name | hits 258773 → 258773 | const ×1.0 | 87 / 332 / 332 | 325.0 / 325 | 0.2 / 0.6 (1.7, 1.7) | 1 | 1 | ok / ok |
| P-term-two-common.p1.-updatedAt | hits 258773 → 258773 | const ×1.0 | 117 / 143 / 143 | 162.0 / 162 | 0.2 / 0.2 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-term-two-common.p2.-updatedAt | hits 258773 → 258773 | const ×1.0 | 56 / 69 / 69 | 67.0 / 67 | 0.1 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-term-two-common.count | hits 258773 → 258773 | const ×1.0 | 36 / 26671 / 26671 | 31449.0 / 31449 | 1.1 / 19.5 (19.1, 20.1) | 20 | 20 | ok / ok |
| P-term-punct-1.p1.name | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.8 / 1431.0 (1423.4, 1501.6) | 14308 | 1100171 | NO / NO |
| P-term-punct-1.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 3.1 / 1449.0 (1448.0, 1466.9) | 14488 | 1100351 | NO / NO |
| P-term-punct-1.count | hits 0 → 0 | products ×10.0 | 36 / 847327 / 8471814 | 1000191.0 / 10000191 | 1.0 / 241.5 (289.4, 289.4) | 2415 | 4230129 | NO / NO |
| P-term-punct-2.p1.name | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.9 / 1479.1 (1463.9, 1515.3) | 14789 | 1100652 | NO / NO |
| P-term-punct-2.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 673 / 217218 / 2171807 | 1000191.0 / 10000191 | 1.7 / 1437.9 (1425.0, 1449.2) | 14376 | 1100239 | NO / NO |
| P-term-punct-2.count | hits 0 → 0 | products ×10.0 | 36 / 847288 / 8471424 | 1000191.0 / 10000191 | 1.0 / 235.0 (290.2, 290.2) | 2350 | 4229870 | NO / NO |
| P-term-punct-3.p1.name | hits 0 → 0 | products ×10.0 | 332 / 157665 / 1576379 | 660920.0 / 6608064 | 0.8 / 652.0 (648.3, 717.6) | 6519 | 794673 | NO / NO |
| P-term-punct-3.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 332 / 157665 / 1576379 | 660920.0 / 6608064 | 0.8 / 664.8 (700.2, 912.4) | 6647 | 794802 | NO / NO |
| P-term-punct-3.count | hits 0 → 0 | products ×10.0 | 326 / 157659 / 1576319 | 660920.0 / 6608064 | 0.8 / 673.8 (656.3, 749.6) | 6737 | 794892 | NO / NO |
| P-term-punct-4.p1.name | hits 0 → 0 | products ×10.0 | 13 / 18 / 180 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| P-term-punct-4.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 13 / 18 / 180 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| P-term-punct-4.count | hits 0 → 0 | products ×10.0 | 7 / 12 / 120 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| P-term-type-early.p1.name | hits 8625 → 8625 | const ×1.0 | 32 / 4450 / 4450 | 4387.0 / 4387 | 0.2 / 7.7 (22.9, 22.9) | 8 | 8 | ok / ok |
| P-term-type-early.p2.name | hits 8625 → 8625 | const ×1.0 | - / 29 / 29 | 26.0 / 26 | - / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-term-type-early.p1.-updatedAt | hits 8625 → 8625 | const ×1.0 | 32 / 3270 / 3270 | 3274.0 / 3274 | 0.1 / 4.1 (8.2, 8.2) | 4 | 4 | ok / ok |
| P-term-type-early.p2.-updatedAt | hits 8625 → 8625 | const ×1.0 | - / 2702 / 2702 | 2693.0 / 2693 | - / 2.9 (4.3, 4.3) | 3 | 3 | ok / ok |
| P-term-type-early.count | hits 8625 → 86235 | products ×10.0 | 26 / 8651 / 86495 | 8625.0 / 86235 | 0.1 / 17.1 (48.4, 48.4) | 171 | 35226 | ok / NO |
| P-term-type-late.p1.name | hits 8584 → 85825 | products ×10.0 | 25 / 8447 / 84455 | 8584.0 / 85825 | 0.1 / 18.7 (48.7, 48.7) | 187 | 34222 | ok / NO |
| P-term-type-late.p2.name | hits 8584 → 8584 | const ×1.0 | - / 30 / 30 | 26.0 / 26 | - / 0.2 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-term-type-late.p1.-updatedAt | hits 8584 → 85825 | products ×10.0 | 25 / 8447 / 84455 | 8584.0 / 85825 | 0.1 / 17.3 (16.9, 18.0) | 173 | 34208 | ok / NO |
| P-term-type-late.p2.-updatedAt | hits 8584 → 85825 | products ×10.0 | - / 8447 / 84455 | 8584.0 / 85825 | - / 17.1 (17.5, 17.7) | 171 | 34206 | ok / NO |
| P-term-type-late.count | hits 8584 → 85825 | products ×10.0 | 19 / 8441 / 84395 | 8584.0 / 85825 | 0.1 / 16.3 (16.2, 17.0) | 163 | 34169 | ok / NO |
| P-term-brand-common.p1.name | hits 60168 → 601577 | products ×10.0 | 69 / 232318 / 2322781 | 229152.0 / 2291126 | 0.2 / 870.1 (875.6, 1027.6) | 8700 | 1161898 | NO / NO |
| P-term-brand-common.p2.name | hits 60168 → 60168 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-term-brand-common.p1.-updatedAt | hits 60168 → 60168 | const ×1.0 | 49 / 569 / 569 | 585.0 / 585 | 0.1 / 1.1 (2.4, 2.4) | 1 | 1 | ok / ok |
| P-term-brand-common.p2.-updatedAt | hits 60168 → 60168 | const ×1.0 | 34 / 307 / 307 | 302.0 / 302 | 0.1 / 0.7 (1.7, 1.7) | 1 | 1 | ok / ok |
| P-term-brand-common.count | hits 60168 → 60168 | const ×1.0 | 36 / 265014 / 265014 | 312675.0 / 312675 | 1.0 / 205.2 (215.8, 215.8) | 205 | 205 | ok / ok |
| P-term-brand-mid.p1.name | hits 17200 → 171970 | products ×10.0 | 60 / 607790 / 6076855 | 599513.0 / 5994100 | 0.3 / 2422.9 (2507.4, 2540.7) | 24225 | 3054460 | NO / NO |
| P-term-brand-mid.p2.name | hits 17200 → 17200 | const ×1.0 | 50 / 29 / 29 | 26.0 / 26 | 0.3 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| P-term-brand-mid.p1.-updatedAt | hits 17200 → 17200 | const ×1.0 | 60 / 2209 / 2209 | 2217.0 / 2217 | 0.2 / 3.3 (7.0, 7.0) | 3 | 3 | ok / ok |
| P-term-brand-mid.p2.-updatedAt | hits 17200 → 17200 | const ×1.0 | 41 / 1666 / 1666 | 1657.0 / 1657 | 0.2 / 2.2 (3.5, 3.5) | 2 | 2 | ok / ok |
| P-term-brand-mid.count | hits 17200 → 171970 | products ×10.0 | 54 / 13584 / 135817 | 12539.0 / 125368 | 0.2 / 39.9 (71.7, 71.7) | 399 | 60115 | ok / NO |
| P-term-polish-lower.p1.name | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.2 / 21.9 (45.3, 45.3) | 219 | 42183 | ok / NO |
| P-term-polish-lower.p2.name | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 22.8 (21.2, 23.7) | 228 | 42192 | ok / NO |
| P-term-polish-lower.p1.-updatedAt | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.1 / 20.5 (20.0, 22.6) | 205 | 42169 | ok / NO |
| P-term-polish-lower.p2.-updatedAt | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 20.9 (20.1, 23.2) | 209 | 42174 | ok / NO |
| P-term-polish-lower.count | hits 10067 → 100653 | products ×10.0 | 30 / 9962 / 99603 | 10001.0 / 99993 | 0.1 / 19.4 (19.1, 21.0) | 194 | 41803 | ok / NO |
| P-term-polish-upper.p1.name | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.2 / 21.4 (20.9, 22.6) | 214 | 42178 | ok / NO |
| P-term-polish-upper.p2.name | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 22.3 (22.7, 24.3) | 223 | 42187 | ok / NO |
| P-term-polish-upper.p1.-updatedAt | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.1 / 20.3 (20.1, 21.8) | 203 | 42167 | ok / NO |
| P-term-polish-upper.p2.-updatedAt | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 22.4 (20.1, 24.3) | 224 | 42188 | ok / NO |
| P-term-polish-upper.count | hits 10067 → 100653 | products ×10.0 | 30 / 9962 / 99603 | 10001.0 / 99993 | 0.1 / 19.8 (19.2, 21.2) | 197 | 41807 | ok / NO |
| P-term-polish-title.p1.name | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.2 / 22.4 (20.3, 23.5) | 224 | 42189 | ok / NO |
| P-term-polish-title.p2.name | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 22.6 (22.4, 23.3) | 226 | 42190 | ok / NO |
| P-term-polish-title.p1.-updatedAt | hits 10067 → 100653 | products ×10.0 | 36 / 10033 / 100313 | 10067.0 / 100653 | 0.1 / 20.4 (19.2, 21.8) | 204 | 42169 | ok / NO |
| P-term-polish-title.p2.-updatedAt | hits 10067 → 100653 | products ×10.0 | - / 10033 / 100313 | 10067.0 / 100653 | - / 21.7 (23.1, 23.1) | 217 | 42181 | ok / NO |
| P-term-polish-title.count | hits 10067 → 100653 | products ×10.0 | 30 / 9962 / 99603 | 10001.0 / 99993 | 0.1 / 19.5 (18.9, 20.5) | 195 | 41805 | ok / NO |
| P-combo-small-cat+common.p1.name | hits 0 → 0 | products ×10.0 | 2120 / 1014124 / 10139497 | 1000551.0 / 10003790 | 1.9 / 4088.5 (3868.7, 4110.8) | 40878 | 5102434 | NO / NO |
| P-combo-small-cat+common.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 775 / 1004352 / 10041794 | 1000551.0 / 10003790 | 1.7 / 1435.2 (1426.4, 1479.7) | 14350 | 5027054 | NO / NO |
| P-combo-small-cat+common.count | hits 0 → 0 | products ×10.0 | 202 / 91061 / 910453 | 262250.0 / 2622049 | 0.5 / 574.1 (595.8, 620.6) | 5740 | 460922 | NO / NO |
| P-combo-small-cat+drafts.p1.name | hits 0 → 0 | products ×10.0 | 61 / 20404 / 204005 | 20468.0 / 204645 | 0.2 / 99.8 (102.0, 114.6) | 997 | 97016 | ok / NO |
| P-combo-small-cat+drafts.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 61 / 20272 / 202685 | 20468.0 / 204645 | 0.2 / 87.0 (37.9, 104.1) | 870 | 94404 | ok / NO |
| P-combo-small-cat+drafts.count | hits 0 → 0 | products ×10.0 | 59 / 20272 / 202685 | 20468.0 / 204645 | 0.2 / 66.9 (46.0, 99.8) | 668 | 93819 | ok / NO |
| P-combo-late-leaf+type.p1.name | hits 0 → 0 | products ×10.0 | 46 / 1015040 / 10148655 | 1001911.0 / 10017388 | 0.2 / 4049.5 (3982.4, 4173.3) | 40488 | 5106624 | NO / NO |
| P-combo-late-leaf+type.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 46 / 1005268 / 10050952 | 1001911.0 / 10017388 | 0.2 / 1366.3 (1331.6, 1384.5) | 13661 | 5030945 | NO / NO |
| P-combo-late-leaf+type.count | hits 0 → 0 | products ×10.0 | 40 / 9579 / 95774 | 10345.0 / 103432 | 0.2 / 44.5 (68.7, 68.7) | 445 | 40140 | ok / NO |
| P-combo-mid-cat+brand.p1.name | hits 0 → 0 | products ×10.0 | 2120 / 1015194 / 10150195 | 1002435.0 / 10022627 | 2.0 / 3675.4 (3487.7, 3764.7) | 36747 | 5103653 | NO / NO |
| P-combo-mid-cat+brand.p1.-updatedAt | hits 0 → 0 | products ×10.0 | 775 / 1005422 / 10052492 | 1002435.0 / 10022627 | 1.7 / 1483.2 (1396.0, 1521.6) | 14829 | 5032883 | NO / NO |
| P-combo-mid-cat+brand.count | hits 0 → 0 | products ×10.0 | 468 / 54792 / 547826 | 62412.0 / 624013 | 3.0 / 377.6 (383.4, 492.8) | 3775 | 274784 | NO / NO |
| P-combo-drafts.p1.name | hits 20108 → 20108 | const ×1.0 | 27 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-drafts.p2.name | hits 20108 → 20108 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-drafts.p1.-updatedAt | hits 20108 → 20108 | const ×1.0 | 27 / 28 / 28 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-drafts.p2.-updatedAt | hits 20108 → 20108 | const ×1.0 | 22 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-drafts.count | hits 20108 → 20108 | const ×1.0 | 2 / 8427 / 8427 | 10001.0 / 10001 | 0.0 / 1.4 (1.7, 1.7) | 1 | 1 | ok / ok |
| P-combo-published.p1.name | hits 980083 → 980083 | const ×1.0 | 23 / 30 / 30 | 27.0 / 27 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-published.p2.name | hits 980083 → 980083 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| P-combo-published.p1.-updatedAt | hits 980083 → 980083 | const ×1.0 | 22 / 26 / 26 | 26.0 / 26 | 0.0 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-combo-published.p2.-updatedAt | hits 980083 → 980083 | const ×1.0 | 20 / 26 / 26 | 26.0 / 26 | 0.0 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| P-combo-published.count | hits 980083 → 9799146 | products ×10.0 | 667 / 1889 / 18887 | 10196.0 / 101942 | 0.9 / 3.5 (64.9, 64.9) | 35 | 768 | ok / ok |
| P-combo-no-images.p1.name | hits 20003 → 20003 | const ×1.0 | 59 / 1087 / 1087 | 1071.0 / 1071 | 0.2 / 1.3 (4.1, 4.1) | 1 | 1 | ok / ok |
| P-combo-no-images.p2.name | hits 20003 → 20003 | const ×1.0 | 59 / 1076 / 1076 | 1059.0 / 1059 | 0.2 / 1.6 (4.9, 4.9) | 2 | 2 | ok / ok |
| P-combo-no-images.p1.-updatedAt | hits 20003 → 20003 | const ×1.0 | 59 / 1255 / 1255 | 1267.0 / 1267 | 0.2 / 1.5 (4.4, 4.4) | 1 | 1 | ok / ok |
| P-combo-no-images.p2.-updatedAt | hits 20003 → 20003 | const ×1.0 | 44 / 1125 / 1125 | 1117.0 / 1117 | 0.2 / 0.9 (1.7, 1.7) | 1 | 1 | ok / ok |
| P-combo-no-images.count | hits 20003 → 20003 | const ×1.0 | 11 / 10809 / 10809 | 10001.0 / 10001 | 0.1 / 4.1 (11.8, 11.8) | 4 | 4 | ok / ok |
| P-combo-no-images+brand.p1.name | hits 1239 → 12388 | products ×10.0 | 59 / 234041 / 2340008 | 230854.0 / 2308143 | 0.2 / 946.6 (1013.0, 1014.1) | 9464 | 1171276 | NO / NO |
| P-combo-no-images+brand.p2.name | hits 1239 → 1239 | const ×1.0 | 22 / 1085 / 1085 | 1069.0 / 1069 | 0.1 / 2.5 (6.1, 6.1) | 3 | 3 | ok / ok |
| P-combo-no-images+brand.p1.-updatedAt | hits 1239 → 1239 | const ×1.0 | 59 / 25488 / 25488 | 25394.0 / 25394 | 0.2 / 30.2 (55.0, 55.0) | 30 | 30 | ok / ok |
| P-combo-no-images+brand.p2.-updatedAt | hits 1239 → 1239 | const ×1.0 | 22 / 23446 / 23446 | 23319.0 / 23319 | 0.1 / 27.2 (43.7, 43.7) | 27 | 27 | ok / ok |
| P-combo-no-images+brand.count | hits 1239 → 12388 | products ×10.0 | 53 / 7260 / 72588 | 1239.0 / 12388 | 0.2 / 36.5 (54.9, 54.9) | 365 | 28467 | ok / NO |
| P-combo-no-images+rare.p1.name | hits 46 → 460 | products ×10.0 | 19 / 3228 / 32274 | 2980.0 / 29795 | 0.1 / 7.6 (15.6, 15.6) | 76 | 8021 | ok / NO |
| P-combo-no-images+rare.p2.name | hits 46 → 460 | products ×10.0 | - / 3228 / 32274 | 2980.0 / 29795 | - / 7.4 (7.5, 7.7) | 74 | 8019 | ok / NO |
| P-combo-no-images+rare.p1.-updatedAt | hits 46 → 460 | products ×10.0 | 19 / 3228 / 32274 | 2980.0 / 29795 | 0.1 / 7.2 (7.3, 8.2) | 72 | 8017 | ok / NO |
| P-combo-no-images+rare.p2.-updatedAt | hits 46 → 460 | products ×10.0 | - / 3228 / 32274 | 2980.0 / 29795 | - / 7.3 (7.5, 7.6) | 73 | 8018 | ok / NO |
| P-combo-no-images+rare.count | hits 46 → 460 | products ×10.0 | 13 / 3222 / 32214 | 2980.0 / 29795 | 0.1 / 7.2 (7.2, 7.5) | 72 | 7988 | ok / NO |
| P-combo-no-images+dept.p1.name | hits 4172 → 41713 | products ×10.0 | 73 / 1092 / 10918 | 1251.0 / 12508 | 0.3 / 1.6 (5.0, 5.0) | 16 | 16 | ok / ok |
| P-combo-no-images+dept.p2.name | hits 4172 → 41713 | products ×10.0 | - / 1081 / 10808 | 1239.0 / 12388 | - / 1.8 (4.9, 4.9) | 18 | 18 | ok / ok |
| P-combo-no-images+dept.p1.-updatedAt | hits 4172 → 41713 | products ×10.0 | 73 / 6430 / 64289 | 205498.0 / 2054627 | 0.3 / 20.9 (29.2, 29.2) | 209 | 24161 | ok / NO |
| P-combo-no-images+dept.p2.-updatedAt | hits 4172 → 41713 | products ×10.0 | - / 8157 / 81556 | 207194.0 / 2071584 | - / 21.3 (25.7, 25.7) | 213 | 32799 | ok / NO |
| P-combo-no-images+dept.count | hits 4172 → 41713 | products ×10.0 | 67 / 26155 / 261505 | 220555.0 / 2205171 | 0.3 / 130.8 (98.4, 161.4) | 1307 | 132010 | ok / NO |
| P-combo-drafts+dept.p1.name | hits 4116 → 41153 | products ×10.0 | 61 / 34 / 340 | 206.0 / 2060 | 0.2 / 0.1 (0.2, 0.2) | 1 | 1 | ok / ok |
| P-combo-drafts+dept.p2.name | hits 4116 → 41153 | products ×10.0 | - / 34 / 340 | 206.0 / 2060 | - / 0.1 (0.2, 0.2) | 1 | 1 | ok / ok |
| P-combo-drafts+dept.p1.-updatedAt | hits 4116 → 41153 | products ×10.0 | 61 / 1638 / 16377 | 200703.0 / 2006685 | 0.2 / 16.5 (18.0, 18.0) | 165 | 165 | ok / ok |
| P-combo-drafts+dept.p2.-updatedAt | hits 4116 → 41153 | products ×10.0 | - / 1599 / 15987 | 200664.0 / 2006295 | - / 16.4 (16.6, 16.9) | 164 | 164 | ok / ok |
| P-combo-drafts+dept.count | hits 4116 → 41153 | products ×10.0 | 59 / 21744 / 217403 | 220660.0 / 2206221 | 0.2 / 132.3 (116.7, 146.1) | 1323 | 102310 | ok / NO |
| O-default.p1.-placedAt | page 25 → 25 | const ×1.0 | 84 / 110 / 110 | 83.97999999999999 / 84 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| O-default.p1.placedAt | page 25 → 25 | const ×1.0 | 84 / 112 / 112 | 75.92 / 76 | 0.1 / 0.1 (0.7, 0.7) | 0 | 0 | ok / ok |
| O-default.p1.-total | page 25 → 25 | const ×1.0 | 107 / 134 / 134 | 93.08 / 93 | 0.3 / 0.2 (2.4, 2.4) | 0 | 0 | ok / ok |
| O-default.p1.total | page 25 → 25 | const ×1.0 | 105 / 132 / 132 | 52.0 / 52 | 0.3 / 0.2 (2.7, 2.7) | 0 | 0 | ok / ok |
| O-default.deep | page 25 → 25 | const ×1.0 | 83 / 109 / 109 | 73.06 / 73 | 0.1 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| O-default.count | page 10000 → 10000 | const ×1.0 | 30 / 2685 / 2685 | 10001.0 / 10001 | 0.4 / 1.0 (2.4, 2.4) | 1 | 1 | ok / ok |
| O-status-paid.p1 | orders with the status 3047 → 3047 | const ×1.0 | 48 / 110 / 110 | 86.06 / 86 | 0.1 / 0.1 (0.7, 0.7) | 0 | 0 | ok / ok |
| O-status-paid.count | orders with the status 3047 → 3047 | const ×1.0 | 3 / 25 / 25 | 3047.0 / 3047 | 0.0 / 0.3 (0.4, 0.4) | 0 | 0 | ok / ok |
| O-status-delivered.p1 | orders with the status 891040 → 4455200 | orders ×5.0 | 86 / 390 / 1950 | 4042.1 / 20210 | 0.1 / 0.8 (2.9, 2.9) | 4 | 4 | ok / ok |
| O-status-delivered.count | orders with the status 891040 → 891040 | const ×1.0 | 44 / 209 / 209 | 10001.0 / 10001 | 0.4 / 1.1 (2.0, 2.0) | 1 | 1 | ok / ok |
| O-frag-common-surname.p1 | hits 5368 → 5368 | const ×1.0 | 249 / 523 / 523 | 5916.06 / 5916 | 0.6 / 4.9 (5.4, 5.4) | 5 | 5 | ok / ok |
| O-frag-common-surname.p2 | hits 5368 → 5368 | const ×1.0 | 249 / 355 / 355 | 3734.92 / 3735 | 0.6 / 3.1 (5.0, 5.0) | 3 | 3 | ok / ok |
| O-frag-common-surname.p1.total | hits 5368 → 5368 | const ×1.0 | 249 / 7515 / 7515 | 7432.06 / 7432 | 0.6 / 12.5 (41.4, 41.4) | 13 | 13 | ok / ok |
| O-frag-common-surname.count | hits 5368 → 26840 | orders ×5.0 | 165 / 5321 / 26605 | 5389.0 / 26945 | 0.4 / 13.1 (26.5, 26.5) | 66 | 5176 | ok / NO |
| O-frag-rare-surname.p1 | hits 301 → 1505 | orders ×5.0 | 23 / 662 / 3310 | 350.92 / 1755 | 0.1 / 3.4 (6.0, 6.0) | 17 | 17 | ok / ok |
| O-frag-rare-surname.p2 | hits 301 → 1505 | orders ×5.0 | - / 662 / 3310 | 351.96 / 1760 | - / 3.4 (6.0, 6.0) | 17 | 17 | ok / ok |
| O-frag-rare-surname.p1.total | hits 301 → 1505 | orders ×5.0 | 23 / 662 / 3310 | 370.94 / 1855 | 0.1 / 3.5 (5.8, 5.8) | 17 | 17 | ok / ok |
| O-frag-rare-surname.count | hits 301 → 1505 | orders ×5.0 | 14 / 552 / 2760 | 301.0 / 1505 | 0.1 / 3.2 (3.2, 3.4) | 16 | 16 | ok / ok |
| O-frag-domain.p1 | hits 1000000 → 1000000 | const ×1.0 | 84 / 110 / 110 | 83.97999999999999 / 84 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| O-frag-domain.p2 | hits 1000000 → 1000000 | const ×1.0 | 82 / 110 / 110 | 81.12 / 81 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| O-frag-domain.p1.total | hits 1000000 → 1000000 | const ×1.0 | 107 / 134 / 134 | 93.08 / 93 | 0.3 / 0.2 (0.2, 0.3) | 0 | 0 | ok / ok |
| O-frag-domain.count | hits 1000000 → 5000000 | orders ×5.0 | 285 / 638 / 3190 | 10001.0 / 50005 | 3.0 / 8.6 (8.6, 8.8) | 43 | 147 | ok / ok |
| O-frag-three-letters.p1 | hits 52044 → 52044 | const ×1.0 | 229 / 145 / 145 | 632.1 / 632 | 0.5 / 0.6 (0.7, 0.7) | 1 | 1 | ok / ok |
| O-frag-three-letters.p2 | hits 52044 → 52044 | const ×1.0 | 229 / 142 / 142 | 521.94 / 522 | 0.5 / 0.5 (0.9, 0.9) | 1 | 1 | ok / ok |
| O-frag-three-letters.p1.total | hits 52044 → 52044 | const ×1.0 | 231 / 525 / 525 | 489.06 / 489 | 0.6 / 1.1 (2.7, 2.7) | 1 | 1 | ok / ok |
| O-frag-three-letters.count | hits 52044 → 260220 | orders ×5.0 | 144 / 6710 / 33550 | 10001.0 / 50005 | 0.4 / 25.6 (41.0, 41.0) | 128 | 8711 | ok / NO |
| O-frag-none.p1 | hits 0 → 0 | orders ×5.0 | 15 / 19 / 95 | 0.0 / 0 | 0.1 / 0.1 (0.2, 0.2) | 1 | 1 | ok / ok |
| O-frag-none.p1.total | hits 0 → 0 | orders ×5.0 | 15 / 19 / 95 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| O-frag-none.count | hits 0 → 0 | orders ×5.0 | 9 / 13 / 65 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| O-frag-punct-1.p1 | hits 0 → 0 | orders ×5.0 | 291 / 76159 / 380795 | 999999.0 / 4999995 | 3.1 / 300.1 (295.5, 333.7) | 1501 | 183706 | ok / NO |
| O-frag-punct-1.p1.total | hits 0 → 0 | orders ×5.0 | 291 / 56260 / 281300 | 999999.0 / 4999995 | 3.5 / 241.3 (237.5, 247.4) | 1207 | 133665 | ok / NO |
| O-frag-punct-1.count | hits 0 → 0 | orders ×5.0 | 285 / 56170 / 280850 | 999999.0 / 4999995 | 3.7 / 242.1 (254.5, 254.5) | 1210 | 11541 | ok / NO |
| O-frag-punct-2.p1 | hits 0 → 0 | orders ×5.0 | 291 / 76156 / 380780 | 999999.0 / 4999995 | 3.3 / 309.8 (306.1, 320.7) | 1549 | 183747 | ok / NO |
| O-frag-punct-2.p1.total | hits 0 → 0 | orders ×5.0 | 291 / 56260 / 281300 | 999999.0 / 4999995 | 4.7 / 249.2 (253.6, 256.4) | 1246 | 133704 | ok / NO |
| O-frag-punct-2.count | hits 0 → 0 | orders ×5.0 | 285 / 56170 / 280850 | 999999.0 / 4999995 | 3.1 / 247.4 (254.8, 255.7) | 1237 | 11568 | ok / NO |
| O-frag-punct-3.p1 | hits 36835 → 36835 | const ×1.0 | 324 / 159 / 159 | 826.12 / 826 | 0.9 / 0.9 (1.1, 1.1) | 1 | 1 | ok / ok |
| O-frag-punct-3.p2 | hits 36835 → 36835 | const ×1.0 | 322 / 166 / 166 | 896.0 / 896 | 0.8 / 0.9 (1.7, 1.7) | 1 | 1 | ok / ok |
| O-frag-punct-3.p1.total | hits 36835 → 36835 | const ×1.0 | 325 / 770 / 770 | 728.98 / 729 | 0.9 / 2.0 (5.7, 5.7) | 2 | 2 | ok / ok |
| O-frag-punct-3.count | hits 36835 → 184175 | orders ×5.0 | 238 / 13291 / 66455 | 29060.0 / 145300 | 0.7 / 51.7 (60.0, 60.0) | 258 | 25294 | ok / NO |
| O-frag-punct-4.p1 | hits 0 → 0 | orders ×5.0 | 13 / 19 / 95 | 0.0 / 0 | 0.1 / 0.1 (0.2, 0.2) | 1 | 1 | ok / ok |
| O-frag-punct-4.p1.total | hits 0 → 0 | orders ×5.0 | 13 / 19 / 95 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.2) | 1 | 1 | ok / ok |
| O-frag-punct-4.count | hits 0 → 0 | orders ×5.0 | 7 / 13 / 65 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| O-frag-polish.p1 | hits 0 → 0 | orders ×5.0 | 9 / 10 / 50 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| O-frag-polish.p1.total | hits 0 → 0 | orders ×5.0 | 9 / 10 / 50 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.2) | 1 | 1 | ok / ok |
| O-frag-polish.count | hits 0 → 0 | orders ×5.0 | 3 / 4 / 20 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| O-frag-number-part.p1 | hits 5394 → 5394 | const ×1.0 | 224 / 669 / 669 | 8138.02 / 8138 | 1.3 / 6.7 (7.7, 7.7) | 7 | 7 | ok / ok |
| O-frag-number-part.p2 | hits 5394 → 5394 | const ×1.0 | 83 / 202 / 202 | 1479.06 / 1479 | 0.1 / 1.2 (1.6, 1.6) | 1 | 1 | ok / ok |
| O-frag-number-part.p1.total | hits 5394 → 5394 | const ×1.0 | 160 / 5985 / 5985 | 5897.06 / 5897 | 0.3 / 11.2 (22.9, 22.9) | 11 | 11 | ok / ok |
| O-frag-number-part.count | hits 5394 → 26970 | orders ×5.0 | 74 / 2643 / 13215 | 5394.0 / 26970 | 0.7 / 8.3 (14.7, 14.7) | 41 | 41 | ok / ok |
| O-frag-status.p1 | hits 4777 → 4777 | const ×1.0 | 249 / 901 / 901 | 11610.06 / 11610 | 0.6 / 4.4 (6.8, 6.8) | 4 | 4 | ok / ok |
| O-number.p1 | page 1 → 1 | const ×1.0 | 12 / 14 / 14 | 2.0 / 2 | 0.1 / 0.1 (0.4, 0.4) | 0 | 0 | ok / ok |
| O-email.p1 | page 1 → 1 | const ×1.0 | 12 / 14 / 14 | 2.0 / 2 | 0.1 / 0.1 (0.4, 0.4) | 0 | 0 | ok / ok |
| O-range.p1 | page 25 → 25 | const ×1.0 | 15 / 112 / 112 | 82.03999999999999 / 82 | 0.0 / 0.1 (0.4, 0.4) | 0 | 0 | ok / ok |
| O-range.year.count | page 10000 → 10000 | const ×1.0 | 56 / 116 / 116 | 10001.0 / 10001 | 0.5 / 1.0 (5.9, 5.9) | 1 | 1 | ok / ok |
| O-refund.p1 | pending refunds 0 → 0 | const ×1.0 | 28 / 132 / 132 | 69.94 / 70 | 0.1 / 0.2 (2.6, 2.6) | 0 | 0 | ok / ok |
| O-refund.count | pending refunds 0 → 0 | const ×1.0 | 2 / 3 / 3 | 160.0 / 160 | 0.0 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| C-default.p1.-lastOrderAt | page 25 → 25 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p2.-lastOrderAt | page 25 → 25 | const ×1.0 | 29 / 30 / 30 | 27.0 / 27 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p1.name | page 25 → 25 | const ×1.0 | 28 / 30 / 30 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p2.name | page 25 → 25 | const ×1.0 | 26 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.3, 0.3) | 0 | 0 | ok / ok |
| C-default.p1.-orderCount | page 25 → 25 | const ×1.0 | 24 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p2.-orderCount | page 25 → 25 | const ×1.0 | 19 / 32 / 32 | 29.0 / 29 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p1.-paidGross | page 25 → 25 | const ×1.0 | 27 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.p2.-paidGross | page 25 → 25 | const ×1.0 | 29 / 30 / 30 | 27.0 / 27 | 0.1 / 0.1 (0.2, 0.2) | 0 | 0 | ok / ok |
| C-default.count | page 10000 → 10000 | const ×1.0 | 32 / 373 / 373 | 10001.0 / 10001 | 0.1 / 0.9 (1.7, 1.7) | 1 | 1 | ok / ok |
| C-frag-common-surname.p1 | hits 234 → 2089 | customers ×8.93 | 38 / 255 / 2277 | 234.0 / 2089 | 0.5 / 0.9 (1.7, 1.7) | 8 | 8 | ok / ok |
| C-frag-common-surname.p2 | hits 234 → 2089 | customers ×8.93 | - / 255 / 2277 | 234.0 / 2089 | - / 0.8 (0.8, 0.9) | 8 | 8 | ok / ok |
| C-frag-common-surname.count | hits 234 → 2089 | customers ×8.93 | 32 / 249 / 2223 | 234.0 / 2089 | 0.5 / 0.8 (0.8, 0.8) | 7 | 7 | ok / ok |
| C-frag-rare-surname.p1 | hits 115 → 1027 | customers ×8.93 | 38 / 152 / 1357 | 115.0 / 1027 | 0.5 / 0.6 (1.1, 1.1) | 5 | 5 | ok / ok |
| C-frag-rare-surname.p2 | hits 115 → 1027 | customers ×8.93 | - / 152 / 1357 | 115.0 / 1027 | - / 0.5 (0.6, 0.6) | 5 | 5 | ok / ok |
| C-frag-rare-surname.count | hits 115 → 1027 | customers ×8.93 | 32 / 146 / 1304 | 115.0 / 1027 | 0.5 / 0.5 (0.5, 0.5) | 5 | 5 | ok / ok |
| C-frag-domain.p1 | hits 98570 → 98570 | const ×1.0 | 28 / 29 / 29 | 26.0 / 26 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| C-frag-domain.p2 | hits 98570 → 98570 | const ×1.0 | 29 / 30 / 30 | 27.0 / 27 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| C-frag-domain.count | hits 98570 → 880082 | customers ×8.93 | 32 / 373 / 3330 | 10001.0 / 89294 | 0.4 / 4.9 (4.9, 5.2) | 44 | 44 | ok / ok |
| C-frag-three-letters.p1 | hits 5121 → 5121 | const ×1.0 | 38 / 422 / 422 | 415.0 / 415 | 0.5 / 0.9 (1.7, 1.7) | 1 | 1 | ok / ok |
| C-frag-three-letters.p2 | hits 5121 → 5121 | const ×1.0 | 38 / 519 / 519 | 510.0 / 510 | 0.3 / 1.1 (2.0, 2.0) | 1 | 1 | ok / ok |
| C-frag-three-letters.count | hits 5121 → 45723 | customers ×8.93 | 32 / 2069 / 18473 | 5121.0 / 45723 | 0.5 / 5.5 (8.4, 8.4) | 49 | 1094 | ok / ok |
| C-frag-none.p1 | hits 0 → 0 | customers ×8.93 | 38 / 28 / 250 | 0.0 / 0 | 0.5 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| C-frag-none.count | hits 0 → 0 | customers ×8.93 | 32 / 22 / 196 | 0.0 / 0 | 0.5 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| C-frag-punct-1.p1 | hits 0 → 0 | customers ×8.93 | 38 / 3690 / 32946 | 98571.0 / 880091 | 0.5 / 25.1 (27.4, 27.4) | 224 | 871 | ok / ok |
| C-frag-punct-1.count | hits 0 → 0 | customers ×8.93 | 32 / 3598 / 32125 | 98571.0 / 880091 | 0.5 / 25.7 (25.3, 26.4) | 229 | 844 | ok / ok |
| C-frag-punct-2.p1 | hits 0 → 0 | customers ×8.93 | 38 / 3690 / 32946 | 98571.0 / 880091 | 0.5 / 25.2 (24.9, 26.2) | 225 | 872 | ok / ok |
| C-frag-punct-2.count | hits 0 → 0 | customers ×8.93 | 32 / 3598 / 32125 | 98571.0 / 880091 | 0.5 / 24.5 (24.5, 26.0) | 219 | 833 | ok / ok |
| C-frag-punct-3.p1 | hits 3440 → 3440 | const ×1.0 | 33 / 702 / 702 | 692.0 / 692 | 0.2 / 1.3 (1.3, 1.4) | 1 | 1 | ok / ok |
| C-frag-punct-3.p2 | hits 3440 → 3440 | const ×1.0 | 33 / 867 / 867 | 855.0 / 855 | 0.2 / 2.0 (2.4, 2.4) | 2 | 2 | ok / ok |
| C-frag-punct-3.count | hits 3440 → 30714 | customers ×8.93 | 27 / 2318 / 20696 | 10605.0 / 94687 | 0.2 / 11.2 (10.8, 11.5) | 100 | 2256 | ok / NO |
| C-frag-punct-4.p1 | hits 0 → 0 | customers ×8.93 | 38 / 26 / 232 | 0.0 / 0 | 0.5 / 0.2 (0.2, 0.2) | 1 | 1 | ok / ok |
| C-frag-punct-4.count | hits 0 → 0 | customers ×8.93 | 32 / 20 / 179 | 0.0 / 0 | 0.5 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| C-frag-polish.p1 | hits 0 → 0 | customers ×8.93 | 12 / 13 / 116 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| C-frag-polish.count | hits 0 → 0 | customers ×8.93 | 6 / 7 / 62 | 0.0 / 0 | 0.1 / 0.1 (0.1, 0.1) | 1 | 1 | ok / ok |
| C-frag-full-email.p1 | hits 1 → 9 | customers ×8.93 | 38 / 499 / 4455 | 1.0 / 9 | 0.5 / 3.2 (4.2, 4.2) | 29 | 29 | ok / ok |
| C-frag-full-email.count | hits 1 → 9 | customers ×8.93 | 32 / 493 / 4402 | 1.0 / 9 | 0.5 / 3.2 (3.2, 3.4) | 29 | 29 | ok / ok |
| X-discount-codes | active uses of the codes 70210 → 351050 | orders ×5.0 | 43 / 122 / 610 | 70227.0 / 351135 | 0.1 / 3.1 (3.6, 3.6) | 16 | 16 | ok / ok |
| X-category-counts | categories x bound 1001 180 → 180 | const ×1.0 | 785 / 2159 / 2159 | 105502.65000000001 / 105503 | 0.5 / 10.3 (15.2, 15.2) | 10 | 10 | ok / ok |
| X-dashboard-sales | hourly rows of 60 days 0 → 0 | const ×1.0 | 334 / 246 / 246 | 19343.0 / 19343 | 0.8 / 1.9 (2.7, 2.7) | 2 | 2 | ok / ok |
| X-dashboard-out-of-stock | bound 1001 → 1001 | const ×1.0 | 5 / 9 / 9 | 1001.0 / 1001 | 0.1 / 0.1 (0.4, 0.4) | 0 | 0 | ok / ok |
| X-dashboard-no-images | bound 1001 → 1001 | const ×1.0 | 11 / 1069 / 1069 | 1001.0 / 1001 | 0.1 / 0.4 (1.3, 1.3) | 0 | 0 | ok / ok |
| X-dashboard-recent-orders | page 5 → 5 | const ×1.0 | 21 / 28 / 28 | 19.98 / 20 | 0.1 / 0.1 (0.1, 0.1) | 0 | 0 | ok / ok |
| X-stock-low-count | bound 10000 → 10000 | const ×1.0 | 5 / 55 / 55 | 11738.0 / 11738 | 0.1 / 1.4 (3.7, 3.7) | 1 | 1 | ok / ok |
| X-sitemap-boundaries-M0 | products 1000191 → 10000191 | products ×10.0 | 24 / 840453 / 8403086 | 2000382.0 / 20000382 | 0.4 / 225.2 (254.5, 254.5) | 2252 | 4195603 | NO / NO |
| X-sitemap-chunk | chunk 25 → 25 | const ×1.0 | 6 / 4 / 4 | 1.0 / 1 | 0.0 / 0.0 (0.0, 0.0) | 0 | 0 | ok / ok |
