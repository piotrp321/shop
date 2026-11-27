-- orders, overdue refunds, count
-- cost variable: pending refunds = 0 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL AND o.refund_due_at < now() LIMIT 1001) bounded;
