"""Temporary runner-only SCM access, using Python's standard library and existing WIF."""
import ipaddress
import json
import os
from pathlib import Path
import sys
import time
import urllib.parse
import urllib.request

SUBSCRIPTION = '3654294d-b816-4cc6-be04-c7d9dd7bb1d0'
TENANT = '9d4bb378-03d3-4e1f-aa13-8ed8953c741b'
CLIENT = '45303972-824a-43fa-beb4-15f0f2dfe335'
CONNECTION = 'dff07a7e-6e30-44e5-9bcc-61381acf1b74'
RESOURCE = ('https://management.azure.com/subscriptions/' + SUBSCRIPTION
            + '/resourceGroups/stellersubscriptionapiprod_group/providers/Microsoft.Web/'
              'sites/stellarSecretApiProd/config/web?api-version=2024-11-01')


def request(url, method='GET', data=None, headers=None):
    with urllib.request.urlopen(urllib.request.Request(url, data=data, headers=headers or {},
                                                       method=method), timeout=60) as response:
        return json.load(response)


def authenticate():
    # Job-scoped OAuth and federated assertions stay in memory, never in logs/files.
    url = os.environ['SYSTEM_OIDCREQUESTURI']
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname != 'dev.azure.com':
        raise RuntimeError('Unexpected Azure DevOps OIDC host')
    query = urllib.parse.parse_qs(parsed.query)
    query.update(serviceConnectionId=[CONNECTION], **{'api-version': ['7.1-preview.1']})
    url = urllib.parse.urlunsplit(parsed._replace(query=urllib.parse.urlencode(query, doseq=True)))
    assertion = request(url, 'POST', b'{}', {
        'Authorization': 'Bearer ' + os.environ['SYSTEM_ACCESSTOKEN'],
        'Content-Type': 'application/json'})['oidcToken']
    payload = urllib.parse.urlencode({
        'client_id': CLIENT, 'scope': 'https://management.azure.com/.default',
        'grant_type': 'client_credentials',
        'client_assertion_type': 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion': assertion}).encode()
    return request('https://login.microsoftonline.com/' + TENANT + '/oauth2/v2.0/token',
                   'POST', payload, {'Content-Type': 'application/x-www-form-urlencoded'})['access_token']


class Azure:
    def __init__(self):
        self.token = authenticate()

    def read(self):
        return request(RESOURCE, headers={'Authorization': 'Bearer ' + self.token})['properties']

    def write_scm(self, rules):
        request(RESOURCE, 'PATCH', json.dumps({'properties': {
            'scmIpSecurityRestrictions': rules}}).encode(), {
                'Authorization': 'Bearer ' + self.token, 'Content-Type': 'application/json'})


def main_rules(config):
    return {key: config.get(key) for key in
            ('ipSecurityRestrictions', 'ipSecurityRestrictionsDefaultAction',
             'scmIpSecurityRestrictionsUseMain')}


def validate(config):
    if config.get('scmIpSecurityRestrictionsUseMain'):
        raise RuntimeError('SCM must use independent rules')
    rules = config.get('ipSecurityRestrictions', [])
    denied = config.get('ipSecurityRestrictionsDefaultAction') == 'Deny' or any(
        r.get('action') == 'Deny' and r.get('ipAddress') in ('Any', '0.0.0.0/0') for r in rules)
    if not denied or any(r.get('action') == 'Allow' for r in rules):
        raise RuntimeError('Expected public main-site deny-all')
    scm = config.get('scmIpSecurityRestrictions', [])
    if not any(r.get('action') == 'Deny' and r.get('ipAddress') in ('Any', '0.0.0.0/0')
               and r.get('priority', 65000) > 90 for r in scm):
        raise RuntimeError('Expected SCM deny-all after priority 90')


def cleanup(api, name, baseline=None):
    for attempt in range(4):
        try:
            current = api.read()
            rules = current.get('scmIpSecurityRestrictions', [])
            remaining = [r for r in rules if r.get('name') != name]
            if len(remaining) != len(rules):
                api.write_scm(remaining)
            after = api.read()
            if any(r.get('name') == name for r in after.get('scmIpSecurityRestrictions', [])):
                raise RuntimeError('Temporary rule still exists')
            if baseline is not None and main_rules(after) != baseline:
                raise RuntimeError('Main-site rules changed unexpectedly')
            print('Verified: temporary SCM rule absent')
            return
        except Exception:
            if attempt == 3:
                raise RuntimeError('SCM cleanup could not be verified; investigate rule ' + name) from None
            time.sleep(5 * (attempt + 1))


def prepare(api, name, state_path):
    before = api.read()
    validate(before)
    if any(r.get('name') == name for r in before.get('scmIpSecurityRestrictions', [])):
        raise RuntimeError('Rule already exists; cleanup required')
    with urllib.request.urlopen('https://checkip.amazonaws.com', timeout=15) as response:
        address = ipaddress.IPv4Address(response.read(64).decode().strip())
    if not address.is_global:
        raise RuntimeError('Runner address must be public IPv4')
    baseline = main_rules(before)
    state_path.write_text(json.dumps(baseline))
    rules = before.get('scmIpSecurityRestrictions', []) + [{
        'name': name, 'action': 'Allow', 'ipAddress': str(address) + '/32', 'priority': 90,
        'description': 'Temporary deployment runner; removed by always-run cleanup'}]
    try:
        api.write_scm(rules)
        after = api.read()
        if main_rules(after) != baseline:
            raise RuntimeError('Main-site rules changed unexpectedly')
        if not any(r.get('name') == name and r.get('ipAddress') == str(address) + '/32'
                   and r.get('action') == 'Allow' for r in after.get('scmIpSecurityRestrictions', [])):
            raise RuntimeError('Temporary SCM rule not applied')
    except BaseException:
        cleanup(api, name, baseline)
        raise
    print('Runner-only SCM rule verified; main-site rules unchanged')


if __name__ == '__main__':
    name = 'stellar-deploy-' + str(int(os.environ['BUILD_BUILDID']))
    state = Path(os.environ['AGENT_TEMPDIRECTORY']) / (name + '.json')
    try:
        api = Azure()
        if sys.argv[1] == 'prepare':
            prepare(api, name, state)
        elif sys.argv[1] == 'cleanup':
            cleanup(api, name, json.loads(state.read_text()) if state.exists() else None)
        else:
            raise RuntimeError('Unsupported operation')
    except Exception as error:
        # HTTP bodies and token exchange errors must never reach pipeline logs.
        print('SCM operation failed: ' + (str(error) if isinstance(error, RuntimeError)
                                         else type(error).__name__), file=sys.stderr)
        sys.exit(1)
