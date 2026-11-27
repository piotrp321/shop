-- PERF-7-1 (I2): a synthetic product that is in the subtree of the biggest department only through TWO additional categories (the domain allows up to
-- ten, ProductCategories::MAX_ADDITIONAL; the demo data never has two in one subtree). Its name sorts first and its updated_at is in the future, so it opens
-- the page of every sort. Run on the COPY shop_proto only; `-v undo=1` removes it again.
\set ON_ERROR_STOP on
\if :{?undo}
DELETE FROM catalog_product WHERE slug = 'aaa-synthetic-two-additional';
SELECT 'removed', count(*) FROM catalog_product WHERE slug = 'aaa-synthetic-two-additional';
\else
CREATE TEMP TABLE dept AS SELECT id FROM catalog_category WHERE path LIKE (SELECT path || '%' FROM catalog_category WHERE id = 'd7c098d0-4bae-595e-a1e1-e4a923448956');
CREATE TEMP TABLE donor AS SELECT * FROM catalog_product WHERE primary_category_id NOT IN (SELECT id FROM dept) LIMIT 1;
UPDATE donor SET id = uuidv7(), slug = 'aaa-synthetic-two-additional', name = 'AAA synthetic with two additional categories', updated_at = now() + interval '1 day';
INSERT INTO catalog_product SELECT * FROM donor;
INSERT INTO catalog_product_category SELECT d.id, c.id FROM donor d, (SELECT id FROM dept WHERE id <> 'd7c098d0-4bae-595e-a1e1-e4a923448956' ORDER BY id LIMIT 2) c;
SELECT 'synthetic', id, (SELECT count(*) FROM catalog_product_category WHERE product_id = donor.id) FROM donor;
\endif
