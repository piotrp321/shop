-- phrase two-common 'er', count by the candidates
-- cost variable: hits = 258773 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('er') AND p.name ILIKE '%er%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
