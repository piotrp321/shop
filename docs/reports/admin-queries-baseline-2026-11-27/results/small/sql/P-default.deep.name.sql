-- all products, a page in the middle of the names
-- cost variable: page = 25 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.name, p.id) > ('Legginsy biegowe Lorrok Kanion 250 Pro', CAST('16f1ffca-de1f-5edc-818c-cc62e342eb0e' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
