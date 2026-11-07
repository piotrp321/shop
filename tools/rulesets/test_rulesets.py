"""Structure tests of the repository rulesets in this directory (offline, standard library only).

The files are request bodies of `POST /repos/{owner}/{repo}/rulesets`. GitHub applies the bypass list of a ruleset to
all of its rules, so the deploy branch is protected by two rulesets: `deploy` (deletion and non-fast-forward, no bypass
actor at all) and `deploy-push` (the rules `creation` and `update`, whose only bypass actor is the deploy key, so
nobody else can create the branch on any commit). The tests also try the
ways of weakening them: a bypass actor in the ruleset with the unconditional rules, another actor type, a mode other
than `always`, another rule next to `creation` and `update`, either of them missing, a wider ref pattern.
"""
import copy
import json
import os
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
UNCONDITIONAL = {"deletion", "non_fast_forward"}


def load(name):
    with open(os.path.join(HERE, name), encoding="utf-8") as file:
        return json.load(file)


def problems(deploy, push):
    """What is wrong with the pair of rulesets that protect refs/heads/deploy (empty list: nothing)."""
    found = []
    for ruleset in (deploy, push):
        name = ruleset.get("name")
        if ruleset.get("target") != "branch":
            found.append(f"{name}: target is not branch")
        if ruleset.get("enforcement") != "active":
            found.append(f"{name}: enforcement is not active")
        ref_name = ruleset.get("conditions", {}).get("ref_name", {})
        if ref_name.get("include") != ["refs/heads/deploy"] or ref_name.get("exclude") != []:
            found.append(f"{name}: the ref pattern is not exactly refs/heads/deploy without exclusions")
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


class DeployRulesets(unittest.TestCase):
    def setUp(self):
        self.deploy = load("deploy.json")
        self.push = load("deploy-push.json")

    def test_the_files_as_committed_have_no_problems(self):
        self.assertEqual([], problems(self.deploy, self.push))

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
        for pattern in (["refs/heads/*"], ["refs/heads/deploy*"], ["~DEFAULT_BRANCH"], ["refs/heads/deploy", "refs/heads/main"]):
            deploy = copy.deepcopy(self.deploy)
            deploy["conditions"]["ref_name"]["include"] = pattern
            self.assert_caught(deploy, self.push, "ref pattern")
        push = copy.deepcopy(self.push)
        push["conditions"]["ref_name"]["exclude"] = ["refs/heads/deploy"]
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


class MissingFile(unittest.TestCase):
    def test_a_missing_file_is_an_error(self):
        with self.assertRaises(FileNotFoundError):
            load("does-not-exist.json")


if __name__ == "__main__":
    unittest.main()
