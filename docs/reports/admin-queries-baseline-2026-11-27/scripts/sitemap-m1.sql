-- M1 of PERF-7-1: the boundaries of the sitemap chunks by keyset, one short statement per chunk (read only, no change of the data).
-- The first boundary is the id at position chunk + 1 of the key, every next one chunk positions after the previous (the same ids as
-- the present query M0: position > 1 and (position - 1) % chunk = 0). The loop runs the statements one after another on the server
-- and times them; the application would add one round trip of the connection to each. Result on the notices: statements, ids,
-- the total time, the slowest statement and whether the ids equal those of M0.
SET perf.chunk = '10000';
SET default_transaction_read_only = on;
DO $$
DECLARE
  chunk constant int := current_setting('perf.chunk')::int;
  previous uuid := NULL;
  boundary uuid;
  boundaries uuid[] := '{}';
  statements int := 0;
  started timestamptz := clock_timestamp();
  step_started timestamptz;
  slowest numeric := 0;
  elapsed numeric;
  reference uuid[];
  total numeric;
BEGIN
  LOOP
    step_started := clock_timestamp();
    IF previous IS NULL THEN
      SELECT id INTO boundary FROM catalog_product ORDER BY id OFFSET chunk LIMIT 1;
    ELSE
      SELECT id INTO boundary FROM catalog_product WHERE id >= previous ORDER BY id OFFSET chunk LIMIT 1;
    END IF;
    elapsed := extract(epoch FROM clock_timestamp() - step_started) * 1000;
    slowest := greatest(slowest, elapsed);
    statements := statements + 1;
    EXIT WHEN boundary IS NULL;
    boundaries := boundaries || boundary;
    previous := boundary;
  END LOOP;
  total := extract(epoch FROM clock_timestamp() - started) * 1000;
  SELECT array_agg(id ORDER BY position) INTO reference FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered
    WHERE position > 1 AND (position - 1) % chunk = 0;
  RAISE NOTICE 'M1: % statements, % boundaries, total % ms, slowest statement % ms, equal to M0: %',
    statements, cardinality(boundaries), round(total, 1), round(slowest, 2), boundaries = reference;
END $$;
