-- all products, page 2, sort name
-- cost variable: page = 25 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.name, p.id) > ('Atlas szlaków Alitar Jastrząb 100', CAST('eadda1d7-d96d-569e-83b9-ce65403f3b6d' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
