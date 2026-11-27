-- phrase two-common 'er', page 2, sort -updatedAt
-- cost variable: hits = 258773 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%er%' AND (p.updated_at, p.id) < (CAST('2026-11-27 12:59:10+00' AS timestamptz), CAST('a1d3632a-5eb6-5a3b-9a8a-9cbce0579f0e' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
