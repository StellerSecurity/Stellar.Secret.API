import copy
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import deploy_private_base as deployment


class FakeAzure:
    def __init__(self):
        self.config = {
            'ipSecurityRestrictions': [{'name': 'Deny all', 'action': 'Deny', 'ipAddress': 'Any', 'priority': 2147483647}],
            'ipSecurityRestrictionsDefaultAction': 'Deny',
            'scmIpSecurityRestrictionsUseMain': False,
            'scmIpSecurityRestrictions': [{'name': 'Block-SCM-All', 'action': 'Deny', 'ipAddress': '0.0.0.0/0', 'priority': 100}],
        }
        self.fail_after_write = False
        self.writes = 0

    def read(self):
        return copy.deepcopy(self.config)

    def write_scm(self, rules):
        for rule in rules:
            if any(char in rule.get('description', '') for char in ';,'):
                raise ValueError("Description cannot contain ';' or ','.")
        self.writes += 1
        self.config['scmIpSecurityRestrictions'] = copy.deepcopy(rules)
        if self.fail_after_write:
            self.fail_after_write = False
            raise TimeoutError('Server applied mutation before response timed out')


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.api = FakeAzure()
        self.original = self.api.read()
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.state = Path(self.directory.name) / 'state.json'

    def prepare(self, address=b'20.1.2.3\n'):
        with patch.object(deployment.urllib.request, 'urlopen', return_value=io.BytesIO(address)):
            deployment.prepare(self.api, 'stellar-deploy-123', self.state)

    def test_only_single_runner_scm_rule_changes_then_full_cleanup(self):
        self.prepare()
        self.assertEqual(deployment.main_rules(self.original), deployment.main_rules(self.api.read()))
        self.assertEqual(self.api.read()['scmIpSecurityRestrictions'][-1]['ipAddress'], '20.1.2.3/32')
        self.assertEqual(self.api.read()['scmIpSecurityRestrictions'][-1]['tag'], 'Default')
        deployment.cleanup(self.api, 'stellar-deploy-123', deployment.main_rules(self.original))
        self.assertEqual(self.original, self.api.read())

    def test_response_timeout_after_applied_mutation_still_cleans_up(self):
        self.api.fail_after_write = True
        with self.assertRaises(TimeoutError):
            self.prepare()
        self.assertEqual(self.original, self.api.read())

    def test_cleanup_keeps_other_deployments_and_existing_rules(self):
        self.prepare()
        other = {'name': 'stellar-deploy-456', 'ipAddress': '20.4.5.6/32', 'action': 'Allow', 'priority': 90}
        self.api.config['scmIpSecurityRestrictions'].append(other)
        deployment.cleanup(self.api, 'stellar-deploy-123')
        self.assertEqual(self.original['scmIpSecurityRestrictions'] + [other], self.api.read()['scmIpSecurityRestrictions'])

    def test_shared_main_rules_rejected_before_mutation(self):
        self.api.config['scmIpSecurityRestrictionsUseMain'] = True
        with self.assertRaises(RuntimeError):
            self.prepare()
        self.assertEqual(0, self.api.writes)

    def test_public_main_allow_rejected_before_mutation(self):
        self.api.config['ipSecurityRestrictions'].append({'action': 'Allow', 'ipAddress': 'Any'})
        with self.assertRaises(RuntimeError):
            self.prepare()
        self.assertEqual(0, self.api.writes)

    def test_private_runner_ip_rejected_before_mutation(self):
        with self.assertRaises(RuntimeError):
            self.prepare(b'10.1.2.3')
        self.assertEqual(0, self.api.writes)

    def test_http_failure_reports_status_without_sensitive_body(self):
        error = deployment.urllib.error.HTTPError(deployment.RESOURCE, 400, 'secret', {},
                                                 io.BytesIO(b'private response'))
        with patch.object(deployment.urllib.request, 'urlopen', side_effect=error):
            with self.assertRaisesRegex(RuntimeError, '^Azure configuration PATCH failed with HTTP 400$'):
                deployment.request(deployment.RESOURCE, 'PATCH', b'{}')

    def test_wire_payload_omits_nulls_and_preserves_other_fields(self):
        api = object.__new__(deployment.Azure)
        api.token = 'test-only'
        rules = [{'name': 'existing', 'headers': None, 'priority': 100, 'tag': 'Default'}]
        with patch.object(deployment, 'request') as send:
            api.write_scm(rules)
        payload = deployment.json.loads(send.call_args.args[2])
        self.assertEqual(payload, {'properties': {'scmIpSecurityRestrictions': [
            {'name': 'existing', 'priority': 100, 'tag': 'Default'}]}})
        self.assertIsNone(rules[0]['headers'])

    def test_only_machine_error_code_is_exposed(self):
        error = deployment.urllib.error.HTTPError(deployment.RESOURCE, 400, 'secret', {},
            io.BytesIO(b'{"error":{"code":"BadRequest","message":"private"}}'))
        with patch.object(deployment.urllib.request, 'urlopen', side_effect=error):
            with self.assertRaisesRegex(RuntimeError, r'HTTP 400 \(BadRequest\)$'):
                deployment.request(deployment.RESOURCE, 'PATCH', b'{}')

    def test_cleanup_is_idempotent(self):
        deployment.cleanup(self.api, 'stellar-deploy-123')
        deployment.cleanup(self.api, 'stellar-deploy-123')
        self.assertEqual(0, self.api.writes)


if __name__ == '__main__':
    unittest.main()
