-- published only, page 1, sort -updatedAt
-- cost variable: hits = 2145 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'published' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
