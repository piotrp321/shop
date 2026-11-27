-- dashboard, sales of 60 days
-- cost variable: hourly rows of 60 days = 0 (scales with const)
SELECT CAST(COALESCE(SUM(order_count), 0) AS bigint), CAST(COALESCE(SUM(gross), 0) AS bigint) FROM ordering_sales_hour WHERE hour_start >= now() - interval '60 days' AND hour_start < now();
