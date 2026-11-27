-- phrase two-rare 'qx', sort -updatedAt: the candidates from the bigrams
-- cost variable: hits = 0 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('qx') AND p.name ILIKE '%qx%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
