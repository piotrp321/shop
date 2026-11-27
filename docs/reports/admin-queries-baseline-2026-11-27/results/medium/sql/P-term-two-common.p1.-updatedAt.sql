-- phrase two-common 'er', page 1, sort -updatedAt
-- cost variable: hits = 258773 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%er%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
