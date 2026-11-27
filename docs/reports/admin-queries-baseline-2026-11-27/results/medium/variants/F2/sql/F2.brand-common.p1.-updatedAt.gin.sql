-- phrase brand-common 'rowerowa', sort -updatedAt: the candidates from the bigrams
-- cost variable: hits = 60168 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('rowerowa') AND p.name ILIKE '%rowerowa%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
