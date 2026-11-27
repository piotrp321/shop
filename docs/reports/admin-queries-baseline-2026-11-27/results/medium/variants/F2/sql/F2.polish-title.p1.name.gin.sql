-- phrase polish-title 'Skała', sort name: the candidates from the bigrams
-- cost variable: hits = 10067 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE perf_bigrams(p.name) @> perf_bigrams('Skała') AND p.name ILIKE '%Skała%') SELECT * FROM m ORDER BY name ASC, id ASC LIMIT 26;
