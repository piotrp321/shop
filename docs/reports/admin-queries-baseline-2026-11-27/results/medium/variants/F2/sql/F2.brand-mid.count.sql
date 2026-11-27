-- phrase brand-mid 'treningowe', count by the candidates
-- cost variable: hits = 17200 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('treningowe') AND p.name ILIKE '%treningowe%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
