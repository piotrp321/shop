-- PERF-7-1 review, R2: the partial indexes of the tests of 10.2 and the probe table of R2a, on the COPY shop_proto only. `-v undo=1` drops them.
-- R2b (drafts): partial btrees by category. R2c (without images): the same predicate as catalog_product_without_images_idx. R2a: a probe table with the
-- main category of the product copied into the memberships, and a covering index, to read the additional-only products without touching the products.
\set ON_ERROR_STOP on
\timing on
\if :{?undo}
DROP INDEX IF EXISTS proto_r2_draft_name, proto_r2_draft_updated, proto_r2_noimg_name, proto_r2_noimg_updated;
DROP TABLE IF EXISTS pc_probe;
\else
CREATE INDEX proto_r2_draft_name ON catalog_product (primary_category_id, name, id) WHERE status = 'draft';
CREATE INDEX proto_r2_draft_updated ON catalog_product (primary_category_id, updated_at, id) WHERE status = 'draft';
CREATE INDEX proto_r2_noimg_name ON catalog_product (primary_category_id, name, id) WHERE images = '[]'::jsonb;
CREATE INDEX proto_r2_noimg_updated ON catalog_product (primary_category_id, updated_at, id) WHERE images = '[]'::jsonb;
CREATE TABLE pc_probe AS SELECT pc.category_id, pc.product_id, p.primary_category_id AS primary_category_id FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id;
CREATE INDEX pc_probe_idx ON pc_probe (category_id) INCLUDE (product_id, primary_category_id);
VACUUM ANALYZE pc_probe;
ANALYZE catalog_product;
SELECT 'sizes', pg_size_pretty(pg_relation_size('proto_r2_draft_name')) AS draft_name, pg_size_pretty(pg_relation_size('proto_r2_draft_updated')) AS draft_updated,
  pg_size_pretty(pg_relation_size('proto_r2_noimg_name')) AS noimg_name, pg_size_pretty(pg_relation_size('proto_r2_noimg_updated')) AS noimg_updated, pg_size_pretty(pg_relation_size('pc_probe_idx')) AS probe_index;
CREATE TEMP TABLE dept AS SELECT id FROM catalog_category WHERE path LIKE (SELECT path || '%' FROM catalog_category WHERE id = 'd7c098d0-4bae-595e-a1e1-e4a923448956');
\echo R2a: the additional-only products of the department from the probe table (index only)
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT DISTINCT product_id FROM pc_probe WHERE category_id IN (SELECT id FROM dept) AND NOT (primary_category_id IN (SELECT id FROM dept));
\endif
