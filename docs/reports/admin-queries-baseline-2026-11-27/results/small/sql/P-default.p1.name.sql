-- all products, page 1, sort name
-- cost variable: page = 25 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p ORDER BY p.name ASC, p.id ASC LIMIT 26;
