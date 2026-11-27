-- P8 of PERF-7-1: the cost of the writes that the new indexes touch, as WAL bytes (pg_current_wal_lsn()) and time per write, on a COPY of
-- the database (it writes). Run it with `psql -v label=<name>` before and after the creation of the indexes of a variant, the same way.
-- Three kinds of write, each in its own transaction as the application commits them:
--   product   UPDATE catalog_product SET updated_at, version (a save changes the indexed updated_at, so the new row version is not HOT)
--   order     the cycle of an order: INSERT, then the status paid, shipped, delivered (four writes of ordering_order)
--   customer  UPDATE ordering_customer (the counters and last_order_at of a new order of the customer)
-- "cold": the first pass after a CHECKPOINT, so the first change of every page adds a full-page image to the WAL; "hot": the second
-- pass over the same rows (the pages are already in the WAL since the checkpoint, no image). The real mix lies between the two.
-- Notices: label, kind, writes, WAL bytes per write, full-page images per write, milliseconds per write.
\set ON_ERROR_STOP on
SELECT set_config('perf.label', :'label', false);
CREATE TEMP TABLE sample_products AS SELECT id FROM catalog_product TABLESAMPLE SYSTEM (0.5) LIMIT 1000;
CREATE TEMP TABLE sample_orders AS SELECT id FROM ordering_order TABLESAMPLE SYSTEM (0.5) LIMIT 250;
CREATE TEMP TABLE sample_customers AS SELECT customer_id FROM ordering_customer TABLESAMPLE SYSTEM (2) LIMIT 1000;
CHECKPOINT;

DO $$
DECLARE
  r record; wal0 pg_lsn; fpi0 bigint; started timestamptz; n int; pass int;
BEGIN
  FOR pass IN 1..2 LOOP
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); started := clock_timestamp(); n := 0;
    FOR r IN SELECT id FROM sample_products LOOP
      UPDATE catalog_product SET updated_at = updated_at + interval '1 second', version = version + 1 WHERE id = r.id;
      n := n + 1;
      COMMIT;
    END LOOP;
    RAISE NOTICE '% product % writes=% wal_bytes_per_write=% fpi_per_write=% ms_per_write=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((pg_current_wal_lsn() - wal0) / n::numeric, 1), round(((SELECT wal_fpi FROM pg_stat_wal) - fpi0) / n::numeric, 3), round((extract(epoch FROM clock_timestamp() - started) * 1000 / n)::numeric, 3);
  END LOOP;
END $$;

CHECKPOINT;
DO $$
DECLARE
  r record; o ordering_order%ROWTYPE; wal0 pg_lsn; fpi0 bigint; started timestamptz; n int; pass int; stamp text;
BEGIN
  FOR pass IN 1..2 LOOP
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); started := clock_timestamp(); n := 0;
    stamp := to_char(clock_timestamp(), 'MMDDHH24MISSUS');
    FOR r IN SELECT id FROM sample_orders LOOP
      SELECT * INTO o FROM ordering_order WHERE id = r.id;
      o.id := uuidv7();
      o.number := 'W' || pass || substr(stamp, 5, 8) || n;
      o.idempotency_key := 'w-' || o.id;
      o.status := 'awaiting_payment'; o.paid_at := NULL; o.shipped_at := NULL; o.delivered_at := NULL; o.cancelled_at := NULL; o.returned_at := NULL;
      o.refund_amount := NULL; o.refund_due_at := NULL; o.refund_completed_at := NULL; o.placed_at := now(); o.access_token := NULL;
      INSERT INTO ordering_order SELECT (o).*;
      COMMIT;
      UPDATE ordering_order SET status = 'paid', paid_at = now(), version = version + 1 WHERE id = o.id; COMMIT;
      UPDATE ordering_order SET status = 'shipped', shipped_at = now(), version = version + 1 WHERE id = o.id; COMMIT;
      UPDATE ordering_order SET status = 'delivered', delivered_at = now(), version = version + 1 WHERE id = o.id; COMMIT;
      n := n + 1;
    END LOOP;
    RAISE NOTICE '% order-cycle % cycles=% wal_bytes_per_cycle=% fpi_per_cycle=% ms_per_cycle=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((pg_current_wal_lsn() - wal0) / n::numeric, 1), round(((SELECT wal_fpi FROM pg_stat_wal) - fpi0) / n::numeric, 3), round((extract(epoch FROM clock_timestamp() - started) * 1000 / n)::numeric, 3);
  END LOOP;
END $$;

CHECKPOINT;
DO $$
DECLARE
  r record; wal0 pg_lsn; fpi0 bigint; started timestamptz; n int; pass int;
BEGIN
  FOR pass IN 1..2 LOOP
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); started := clock_timestamp(); n := 0;
    FOR r IN SELECT customer_id FROM sample_customers LOOP
      UPDATE ordering_customer SET order_count = order_count + 1, paid_count = paid_count + 1, paid_gross = paid_gross + 1000, last_order_at = last_order_at + interval '1 second' WHERE customer_id = r.customer_id;
      n := n + 1;
      COMMIT;
    END LOOP;
    RAISE NOTICE '% customer % writes=% wal_bytes_per_write=% fpi_per_write=% ms_per_write=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((pg_current_wal_lsn() - wal0) / n::numeric, 1), round(((SELECT wal_fpi FROM pg_stat_wal) - fpi0) / n::numeric, 3), round((extract(epoch FROM clock_timestamp() - started) * 1000 / n)::numeric, 3);
  END LOOP;
END $$;
