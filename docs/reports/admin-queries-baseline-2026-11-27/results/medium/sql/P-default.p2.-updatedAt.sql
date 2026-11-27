-- all products, page 2, sort -updatedAt
-- cost variable: page = 25 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.updated_at, p.id) < (CAST('2026-11-27 12:59:13+00' AS timestamptz), CAST('9e324333-a38d-5c2f-9590-23ea5f1e40f5' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
