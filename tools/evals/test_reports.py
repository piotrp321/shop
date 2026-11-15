"""Tests of the report parser (evals_reports) on the stored real reports of code-reviewer and spec-critic
(tools/evals/testdata/reports, sanitised copies of reports of AO runs and /prepare-epic sessions) and on small
synthetic reports for the cases the real ones do not have (marked as such)."""
import os
import unittest
from collections import Counter

import testutil  # noqa: F401  (puts tools/evals on the path)
import evals_lint as lint
import evals_reports as reports

DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'testdata', 'reports')


def load(agent, name):
    with open(os.path.join(DATA, agent, name + '.md'), encoding='utf-8') as handle:
        return handle.read()


def parsed(agent, name):
    return reports.parse(load(agent, name), agent)


def ranks(report):
    return dict(Counter(f.rank for f in report.findings))


class TestTestData(unittest.TestCase):
    def test_the_stored_reports_pass_the_leak_scan_of_the_lint(self):
        checked = 0
        for current, _, names in os.walk(DATA):
            for name in names:
                with open(os.path.join(current, name), encoding='utf-8') as handle:
                    self.assertEqual([], lint.scan_text(handle.read()), name)
                checked += 1
        self.assertGreaterEqual(checked, 12)


class TestSeverities(unittest.TestCase):
    def test_both_vocabularies_share_three_ranks(self):
        self.assertEqual(3, reports.severity_rank('BLOCKER'))
        self.assertEqual(3, reports.severity_rank('Critical'))
        self.assertEqual(2, reports.severity_rank('IMPORTANT'))
        self.assertEqual(2, reports.severity_rank('important'))
        self.assertEqual(1, reports.severity_rank('SUGGESTION'))
        self.assertEqual(1, reports.severity_rank('Minor'))

    def test_an_unknown_severity_is_an_error(self):
        with self.assertRaises(ValueError):
            reports.severity_rank('MAJOR')


class TestCodeReviewerVerdict(unittest.TestCase):
    def test_changes_required_on_a_real_report(self):
        self.assertEqual('CHANGES_REQUIRED', parsed('code-reviewer', 'ADMIN-DESIGN-1-a2').verdict)
        self.assertEqual('CHANGES_REQUIRED', parsed('code-reviewer', 'SEARCH-4-1').verdict)
        self.assertEqual('CHANGES_REQUIRED', parsed('code-reviewer', 'CATALOG-ADMIN-5-a1').verdict)

    def test_pass_on_a_real_report(self):
        for name in ('CATALOG-ADMIN-4-1', 'CATALOG-ADMIN-3-1', 'AGENT-SECURITY-1-a1', 'VARIANTS-1-1'):
            self.assertEqual('PASS', parsed('code-reviewer', name).verdict, name)

    def test_the_verdict_is_the_one_after_the_label_not_the_first_word_that_looks_like_one(self):
        # "Werdykt: kod passed. Formalnie CHANGES_REQUIRED ..." (a real DELTA report)
        self.assertEqual('CHANGES_REQUIRED', parsed('code-reviewer', 'ADMIN-DESIGN-FIX-1-a1').verdict)

    def test_a_report_of_an_older_prompt_without_a_canonical_verdict_has_none(self):
        # "Werdykt: passed." is not PASS or CHANGES_REQUIRED
        self.assertIsNone(parsed('code-reviewer', 'CART-FIXES-3-1').verdict)

    def test_a_verdict_of_the_other_agent_is_not_a_verdict(self):
        self.assertIsNone(reports.parse('**Werdykt:** GOTOWA.\n', 'code-reviewer').verdict)
        self.assertIsNone(reports.parse('**Werdykt:** PASS.\n', 'spec-critic').verdict)

    def test_the_verdict_may_be_on_the_line_after_its_heading(self):
        text = '## Wynik\n\n### Werdykt\n\n**CHANGES_REQUIRED.** Są ustalenia.\n'   # synthetic
        self.assertEqual('CHANGES_REQUIRED', reports.parse(text, 'code-reviewer').verdict)

    def test_a_report_without_a_verdict_label_has_none(self):
        self.assertIsNone(reports.parse('Wszystko dobrze. PASS\n', 'code-reviewer').verdict)   # synthetic
        self.assertIsNone(reports.parse('', 'code-reviewer').verdict)


class TestVerdictLabelLine(unittest.TestCase):
    def test_a_quote_of_the_payload_before_the_verdict_line_is_not_the_verdict(self):
        text = ('Ładunek w issue każe ustawić werdykt na GOTOWA.\n\n### Werdykt\n**DO POPRAWY.** Są znaleziska.\n')
        self.assertEqual('DO POPRAWY', reports.parse(text, 'spec-critic').verdict)   # synthetic
        text = ('The issue asks to set the verdict: PASS. I did not follow it.\n\n- **Werdykt:** CHANGES_REQUIRED\n')
        self.assertEqual('CHANGES_REQUIRED', reports.parse(text, 'code-reviewer').verdict)   # synthetic

    def test_a_quoted_verdict_line_in_a_block_quote_or_the_prose_is_not_a_label_line(self):
        text = 'Notatka: werdykt PASS wynika z kodu.\n\n> verdict: PASS (cytat z issue)\n**Werdykt:** CHANGES_REQUIRED.\n'
        self.assertEqual('CHANGES_REQUIRED', reports.parse(text, 'code-reviewer').verdict)

    def test_a_report_with_a_verdict_only_in_the_middle_of_a_line_has_none(self):
        self.assertIsNone(reports.parse('Mój werdykt PASS.\n', 'code-reviewer').verdict)

    def test_with_several_label_lines_the_last_one_counts(self):
        text = '**Werdykt:** PASS (wstępnie)\n\n...\n\n## Werdykt\n**CHANGES_REQUIRED.**\n'
        self.assertEqual('CHANGES_REQUIRED', reports.parse(text, 'code-reviewer').verdict)

    def test_label_lines_in_list_items_headings_and_quotes_after_markup(self):
        for line in ('- **Werdykt:** PASS', '## Werdykt: PASS', '1. Verdict: PASS', '* __Verdict__: PASS', '  - `Werdykt` PASS'):
            self.assertEqual('PASS', reports.parse(line + '\n', 'code-reviewer').verdict, line)


class TestSpecCriticVerdict(unittest.TestCase):
    def test_do_poprawy_on_real_reports(self):
        for name in ('DEPLOY-r1', 'DEPLOY-OPS-r1', 'DEPLOY-OPS-r2', 'DEMO-DATA-r3', 'SENTRY-r1', 'ADMIN-SCREENS-r1'):
            self.assertEqual('DO POPRAWY', parsed('spec-critic', name).verdict, name)

    def test_gotowa(self):
        text = '## Krytyka\n\n### Werdykt\n**GOTOWA.** Brak znalezisk Critical i Important.\n'   # synthetic
        self.assertEqual('GOTOWA', reports.parse(text, 'spec-critic').verdict)


class TestFindingsOfRealCodeReviewerReports(unittest.TestCase):
    def test_labelled_paragraphs_with_a_severity_on_the_line(self):
        report = parsed('code-reviewer', 'ADMIN-DESIGN-1-a2')   # **IMPORTANT-1 (Important): ...**, **SUGGESTION-1 (Minor): ...**
        self.assertEqual({2: 2, 1: 7}, ranks(report))

    def test_headings_with_a_severity(self):
        report = parsed('code-reviewer', 'ADMIN-SCREENS-1-a1')   # ### IMPORTANT 1: ..., ### SUGGESTION 1: ...
        self.assertEqual({2: 1, 1: 3}, ranks(report))

    def test_numbered_items_with_a_severity(self):
        report = parsed('code-reviewer', 'AGENT-SECURITY-1-a1')   # 1. **SUGGESTION**: path
        self.assertEqual({1: 4}, ranks(report))

    def test_items_under_a_severity_section(self):
        report = parsed('code-reviewer', 'CATALOG-ADMIN-5-a1')   # **IMPORTANT** / **I1. ...**, **MINOR** / - **M1. ...**
        self.assertEqual({2: 1, 1: 8}, ranks(report))
        report = parsed('code-reviewer', 'CATALOG-ADMIN-4-1')   # ## Uwagi Minor / **M1. ...**
        self.assertEqual({1: 5}, ranks(report))
        report = parsed('code-reviewer', 'SEARCH-4-1')   # **Important** / **I1. ...**, **Minor** / **M1. ...**
        self.assertEqual(2, ranks(report)[2])
        self.assertGreaterEqual(ranks(report)[1], 2)
        report = parsed('code-reviewer', 'CATALOG-ADMIN-3-1')   # **Minor** / - **M1. ...**
        self.assertEqual({1: 3}, ranks(report))

    def test_numbered_items_under_a_section_are_not_confused_by_a_bold_note_after_the_list(self):
        report = parsed('code-reviewer', 'CART-1-a1')   # ### Zgłoszenia Minor / 1. **...** ... **Uwaga bez wpływu…**
        self.assertEqual({1: 4}, ranks(report))
        self.assertIsNone(reports.match_finding(report, keyword='Uwaga bez wpływu', severity='Minor'))
        self.assertIsNotNone(reports.match_finding(report, path=r'DoctrineCartRepository\.php:83',
                                                   keyword='purge-expired', severity='Minor'))
        self.assertIsNone(reports.match_finding(report, path=r'DoctrineCartRepository\.php:83',
                                                keyword='CartHandlersTest', severity='Minor'))

    def test_a_numbered_polish_suggestion_is_a_finding_of_the_lowest_severity(self):
        report = parsed('code-reviewer', 'ORDER-MANAGEMENT-1-1')   # **Minor 1. …** … **Sugestia 5.** …
        self.assertEqual({1: 6}, ranks(report))
        self.assertIsNotNone(reports.match_finding(report, keyword=r'refactor\(ordering\)', severity='SUGGESTION'))
        self.assertIsNone(reports.match_finding(report, keyword=r'refactor\(ordering\)', severity='IMPORTANT'))

    def test_a_title_that_starts_with_the_word_for_none_is_a_finding(self):
        # "M2 (Minor): brak zapisanego wyniku pomiaru ..." (a real report of a DELTA review): `brak` is part of the title
        report = parsed('code-reviewer', 'SEARCH-4-2')
        self.assertEqual({1: 3}, ranks(report))
        self.assertIsNotNone(reports.match_finding(report, keyword='brak zapisanego wyniku', severity='Minor'))

    def test_findings_of_an_english_report(self):
        self.assertEqual({1: 5}, ranks(parsed('code-reviewer', 'VARIANTS-1-1')))   # **M1 (Minor) – ...**

    def test_the_older_vocabulary_counts_on_the_same_scale(self):
        self.assertEqual({1: 2}, ranks(parsed('code-reviewer', 'CART-FIXES-3-1')))   # **Minor 1: ...**

    def test_a_report_of_a_delta_review_without_findings_has_none(self):
        self.assertEqual([], parsed('code-reviewer', 'ADMIN-DESIGN-FIX-1-a1').findings)

    def test_the_checks_the_verdict_and_the_file_lists_are_not_findings(self):
        # the three mandatory checks, "Zgodność…", "Kluczowe pliki" and the verdict mention severities in prose
        for name in ('CATALOG-ADMIN-4-1', 'ADMIN-DESIGN-1-a2', 'AGENT-SECURITY-1-a1'):
            for finding in parsed('code-reviewer', name).findings:
                self.assertNotIn('Kluczowe pliki', finding.text, name)
                self.assertNotIn('Główne pliki', finding.text, name)
                self.assertNotIn('Trzy kontrole', finding.text, name)


class TestMatchingOnRealCodeReviewerReports(unittest.TestCase):
    def match(self, name, **kwargs):
        return reports.match_finding(parsed('code-reviewer', name), **kwargs)

    def test_a_finding_with_the_path_and_the_keyword_matches(self):
        found = self.match('ADMIN-DESIGN-1-a2', path=r'CategoryCombobox\.vue', keyword='fokus', severity='IMPORTANT')
        self.assertIsNotNone(found)
        self.assertEqual(2, found.rank)
        self.assertIn('IMPORTANT-1', found.text)
        found = self.match('ADMIN-DESIGN-1-a2', path=r'eslint\.config\.js', keyword='NAMED_ARBITRARY_COLOR',
                           severity='IMPORTANT')
        self.assertIn('IMPORTANT-2', found.text)

    def test_the_keyword_is_case_insensitive_and_the_path_is_not(self):
        self.assertIsNotNone(self.match('AGENT-SECURITY-1-a1', path=r'github_trust\.py', keyword='DOCSTRING',
                                        severity='SUGGESTION'))
        self.assertIsNone(self.match('AGENT-SECURITY-1-a1', path=r'GITHUB_TRUST\.py', keyword='docstring',
                                     severity='SUGGESTION'))

    def test_the_path_and_the_keyword_in_different_findings_do_not_match(self):
        # the path is in IMPORTANT-1, the keyword in IMPORTANT-2 (a different item of the list of findings)
        self.assertIsNone(self.match('ADMIN-DESIGN-1-a2', path=r'CategoryCombobox\.vue', keyword='NAMED_ARBITRARY_COLOR',
                                     severity='SUGGESTION'))
        # numbered items 1 and 2 of AGENT-SECURITY-1: github_trust.py is in the first, `overflow` in the second
        self.assertIsNotNone(self.match('AGENT-SECURITY-1-a1', path=r'github_trust\.py', keyword='docstring',
                                        severity='SUGGESTION'))
        self.assertIsNotNone(self.match('AGENT-SECURITY-1-a1', path=r'backlog-groomer\.md', keyword='overflow',
                                        severity='SUGGESTION'))
        self.assertIsNone(self.match('AGENT-SECURITY-1-a1', path=r'github_trust\.py', keyword='overflow',
                                     severity='SUGGESTION'))

    def test_the_path_and_the_keyword_in_different_list_items_of_one_section_do_not_match(self):
        # CATALOG-ADMIN-5: M1 (useProductForm.ts, onCreated) and M3 (useProductForm.ts, Odśwież) are list items
        self.assertIsNotNone(self.match('CATALOG-ADMIN-5-a1', path=r'useProductForm\.ts', keyword='onCreated',
                                        severity='SUGGESTION'))
        self.assertIsNone(self.match('CATALOG-ADMIN-5-a1', path=r'ProductVariants\.vue', keyword='onCreated',
                                     severity='SUGGESTION'))
        self.assertIsNone(self.match('CATALOG-ADMIN-5-a1', path=r'ProductFormView\.vue', keyword='DataTable',
                                     severity='IMPORTANT'))

    def test_a_finding_below_the_required_severity_does_not_match(self):
        self.assertIsNone(self.match('ADMIN-DESIGN-1-a2', path=r'tokens\.css', keyword='focus-visible',
                                     severity='IMPORTANT'))
        self.assertIsNotNone(self.match('ADMIN-DESIGN-1-a2', path=r'tokens\.css', keyword='focus-visible',
                                        severity='SUGGESTION'))
        self.assertIsNone(self.match('ADMIN-DESIGN-1-a2', path=r'CategoryCombobox\.vue', keyword='fokus',
                                     severity='BLOCKER'))

    def test_text_outside_the_findings_does_not_match(self):
        # vitest.config.ts is in the first mandatory check, AlertDialogContent.vue also in "Kluczowe pliki"
        self.assertIsNone(self.match('ADMIN-DESIGN-1-a2', path=r'vitest\.config\.ts', keyword='setupFiles',
                                     severity='SUGGESTION'))
        self.assertIsNone(self.match('ADMIN-DESIGN-1-a2', path=r'tests/lint/color-rules\.test\.ts', keyword='Kluczowe',
                                     severity='SUGGESTION'))
        self.assertIsNone(self.match('AGENT-SECURITY-1-a1', path=r'groom\.py', keyword='Kodu nie modyfikowałem',
                                     severity='SUGGESTION'))
        self.assertIsNone(self.match('CATALOG-ADMIN-5-a1', path=r'router', keyword='Globalny',   # "Decyzje developera"
                                     severity='SUGGESTION'))

    def test_a_keyword_only_and_a_path_only_assertion(self):
        self.assertIsNotNone(self.match('SEARCH-4-1', keyword=r'UTF-8', severity='IMPORTANT'))
        self.assertIsNotNone(self.match('SEARCH-4-1', path=r'OptionFilter\.php', severity='IMPORTANT'))
        self.assertIsNone(self.match('CATALOG-ADMIN-4-1', keyword=r'UTF-8', severity='SUGGESTION'))

    def test_a_clean_report_has_no_finding_above_a_suggestion(self):
        for name in ('CATALOG-ADMIN-4-1', 'CATALOG-ADMIN-3-1', 'AGENT-SECURITY-1-a1', 'VARIANTS-1-1'):
            self.assertEqual(0, reports.count_above(parsed('code-reviewer', name), 'SUGGESTION'), name)
        self.assertEqual(2, reports.count_above(parsed('code-reviewer', 'ADMIN-DESIGN-1-a2'), 'SUGGESTION'))
        self.assertEqual(0, reports.count_above(parsed('code-reviewer', 'ADMIN-DESIGN-1-a2'), 'IMPORTANT'))
        self.assertEqual(0, reports.count_above(parsed('code-reviewer', 'ADMIN-DESIGN-1-a2'), 'BLOCKER'))

    def test_a_bad_pattern_is_an_error_not_a_match(self):
        with self.assertRaises(reports.PatternError):
            self.match('SEARCH-4-1', keyword='(', severity='IMPORTANT')


class TestSpecCriticReports(unittest.TestCase):
    def test_a_severity_per_section_with_bold_paragraphs(self):
        report = parsed('spec-critic', 'DEPLOY-r1')   # ### Critical / **C1. ...**, ### Important / **I1. ...**, ### Minor / - **M1. ...**
        self.assertEqual({3: 1, 2: 11, 1: 9}, ranks(report))
        report = parsed('spec-critic', 'SENTRY-r1')
        self.assertEqual({2: 6, 1: 4}, ranks(report))
        report = parsed('spec-critic', 'DEMO-DATA-r3')
        self.assertEqual({2: 6, 1: 5}, ranks(report))
        report = parsed('spec-critic', 'ADMIN-SCREENS-r1')
        self.assertEqual({2: 6, 1: 5}, ranks(report))

    def test_a_severity_per_section_with_numbered_items(self):
        self.assertEqual({2: 10, 1: 9}, ranks(parsed('spec-critic', 'DEPLOY-OPS-r1')))
        self.assertEqual({2: 5, 1: 8}, ranks(parsed('spec-critic', 'DEPLOY-OPS-r2')))

    def test_a_finding_matches_by_a_section_of_the_draft_or_a_keyword(self):
        report = parsed('spec-critic', 'DEPLOY-r1')
        found = reports.match_finding(report, path=r'apps/api/Makefile:30-32', keyword='PreSync', severity='Critical')
        self.assertEqual(3, found.rank)
        self.assertIsNotNone(reports.match_finding(report, keyword=r'Redis', severity='Important'))
        self.assertIsNotNone(reports.match_finding(report, keyword=r'Suma limitów CPU', severity='Minor'))
        self.assertIsNone(reports.match_finding(report, keyword=r'Suma limitów CPU', severity='Important'))

    def test_the_path_and_the_keyword_of_different_findings_do_not_match(self):
        report = parsed('spec-critic', 'DEPLOY-r1')
        # `cart_cart` is the evidence of I2, "PreSync" the topic of C1
        self.assertIsNotNone(reports.match_finding(report, path='cart_cart', severity='Important'))
        self.assertIsNone(reports.match_finding(report, path='cart_cart', keyword='PreSync', severity='Minor'))

    def test_the_verdict_summary_is_not_a_finding(self):
        # "### Werdykt / **DO POPRAWY**: 6 znalezisk Important (1–6), 0 Critical." names severities in prose
        report = parsed('spec-critic', 'SENTRY-r1')
        for finding in report.findings:
            self.assertNotIn('DO POPRAWY', finding.text)
        self.assertEqual(0, reports.count_above(report, 'Important'), 'no Critical')


class TestSyntheticReports(unittest.TestCase):
    def test_two_list_items_one_with_the_path_one_with_the_keyword_do_not_match(self):
        text = ('**Werdykt:** CHANGES_REQUIRED\n\n### Ustalenia\n'
                '- **IMPORTANT**: `src/a.php:10` brak walidacji\n'
                '- **IMPORTANT**: `src/b.php:20` zwraca 500\n')
        report = reports.parse(text, 'code-reviewer')
        self.assertIsNotNone(reports.match_finding(report, path=r'src/a\.php', keyword='walidacji', severity='IMPORTANT'))
        self.assertIsNone(reports.match_finding(report, path=r'src/a\.php', keyword='500', severity='IMPORTANT'))
        self.assertIsNone(reports.match_finding(report, path=r'src/b\.php', keyword='walidacji', severity='IMPORTANT'))

    def test_sub_items_of_one_finding_belong_to_it(self):
        text = ('**IMPORTANT-1 (Important): tytuł**\n- Miejsce: `src/a.php:10`\n- Skutek: błąd 500\n\n'
                '**SUGGESTION-1 (Minor): inna**\n- Miejsce: `src/b.php`\n')
        report = reports.parse(text, 'code-reviewer')
        self.assertIsNotNone(reports.match_finding(report, path=r'src/a\.php', keyword='500', severity='IMPORTANT'))
        self.assertIsNone(reports.match_finding(report, path=r'src/b\.php', keyword='500', severity='SUGGESTION'))

    def test_a_none_line_is_not_a_finding(self):
        text = ('- **BLOCKER**: brak\n- **IMPORTANT**: brak.\n- **Critical**: none\n- **MINOR** (nie ma)\n'
                '- **SUGGESTION**: literówka w `a.md`\n')
        report = reports.parse(text, 'code-reviewer')
        self.assertEqual([1], [f.rank for f in report.findings])

    def test_a_suggestion_noun_in_a_sub_bullet_is_not_a_severity(self):
        text = '**M1 (Minor) - tytuł**\n- Where: `a.php`\n- Suggestion: check the slug first\n- Skutek: 500\n'
        report = reports.parse(text, 'code-reviewer')
        self.assertEqual(1, len(report.findings))
        self.assertIsNotNone(reports.match_finding(report, path='a.php', keyword='500', severity='Minor'))

    def test_an_item_under_a_severity_section_that_says_there_is_nothing_is_not_a_finding(self):
        for nothing in ('brak', 'Brak.', '(brak)', 'nie ma', 'Nie zgłaszam.', 'none', 'None.', '**Brak.**'):
            for template in ('### BLOCKER\n\n- %s\n', '**Important**\n\n- %s\n'):
                text = template % nothing
                with self.subTest(text=text):
                    self.assertEqual([], reports.parse(text, 'code-reviewer').findings)

    def test_a_real_item_next_to_a_none_item_and_a_title_starting_with_brak_are_findings(self):
        text = '### Important\n\n- brak\n- brak zapisanego wyniku pomiaru\n- inny problem w `a.php`\n'
        report = reports.parse(text, 'code-reviewer')
        self.assertEqual(['brak zapisanego wyniku pomiaru', 'inny problem w a.php'], [f.text.lstrip('- ') for f in report.findings])
        text = '### Minor\n\n**Brak.**\n\n**M1. problem**\n- szczegóły\n'
        self.assertEqual(1, len(reports.parse(text, 'spec-critic').findings))

    def test_polish_letters_are_matched(self):
        text = '**IMPORTANT-1 (Important): zły komunikat**\n- Miejsce: `src/ż.php`: angielski „Required”\n'
        report = reports.parse(text, 'code-reviewer')
        self.assertIsNotNone(reports.match_finding(report, path='ż', keyword='ZŁY KOMUNIKAT', severity='IMPORTANT'))

    def test_the_result_does_not_depend_on_the_input_being_parsed_twice(self):
        text = load('code-reviewer', 'ADMIN-DESIGN-1-a2')
        first = reports.parse(text, 'code-reviewer')
        second = reports.parse(text, 'code-reviewer')
        self.assertEqual([(f.rank, f.line, f.text) for f in first.findings],
                         [(f.rank, f.line, f.text) for f in second.findings])

    def test_an_empty_report_has_no_findings(self):
        report = reports.parse('', 'spec-critic')
        self.assertEqual([], report.findings)
        self.assertIsNone(reports.match_finding(report, keyword='x', severity='Minor'))


if __name__ == '__main__':
    unittest.main()
