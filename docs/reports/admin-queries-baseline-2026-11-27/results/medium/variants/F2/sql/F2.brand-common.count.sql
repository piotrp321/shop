-- phrase brand-common 'rowerowa', count by the candidates
-- cost variable: hits = 60168 (scales with products)
WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('rowerowa') AND p.name ILIKE '%rowerowa%') SELECT count(*) FROM (SELECT * FROM m LIMIT 10001) bounded;
