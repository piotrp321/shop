-- PERF-7-1 (I2): the count of the department without the bound of 10 001, by the present statement, by the old formula of K1 (the memberships joined, so a
-- product with two additional categories in the subtree counts twice) and by the final formula (a semi-join). On shop_proto with the synthetic product.
\set ON_ERROR_STOP on
CREATE TEMP TABLE ids AS SELECT id FROM catalog_category WHERE path LIKE (SELECT path || '%' FROM catalog_category WHERE id = 'd7c098d0-4bae-595e-a1e1-e4a923448956');
SELECT 'present statement' AS formula, count(*) FROM catalog_product p WHERE p.primary_category_id IN (SELECT id FROM ids)
  OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT id FROM ids));
SELECT 'K1 old formula (join)' AS formula, (SELECT count(*) FROM catalog_product p WHERE p.primary_category_id IN (SELECT id FROM ids))
  + (SELECT count(*) FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id IN (SELECT id FROM ids) AND NOT (p.primary_category_id IN (SELECT id FROM ids)));
SELECT 'K1 final formula (semi-join)' AS formula, (SELECT count(*) FROM catalog_product p WHERE p.primary_category_id IN (SELECT id FROM ids))
  + (SELECT count(*) FROM catalog_product p WHERE p.id IN (SELECT product_id FROM catalog_product_category WHERE category_id IN (SELECT id FROM ids)) AND NOT (p.primary_category_id IN (SELECT id FROM ids)));
