-- phrase type-early 'bagażnik', sort -updatedAt: the candidates from the bigrams
-- cost variable: hits = 8625 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('bagażnik') AND p.name ILIKE '%bagażnik%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
