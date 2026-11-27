-- phrase type-late 'zupa', page 2, sort -updatedAt
-- cost variable: hits = 8584 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zupa%' AND (p.updated_at, p.id) < (CAST('2026-11-09 01:31:32+00' AS timestamptz), CAST('a8e88dfa-0efb-5916-9555-a8848c9d2dd2' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
