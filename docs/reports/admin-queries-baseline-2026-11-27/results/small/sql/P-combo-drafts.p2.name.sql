-- drafts, page 2, sort name
-- cost variable: hits = 46 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' AND (p.name, p.id) > ('Ławka treningowa Valridor Jastrząb 500', CAST('63be659d-2da1-52ce-8b6f-afd3cbe6930d' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
