-- phrase brand-mid 'treningowe', page 2, sort -updatedAt
-- cost variable: hits = 35 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%treningowe%' AND (p.updated_at, p.id) < (CAST('2024-10-11 02:09:13+00' AS timestamptz), CAST('3966d8eb-408e-568d-afdc-7af7348af3ee' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
