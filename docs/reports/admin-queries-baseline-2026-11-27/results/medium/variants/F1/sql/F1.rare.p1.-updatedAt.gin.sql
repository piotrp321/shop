-- phrase rare 'ravotar', sort -updatedAt: the candidates from the trigrams
-- cost variable: hits = 2980 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
