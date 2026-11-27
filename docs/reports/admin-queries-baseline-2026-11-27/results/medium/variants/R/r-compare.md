| case | hits | before ms / buffers | after ms / buffers | plan after |
|---|---|---|---|---|
| R1.rowerowa.walk-to-26 | 60168 | 159.2 / 202726 | 157.9 / 202726 | Subquery Scan ; Index Only Scan catalog_product_name_id_idx |
| R1.snowboardowe.walk-to-26 | 12952 | 92.5 / 115217 | 92.2 / 115217 | Subquery Scan ; Index Only Scan catalog_product_name_id_idx |
| R1.pływacki.walk-to-26 | 25720 | 137.3 / 179691 | 140.1 / 179691 | Subquery Scan ; Index Only Scan catalog_product_name_id_idx |
| R1.zupa.walk-to-26 | 8584 | 21.0 / 9165 | 21.6 / 9165 | Subquery Scan ; Bitmap Heap Scan catalog_product; Bitmap Index Scan ca |
| R1.źródło.walk-to-26 | 10298 | 22.7 / 10862 | 2.5 / 2982 | Subquery Scan ; Index Only Scan catalog_product_name_id_idx |
| R1.bagażnik.walk-to-26 | 8625 | 3.0 / 3897 | 3.2 / 3897 | Subquery Scan ; Index Only Scan catalog_product_name_id_idx |
| R2.department.rare.p1.name | 0 | 119.2 / 30825 | 119.0 / 30825 | CTE Scan ; Bitmap Heap Scan catalog_product; Bitmap Index Scan catalog |
| R2.department.rare.p1.-updatedAt | 0 | 117.6 / 30825 | 120.4 / 30825 | CTE Scan ; Bitmap Heap Scan catalog_product; Bitmap Index Scan catalog |
| R2.department.rare.count | 0 | 16.7 / 17034 | 18.4 / 17034 | Bitmap Heap Scan catalog_product; Bitmap Index Scan catalog_product_na |
| R2.department.medium.p1.name | 0 | 404.1 / 46789 | 395.5 / 46830 | CTE Scan ; Bitmap Heap Scan catalog_product; Bitmap Index Scan catalog |
| R2.department.medium.p1.-updatedAt | 0 | 902.1 / 223192 | 895.6 / 223233 | CTE Scan ; Index Scan proto_k1_updated; Bitmap Heap Scan catalog_produ |
| R2.department.medium.count | 0 | 78.3 / 23623 | 74.0 / 23647 | Subquery Scan ; Bitmap Heap Scan catalog_product; Bitmap Index Scan ca |
| R2.department.drafts.p1.name | 0 | 160.2 / 30219 | 108.6 / 21970 | CTE Scan ; Index Scan proto_r2_draft_name; Bitmap Heap Scan catalog_pr |
| R2.department.drafts.p1.-updatedAt | 0 | 1094.8 / 350379 | 110.3 / 21936 | CTE Scan ; Index Scan proto_r2_draft_updated; Bitmap Heap Scan catalog |
| R2.department.drafts.count | 0 | 107.5 / 33767 | 13.4 / 22900 | Subquery Scan ; Index Only Scan proto_r2_draft_updated; Bitmap Heap Sc |
| R2.department.noimages.p1.name | 0 | 176.0 / 33207 | 42.0 / 21792 | CTE Scan ; Index Scan proto_r2_noimg_name; Bitmap Heap Scan catalog_pr |
| R2.department.noimages.p1.-updatedAt | 0 | 66.7 / 28662 | 41.7 / 21757 | CTE Scan ; Index Scan proto_r2_noimg_updated; Bitmap Heap Scan catalog |
| R2.department.noimages.count | 0 | 180.8 / 43877 | 10.6 / 6167 | Subquery Scan ; Index Only Scan proto_r2_noimg_updated; Bitmap Heap Sc |
| R2.department.drafts-noimages.p1.name | 0 | 73.7 / 14717 | 11.8 / 4064 | CTE Scan ; Index Scan proto_r2_noimg_name; Index Scan idx_8bebef264584 |
| R2.department.drafts-noimages.p1.-updatedAt | 0 | 71.4 / 14717 | 9.6 / 2639 | CTE Scan ; Index Scan proto_r2_noimg_updated; Index Scan idx_8bebef264 |
| R2.department.drafts-noimages.count | 0 | 65.2 / 18259 | 11.9 / 2037 | Bitmap Heap Scan catalog_product; Bitmap Index Scan catalog_product_dr |
| R2.small.drafts.p1.name | 0 | 9.4 / 1632 | 6.4 / 1480 | CTE Scan ; Index Scan proto_r2_draft_name; Index Scan catalog_product_ |
| R2.small.drafts.p1.-updatedAt | 0 | 9.4 / 1632 | 6.6 / 1479 | CTE Scan ; Index Scan proto_r2_draft_updated; Index Scan catalog_produ |
| R2a.department.additional-only.join | 41770 | 114.7 / 137073 | 120.2 / 137082 | Bitmap Heap Scan catalog_product_category; Bitmap Index Scan idx_8bebe |
