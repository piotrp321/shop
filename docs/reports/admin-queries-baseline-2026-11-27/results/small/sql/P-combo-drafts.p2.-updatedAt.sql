-- drafts, page 2, sort -updatedAt
-- cost variable: hits = 46 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' AND (p.updated_at, p.id) < (CAST('2026-06-05 14:04:35+00' AS timestamptz), CAST('8e3d3fad-cb64-5c0d-ba46-cf676c05e6bb' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
