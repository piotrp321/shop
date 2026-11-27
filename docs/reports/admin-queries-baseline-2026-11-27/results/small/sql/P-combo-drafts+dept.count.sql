-- drafts in the biggest department, count of the first page
-- cost variable: hits = 6 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%') OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%'))) AND p.status = 'draft' LIMIT 10001) bounded;
