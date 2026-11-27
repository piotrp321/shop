-- without images, page 2, sort -updatedAt
-- cost variable: hits = 20003 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb AND (p.updated_at, p.id) < (CAST('2026-11-11 07:07:54+00' AS timestamptz), CAST('aa5d3c6f-5002-5878-a4ee-1da603708c59' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
