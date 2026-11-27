-- without images, page 2, sort name
-- cost variable: hits = 20003 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb AND (p.name, p.id) > ('Atlas szlaków Fenmizor Step 800', CAST('e44e6bb6-3fdb-5dc7-bf44-3bde60e0ee15' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
