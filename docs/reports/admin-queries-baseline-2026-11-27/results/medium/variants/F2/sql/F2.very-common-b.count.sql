-- phrase very-common-b 'ka', count by the candidates
-- cost variable: hits = 459399 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('ka') AND p.name ILIKE '%ka%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
