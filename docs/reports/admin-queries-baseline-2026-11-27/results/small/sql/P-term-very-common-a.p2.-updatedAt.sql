-- phrase very-common-a 'pro', page 2, sort -updatedAt
-- cost variable: hits = 251 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' AND (p.updated_at, p.id) < (CAST('2026-07-22 22:07:20+00' AS timestamptz), CAST('19520c7d-3222-585c-a2d6-7e60550f8c34' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
