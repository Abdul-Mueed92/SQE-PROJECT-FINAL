"""Enforce complete executable line and branch coverage of the payment job."""

import re
import sys
from pathlib import Path
from xml.etree import ElementTree


report = ElementTree.parse(sys.argv[1])
file = next(node for node in report.iter('file') if node.attrib['name'].replace('\\', '/').endswith('/CreateBankingDocumentTransaction.php'))
statements = [node for node in file.findall('line') if node.attrib['type'] == 'stmt']
if not statements or any(int(node.attrib['count']) == 0 for node in statements):
    raise SystemExit('Payment job statement gate failed.')

html = Path(sys.argv[2]).read_text(encoding='utf-8')
row = next(segment for segment in re.findall(r'<tr>(.*?)</tr>', html, re.S) if '>Total</td>' in segment)
line_percent, branch_percent, *_ = re.findall(r'<div align="right">([\d.]+)%</div>', row)
if float(line_percent) != 100 or float(branch_percent) != 100:
    raise SystemExit(f'Payment job coverage gate failed (lines {line_percent}%, branches {branch_percent}%).')
print(f'Payment job coverage gate passed ({len(statements)} executable lines, {branch_percent}% branches).')
