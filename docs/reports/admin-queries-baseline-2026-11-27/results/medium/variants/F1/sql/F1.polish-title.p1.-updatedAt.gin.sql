-- phrase polish-title 'Skała', sort -updatedAt: the candidates from the trigrams
-- cost variable: hits = 10067 (scales with products)
WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%Skała%') SELECT * FROM m ORDER BY updated_at DESC, id DESC LIMIT 26;
