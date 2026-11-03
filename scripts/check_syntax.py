import sys

def parse_php_brackets(filename):
    with open(filename, 'r', encoding='utf-8') as f:
        src = f.read()

    # If mixed template, blank out text outside <?php / <?= and ?>
    is_template = any(filename.endswith(s) for s in ['index.php', 'login.php', 'semena.php', 'energo.php', 'export.php'])
    if is_template:
        clean = []
        i = 0
        n = len(src)
        in_php = False
        while i < n:
            if not in_php:
                if src[i:i+5] == '<?php':
                    clean.append('<?php')
                    in_php = True
                    i += 5
                elif src[i:i+3] == '<?=':
                    clean.append('<?=')
                    in_php = True
                    i += 3
                else:
                    clean.append('\n' if src[i] == '\n' else ' ')
                    i += 1
            else:
                if src[i:i+2] == '?>':
                    clean.append('  ')
                    in_php = False
                    i += 2
                else:
                    clean.append(src[i])
                    i += 1
        src = ''.join(clean)
    elif src.lstrip().startswith('<?php'):
        src = src[src.find('<?php')+5:]

    i = 0
    n = len(src)
    stack = []
    line = 1
    col = 1

    STATE_CODE = 0
    STATE_SQ = 1
    STATE_DQ = 2
    STATE_COMMENT_LINE = 3
    STATE_COMMENT_BLOCK = 4

    state = STATE_CODE

    while i < n:
        c = src[i]
        c2 = src[i:i+2]

        if c == '\n':
            line += 1
            col = 1
            if state == STATE_COMMENT_LINE:
                state = STATE_CODE
            i += 1
            continue
        else:
            col += 1

        if state == STATE_CODE:
            if c2 == '//' or c == '#':
                state = STATE_COMMENT_LINE
                i += 2 if c2 == '//' else 1
                continue
            elif c2 == '/*':
                state = STATE_COMMENT_BLOCK
                i += 2
                continue
            elif c == "'":
                state = STATE_SQ
                i += 1
                continue
            elif c == '"':
                state = STATE_DQ
                i += 1
                continue
            elif c in '({[':
                stack.append((c, line, col))
                i += 1
                continue
            elif c in ')}]':
                if not stack:
                    print(f"{filename}:{line}:{col} - Unexpected closing '{c}'")
                    return False
                top, tline, tcol = stack.pop()
                expected = {')': '(', '}': '{', ']': '['}[c]
                if top != expected:
                    print(f"{filename}:{line}:{col} - Mismatched '{c}', expected matching for '{top}' from {tline}:{tcol}")
                    return False
                i += 1
                continue
            else:
                i += 1
                continue

        elif state == STATE_SQ:
            if c == '\\':
                i += 2
                continue
            elif c == "'":
                state = STATE_CODE
                i += 1
                continue
            else:
                i += 1
                continue

        elif state == STATE_DQ:
            if c == '\\':
                i += 2
                continue
            elif c == '"':
                state = STATE_CODE
                i += 1
                continue
            else:
                i += 1
                continue

        elif state == STATE_COMMENT_BLOCK:
            if c2 == '*/':
                state = STATE_CODE
                i += 2
                continue
            else:
                i += 1
                continue

        elif state == STATE_COMMENT_LINE:
            i += 1
            continue

    if stack:
        print(f"{filename} - Unclosed brackets remaining:")
        for b, l, c in stack[-5:]:
            print(f"  '{b}' opened at {l}:{c}")
        return False

    print(f"{filename} - All brackets perfectly balanced!")
    return True

def check_duplicate_defaults(filename):
    import re
    with open(filename, 'r', encoding='utf-8') as f:
        src = f.read()

    clean = []
    i = 0
    n = len(src)
    in_sq = False
    in_dq = False
    in_line_cmt = False
    in_blk_cmt = False
    while i < n:
        c = src[i]
        c2 = src[i:i+2]
        if in_line_cmt:
            if c == '\n': in_line_cmt = False
            clean.append(c)
        elif in_blk_cmt:
            if c2 == '*/':
                in_blk_cmt = False
                i += 1
            clean.append(' ')
        elif in_sq:
            if c == '\\':
                clean.append('  ')
                i += 1
            elif c == "'":
                in_sq = False
            clean.append(' ')
        elif in_dq:
            if c == '\\':
                clean.append('  ')
                i += 1
            elif c == '"':
                in_dq = False
            clean.append(' ')
        else:
            if c2 == '//' or c == '#':
                in_line_cmt = True
                clean.append(' ')
            elif c2 == '/*':
                in_blk_cmt = True
                i += 1
                clean.append('  ')
            elif c == "'":
                in_sq = True
                clean.append(' ')
            elif c == '"':
                in_dq = True
                clean.append(' ')
            else:
                clean.append(c)
        i += 1
    
    clean_code = ''.join(clean)
    tokens = re.finditer(r'(\bswitch\b|\bdefault\s*:|\{|\})', clean_code)
    stack = []
    has_err = False
    for m in tokens:
        t = m.group(1)
        if t == 'switch':
            stack.append({'type': 'switch', 'pos': m.start(), 'defaults': []})
        elif t == '{':
            if stack and stack[-1]['type'] == 'switch':
                stack[-1]['type'] = 'switch_body'
            else:
                stack.append({'type': 'block', 'pos': m.start()})
        elif t == '}':
            if stack:
                top = stack.pop()
                if top['type'] == 'switch_body' and len(top['defaults']) > 1:
                    print(f"ERROR: {filename} has switch with MULTIPLE defaults at lines: {top['defaults']}")
                    has_err = True
        elif t.startswith('default'):
            for s in reversed(stack):
                if s['type'] == 'switch_body':
                    line_no = clean_code[:m.start()].count('\n') + 1
                    s['defaults'].append(line_no)
                    break
    return not has_err

files = [
    'includes/helpers.php',
    'includes/metrics_calculator.php',
    'api/import.php',
    'api/metrics.php',
    'api/export.php',
    'index.php',
    'login.php',
    'semena.php',
    'energo.php'
]

all_ok = True
for f in files:
    if not parse_php_brackets(f):
        all_ok = False
    if not check_duplicate_defaults(f):
        all_ok = False

if not all_ok:
    sys.exit(1)
print("SUCCESS: ALL PHP FILES SYNTACTICALLY BALANCED!")
