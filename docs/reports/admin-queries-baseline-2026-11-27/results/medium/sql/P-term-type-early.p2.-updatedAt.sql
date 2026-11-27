-- phrase type-early 'bagażnik', page 2, sort -updatedAt
-- cost variable: hits = 8625 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%bagażnik%' AND (p.updated_at, p.id) < (CAST('2026-11-09 03:38:22+00' AS timestamptz), CAST('14e27cfe-3835-593a-843f-3e3f3b725f2f' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
