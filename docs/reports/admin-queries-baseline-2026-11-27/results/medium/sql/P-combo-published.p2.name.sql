-- published only, page 2, sort name
-- cost variable: hits = 980083 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'published' AND (p.name, p.id) > ('Atlas szlaków Alitar Jastrząb 400', CAST('bb9a7662-3492-5ee8-a56c-6df48c5110c0' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
