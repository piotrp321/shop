-- phrase punct-4 'ab-cd', sort -updatedAt: the candidates from the trigrams
-- cost variable: hits = 0 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ab-cd%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
