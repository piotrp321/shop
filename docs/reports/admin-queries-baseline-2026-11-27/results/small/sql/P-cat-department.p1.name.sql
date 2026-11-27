-- category department (the biggest department; 444 products), page 1, sort name
-- cost variable: products in the subtree = 444 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%') OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%'))) ORDER BY p.name ASC, p.id ASC LIMIT 26;
