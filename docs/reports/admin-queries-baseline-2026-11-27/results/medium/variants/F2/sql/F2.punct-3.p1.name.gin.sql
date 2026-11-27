-- phrase punct-3 'a.b', sort name: the candidates from the bigrams
-- cost variable: hits = 0 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('a.b') AND p.name ILIKE '%a.b%') SELECT * FROM m ORDER BY name ASC, id ASC LIMIT 26;
