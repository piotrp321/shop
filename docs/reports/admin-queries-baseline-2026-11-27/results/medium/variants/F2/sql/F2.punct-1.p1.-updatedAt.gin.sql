-- phrase punct-1 '#!#', sort -updatedAt: the candidates from the bigrams
-- cost variable: hits = 0 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('#!#') AND p.name ILIKE '%#!#%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
