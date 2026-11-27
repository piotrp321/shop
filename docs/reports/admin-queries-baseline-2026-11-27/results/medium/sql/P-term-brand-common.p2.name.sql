-- phrase brand-common 'rowerowa', page 2, sort name
-- cost variable: hits = 60168 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%rowerowa%' AND (p.name, p.id) > ('Dętka rowerowa Alavar Fala 400 Plus', CAST('dc544e4c-01a3-54c3-9f59-cd884654e5a6' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
