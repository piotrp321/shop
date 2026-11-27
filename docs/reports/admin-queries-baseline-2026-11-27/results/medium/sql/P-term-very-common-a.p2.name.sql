-- phrase very-common-a 'pro', page 2, sort name
-- cost variable: hits = 119269 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' AND (p.name, p.id) > ('Atlas szlaków Calamek Opal 800 Pro', CAST('1dccb2eb-9690-599b-a701-72ff0e2e22f7' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
