-- phrase brand-common 'rowerowa', page 2, sort -updatedAt
-- cost variable: hits = 60168 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%rowerowa%' AND (p.updated_at, p.id) < (CAST('2026-11-18 16:04:29+00' AS timestamptz), CAST('5f930b36-b391-5f76-bca8-67e30be5ae7d' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
