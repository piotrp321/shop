-- without images + rare phrase, page 2, sort name
-- cost variable: hits = 46 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%' AND p.images = '[]'::jsonb AND (p.name, p.id) > ('Kijki narciarskie Ravotar Fala 500', CAST('a3794dd5-035a-5104-b206-f00d1108200e' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
