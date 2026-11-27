-- phrase brand-mid 'treningowe', page 2, sort name
-- cost variable: hits = 17200 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%treningowe%' AND (p.name, p.id) > ('Pachołki treningowe Armidar Dystans 600', CAST('5b832e6f-1834-5c73-a02f-6b4517eefbf7' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
