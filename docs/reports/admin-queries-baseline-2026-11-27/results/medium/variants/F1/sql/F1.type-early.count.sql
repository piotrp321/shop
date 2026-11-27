-- phrase type-early 'bagażnik', count by the candidates
-- cost variable: hits = 8625 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%bagażnik%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
