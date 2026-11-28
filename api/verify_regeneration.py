"""Demonstrate two constraint changes without modifying the baseline OpenAPI file."""

from copy import deepcopy
import json
from pathlib import Path

import yaml

from generate_cases import generate


root = Path(__file__).resolve().parents[1]
spec = yaml.safe_load((root / 'api/openapi-sqe.yaml').read_text(encoding='utf-8'))
revised = deepcopy(spec)
invoice = revised['components']['schemas']['InvoiceCreate']['properties']
payment = revised['components']['schemas']['PaymentCreate']['properties']
invoice['items']['items']['properties']['name']['maxLength'] = 40
payment['amount']['maximum'] = 80

before = {case['id']: case for case in generate(spec)}
after = {case['id']: case for case in generate(revised)}
assert before.keys() == after.keys()
changed = {key for key in before if before[key] != after[key]}
expected = {f'{prefix}-{suffix}' for prefix in ('InvoiceCreate:items.0.name:maxLength', 'PaymentCreate:amount:maximum')
            for suffix in ('below', 'at', 'above')}
assert changed == expected
assert len(after['InvoiceCreate:items.0.name:maxLength-at']['value']) == 40
assert after['PaymentCreate:amount:maximum-at']['value'] == 80

results = [{'id': key, 'before': before[key]['value'], 'after': after[key]['value'],
            'expected_status': after[key]['expected']} for key in sorted(changed)]
evidence = root / 'sqe/evidence/api/regeneration.json'
evidence.parent.mkdir(parents=True, exist_ok=True)
evidence.write_text(json.dumps({'case_count': len(after), 'changes': results}, indent=2) + '\n', encoding='utf-8')
print(f'Verified two changed limits and {len(changed)} regenerated boundary cases.')
