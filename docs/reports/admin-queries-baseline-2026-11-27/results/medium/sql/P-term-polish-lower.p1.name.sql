-- phrase polish-lower 'skała', page 1, sort name
-- cost variable: hits = 10067 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%skała%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
