-- phrase very-common-a 'pro', sort name: the walk of 20000 rows
-- cost variable: budget = 20000 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.id IN (SELECT w.id FROM (SELECT id, name, updated_at FROM catalog_product ORDER BY name ASC, id ASC LIMIT 20000) w WHERE w.name ILIKE '%pro%') ORDER BY p.name ASC, p.id ASC LIMIT 26;
