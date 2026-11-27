-- phrase type-early 'bagażnik', page 2, sort name
-- cost variable: hits = 8625 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%bagażnik%' AND (p.name, p.id) > ('Bagażnik rowerowy Alavar Horyzont 600 Pro', CAST('23ab782d-72d1-590a-ba32-6e0871c7d516' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
