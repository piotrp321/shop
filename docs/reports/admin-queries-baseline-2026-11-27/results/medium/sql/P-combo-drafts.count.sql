-- drafts, count of the first page
-- cost variable: hits = 20108 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.status = 'draft' LIMIT 10001) bounded;
