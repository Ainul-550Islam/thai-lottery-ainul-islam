"""Offline all-error-branch HTML/ARIA structural regressions, NOT Blade rendering.

The actual templates are projected into HTMLParser after replacing Blade echos
and removing only error-condition delimiters. No PHP/controller is executed,
no challenge value is fabricated, and no browser/axe acceptance is claimed.
Run the protected challenge audit separately; its known failure is not waived.
"""
from html.parser import HTMLParser
from pathlib import Path
import json
import re
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIELDS = {
    'member-login': ['login', 'password'],
    'member-register': ['referral_id', 'mobile', 'password', 'password_confirmation',
                        'first_name', 'last_name', 'gender', 'city', 'country',
                        'email', 'date_of_birth', 'nationality', 'terms'],
}

class Nodes(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.nodes = []
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        self.nodes.append({'tag': tag, 'attrs': dict(attrs)})

    def by_id(self, ident):
        matches = [n for n in self.nodes if n['attrs'].get('id') == ident]
        if len(matches) != 1:
            raise AssertionError(f'Expected one id={ident}; found {len(matches)}')
        return matches[0]


def project(source):
    source = re.sub(r'\{\{.*?\}\}', 'BLADE_ECHO_NOT_RENDERED', source, flags=re.S)
    source = re.sub(r"@if\s*\(\$errors->has\('[^']+'\)\)", '', source)
    return Nodes(source.replace('@endif', ''))


def assert_error_link(nodes, field):
    control = nodes.by_id(field)
    attrs = control['attrs']
    assert attrs.get('aria-invalid') == 'true', f'{field}: error branch must retain aria-invalid'
    target = attrs.get('aria-errormessage')
    assert target, f'{field}: visible server error has no programmatic association'
    error = nodes.by_id(target)
    assert error['attrs'].get('role') == 'alert', f'{field}: error target must remain announced'
    assert error['attrs'].get('aria-hidden') != 'true', f'{field}: error target must be exposed'
    labels = [n for n in nodes.nodes if n['tag'] == 'label' and n['attrs'].get('for') == field]
    assert len(labels) == 1, f'{field}: preserve its existing programmatic label'
    for hint in attrs.get('aria-describedby', '').split():
        nodes.by_id(hint)


class MemberAuthMarkup(unittest.TestCase):
    def test_checker_rejects_a_missing_error_target(self):
        broken = Nodes('<label for="field">Name</label><input id="field" aria-invalid="true" aria-errormessage="gone">')
        with self.assertRaisesRegex(AssertionError, 'Expected one id=gone'):
            assert_error_link(broken, 'field')


def make_case(view, field):
    def case(self):
        source = (ROOT / f'resources/views/auth/{view}.blade.php').read_text()
        assert_error_link(project(source), field)
    return case

for view, fields in FIELDS.items():
    for field in fields:
        setattr(MemberAuthMarkup, f'test_{view.replace("-", "_")}_{field}_error_association', make_case(view, field))

if __name__ == '__main__':
    if '--captcha-audit' in sys.argv:
        source = (ROOT / 'resources/views/auth/member-login.blade.php').read_text()
        nodes = project(source)
        challenge = re.search(r'<span\b([^>]*)>\s*\{\{\s*\$captcha\[\x27question\x27\]\s*\}\}\s*</span>', source, re.S)
        hidden = bool(challenge and re.search(r'aria-hidden="true"', challenge[1]))
        control = nodes.by_id('captcha_answer')['attrs']
        result = {'grade': 'unrendered actual-template structural audit; NOT browser acceptance',
                  'status': 'FAIL / OWNER-APPROVAL BLOCKED' if hidden else 'NOT VERIFIED',
                  'finding_count': 1 if hidden else 0,
                  'question_aria_hidden': hidden,
                  'answer_describedby': control.get('aria-describedby'),
                  'owner': 'Security/CAPTCHA + Frontend accessibility',
                  'required_decision': 'Approve exposed question id and answer description relationship without changing generation/validation/challenge semantics; then actual PHP/browser/AT verification',
                  'production_changed': False}
        print(json.dumps(result, indent=2))
        sys.exit(1 if hidden else 2)
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(MemberAuthMarkup)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print('COUNTS_JSON=' + json.dumps({'tests': result.testsRun, 'pass': result.testsRun-len(result.failures)-len(result.errors), 'fail': len(result.failures), 'errors': len(result.errors), 'skipped': len(result.skipped)}))
    sys.exit(0 if result.wasSuccessful() else 1)
