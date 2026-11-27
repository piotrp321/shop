-- without images, page 2, sort name
-- cost variable: hits = 43 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb AND (p.name, p.id) > ('Koszulka z filtrem UV Dunegon Połonina 200 Evo', CAST('d391ebc5-a0b2-5295-97e9-60e41471e2e1' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
