select code_id, count(*), count(*) filter (where released_at is null) from pricing_discount_code_usage group by 1 order by 2 desc limit 5;
\echo '=== DC1 discount code list with active_uses per row'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.id, (SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses FROM pricing_discount_code c ORDER BY c.created_at DESC, c.id LIMIT 26;
