-- phrase very-common-a 'pro', page 1, sort -updatedAt
-- cost variable: hits = 119269 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
