-- phrase type-early 'bagażnik', sort name: the candidates from the trigrams
-- cost variable: hits = 8625 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%bagażnik%') SELECT * FROM m ORDER BY name ASC, id ASC LIMIT 26;
