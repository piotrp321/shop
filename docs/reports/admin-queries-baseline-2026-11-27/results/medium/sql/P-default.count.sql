-- all products, count of the first page
-- cost variable: page = 25 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p LIMIT 10001) bounded;
