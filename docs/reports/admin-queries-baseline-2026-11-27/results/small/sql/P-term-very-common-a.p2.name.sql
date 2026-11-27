-- phrase very-common-a 'pro', page 2, sort name
-- cost variable: hits = 251 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' AND (p.name, p.id) > ('Baton proteinowy Ravirik Dolina 100 Pro', CAST('1054193e-48b3-5105-bd91-1e57c3c18514' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
