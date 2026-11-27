-- phrase brand-mid 'treningowe', page 2, sort -updatedAt
-- cost variable: hits = 17200 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%treningowe%' AND (p.updated_at, p.id) < (CAST('2026-11-10 05:20:19+00' AS timestamptz), CAST('ca99d9da-c86c-5a34-a12a-260a44769ec4' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
