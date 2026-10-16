#!/usr/bin/env python3
"""Poor man's static check used when the Dart SDK is unavailable: bracket balance (string/comment aware),
relative-import existence, package imports declared in pubspec. It does NOT replace `flutter analyze`."""
import os, re, sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
pub = open(os.path.join(root, 'pubspec.yaml')).read()
declared = set(re.findall(r'^  ([a-z_0-9]+):', pub, re.M)) | {'flutter', 'flutter_test', 'dart'}
problems = 0

def strip(src):
    out, i, n = [], 0, len(src)
    stack = []  # string delimiters
    while i < n:
        c = src[i]
        if not stack:
            if src.startswith('//', i):
                j = src.find('\n', i); i = n if j < 0 else j; continue
            if src.startswith('/*', i):
                j = src.find('*/', i + 2); i = n if j < 0 else j + 2; continue
            m = re.match(r"r?('''|\"\"\"|'|\")", src[i:])
            if m and (c in "'\"" or (c == 'r' and i + 1 < n and src[i+1] in "'\"")):
                raw = c == 'r'
                delim = m.group(1)
                stack.append((delim, raw)); i += len(m.group(0)); out.append('S'); continue
            out.append(c); i += 1
        else:
            delim, raw = stack[-1]
            if not raw and c == '\\': i += 2; continue
            if not raw and c == '$' and i + 1 < n and src[i+1] == '{':
                # interpolation: scan to matching brace
                depth, j = 1, i + 2
                while j < n and depth:
                    if src[j] == '{': depth += 1
                    elif src[j] == '}': depth -= 1
                    j += 1
                i = j; continue
            if src.startswith(delim, i):
                stack.pop(); i += len(delim); continue
            if len(delim) == 1 and c == '\n':
                stack.pop(); out.append('\n'); i += 1; continue  # unterminated: let balance catch it
            i += 1
    return ''.join(out)

pairs = {')': '(', ']': '[', '}': '{'}
for d, _, files in os.walk(root):
    if any(x in d for x in ('.dart_tool', 'build')): continue
    for f in files:
        if not f.endswith('.dart'): continue
        p = os.path.join(d, f)
        src = open(p).read()
        s = strip(src)
        st = []
        line = 1
        for ch in s:
            if ch == '\n': line += 1
            elif ch in '([{': st.append((ch, line))
            elif ch in ')]}':
                if not st or st[-1][0] != pairs[ch]:
                    print(f'{p}:{line}: unbalanced {ch}'); problems += 1; break
                st.pop()
        else:
            if st: print(f'{p}: unclosed {st[-1][0]} from line {st[-1][1]}'); problems += 1
        for m in re.finditer(r"^import '([^']+)'", src, re.M):
            imp = m.group(1)
            if imp.startswith('package:'):
                pkg = imp.split(':')[1].split('/')[0]
                if pkg not in declared and pkg != 'field_ops_mobile':
                    print(f'{p}: package {pkg} not in pubspec'); problems += 1
                elif pkg == 'field_ops_mobile':
                    t = os.path.join(root, 'lib', imp.split('/', 1)[1])
                    if not os.path.exists(t): print(f'{p}: missing {imp}'); problems += 1
            elif not imp.startswith('dart:'):
                t = os.path.normpath(os.path.join(d, imp))
                if not os.path.exists(t): print(f'{p}: missing import {imp}'); problems += 1
print('dart files checked; problems:', problems)
sys.exit(1 if problems else 0)
