"""Structure tests of the repository rulesets in this directory (offline, standard library only).

The files are request bodies of `POST /repos/{owner}/{repo}/rulesets`. GitHub applies the bypass list of a ruleset to
all of its rules, so the deploy branches (`deploy` and `deploy-images`, the snapshots Argo CD tracks) are protected by
two rulesets, both covering exactly these two refs: `deploy` (deletion and non-fast-forward, no bypass
actor at all) and `deploy-push` (the rules `creation` and `update`, whose only bypass actor is the deploy key, so
nobody else can create the branch on any commit). The tests also try the
ways of weakening them: a bypass actor in the ruleset with the unconditional rules, another actor type, a mode other
than `always`, another rule next to `creation` and `update`, either of them missing, a wider ref pattern.

`main` and `epic/*` follow the same split into two rulesets: `main` and `epic` (deletion and non-fast-forward) and
`main-merge` and `epic-merge` (pull request and the required checks `checks` and `e2e`). None of the four has a bypass
actor, so nobody, the repository owner included, can merge a pull request without green `checks` and `e2e`, push
directly, delete the branch or force-push. The tests try the ways of adding a bypass actor back (any actor type and
mode) and of weakening the rules.
"""
import copy
import json
import os
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
UNCONDITIONAL = {"deletion", "non_fast_forward"}
DEPLOY_REFS = ["refs/heads/deploy", "refs/heads/deploy-images"]
MERGE_RULES = ["pull_request", "required_status_checks"]
REQUIRED_CHECKS = {"checks", "e2e"}


def load(name):
    with open(os.path.join(HERE, name), encoding="utf-8") as file:
        return json.load(file)


def problems(deploy, push):
    """What is wrong with the pair of rulesets that protect the deploy branches (empty list: nothing)."""
    found = []
    for ruleset in (deploy, push):
        name = ruleset.get("name")
        if ruleset.get("target") != "branch":
            found.append(f"{name}: target is not branch")
        if ruleset.get("enforcement") != "active":
            found.append(f"{name}: enforcement is not active")
        ref_name = ruleset.get("conditions", {}).get("ref_name", {})
        if ref_name.get("include") != DEPLOY_REFS or ref_name.get("exclude") != []:
            found.append(f"{name}: the ref pattern is not exactly {' and '.join(DEPLOY_REFS)} without exclusions")
    deploy_types = [rule.get("type") for rule in deploy.get("rules", [])]
    if sorted(deploy_types) != sorted(UNCONDITIONAL):
        found.append(f"deploy: the rules are {deploy_types}, not deletion and non_fast_forward")
    if any(rule.get("parameters") for rule in deploy.get("rules", [])):
        found.append("deploy: a rule has parameters (an exception)")
    if deploy.get("bypass_actors") != []:
        found.append("deploy: has a bypass actor (it would lift deletion and non_fast_forward for it)")
    if sorted(rule.get("type") for rule in push.get("rules", [])) != ["creation", "update"]:
        found.append("deploy-push: the rules must be exactly creation and update")
    if any(rule.get("parameters") for rule in push.get("rules", [])):
        found.append("deploy-push: a rule has parameters")
    actors = push.get("bypass_actors")
    if not (isinstance(actors, list) and len(actors) == 1):
        found.append("deploy-push: must have exactly one bypass actor")
    else:
        actor = actors[0]
        if actor.get("actor_type") != "DeployKey":
            found.append("deploy-push: the bypass actor is not a DeployKey")
        if actor.get("actor_id") is not None:
            found.append("deploy-push: a DeployKey bypass actor has no actor_id")
        if actor.get("bypass_mode") != "always":
            found.append("deploy-push: the bypass mode is not always")
    if UNCONDITIONAL & {rule.get("type") for rule in push.get("rules", [])}:
        found.append("deploy-push: has deletion or non_fast_forward next to a bypass actor")
    return found



def merge_problems(plain, merge):
    """What is wrong with a branch's pair of rulesets: `plain` (deletion, non-fast-forward) and `merge` (pull request,
    required checks), both without a bypass actor."""
    found = []
    base = plain.get("name")
    if merge.get("name") != f"{base}-merge":
        found.append(f"{base}: the merge ruleset is not named {base}-merge")
    for ruleset in (plain, merge):
        name = ruleset.get("name")
        if ruleset.get("target") != "branch":
            found.append(f"{name}: target is not branch")
        if ruleset.get("enforcement") != "active":
            found.append(f"{name}: enforcement is not active")
    if plain.get("conditions") != merge.get("conditions"):
        found.append(f"{base}: the ref pattern of the merge ruleset differs from its pair")
    plain_types = [rule.get("type") for rule in plain.get("rules", [])]
    if sorted(plain_types) != sorted(UNCONDITIONAL):
        found.append(f"{base}: the rules are {plain_types}, not deletion and non_fast_forward")
    if plain.get("bypass_actors") != []:
        found.append(f"{base}: has a bypass actor (it would lift deletion and non_fast_forward for it)")
    merge_types = sorted(rule.get("type") for rule in merge.get("rules", []))
    if merge_types != MERGE_RULES:
        found.append(f"{base}-merge: the rules are {merge_types}, not pull_request and required_status_checks")
    checks = set()
    for rule in merge.get("rules", []):
        if rule.get("type") == "required_status_checks":
            parameters = rule.get("parameters", {})
            checks = {check.get("context") for check in parameters.get("required_status_checks", [])}
    if not REQUIRED_CHECKS <= checks:
        found.append(f"{base}-merge: the required checks {sorted(checks)} lack checks or e2e")
    if merge.get("bypass_actors") != []:
        found.append(f"{base}-merge: has a bypass actor (it could merge without green checks and e2e)")
    return found


class DeployRulesets(unittest.TestCase):
    def setUp(self):
        self.deploy = load("deploy.json")
        self.push = load("deploy-push.json")

    def test_the_files_as_committed_have_no_problems(self):
        self.assertEqual([], problems(self.deploy, self.push))

    def test_both_rulesets_cover_deploy_and_deploy_images(self):
        for ruleset in (self.deploy, self.push):
            self.assertEqual(DEPLOY_REFS, ruleset["conditions"]["ref_name"]["include"])
            self.assertEqual([], ruleset["conditions"]["ref_name"]["exclude"])

    def test_names(self):
        self.assertEqual("deploy", self.deploy["name"])
        self.assertEqual("deploy-push", self.push["name"])

    def test_deploy_forbids_deletion_and_force_push_for_everyone(self):
        self.assertEqual([], self.deploy["bypass_actors"])
        self.assertEqual({"deletion", "non_fast_forward"}, {rule["type"] for rule in self.deploy["rules"]})

    def test_only_the_deploy_key_may_create_and_update(self):
        self.assertEqual(
            [{"actor_type": "DeployKey", "actor_id": None, "bypass_mode": "always"}], self.push["bypass_actors"]
        )
        self.assertEqual(["creation", "update"], sorted(rule["type"] for rule in self.push["rules"]))

    def test_every_ruleset_with_a_bypass_actor_lacks_the_unconditional_rules(self):
        for name in sorted(os.listdir(HERE)):
            if not name.endswith(".json"):
                continue
            ruleset = load(name)
            if ruleset.get("bypass_actors"):
                self.assertFalse(UNCONDITIONAL & {rule["type"] for rule in ruleset["rules"]}, name)


class AttemptsToWeakenThem(unittest.TestCase):
    """The check itself must catch each weakening (a check that always passes proves nothing)."""

    def setUp(self):
        self.deploy = load("deploy.json")
        self.push = load("deploy-push.json")

    def assert_caught(self, deploy, push, expected):
        found = problems(deploy, push)
        self.assertTrue(any(expected in line for line in found), f"{expected!r} not in {found}")

    def test_bypass_actor_in_deploy(self):
        deploy = copy.deepcopy(self.deploy)
        deploy["bypass_actors"] = [{"actor_type": "DeployKey", "actor_id": None, "bypass_mode": "always"}]
        self.assert_caught(deploy, self.push, "deploy: has a bypass actor")

    def test_unconditional_rules_moved_next_to_the_bypass_actor(self):
        push = copy.deepcopy(self.push)
        push["rules"].append({"type": "non_fast_forward"})
        self.assert_caught(self.deploy, push, "deploy-push")

    def test_missing_rule_in_deploy(self):
        for dropped in ("deletion", "non_fast_forward"):
            deploy = copy.deepcopy(self.deploy)
            deploy["rules"] = [rule for rule in deploy["rules"] if rule["type"] != dropped]
            self.assert_caught(deploy, self.push, "deploy: the rules are")

    def test_another_actor(self):
        for actor in (
            {"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "always"},
            {"actor_type": "OrganizationAdmin", "actor_id": 1, "bypass_mode": "always"},
            {"actor_type": "User", "actor_id": 1, "bypass_mode": "always"},
        ):
            push = copy.deepcopy(self.push)
            push["bypass_actors"] = [actor]
            self.assert_caught(self.deploy, push, "not a DeployKey")

    def test_missing_creation_or_update_in_deploy_push(self):
        for dropped in ("creation", "update"):
            push = copy.deepcopy(self.push)
            push["rules"] = [rule for rule in push["rules"] if rule["type"] != dropped]
            self.assert_caught(self.deploy, push, "creation and update")

    def test_second_actor(self):
        push = copy.deepcopy(self.push)
        push["bypass_actors"].append({"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "always"})
        self.assert_caught(self.deploy, push, "exactly one bypass actor")

    def test_other_bypass_mode(self):
        for mode in ("exempt", "pull_request"):
            push = copy.deepcopy(self.push)
            push["bypass_actors"][0]["bypass_mode"] = mode
            self.assert_caught(self.deploy, push, "bypass mode")

    def test_no_bypass_actor_at_all(self):
        push = copy.deepcopy(self.push)
        push["bypass_actors"] = []
        self.assert_caught(self.deploy, push, "exactly one bypass actor")

    def test_wider_ref_pattern_and_exclusions(self):
        for pattern in (
            ["refs/heads/*"],
            ["refs/heads/deploy*"],
            ["~DEFAULT_BRANCH"],
            ["refs/heads/deploy"],
            ["refs/heads/deploy-images"],
            ["refs/heads/deploy-images", "refs/heads/deploy"],
            ["refs/heads/deploy", "refs/heads/deploy-images", "refs/heads/main"],
            ["refs/heads/deploy", "refs/heads/main"],
        ):
            deploy = copy.deepcopy(self.deploy)
            deploy["conditions"]["ref_name"]["include"] = pattern
            self.assert_caught(deploy, self.push, "ref pattern")
        push = copy.deepcopy(self.push)
        push["conditions"]["ref_name"]["exclude"] = ["refs/heads/deploy-images"]
        self.assert_caught(self.deploy, push, "ref pattern")
        push = copy.deepcopy(self.push)
        push["conditions"]["ref_name"]["include"] = ["refs/heads/deploy"]
        self.assert_caught(self.deploy, push, "ref pattern")

    def test_not_active_or_not_a_branch_ruleset(self):
        deploy = copy.deepcopy(self.deploy)
        deploy["enforcement"] = "evaluate"
        self.assert_caught(deploy, self.push, "enforcement")
        push = copy.deepcopy(self.push)
        push["target"] = "tag"
        self.assert_caught(self.deploy, push, "target")

    def test_parameters_on_a_rule(self):
        deploy = copy.deepcopy(self.deploy)
        deploy["rules"][0]["parameters"] = {"anything": True}
        self.assert_caught(deploy, self.push, "parameters")
        push = copy.deepcopy(self.push)
        push["rules"][0]["parameters"] = {"update_allows_fetch_and_merge": True}
        self.assert_caught(self.deploy, push, "parameters")


class MergeRulesets(unittest.TestCase):
    def test_the_files_as_committed_have_no_problems(self):
        for base in ("main", "epic"):
            self.assertEqual([], merge_problems(load(f"{base}.json"), load(f"{base}-merge.json")), base)

    def test_nobody_can_bypass_the_merge_rulesets(self):
        for base in ("main", "epic"):
            self.assertEqual([], load(f"{base}-merge.json")["bypass_actors"], base)
            self.assertEqual([], load(f"{base}.json")["bypass_actors"], base)

    def test_ref_patterns(self):
        self.assertEqual(["~DEFAULT_BRANCH"], load("main.json")["conditions"]["ref_name"]["include"])
        self.assertEqual(["refs/heads/epic/*"], load("epic.json")["conditions"]["ref_name"]["include"])

    def test_epic_branches_can_be_created_from_main(self):
        rule = next(r for r in load("epic-merge.json")["rules"] if r["type"] == "required_status_checks")
        self.assertTrue(rule["parameters"]["do_not_enforce_on_create"])

    def test_epic_allows_squash_and_merge_commit(self):
        rule = next(r for r in load("epic-merge.json")["rules"] if r["type"] == "pull_request")
        self.assertEqual({"squash", "merge"}, set(rule["parameters"]["allowed_merge_methods"]))


class AttemptsToWeakenTheMergeRulesets(unittest.TestCase):
    """Each way of adding a bypass actor or weakening the merge rules must be caught."""

    BYPASS_ACTORS = (
        {"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "pull_request"},
        {"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "always"},
        {"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "exempt"},
        {"actor_type": "RepositoryRole", "actor_id": 4, "bypass_mode": "pull_request"},
        {"actor_type": "RepositoryRole", "actor_id": 2, "bypass_mode": "always"},
        {"actor_type": "Integration", "actor_id": 1, "bypass_mode": "always"},
        {"actor_type": "DeployKey", "actor_id": None, "bypass_mode": "always"},
        {"actor_type": "OrganizationAdmin", "actor_id": 1, "bypass_mode": "pull_request"},
        {"actor_type": "Team", "actor_id": 1, "bypass_mode": "pull_request"},
        {"actor_type": "User", "actor_id": 1, "bypass_mode": "pull_request"},
    )

    def setUp(self):
        self.pairs = [(load(f"{base}.json"), load(f"{base}-merge.json")) for base in ("main", "epic")]

    def assert_caught(self, change_plain, change_merge, expected):
        for plain, merge in self.pairs:
            plain, merge = copy.deepcopy(plain), copy.deepcopy(merge)
            change_plain(plain)
            change_merge(merge)
            found = merge_problems(plain, merge)
            self.assertTrue(any(expected in line for line in found), f"{expected!r} not in {found}")

    @staticmethod
    def keep(_):
        pass

    def test_any_bypass_actor_on_the_merge_ruleset(self):
        for actor in self.BYPASS_ACTORS:
            def change(merge, actor=actor):
                merge["bypass_actors"] = [dict(actor)]
            self.assert_caught(self.keep, change, "-merge: has a bypass actor")

    def test_a_bypass_list_that_is_not_an_empty_list(self):
        for value in (None, {}, [{}]):
            def change(merge, value=value):
                merge["bypass_actors"] = value
            self.assert_caught(self.keep, change, "-merge: has a bypass actor")

        def drop(merge):
            del merge["bypass_actors"]
        self.assert_caught(self.keep, drop, "-merge: has a bypass actor")

    def test_bypass_actor_on_the_plain_ruleset(self):
        def change(plain):
            plain["bypass_actors"] = [{"actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "pull_request"}]
        self.assert_caught(change, self.keep, "has a bypass actor")

    def test_unconditional_rule_moved_to_the_merge_ruleset(self):
        for moved in ("deletion", "non_fast_forward"):
            def change_plain(plain, moved=moved):
                plain["rules"] = [rule for rule in plain["rules"] if rule["type"] != moved]

            def change_merge(merge, moved=moved):
                merge["rules"].append({"type": moved})
            self.assert_caught(change_plain, change_merge, "the rules are")

    def test_missing_required_check(self):
        for dropped in ("checks", "e2e"):
            def change(merge, dropped=dropped):
                for rule in merge["rules"]:
                    if rule["type"] == "required_status_checks":
                        checks = rule["parameters"]["required_status_checks"]
                        rule["parameters"]["required_status_checks"] = [c for c in checks if c["context"] != dropped]
            self.assert_caught(self.keep, change, "lack checks or e2e")

    def test_missing_pull_request_rule(self):
        def change(merge):
            merge["rules"] = [rule for rule in merge["rules"] if rule["type"] != "pull_request"]
        self.assert_caught(self.keep, change, "not pull_request and required_status_checks")

    def test_ref_pattern_differs_from_its_pair(self):
        def change(merge):
            merge["conditions"]["ref_name"]["exclude"] = ["refs/heads/epic/x"]
        self.assert_caught(self.keep, change, "ref pattern")

    def test_not_active(self):
        def change(merge):
            merge["enforcement"] = "evaluate"
        self.assert_caught(self.keep, change, "enforcement")


class MissingFile(unittest.TestCase):
    def test_a_missing_file_is_an_error(self):
        with self.assertRaises(FileNotFoundError):
            load("does-not-exist.json")


if __name__ == "__main__":
    unittest.main()
