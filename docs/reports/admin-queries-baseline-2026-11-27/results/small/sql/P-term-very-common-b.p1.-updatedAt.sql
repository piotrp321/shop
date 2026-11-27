-- phrase very-common-b 'ka', page 1, sort -updatedAt
-- cost variable: hits = 1008 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
