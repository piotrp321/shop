-- without images, page 1, sort -updatedAt
-- cost variable: hits = 43 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
