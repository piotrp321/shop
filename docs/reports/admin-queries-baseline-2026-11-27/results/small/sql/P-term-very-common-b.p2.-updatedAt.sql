-- phrase very-common-b 'ka', page 2, sort -updatedAt
-- cost variable: hits = 1008 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' AND (p.updated_at, p.id) < (CAST('2026-11-27 11:58:49+00' AS timestamptz), CAST('4a547c10-d89a-5ed3-ab4f-4380ff7bbad9' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
