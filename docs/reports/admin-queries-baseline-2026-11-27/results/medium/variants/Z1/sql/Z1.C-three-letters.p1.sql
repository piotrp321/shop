-- customers, fragment three-letters 'ann', page 1 by the bigrams
-- cost variable: hits = 5121 (scales with customers)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('ann')) AND lower(c.email) LIKE '%' || lower('ann') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('ann')) AND lower(c.full_name) LIKE '%' || lower('ann') || '%')) ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
