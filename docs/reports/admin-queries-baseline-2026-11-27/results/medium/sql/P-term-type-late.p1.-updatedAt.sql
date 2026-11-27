-- phrase type-late 'zupa', page 1, sort -updatedAt
-- cost variable: hits = 8584 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zupa%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
