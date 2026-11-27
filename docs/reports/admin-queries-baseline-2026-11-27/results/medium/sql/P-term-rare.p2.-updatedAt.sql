-- phrase rare 'ravotar', page 2, sort -updatedAt
-- cost variable: hits = 2980 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%' AND (p.updated_at, p.id) < (CAST('2026-11-04 01:18:27+00' AS timestamptz), CAST('bcd13b01-2ad6-52a8-a40c-7fefce7555f3' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
