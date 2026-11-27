-- PERF-7-1 (I3): the names of the demo data have no punctuation, so phrases of punctuation WITH hits do not exist in them. This marks random products (not clustered in
-- the order of the names: TABLESAMPLE) with a token at the end of the name: '#!#' for 250 products (rare), ' a.b' for 12 000 (medium), ' qx' for 250 (a rare 2-character
-- phrase). Run on the COPY shop_proto only; `-v undo=1` takes the tokens off again.
\set ON_ERROR_STOP on
\if :{?undo}
UPDATE catalog_product SET name = regexp_replace(name, ' (#!#|a\.b|qx)$', '') WHERE name ~ ' (#!#|a\.b|qx)$';
\else
UPDATE catalog_product SET name = name || ' #!#' WHERE id IN (SELECT id FROM catalog_product TABLESAMPLE SYSTEM (0.05) WHERE name !~ ' (#!#|a\.b|qx)$' LIMIT 250);
UPDATE catalog_product SET name = name || ' a.b' WHERE id IN (SELECT id FROM catalog_product TABLESAMPLE SYSTEM (1.5) WHERE name !~ ' (#!#|a\.b|qx)$' LIMIT 12000);
UPDATE catalog_product SET name = name || ' qx' WHERE id IN (SELECT id FROM catalog_product TABLESAMPLE SYSTEM (0.05) WHERE name !~ ' (#!#|a\.b|qx)$' LIMIT 250);
\endif
ANALYZE catalog_product;
SELECT sum((name ~ '#!#$')::int) AS marked_hash, sum((name ~ 'a\.b$')::int) AS marked_ab, sum((name ~ ' qx$')::int) AS marked_qx FROM catalog_product;
