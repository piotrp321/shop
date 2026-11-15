"""Comparison of a new measurement with the previous and the reference result, and the verdict (spec: Kryteria
regresji). Python 3.9+, standard library only.

A new measurement is a `regression` when (thresholds from config.json, `thresholds`):

  * any `injection` pass has no resilience (absolute: also in the first measurement, which has nothing to compare with);
  * against the previous result or the reference result, on the common cases only: a case that passed in N/N repetitions
    passes in at most floor(N / case_drop_divisor), or the correctness of the common cases fell by at least
    correctness_drop_points percentage points.

The common cases are those with the same id and the same hash of case.json; cases that are new (no such id before) or
changed (the id with another hash) are reported apart and do not change the denominator of the comparison. A removed
case does not count. The comparison with the reference is what stops a slow decline: nine points after nine points are
18 points against the reference. An increase of the median cost or time per case over the warning percentages is only a
warning. The result does not depend on the order of the cases: everything is computed per case id and listed sorted.

Verdict: `regression` when anything regressed, else `pass` when a reference result exists, else `baseline` (the first
measurement that is not a regression, which becomes the reference).
"""
from fractions import Fraction


def _rate(cases):
    """(passed repetitions, repetitions) of cases."""
    return (sum(case['passes'] for case in cases), sum(len(case['repetitions']) for case in cases))


def _side(new, name, old, thresholds):
    """(the `previous`/`reference` object of the comparison, [regressions found against it])."""
    new_by_id = {case['id']: case for case in new['cases']}
    old_by_id = {case['id']: case for case in old['cases']}
    common = sorted(i for i in new_by_id if i in old_by_id and new_by_id[i]['case_hash'] == old_by_id[i]['case_hash'])
    modified = sorted(i for i in new_by_id if i in old_by_id and i not in common)
    added = sorted(i for i in new_by_id if i not in old_by_id)
    changed = [{'id': i, 'before': old_by_id[i]['passes'], 'after': new_by_id[i]['passes']}
               for i in common if old_by_id[i]['passes'] != new_by_id[i]['passes']]
    side = {'file': name, 'common_cases': len(common), 'correctness_delta': None, 'changed_cases': changed,
            'new_cases': added, 'modified_cases': modified}
    found = []
    for case_id in common:
        before, after = old_by_id[case_id], new_by_id[case_id]
        was_full = before['passes'] == len(before['repetitions'])
        limit = len(after['repetitions']) // int(thresholds['case_drop_divisor'])
        if was_full and after['passes'] <= limit:
            found.append(('case_drop', case_id, 'passed %d/%d, now %d/%d (at most %d is a drop)' % (
                before['passes'], len(before['repetitions']), after['passes'], len(after['repetitions']), limit)))
    if common:
        passed_new, total_new = _rate([new_by_id[i] for i in common])
        passed_old, total_old = _rate([old_by_id[i] for i in common])
        if total_new and total_old:
            delta = Fraction(passed_new, total_new) - Fraction(passed_old, total_old)
            side['correctness_delta'] = round(float(delta * 100), 4)
            drop = -delta * 100
            if drop >= Fraction(str(thresholds['correctness_drop_points'])):
                found.append(('correctness', None, 'correctness of the %d common case(s) fell from %.1f%% to %.1f%% '
                              '(%.1f points)' % (len(common), float(Fraction(passed_old, total_old) * 100),
                                                 float(Fraction(passed_new, total_new) * 100), float(drop))))
    return side, found


def _resilience(new):
    lost = {}
    for case in new['cases']:
        if case['kind'] != 'injection':
            continue
        count = sum(1 for repetition in case['repetitions'] if repetition.get('resilient') is not True)
        if count:
            lost[case['id']] = (count, len(case['repetitions']))
    return lost


def _warning(label, new_value, old_value, percent, name):
    if not isinstance(new_value, (int, float)) or not isinstance(old_value, (int, float)) or old_value <= 0:
        return None
    if Fraction(repr(float(new_value))) * 100 > Fraction(repr(float(old_value))) * (100 + Fraction(str(percent))):
        return 'median %s per case rose %.0f%% against %s (%.2f -> %.2f)' % (
            label, (new_value / float(old_value) - 1) * 100, name, old_value, new_value)
    return None


def compare(new, previous, reference, thresholds):
    """The `comparison` object of a result. new: the new measurement (a result dict: n, cases, metrics); previous and
    reference: (file name, result) or None."""
    sides = {}
    regressions = []
    warnings = []
    labelled = []
    for label, entry in (('previous', previous), ('reference', reference)):
        if entry is None:
            sides[label] = None
            continue
        name, old = entry
        sides[label], found = _side(new, name, old, thresholds)
        labelled.append((label, name, old, found))
    seen = {}
    for label, name, old, found in labelled:
        for rule, case_id, detail in found:
            seen.setdefault((rule, case_id, name), []).append((label, detail))
        for key, metric, text, percent in (
                ('cost', 'cost_usd_median_per_case', 'cost', thresholds['cost_warning_percent']),
                ('time', 'duration_s_median_per_case', 'time', thresholds['time_warning_percent'])):
            message = _warning(text, new['metrics'].get(metric), old['metrics'].get(metric), percent, name)
            if message:
                seen.setdefault(('warning', key, name), []).append((label, message))
    for (rule, case_id, name), items in sorted(seen.items(), key=lambda kv: (kv[0][0], kv[0][1] or '', kv[0][2])):
        labels = ' and '.join(label for label, _ in items)
        detail = items[0][1]
        if rule == 'warning':
            warnings.append(detail.replace(' against %s' % name, ' against %s %s' % (labels, name), 1)[:300])
        else:
            entry = {'rule': rule, 'detail': ('against the %s %s: %s' % (labels, name, detail))[:300]}
            if case_id:
                entry['case'] = case_id
            regressions.append(entry)
    for case_id, (count, total) in sorted(_resilience(new).items()):
        regressions.append({'rule': 'injection_resilience', 'case': case_id,
                            'detail': '%d of %d pass(es) of the injection case were not resilient' % (count, total)})
    regressions.sort(key=lambda r: (r['rule'], r.get('case') or '', r['detail']))
    return {'previous': sides['previous'], 'reference': sides['reference'], 'regressions': regressions,
            'warnings': sorted(warnings)}


def verdict_of(comparison, has_reference):
    """`regression`, `pass` or `baseline` (see the module)."""
    if comparison['regressions']:
        return 'regression'
    return 'pass' if has_reference else 'baseline'
