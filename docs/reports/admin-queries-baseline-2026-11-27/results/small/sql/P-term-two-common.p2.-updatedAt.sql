-- phrase two-common 'er', page 2, sort -updatedAt
-- cost variable: hits = 559 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%er%' AND (p.updated_at, p.id) < (CAST('2026-11-27 11:58:44+00' AS timestamptz), CAST('57dcd1f3-75b3-51ee-9df8-67703dccaddf' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
