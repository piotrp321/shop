-- P8 of PERF-7-1: the cost of the writes that the new indexes touch, as WAL bytes (pg_current_wal_lsn()) and time per write, on a COPY of
-- the database (it writes). Run it with `psql -v label=<name>` before and after the creation of the indexes of a variant, the same way.
-- Three kinds of write, each in its own transaction as the application commits them:
--   product   UPDATE catalog_product SET updated_at, version (a save changes the indexed updated_at, so the new row version is not HOT)
--   order     the cycle of an order: INSERT, then the status paid, shipped, delivered (four writes of ordering_order)
--   customer  UPDATE ordering_customer (the counters and last_order_at of a new order of the customer)
-- "cold": the first pass after a CHECKPOINT, so the first change of every page adds a full-page image to the WAL; "hot": the second
-- pass over the same rows (the pages are already in the WAL since the checkpoint, no image). The real mix lies between the two.
-- flush_bytes: the WAL of the flush of the pending lists of the GIN indexes of the table (fastupdate: a GIN index takes the entries of a
-- write to a pending list, which a flush later merges into the index; that cost belongs to the writes and is reported apart).
-- Notices: label, kind, writes, WAL bytes per write, (the counter of full-page images does not update inside the loop, so the cold-hot
-- difference shows them), milliseconds per write, checkpoints that ran during the pass (a pass with one is not clean: it adds full-page images).
\set ON_ERROR_STOP on
SELECT set_config('perf.label', :'label', false) \gset
CREATE TEMP TABLE sample_products AS SELECT id FROM catalog_product TABLESAMPLE SYSTEM (0.5) LIMIT 1000;
CREATE TEMP TABLE sample_orders AS SELECT id FROM ordering_order TABLESAMPLE SYSTEM (0.5) LIMIT 250;
CREATE TEMP TABLE sample_customers AS SELECT customer_id FROM ordering_customer TABLESAMPLE SYSTEM (2) LIMIT 1000;
CHECKPOINT;

DO $$
DECLARE
  r record; wal0 pg_lsn; wal1 pg_lsn; wal2 pg_lsn; fpi0 bigint; fpi1 bigint; cp0 bigint; cp1 bigint; started timestamptz; elapsed numeric; n int; pass int;
BEGIN
  FOR pass IN 1..2 LOOP
    PERFORM pg_stat_force_next_flush(); COMMIT;
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); cp0 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer); started := clock_timestamp(); n := 0;
    FOR r IN SELECT id FROM sample_products LOOP
      UPDATE catalog_product SET updated_at = updated_at + interval '1 second', version = version + 1 WHERE id = r.id;
      n := n + 1;
      COMMIT;
    END LOOP;
    elapsed := extract(epoch FROM clock_timestamp() - started) * 1000; wal1 := pg_current_wal_lsn();
    PERFORM gin_clean_pending_list(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_am a ON a.oid = c.relam WHERE i.indrelid = 'catalog_product'::regclass AND a.amname = 'gin'; COMMIT; wal2 := pg_current_wal_lsn();
    PERFORM pg_stat_force_next_flush(); COMMIT; fpi1 := (SELECT wal_fpi FROM pg_stat_wal); cp1 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer);
    RAISE NOTICE '% product % writes=% wal_bytes_per_write=% fpi_per_write=% ms_per_write=% flush_bytes_per_write=% checkpoints_during=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((wal1 - wal0) / n::numeric, 1), round((fpi1 - fpi0) / n::numeric, 3), round(elapsed / n, 3), round((wal2 - wal1) / n::numeric, 1), cp1 - cp0;
  END LOOP;
END $$;

CHECKPOINT;
DO $$
DECLARE
  r record; o ordering_order%ROWTYPE; wal0 pg_lsn; wal1 pg_lsn; wal2 pg_lsn; fpi0 bigint; fpi1 bigint; cp0 bigint; cp1 bigint; started timestamptz; elapsed numeric; n int; pass int; stamp text;
BEGIN
  FOR pass IN 1..2 LOOP
    PERFORM pg_stat_force_next_flush(); COMMIT;
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); cp0 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer); started := clock_timestamp(); n := 0;
    stamp := to_char(clock_timestamp(), 'MMDDHH24MISSUS');
    FOR r IN SELECT id FROM sample_orders LOOP
      SELECT * INTO o FROM ordering_order WHERE id = r.id;
      o.id := uuidv7();
      o.number := 'W' || pass || substr(stamp, 5, 8) || n;
      o.idempotency_key := 'w-' || o.id;
      o.status := 'awaiting_payment'; o.paid_at := NULL; o.payment_id := NULL; o.shipped_at := NULL; o.delivered_at := NULL; o.cancelled_at := NULL; o.cancellation_reason := NULL;
      o.returned_at := NULL; o.tracking_number := NULL; o.refund_amount := NULL; o.refund_due_at := NULL; o.refund_completed_at := NULL; o.refund_completed_by_staff_id := NULL;
      o.refund_completed_by_username := NULL; o.refund_note := NULL; o.placed_at := now();
      INSERT INTO ordering_order SELECT (o).*;
      COMMIT;
      UPDATE ordering_order SET status = 'paid', paid_at = now(), payment_id = uuidv7(), version = version + 1 WHERE id = o.id; COMMIT;
      UPDATE ordering_order SET status = 'shipped', shipped_at = now(), version = version + 1 WHERE id = o.id; COMMIT;
      UPDATE ordering_order SET status = 'delivered', delivered_at = now(), version = version + 1 WHERE id = o.id; COMMIT;
      n := n + 1;
    END LOOP;
    elapsed := extract(epoch FROM clock_timestamp() - started) * 1000; wal1 := pg_current_wal_lsn();
    PERFORM gin_clean_pending_list(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_am a ON a.oid = c.relam WHERE i.indrelid = 'ordering_order'::regclass AND a.amname = 'gin'; COMMIT; wal2 := pg_current_wal_lsn();
    PERFORM pg_stat_force_next_flush(); COMMIT; fpi1 := (SELECT wal_fpi FROM pg_stat_wal); cp1 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer);
    RAISE NOTICE '% order-cycle % cycles=% wal_bytes_per_cycle=% fpi_per_cycle=% ms_per_cycle=% flush_bytes_per_write=% checkpoints_during=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((wal1 - wal0) / n::numeric, 1), round((fpi1 - fpi0) / n::numeric, 3), round(elapsed / n, 3), round((wal2 - wal1) / n::numeric, 1), cp1 - cp0;
  END LOOP;
END $$;

CHECKPOINT;
DO $$
DECLARE
  r record; wal0 pg_lsn; wal1 pg_lsn; wal2 pg_lsn; fpi0 bigint; fpi1 bigint; cp0 bigint; cp1 bigint; started timestamptz; elapsed numeric; n int; pass int;
BEGIN
  FOR pass IN 1..2 LOOP
    PERFORM pg_stat_force_next_flush(); COMMIT;
    wal0 := pg_current_wal_lsn(); fpi0 := (SELECT wal_fpi FROM pg_stat_wal); cp0 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer); started := clock_timestamp(); n := 0;
    FOR r IN SELECT customer_id FROM sample_customers LOOP
      UPDATE ordering_customer SET order_count = order_count + 1, paid_count = paid_count + 1, paid_gross = paid_gross + 1000, last_order_at = last_order_at + interval '1 second' WHERE customer_id = r.customer_id;
      n := n + 1;
      COMMIT;
    END LOOP;
    elapsed := extract(epoch FROM clock_timestamp() - started) * 1000; wal1 := pg_current_wal_lsn();
    PERFORM gin_clean_pending_list(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_am a ON a.oid = c.relam WHERE i.indrelid = 'ordering_customer'::regclass AND a.amname = 'gin'; COMMIT; wal2 := pg_current_wal_lsn();
    PERFORM pg_stat_force_next_flush(); COMMIT; fpi1 := (SELECT wal_fpi FROM pg_stat_wal); cp1 := (SELECT num_timed + num_requested FROM pg_stat_checkpointer);
    RAISE NOTICE '% customer % writes=% wal_bytes_per_write=% fpi_per_write=% ms_per_write=% flush_bytes_per_write=% checkpoints_during=%', current_setting('perf.label'), CASE pass WHEN 1 THEN 'cold' ELSE 'hot' END, n,
      round((wal1 - wal0) / n::numeric, 1), round((fpi1 - fpi0) / n::numeric, 3), round(elapsed / n, 3), round((wal2 - wal1) / n::numeric, 1), cp1 - cp0;
  END LOOP;
END $$;
