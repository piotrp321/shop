-- without images + brand, page 1, sort name
-- cost variable: hits = 26 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%z%' AND p.images = '[]'::jsonb ORDER BY p.name ASC, p.id ASC LIMIT 26;
