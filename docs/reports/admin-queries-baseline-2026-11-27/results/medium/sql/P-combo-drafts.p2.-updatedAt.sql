-- drafts, page 2, sort -updatedAt
-- cost variable: hits = 20108 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' AND (p.updated_at, p.id) < (CAST('2026-11-26 11:01:54+00' AS timestamptz), CAST('27a6a021-936a-50a8-b100-96025ef74158' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
