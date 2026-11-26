\echo '=== K1 list -lastOrderAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
\echo '=== K2 list name'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c ORDER BY lower(c.full_name) ASC, c.customer_id LIMIT 26;
\echo '=== K3 list -paidGross'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c ORDER BY c.paid_gross DESC, c.customer_id LIMIT 26;
\echo '=== K4 fragment frequent "ann"'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ann') || '%' OR lower(c.full_name) LIKE '%' || lower('ann') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
\echo '=== K4c count ann'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ann') || '%' OR lower(c.full_name) LIKE '%' || lower('ann') || '%') LIMIT 10001) bounded;
\echo '=== K5 fragment none "qqzzxx"'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('qqzzxx') || '%' OR lower(c.full_name) LIKE '%' || lower('qqzzxx') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
\echo '=== K6 fragment medium "czerniak"'
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('czerniak') || '%' OR lower(c.full_name) LIKE '%' || lower('czerniak') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
\echo '=== K7 customer detail addresses'
EXPLAIN (ANALYZE, BUFFERS) SELECT street, postal_code, city, country FROM (SELECT DISTINCT ON (street, postal_code, city, country) street, postal_code, city, country, placed_at, id FROM (SELECT o.street, o.postal_code, o.city, o.country, o.placed_at, o.id FROM ordering_order o WHERE o.owner_type = 'customer' AND o.customer_id = '77e9e8db-4af0-5ed8-96b7-182d1a8588e7' ORDER BY o.placed_at DESC, o.id DESC LIMIT 50) AS recent ORDER BY street, postal_code, city, country, placed_at DESC, id DESC) AS used ORDER BY placed_at DESC, id DESC LIMIT 5;
\echo '=== K8 customer orders list (top customer)'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id FROM ordering_order o WHERE o.owner_type = 'customer' AND o.customer_id = '77e9e8db-4af0-5ed8-96b7-182d1a8588e7' ORDER BY o.placed_at DESC, o.id DESC LIMIT 11;
\echo '=== K8c count customer orders'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.owner_type = 'customer' AND o.customer_id = '77e9e8db-4af0-5ed8-96b7-182d1a8588e7' LIMIT 10001) bounded;
