-- phrase very-common-a 'pro', sort -updatedAt: the candidates from the bigrams
-- cost variable: hits = 119269 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('pro') AND p.name ILIKE '%pro%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
