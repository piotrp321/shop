-- phrase two-common 'er', page 2, sort name
-- cost variable: hits = 258773 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%er%' AND (p.name, p.id) > ('Atlas szlaków Calvavon Trawers 300', CAST('ddb83a47-370d-5308-b65c-6c7f5ec4826b' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
