-- phrase polish-title 'Skała', count by the candidates
-- cost variable: hits = 10067 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('Skała') AND p.name ILIKE '%Skała%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
