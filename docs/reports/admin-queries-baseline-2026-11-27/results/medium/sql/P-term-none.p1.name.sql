-- phrase none 'zzqqxx', page 1, sort name
-- cost variable: hits = 0 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zzqqxx%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
