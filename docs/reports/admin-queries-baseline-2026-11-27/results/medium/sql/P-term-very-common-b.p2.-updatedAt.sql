-- phrase very-common-b 'ka', page 2, sort -updatedAt
-- cost variable: hits = 459399 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' AND (p.updated_at, p.id) < (CAST('2026-11-27 12:59:13+00' AS timestamptz), CAST('6e9d2963-8d00-5780-aa0d-5ee0ba85e433' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
