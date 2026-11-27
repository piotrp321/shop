-- phrase type-late 'zupa', page 2, sort name
-- cost variable: hits = 8584 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zupa%' AND (p.name, p.id) > ('Zupa liofilizowana Alitar Delta 200 Lite', CAST('d8745d78-d66e-5cb9-bfd2-dad5332b46fc' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
