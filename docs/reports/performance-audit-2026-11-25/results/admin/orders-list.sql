\echo '=== O1 no filter -placedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O1c count no filter'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o LIMIT 10001) bounded;
\echo '=== O2 status=paid -placedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.status='paid' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O2c count paid (dashboard paidToShip, limit 1000)'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status='paid' LIMIT 1001) bounded;
\echo '=== O2d count delivered (10000)'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status='delivered' LIMIT 10001) bounded;
\echo '=== O3 -total no filter'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o ORDER BY o.total_gross DESC, o.id DESC LIMIT 26;
\echo '=== O4 pending refunds sort refund'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL ORDER BY o.refund_due_at ASC, o.id ASC LIMIT 26;
\echo '=== O4c overdue refunds count'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL AND o.refund_due_at < now() LIMIT 1001) bounded;
\echo '=== O5 fragment frequent "kowal"'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kowal') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O5c count kowal'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kowal') || '%' LIMIT 10001) bounded;
\echo '=== O6 fragment very frequent "example"'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('example') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O6c count example'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('example') || '%' LIMIT 10001) bounded;
\echo '=== O7 fragment no result "qqzzxx"'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('qqzzxx') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O8 fragment 3 chars "ann"'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('ann') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O9 fragment rare "czerniak.g331" with placedAt asc'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('czerniak.g331') || '%' ORDER BY o.placed_at ASC, o.id ASC LIMIT 26;
\echo '=== O10 date range 1 year + status delivered'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.status='delivered' AND o.placed_at >= '2025-01-01' AND o.placed_at < '2026-01-01' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O10c count date range'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status='delivered' AND o.placed_at >= '2025-01-01' AND o.placed_at < '2026-01-01' LIMIT 10001) bounded;
\echo '=== O11 date range narrow (1 day, old) + fragment frequent'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('example') || '%' AND o.placed_at >= '2024-03-01' AND o.placed_at < '2024-03-02' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O12 status paid + fragment no-result'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.status='paid' AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('qqzzxx') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O13 email exact'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.email) = lower('roman.dudziak.g331096@example.com') ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
\echo '=== O14 far page -placedAt cursor old'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE (o.placed_at, o.id) < (CAST('2024-01-01' AS timestamptz), CAST('00000000-0000-0000-0000-000000000000' AS uuid)) ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
