-- phrase brand-common 'z', page 2, sort -updatedAt
-- cost variable: hits = 1239 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%z%' AND (p.updated_at, p.id) < (CAST('2026-11-27 11:58:48+00' AS timestamptz), CAST('1e4b9bec-4bfa-58d0-a342-6af270827712' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
