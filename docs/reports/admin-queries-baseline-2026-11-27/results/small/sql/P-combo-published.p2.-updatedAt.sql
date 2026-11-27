-- published only, page 2, sort -updatedAt
-- cost variable: hits = 2145 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'published' AND (p.updated_at, p.id) < (CAST('2026-11-27 11:58:49+00' AS timestamptz), CAST('a78579df-7a24-5bdb-acd1-0480b32c2348' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
