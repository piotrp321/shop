-- PERF-2B-8: the state of the data of the stack, one line per table of the schema public: name|rows|sum of the hashes of the rows.
-- The sum of hashtextextended over the text of every row does not depend on the order of the rows and needs constant memory, so it
-- runs on the 11 GB medium database. Two runs with the same lines (diff of the two outputs) mean no table changed between them.
-- Run by run.sh: psql -U postgres -d shop -At -f this file (a read only query, no change of data).
SELECT table_name
    || '|' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM %I', table_name), false, true, '')))[1]::text
    || '|' || (xpath('/row/h/text()', query_to_xml(
        format('SELECT coalesce(sum(hashtextextended(t::text, 0)::numeric), 0) AS h FROM %I t', table_name), false, true, '')))[1]::text
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;
