-- sitemap boundaries, row_number() over the whole key (M0, the present query)
-- cost variable: products = 2191 (scales with products)
SELECT id FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered WHERE position > 1 AND (position - 1) % 10000 = 0 ORDER BY position;
