-- drafts, page 1, sort name
-- cost variable: hits = 46 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' ORDER BY p.name ASC, p.id ASC LIMIT 26;
