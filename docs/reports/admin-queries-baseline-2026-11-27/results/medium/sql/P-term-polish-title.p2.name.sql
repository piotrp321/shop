-- phrase polish-title 'Skała', page 2, sort name
-- cost variable: hits = 10067 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%Skała%' AND (p.name, p.id) > ('Atlas szlaków Orakos Skała 120', CAST('056362fb-b6f4-5dfe-9c4e-92852138a165' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
