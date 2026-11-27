-- published only, page 2, sort name
-- cost variable: hits = 2145 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'published' AND (p.name, p.id) > ('Baton proteinowy Calavik Kocioł 800 Pro', CAST('0bf438a6-84be-53b6-960a-90aba1e742bc' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
