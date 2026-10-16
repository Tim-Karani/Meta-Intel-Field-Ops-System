#!/usr/bin/env python3
"""Checks every project symbol used in a file is imported there (and flags unused relative imports)."""
import os, re, sys
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
lib = os.path.join(root, 'lib')
files = {}
for d, _, fs in os.walk(lib):
    for f in fs:
        if f.endswith('.dart'): files[os.path.join(d, f)] = open(os.path.join(d, f)).read()

def strip(src):
    src = re.sub(r'//[^\n]*', '', src)
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    return src

decl = {}
for p, src in files.items():
    s = strip(src)
    names = set(re.findall(r'^(?:abstract\s+|final\s+)?(?:class|enum|typedef|mixin)\s+([A-Za-z_]\w*)', s, re.M))
    names |= set(re.findall(r'^extension\s+([A-Za-z_]\w*)\s+on', s, re.M))
    names |= set(re.findall(r'^(?:const|final)\s+(?:[\w<>?,\s]+?\s+)?([a-zA-Z_]\w*)\s*=', s, re.M))
    names |= set(re.findall(r'^(?:Future<[^>]*>|Future|String|bool|int|double|void|GoRouter|OpStatus|Duration|\w+)\s+([a-z]\w*)\(', s, re.M))
    decl[p] = {n for n in names if not n.startswith('_')}
# extension members (JsonRead on Map): treat extension name as symbol for method use
ext_methods = {'str': 'JsonRead', 'dbl': 'JsonRead', 'integer': 'JsonRead', 'map': 'JsonRead', 'list': 'JsonRead'}

problems = 0
for p, src in files.items():
    s = strip(src)
    imports = []
    for m in re.finditer(r"^import '([^']+)'", src, re.M):
        imp = m.group(1)
        if imp.startswith('package:field_ops_mobile/'):
            imports.append(os.path.join(lib, imp.split('/', 1)[1]))
        elif not imp.startswith(('package:', 'dart:')):
            imports.append(os.path.normpath(os.path.join(os.path.dirname(p), imp)))
    imported = set(imports)
    body = re.sub(r"^import [^\n]*\n", '', s, flags=re.M)
    for q, names in decl.items():
        if q == p: continue
        used = [n for n in names if re.search(r'\b' + re.escape(n) + r'\b', body)]
        if used and q not in imported:
            # a name could be locally shadowed/duplicated; report anyway
            print(f'{os.path.relpath(p, root)}: uses {used[:4]} from {os.path.relpath(q, root)} but does not import it'); problems += 1
    for q in imported:
        used = [n for n in decl.get(q, ()) if re.search(r'\b' + re.escape(n) + r'\b', body)]
        if not used and q in decl:
            print(f'{os.path.relpath(p, root)}: unused import {os.path.relpath(q, root)}')
    # extension members
    uses_ext = re.search(r"\.(str|dbl|integer|map|list)\('", body)
    if uses_ext and os.path.join(lib, 'core', 'util.dart') not in imported and 'core/util.dart' not in p:
        print(f'{os.path.relpath(p, root)}: uses JsonRead extension without importing core/util.dart'); problems += 1
print('symbol problems:', problems)
