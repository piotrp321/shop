-- phrase type-late 'zupa', count by the candidates
-- cost variable: hits = 8584 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%zupa%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
