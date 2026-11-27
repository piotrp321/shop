-- without images + rare phrase, page 2, sort -updatedAt
-- cost variable: hits = 46 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%' AND p.images = '[]'::jsonb AND (p.updated_at, p.id) < (CAST('2025-01-14 05:43:26+00' AS timestamptz), CAST('497ae07e-eb1e-504d-abf4-f39275c0fddb' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
