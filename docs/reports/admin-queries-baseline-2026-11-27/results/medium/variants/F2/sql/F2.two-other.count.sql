-- phrase two-other 'zx', count by the candidates
-- cost variable: hits = 0 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('zx') AND p.name ILIKE '%zx%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
