#!/usr/bin/env python3
"""PERF-7-2: the regressions of the way of the phrase of the product list (PhraseSearch) against the present statement, on the COPY shop_proto
of the database `medium` (light read queries, no k6). Python 3 standard library only, on the host; the database is reached with
`docker exec -i <container> psql`. Read only, except `setup`, which creates the function and the index of the bigrams on the copy.

  phrase-regressions.py setup  --db shop_proto               catalog_name_bigrams(text) and catalog_product_name_bigrams_idx, as in the migration
                                                             Version20261127100000, then ANALYZE (the build takes about 40 s)
  phrase-regressions.py counts --db shop_proto --out DIR     the count of the first page for frequent phrases (ze, pro, ka, er):
                                                             the present statement (BoundedCounter, limit 10 001) against the way of PhraseSearch::count
  phrase-regressions.py sorts  --db shop_proto --out DIR     the page 1 of the frequent phrases that are correlated with the order of the names,
                                                             sorts name, updatedAt and -updatedAt: the present statement against the algorithm of
                                                             PhraseSearch::page (every statement of every step, with its buffers)

Every statement runs REPEATS times (the first is the coldest) under EXPLAIN (ANALYZE, BUFFERS); the tables give the median of the runs of the
time (ms) and the buffers (hit + read) of the top node. The statements are the ones of PhraseSearch (first budget 20 000, growth 10, cap = budget / 2),
and the candidates run with the settings of D10 (one set_config statement in a transaction that is rolled back). Output: DIR/<case>.txt (the plans)
and DIR/summary.md. The container is PG_CONTAINER (default perf7-postgres-1).
"""
import argparse
import os
import re
import statistics
import subprocess
import sys

CONTAINER = os.environ.get("PG_CONTAINER", "perf7-postgres-1")
REPEATS = int(os.environ.get("REPEATS", "3"))
PAGE = 26
BUDGET = 20_000
LIMIT = 10_000
STEER = "SELECT set_config('enable_seqscan', 'off', true), set_config('enable_indexscan', 'off', true), set_config('enable_indexonlyscan', 'off', true);"
SETUP = r"""
CREATE OR REPLACE FUNCTION catalog_name_bigrams(text) RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
 SELECT coalesce(array_agg(DISTINCT substr(lower($1), i, 2)), '{}'::text[]) FROM generate_series(1, greatest(length(lower($1)) - 1, 0)) AS i $$;
\timing on
CREATE INDEX IF NOT EXISTS catalog_product_name_bigrams_idx ON catalog_product USING gin (catalog_name_bigrams(name));
\timing off
ANALYZE catalog_product;
SELECT pg_size_pretty(pg_relation_size('catalog_product_name_bigrams_idx'));
"""


def psql(db, script):
    result = subprocess.run(
        ["docker", "exec", "-i", CONTAINER, "psql", "-U", "postgres", "-d", db, "-At", "-v", "ON_ERROR_STOP=1", "-q"],
        input=script, capture_output=True, text=True, timeout=3600,
    )
    if result.returncode != 0:
        sys.exit("psql failed: " + result.stderr.strip())
    return result.stdout


def lit(text):
    return "'" + text.replace("'", "''") + "'"


def pattern(term):
    return lit("%" + term.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%")


def route(term):
    """PhraseRoute::of: a run of three letters or digits goes by the trigrams, any other phrase by the bigrams."""
    return "trigrams" if re.search(r"[^\W_]{3}", term) else "bigrams"


def candidate_condition(term):
    if route(term) == "trigrams":
        return f"p.name ILIKE {pattern(term)}"
    return f"catalog_name_bigrams(p.name) @> catalog_name_bigrams({lit(term)}) AND (p.name || '') ILIKE {pattern(term)}"


def sort_parts(sort):
    return ("updated_at", "DESC") if sort == "-updatedAt" else (("updated_at", "ASC") if sort == "updatedAt" else ("name", "ASC"))


def explain(db, sql, steered, label, out, runs=REPEATS):
    """Runs the statement under EXPLAIN (ANALYZE, BUFFERS) `runs` times; returns [(ms, buffers, rows)] and keeps the plans."""
    results = []
    plans = []
    for _ in range(runs):
        script = "BEGIN READ ONLY;\n" + (STEER + "\n" if steered else "") + "EXPLAIN (ANALYZE, BUFFERS) " + sql + ";\nROLLBACK;\n"
        text = psql(db, script)
        if steered:
            text = "\n".join(text.splitlines()[1:])  # the line of set_config
        plans.append(text)
        ms = float(re.search(r"Execution Time: ([0-9.]+) ms", text).group(1))
        first = re.search(r"Buffers: ([^\n]*)", text)
        buffers = sum(int(n) for n in re.findall(r"(?:hit|read)=(\d+)", first.group(1).split("\n")[0])) if first else 0
        rows = int(float(re.search(r"\(actual time=[^)]*? rows=([0-9.]+)", text).group(1)))
        results.append((ms, buffers, rows))
    with open(os.path.join(out, label + ".txt"), "w") as handle:
        handle.write(sql + "\n\n" + "\n=====\n".join(plans))
    return results


def median(results):
    return statistics.median(r[0] for r in results), int(statistics.median(r[1] for r in results))


def value(db, sql, steered=False):
    script = "BEGIN READ ONLY;\n" + (STEER + "\n" if steered else "") + sql + ";\nROLLBACK;\n"
    lines = psql(db, script).splitlines()
    return lines[1:] if steered else lines


def counts(db, out):
    """The count as PhraseSearch::count does it: the walk of the budget (20 000), grown tenfold (twice at most) while hits * 10 > limit,
    then, if the limit was not reached, the candidates of the route."""
    os.makedirs(out, exist_ok=True)
    table = ["| fraza | droga | obecne: ms / bufory | kroki walk (budżet: ms / bufory / trafień) | kandydaci: ms / bufory | razem nowe: ms / bufory | wynik (obecny = nowy) |", "|---|---|---|---|---|---|---|"]
    for term in ("ze", "pro", "ka", "er"):
        present = f"SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE {pattern(term)} LIMIT {LIMIT + 1}) bounded"
        cand = f"WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE {candidate_condition(term)}) SELECT count(*) FROM (SELECT * FROM m LIMIT {LIMIT + 1}) bounded"
        r_present = explain(db, present, False, f"count-{term}-present", out)
        expected = int(value(db, present)[0])
        total_ms, total_b, steps, budget, second, answer = 0.0, 0, [], BUDGET, None, None
        for extension in range(3):
            walk = f"SELECT count(*) FROM (SELECT 1 FROM (SELECT w0.id, w0.name FROM catalog_product w0 ORDER BY w0.name, w0.id LIMIT {budget}) w WHERE w.name ILIKE {pattern(term)} LIMIT {LIMIT + 1}) bounded"
            ms, b = median(explain(db, walk, False, f"count-{term}-walk{extension}", out))
            walked = int(value(db, walk)[0])
            total_ms, total_b = total_ms + ms, total_b + b
            steps.append(f"{budget}: {ms:.1f} / {b} / {walked}")
            if walked > LIMIT:
                answer = walked
                break
            if extension >= 2 or walked * 10 <= LIMIT:
                break
            budget *= 10
        if answer is None:
            second = median(explain(db, cand, True, f"count-{term}-candidates", out))
            total_ms, total_b = total_ms + second[0], total_b + second[1]
            answer = int(value(db, cand, True)[0])
        table.append(
            f"| `{term}` | {route(term)} | {median(r_present)[0]:.1f} / {median(r_present)[1]} | {'; '.join(steps)} | "
            + (f"{second[0]:.1f} / {second[1]}" if second else "pominięci (limit w przejściu)")
            + f" | {total_ms:.1f} / {total_b} | {expected} = {answer} |"
        )
    summary(out, "counts", table)


def page_present(term, sort):
    column, direction = sort_parts(sort)
    return f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE {pattern(term)} ORDER BY p.{column} {direction}, p.id {direction} LIMIT {PAGE}"


def page_walk(term, sort, budget):
    column, direction = sort_parts(sort)
    extra = ", w0.updated_at" if column == "updated_at" else ""
    return (
        f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM (SELECT w0.id, w0.name{extra} FROM catalog_product w0 ORDER BY w0.{column} {direction}, w0.id {direction} LIMIT {budget}) w "
        f"JOIN catalog_product p ON p.id = w.id WHERE w.name ILIKE {pattern(term)} ORDER BY w.{column} {direction}, w.id {direction} LIMIT {PAGE}"
    )


def page_probe(sort, budget):
    column, direction = sort_parts(sort)
    return f"SELECT count(*) FROM (SELECT 1 FROM catalog_product w0 ORDER BY w0.{column} {direction}, w0.id {direction} LIMIT {budget}) e"


def page_candidates(term, sort, cap):
    column, direction = sort_parts(sort)
    return (
        f"WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE {candidate_condition(term)}) "
        f"SELECT c.id, c.name, c.tax_class, c.updated_at, count(*) OVER () AS candidates FROM (SELECT * FROM m LIMIT {cap + 1}) c ORDER BY c.{column} {direction}, c.id {direction} LIMIT {PAGE}"
    )


def sorts(db, out):
    os.makedirs(out, exist_ok=True)
    table = ["| fraza (trafień) | sort | obecne: ms / bufory | nowe razem: ms / bufory | kroki (statement: ms / bufory) | te same wiersze |", "|---|---|---|---|---|---|"]
    for term in ("rowerowa", "snowboardowe", "pływacki", "zupa", "pro", "ze"):
        hits = int(value(db, f"SELECT count(*) FROM catalog_product p WHERE p.name ILIKE {pattern(term)}")[0])
        for sort in ("name", "updatedAt", "-updatedAt"):
            tag = f"page-{term}-{sort}"
            present = explain(db, page_present(term, sort), False, tag + "-present", out)
            expected = value(db, page_present(term, sort))
            steps, total_ms, total_b, found = [], 0.0, 0, None
            budget, level = BUDGET, 0
            while True:
                walk = page_walk(term, sort, budget)
                r = explain(db, walk, False, f"{tag}-L{level}-walk", out)
                ms, b = median(r)
                total_ms, total_b = total_ms + ms, total_b + b
                steps.append(f"L{level} walk B={budget}: {ms:.1f} / {b}")
                got = value(db, walk)
                if len(got) >= PAGE:
                    found = got
                    break
                probe = page_probe(sort, budget)
                ms, b = median(explain(db, probe, False, f"{tag}-L{level}-probe", out))
                total_ms, total_b = total_ms + ms, total_b + b
                steps.append(f"L{level} probe: {ms:.1f} / {b}")
                if int(value(db, probe)[0]) < budget:
                    found = got
                    break
                cap = max(1, budget // 2)
                cand = page_candidates(term, sort, cap)
                ms, b = median(explain(db, cand, True, f"{tag}-L{level}-candidates", out))
                total_ms, total_b = total_ms + ms, total_b + b
                steps.append(f"L{level} candidates cap={cap}: {ms:.1f} / {b}")
                rows = value(db, cand, True)
                if len(rows) <= 0 or int(rows[0].rsplit("|", 1)[-1]) <= cap:
                    found = [row.rsplit("|", 1)[0] for row in rows]
                    break
                budget *= 10
                level += 1
            same = [row.split("|")[0] for row in found] == [row.split("|")[0] for row in expected]
            pm, pb = median(present)
            table.append(f"| `{term}` ({hits}) | {sort} | {pm:.1f} / {pb} | {total_ms:.1f} / {total_b} | {'; '.join(steps)} | {'tak' if same else 'NIE'} |")
    summary(out, "sorts", table)


def summary(out, name, table):
    text = "\n".join(table) + "\n"
    with open(os.path.join(out, f"summary-{name}.md"), "w") as handle:
        handle.write(text)
    print(text)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("command", choices=["setup", "counts", "sorts"])
    parser.add_argument("--db", default="shop_proto")
    parser.add_argument("--out", default="phrase-regressions-out")
    args = parser.parse_args()
    if args.db == "shop":
        sys.exit("refusing to run on the database `shop`: use the copy shop_proto")
    if args.command == "setup":
        print(psql(args.db, SETUP))
    elif args.command == "counts":
        counts(args.db, args.out)
    else:
        sorts(args.db, args.out)


if __name__ == "__main__":
    main()
