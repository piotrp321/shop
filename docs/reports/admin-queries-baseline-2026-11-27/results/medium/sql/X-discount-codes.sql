-- discount codes, list with the active uses per row (A4)
-- cost variable: active uses of the codes = 70210 (scales with orders)
SELECT c.id, (SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses FROM pricing_discount_code c ORDER BY c.created_at DESC, c.id LIMIT 26;
