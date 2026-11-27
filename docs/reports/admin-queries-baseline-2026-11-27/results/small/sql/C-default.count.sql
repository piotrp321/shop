-- customers, count without filter
-- cost variable: page = 10000 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c LIMIT 10001) bounded;
