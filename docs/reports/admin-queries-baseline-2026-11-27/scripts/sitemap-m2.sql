-- M2 of PERF-7-1 (run on a COPY of the database: it changes the statistics target of catalog_product.id).
-- The candidate boundaries come from pg_stats.histogram_bounds of catalog_product.id (SET STATISTICS :target, then ANALYZE); a
-- candidate chunk is the range between boundaries that are about `chunk` rows apart (every :group-th bound), and every chunk is checked by
-- its count with LIMIT row_limit + 1 (an index-only scan of the primary key, at most row_limit + 1 entries). A chunk over the row limit
-- is cut by the keyset statement of M1. Result on the notices: the time of ANALYZE, the number of bounds, the chunks, the chunks over
-- the limit, the largest and the smallest chunk, the total time of the verification.
\set target 100
SET perf.chunk = '10000';
SET perf.row_limit = '50000';
\timing off
ALTER TABLE catalog_product ALTER COLUMN id SET STATISTICS :target;
\o /dev/null
SELECT now() AS analyze_started \gset
ANALYZE catalog_product;
SELECT round(extract(epoch FROM clock_timestamp() - :'analyze_started'::timestamptz) * 1000) AS analyze_ms \gset
\o
\echo ANALYZE ms: :analyze_ms
DO $$
DECLARE
  chunk constant int := current_setting('perf.chunk')::int;
  row_limit constant int := current_setting('perf.row_limit')::int;
  bounds text[];
  total_rows bigint;
  per_bound numeric;
  grouping int;
  candidates uuid[] := '{}';
  i int;
  n int;
  lower_id uuid;
  upper_id uuid;
  counted int;
  largest int := 0;
  smallest int := 2147483647;
  over int := 0;
  chunks int := 0;
  started timestamptz := clock_timestamp();
BEGIN
  SELECT histogram_bounds::text::text[] INTO bounds FROM pg_stats WHERE tablename = 'catalog_product' AND attname = 'id';
  SELECT reltuples::bigint INTO total_rows FROM pg_class WHERE relname = 'catalog_product';
  n := cardinality(bounds);
  per_bound := total_rows::numeric / (n - 1);
  grouping := greatest(1, round(chunk / per_bound)::int);
  FOR i IN 1..n BY grouping LOOP
    candidates := candidates || bounds[i]::uuid;
  END LOOP;
  FOR i IN 0..cardinality(candidates) LOOP
    lower_id := CASE WHEN i = 0 THEN NULL ELSE candidates[i] END;
    upper_id := CASE WHEN i = cardinality(candidates) THEN NULL ELSE candidates[i + 1] END;
    SELECT count(*) INTO counted FROM (SELECT 1 FROM catalog_product WHERE (lower_id IS NULL OR id >= lower_id) AND (upper_id IS NULL OR id < upper_id) LIMIT row_limit + 1) c;
    chunks := chunks + 1;
    IF counted > row_limit THEN over := over + 1; END IF;
    largest := greatest(largest, counted);
    smallest := least(smallest, counted);
  END LOOP;
  RAISE NOTICE 'M2: % bounds (% rows each), % candidates, % chunks, % over the limit, largest %, smallest %, verification % ms',
    n, round(per_bound), cardinality(candidates), chunks, over, largest, smallest, round(extract(epoch FROM clock_timestamp() - started) * 1000);
END $$;
