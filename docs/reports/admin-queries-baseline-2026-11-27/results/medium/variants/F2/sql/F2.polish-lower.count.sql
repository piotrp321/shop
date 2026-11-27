-- phrase polish-lower 'skała', count by the candidates
-- cost variable: hits = 10067 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('skała') AND p.name ILIKE '%skała%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
