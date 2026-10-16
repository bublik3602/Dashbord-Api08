#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Helper script to parse .xls (Excel 97-2003) files and output JSON.
Used by api/import.php when an XLS file is uploaded.
"""

import sys
import json
import os

def parse_xls(file_path):
    try:
        import xlrd
    except ImportError:
        sys.stderr.write("Error: Python xlrd library is not installed.\n")
        sys.exit(2)

    try:
        wb = xlrd.open_workbook(file_path)
    except Exception as e:
        sys.stderr.write(f"Error opening XLS: {e}\n")
        sys.exit(3)

    result = {}
    for sname in wb.sheet_names():
        sheet = wb.sheet_by_name(sname)
        rows = []
        for r in range(sheet.nrows):
            row_vals = []
            for c in range(sheet.ncols):
                val = sheet.cell_value(r, c)
                ctype = sheet.cell_type(r, c)
                if ctype == xlrd.XL_CELL_DATE:
                    try:
                        date_tuple = xlrd.xldate_as_tuple(val, wb.datemode)
                        val = f"{date_tuple[0]:04d}-{date_tuple[1]:02d}-{date_tuple[2]:02d}"
                    except Exception:
                        pass
                elif ctype == xlrd.XL_CELL_EMPTY:
                    val = None
                elif ctype == xlrd.XL_CELL_NUMBER:
                    if val.is_integer():
                        val = int(val)
                row_vals.append(val)
            rows.append(row_vals)
        result[sname] = rows

    return result

if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.stderr.write("Usage: python parse_xls.py <path_to_xls>\n")
        sys.exit(1)

    path = sys.argv[1]
    if not os.path.exists(path):
        sys.stderr.write(f"File not found: {path}\n")
        sys.exit(1)

    data = parse_xls(path)
    sys.stdout.reconfigure(encoding='utf-8')
    print(json.dumps(data, ensure_ascii=False))
