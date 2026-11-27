-- phrase type-late 'worek', page 1, sort -updatedAt
-- cost variable: hits = 12 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%worek%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
