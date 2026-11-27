-- phrase polish-upper 'SKAŁA', page 2, sort -updatedAt
-- cost variable: hits = 10067 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%SKAŁA%' AND (p.updated_at, p.id) < (CAST('2026-11-10 07:03:37+00' AS timestamptz), CAST('9c89c817-3fad-50d6-a762-e26dc221ffa6' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
