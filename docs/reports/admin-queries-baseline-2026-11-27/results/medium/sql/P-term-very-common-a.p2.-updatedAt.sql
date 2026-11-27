-- phrase very-common-a 'pro', page 2, sort -updatedAt
-- cost variable: hits = 119269 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' AND (p.updated_at, p.id) < (CAST('2026-11-24 10:28:38+00' AS timestamptz), CAST('14b66190-4c83-5d40-a5b8-cef03c3b7caa' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
