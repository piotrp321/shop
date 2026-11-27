-- phrase polish-upper 'KAMIEŃ', count of the first page
-- cost variable: hits = 21 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%KAMIEŃ%' LIMIT 10001) bounded;
