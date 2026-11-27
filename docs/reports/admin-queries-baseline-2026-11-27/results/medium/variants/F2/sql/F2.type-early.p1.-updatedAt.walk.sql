-- phrase type-early 'bagażnik', sort -updatedAt: the walk of 20000 rows
-- cost variable: budget = 20000 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.id IN (SELECT w.id FROM (SELECT id, name, updated_at FROM catalog_product ORDER BY updated_at DESC, id DESC LIMIT 20000) w WHERE w.name ILIKE '%bagażnik%') ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
