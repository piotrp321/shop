-- phrase very-common-a 'pro', count by the candidates
-- cost variable: hits = 119269 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('pro') AND p.name ILIKE '%pro%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
