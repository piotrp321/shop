-- phrase very-common-b 'ka', page 2, sort name
-- cost variable: hits = 1008 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' AND (p.name, p.id) > ('Butelka turystyczna Ilogen Klif 100', CAST('59e76268-d028-59d4-956e-7f027db6cb18' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
