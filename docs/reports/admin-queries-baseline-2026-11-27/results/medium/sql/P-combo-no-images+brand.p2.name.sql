-- without images + brand, page 2, sort name
-- cost variable: hits = 1239 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%rowerowa%' AND p.images = '[]'::jsonb AND (p.name, p.id) > ('Dętka rowerowa Emneik Start 350 Pro', CAST('cf190395-5690-5878-b553-a9df5ad1dbd5' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
