-- without images, page 2, sort -updatedAt
-- cost variable: hits = 43 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb AND (p.updated_at, p.id) < (CAST('2025-03-22 03:12:51+00' AS timestamptz), CAST('388a8069-c0c2-5ed8-8c5f-eb01c5d7f71d' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
