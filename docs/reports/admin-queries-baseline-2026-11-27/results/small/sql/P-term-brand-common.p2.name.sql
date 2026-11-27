-- phrase brand-common 'z', page 2, sort name
-- cost variable: hits = 1239 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%z%' AND (p.name, p.id) > ('Bidon termiczny Dravik Źródło 750', CAST('bff90e7f-b5fc-5877-a3b7-5c5316735b01' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
