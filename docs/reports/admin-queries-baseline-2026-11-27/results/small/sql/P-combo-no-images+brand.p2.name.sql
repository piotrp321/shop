-- without images + brand, page 2, sort name
-- cost variable: hits = 26 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%z%' AND p.images = '[]'::jsonb AND (p.name, p.id) > ('Talerze żeliwne Isven Szlak 200', CAST('d212e450-75e6-5085-a0af-7a618a92dd6c' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
