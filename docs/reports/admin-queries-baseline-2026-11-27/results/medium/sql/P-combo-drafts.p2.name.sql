-- drafts, page 2, sort name
-- cost variable: hits = 20108 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' AND (p.name, p.id) > ('Atlas szlaków Fenmizor Granit 900', CAST('f0635a7b-f00d-5b4a-a834-1737f36be977' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
