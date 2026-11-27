-- without images in the biggest department, page 1, sort -updatedAt
-- cost variable: hits = 5 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%') OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%'))) AND p.images = '[]'::jsonb ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
