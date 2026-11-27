-- category tree with counts
-- cost variable: categories x bound 1001 = 180 (scales with const)
SELECT c.id, (SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id UNION ALL SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = c.id LIMIT 1001) t) AS products FROM catalog_category c;
