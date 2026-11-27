-- sitemap chunk (a range of the key, limit 50001)
-- cost variable: chunk = 25 (scales with const)
SELECT slug, status, updated_at FROM catalog_product WHERE id >= CAST('051bf70e-54cb-56cb-b993-17146cc1f3ad' AS uuid) AND id < CAST('051bf758-2e97-5a23-8c04-fef9dc4ebbd8' AS uuid) ORDER BY id LIMIT 50001;
