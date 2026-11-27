-- phrase rare 'ravotar', page 2, sort name
-- cost variable: hits = 2980 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%' AND (p.name, p.id) > ('Bielizna termoaktywna Ravotar Czapla 500 Pro', CAST('1297dc6e-a780-5777-a2b5-4f4a508c96b4' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
