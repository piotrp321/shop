\timing on
-- The same results as the verifier's queries (without the exclusion of the 191 seed products), other plans.
-- Q4: published products without variants, with the planner kept from nested loops.
SET enable_nestloop = off;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT p.slug FROM catalog_product p WHERE p.status = 'published' AND NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.product_id = p.id) ORDER BY p.slug LIMIT 11;
-- Q2: variants without price history.
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT DISTINCT p.slug FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id WHERE NOT EXISTS (SELECT 1 FROM pricing_price_history t WHERE t.variant_id = v.id) ORDER BY p.slug LIMIT 11;
RESET enable_nestloop;
-- Q3: stock against the latest movement, latest movement per variant in one pass instead of a probe per row.
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT s.variant_id FROM inventory_stock_item s JOIN (SELECT DISTINCT ON (variant_id) variant_id, physical_after, reserved_after FROM inventory_stock_movement ORDER BY variant_id, occurred_at DESC, id DESC) l ON l.variant_id = s.variant_id WHERE (l.physical_after, l.reserved_after) IS DISTINCT FROM (s.physical, s.reserved) ORDER BY s.variant_id LIMIT 11;
-- Q1: current price against the latest history entry, the same way (the sample of every third variant kept).
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT DISTINCT p.slug FROM pricing_variant_price pr JOIN catalog_product_variant v ON v.id = pr.variant_id JOIN catalog_product p ON p.id = v.product_id
  JOIN (SELECT DISTINCT ON (variant_id) * FROM pricing_price_history ORDER BY variant_id, changed_at DESC, id DESC) l ON l.variant_id = pr.variant_id
  WHERE abs((hashtext(pr.variant_id::text))::bigint) % 3 = 0
    AND (l.gross_amount, l.net_amount, l.vat_amount, l.vat_rate_bp, l.tax_class, l.currency, l.changed_at) IS DISTINCT FROM (pr.gross_amount, pr.net_amount, pr.vat_amount, pr.vat_rate_bp, pr.tax_class, pr.currency, pr.updated_at)
  ORDER BY p.slug LIMIT 11;
