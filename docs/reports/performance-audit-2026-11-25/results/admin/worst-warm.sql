\echo '=== W1 qx x3'
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE p.name ILIKE '%qx%' ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE p.name ILIKE '%qx%' ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
\echo '=== W2 termos x2'
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE p.name ILIKE '%termos%' ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE p.name ILIKE '%termos%' ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
\echo '=== W3 small category x2'
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/70134891-dc80-5215-9109-23f8f80ad40a/%') OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/70134891-dc80-5215-9109-23f8f80ad40a/%'))) ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
SELECT count(*) FROM (SELECT p.id FROM catalog_product p WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/70134891-dc80-5215-9109-23f8f80ad40a/%') OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE '/d7c098d0-4bae-595e-a1e1-e4a923448956/70134891-dc80-5215-9109-23f8f80ad40a/%'))) ORDER BY p.name ASC, p.id ASC LIMIT 26) x;
\echo '=== W4 actual matches termos / qx'
SELECT count(*) FROM catalog_product WHERE name ILIKE '%termos%';
\echo '=== W5 trgm of qx'
SELECT show_trgm('qx'), show_trgm('termos');
