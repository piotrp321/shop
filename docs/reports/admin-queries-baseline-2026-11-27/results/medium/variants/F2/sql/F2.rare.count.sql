-- phrase rare 'ravotar', count by the candidates
-- cost variable: hits = 2980 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('ravotar') AND p.name ILIKE '%ravotar%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
