"""Generate executable EP and three-point BVA cases from the OpenAPI contract."""

import argparse
import json
from datetime import datetime, timedelta
from decimal import Decimal
from pathlib import Path

import yaml


def generate(spec):
    cases = []

    def visit(schema, field, rule, required):
        def add(partition, expected, **change):
            cases.append({
                'id': f'{schema}:{field}:{partition}', 'schema': schema, 'field': field,
                'partition': partition, 'expected': expected, **change,
            })

        if required:
            add('missing', 422, remove=True)
        if 'example' in rule:
            add('valid', 201, value=rule['example'])
        for label, values in rule.get('x-sqe-partitions', {}).items():
            add(label, values['status'], value=values['value'])

        for boundary, delta_sign in {'minimum': 1, 'maximum': -1}.items():
            if boundary not in rule:
                continue
            limit = Decimal(str(rule[boundary]))
            step = Decimal(str(rule.get('x-sqe-step', 1 if rule['type'] == 'integer' else 0.01)))
            exclusive = rule.get('exclusive' + boundary.capitalize(), False)
            if not isinstance(exclusive, bool):
                raise ValueError(f'{schema}.{field} requires an OpenAPI 3.0 boolean exclusive bound')
            for direction, label in ((-1, 'below'), (0, 'at'), (1, 'above')):
                valid = direction * delta_sign > 0 or (direction == 0 and not exclusive)
                value = limit + direction * step
                add(f'{boundary}-{label}', 201 if valid else 422,
                    value=int(value) if rule['type'] == 'integer' else float(value))

        for boundary, delta_sign in {'minLength': 1, 'maxLength': -1}.items():
            if boundary not in rule:
                continue
            for direction, label in ((-1, 'below'), (0, 'at'), (1, 'above')):
                length = rule[boundary] + direction
                if length >= 0:
                    add(f'{boundary}-{label}', 201 if direction * delta_sign >= 0 else 422, value='X' * length)

        if 'x-sqe-date-limit' in rule:
            limit = rule['x-sqe-date-limit']
            anchor = datetime.fromisoformat(limit['value'])
            for offset, label in ((-1, 'before'), (0, 'at'), (1, 'after')):
                point = (anchor + timedelta(seconds=offset)).isoformat(sep=' ')
                allowed = offset >= 0 if limit['inclusive'] == 'after' else offset <= 0
                add(f'date-{label}', 201 if allowed else 422, value=point)

        if rule.get('type') == 'object':
            for name, child in rule.get('properties', {}).items():
                visit(schema, '.'.join(filter(None, [field, name])), child, name in rule.get('required', []))
        if rule.get('type') == 'array':
            visit(schema, field + '.0', rule['items'], False)

    for schema, definition in spec['components']['schemas'].items():
        visit(schema, '', definition, False)

    identifiers = {case['id'] for case in cases}
    if len(identifiers) != len(cases):
        raise ValueError('Generated case identifiers must be unique')
    return cases


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--spec', default='api/openapi-sqe.yaml', type=Path)
    parser.add_argument('--out', default='api/generated_cases.json', type=Path)
    args = parser.parse_args()
    cases = generate(yaml.safe_load(args.spec.read_text(encoding='utf-8')))
    args.out.write_text(json.dumps(cases, indent=2) + '\n', encoding='utf-8')
    print(f'Generated {len(cases)} cases into {args.out}')


if __name__ == '__main__':
    main()
