import sys

def parse_php_brackets(filename):
    with open(filename, 'r', encoding='utf-8') as f:
        src = f.read()

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

files = [
    'includes/metrics_calculator.php',
    'api/import.php',
    'api/export.php'
]

all_ok = True
for f in files:
    if not parse_php_brackets(f):
        all_ok = False

if not all_ok:
    sys.exit(1)
print("SUCCESS: ALL PHP FILES SYNTACTICALLY BALANCED!")
