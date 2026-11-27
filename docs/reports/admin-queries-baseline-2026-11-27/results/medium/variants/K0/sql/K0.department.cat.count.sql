-- category department (206849 products), count
-- cost variable: products in the subtree = 206849 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.id IN (SELECT id FROM catalog_product WHERE primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%') UNION SELECT product_id FROM catalog_product_category WHERE category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/%')) LIMIT 10001) bounded;
