-- category big-early (15199 products), count
-- cost variable: products in the subtree = 15199 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY('{22652b43-0274-5fd4-b1b8-76cd4469ead0}'::uuid[]) UNION ALL SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY('{22652b43-0274-5fd4-b1b8-76cd4469ead0}'::uuid[]) AND NOT (p.primary_category_id = ANY('{22652b43-0274-5fd4-b1b8-76cd4469ead0}'::uuid[])) LIMIT 10001) bounded;
