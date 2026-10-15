"""
Модуль обробки даних та формування фінального звіту «Лист погодження» (Соняшник)
відповідно до оновлених вимог:

1. Ціна поставки (з дост.) та Ціна без доставки (з Реєстру специфікацій).
2. Завезено (т), Залікова вага (т) та Різниця ваги (т).
3. Збереження точної ширини колонок, налаштованої користувачем в Excel.
4. Хронологічне сортування: від більш ранніх дат до більш нових.
5. Інкрементальний аудит: колонка «Примітка».
"""

import os
import re
import sys
from datetime import datetime
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

# Точна ширина колонок, налаштована користувачем
CUSTOM_COLUMN_WIDTHS = {
    'A': 11.0, # №
    'B': 32.0, # Назва (Контрагент)
    'C': 16.0, # Специфікація (вміщує "А-1005 (Сп.1)")
    'D': 13.0, # Дата
    'E': 28.0, # Менеджер
    'F': 17.0, # Умови доставки
    'G': 16.0, # Договір (т)
    'H': 15.0, # Завезено (т)
    'I': 20.0, # Залікова вага (т)
    'J': 19.0, # Різниця ваги (т)
    'K': 25.0, # Ціна поставки (з дост.)
    'L': 25.0, # Ціна без доставки (з місця)
    'M': 19.0, # Масло (База)
    'N': 19.0, # Волога (Факт)
    'O': 19.0, # Сор (Факт)
    'P': 34.0, # Факт завозу (олійність)
    'Q': 23.0, # Разница (відхилення)
    'R': 68.0  # Примітка (Аудит змін)
}

class StaleReceiptsError(ValueError):
    """Виникає при спробі оновити звіт застарілим файлом прийомок 1С (старіша дата або час)."""
    pass

def parse_date_sort_key(d_val):
    """
    Перетворює дату у кортеж (рік, місяць, день) для точного хронологічного сортування.
    """
    if not d_val:
        return (9999, 99, 99)
    if hasattr(d_val, 'year'):
        return (d_val.year, d_val.month, d_val.day)
    s = str(d_val).strip().split(' ')[0]
    parts = s.split('.')
    if len(parts) == 3:
        try:
            day = int(parts[0])
            month = int(parts[1])
            year = int(parts[2])
            if year < 100:
                year += 2000
            return (year, month, day)
        except:
            pass
    return (9999, 99, 99)

def extract_code_and_spec_num(text):
    """
    Витягує код договору (наприклад 'А-800') та номер специфікації (наприклад 'Сп.1').
    Повертає кортеж: (code, sp_num, display_name)
    Приклади:
    'Спецификация № 1 (А-800) від 02.09.2026' -> ('А-800', 'Сп.1', 'А-800 (Сп.1)')
    'Сп.1 (А-789)'                             -> ('А-789', 'Сп.1', 'А-789 (Сп.1)')
    'Сп.2 (А-856)'                             -> ('А-856', 'Сп.2', 'А-856 (Сп.2)')
    'А-800 (Сп.1)'                             -> ('А-800', 'Сп.1', 'А-800 (Сп.1)')
    'А-800'                                    -> ('А-800', '', 'А-800')
    """
    if not text:
        return "", "", ""
    s = str(text).strip()
    
    # 1. Номер специфікації (№ 1, Сп. 1, Сп.1, СП 1, (Сп.1))
    sp_num = ""
    sp_m = re.search(r'(?:Сп\.?|СП\.?|№)\s*(\d+)', s, re.IGNORECASE)
    if sp_m:
        sp_num = f"Сп.{sp_m.group(1)}"
        
    # 2. Код договору (наприклад 'А-800', 'А - 800')
    code_m = re.search(r'([A-Za-zА-Яа-яІіЇїЄє]\s*[-–]\s*\d+)', s)
    if code_m:
        code = re.sub(r'\s+', '', code_m.group(1)).upper()
    else:
        p_m = re.search(r'\(([^)]+)\)', s)
        if p_m and not re.search(r'(?:Сп\.?|СП\.?|№)\s*\d+', p_m.group(1), re.IGNORECASE):
            code = p_m.group(1).strip().upper()
        else:
            code = s.split('(')[0].strip().upper()
            
    if sp_num:
        display = f"{code} ({sp_num})"
    else:
        display = code
        
    return code, sp_num, display

def extract_spec_code(text):
    """
    Витягує чистий код договору/специфікації (наприклад 'А-800').
    """
    code, _, _ = extract_code_and_spec_num(text)
    return code

def load_existing_report(report_path):
    """
    Зчитує попередній звіт «Лист погодження.xlsx» (якщо він вже є),
    щоб порівняти старі записи з новими вивантаженнями 1С.
    """
    if not report_path or not os.path.exists(report_path):
        return {}
        
    try:
        wb = openpyxl.load_workbook(report_path, data_only=True)
        sheet_name = "Лист погодження" if "Лист погодження" in wb.sheetnames else wb.sheetnames[0]
        ws = wb[sheet_name]
        
        existing = {}
        header_row = 4
        for r in range(1, 10):
            val1 = str(ws.cell(r, 1).value or '')
            val2 = str(ws.cell(r, 2).value or '')
            if '№' in val1 or 'Контрагент' in val2:
                header_row = r
                break
                
        col_spec = 3
        col_zavoz = 8
        col_zachet = 9
        col_price_dost = 11
        col_price_no_dost = None
        col_oil_base = 13
        col_w_fact = 14
        col_s_fact = 15
        col_oil_fact = 16
        col_oil_diff = 17
        col_note = 18

        for c in range(1, ws.max_column + 1):
            h_val = str(ws.cell(header_row, c).value or '').lower()
            if 'специфікація' in h_val:
                col_spec = c
            elif 'завезено' in h_val:
                col_zavoz = c
            elif 'залікова' in h_val:
                col_zachet = c
            elif 'без доставки' in h_val:
                col_price_no_dost = c
            elif 'ціна поставки' in h_val or ('ціна' in h_val and 'з дост' in h_val) or ('ціна' in h_val and 'дост' in h_val):
                col_price_dost = c
            elif 'масло (база)' in h_val or 'база' in h_val:
                col_oil_base = c
            elif 'волога' in h_val:
                col_w_fact = c
            elif 'сор' in h_val:
                col_s_fact = c
            elif 'факт завозу' in h_val or 'факт олійність' in h_val:
                col_oil_fact = c
            elif 'разница' in h_val or 'відхилення' in h_val:
                col_oil_diff = c
            elif 'примітка' in h_val:
                col_note = c

        for r in range(header_row + 1, ws.max_row + 1):
            c_spec = ws.cell(r, col_spec).value
            c_sup = ws.cell(r, 2).value
            
            if not c_spec or str(c_spec).strip().lower().startswith('всього') or str(c_sup or '').startswith('Всього'):
                continue
                
            code = extract_spec_code(str(c_spec))
            if not code:
                continue
                
            weight_delivered = ws.cell(r, col_zavoz).value if col_zavoz else None
            weight_zachet = ws.cell(r, col_zachet).value if col_zachet else None
            price_dost = ws.cell(r, col_price_dost).value if col_price_dost else None
            price_no_dost = ws.cell(r, col_price_no_dost).value if col_price_no_dost else None
            oil_base = ws.cell(r, col_oil_base).value if col_oil_base else None
            w_fact = ws.cell(r, col_w_fact).value if col_w_fact else None
            s_fact = ws.cell(r, col_s_fact).value if col_s_fact else None
            oil_fact = ws.cell(r, col_oil_fact).value if col_oil_fact else None
            oil_diff = ws.cell(r, col_oil_diff).value if col_oil_diff else None
            old_note = ws.cell(r, col_note).value if (col_note and ws.max_column >= col_note) else ""
            
            existing[code] = {
                'code': code,
                'weight_delivered': float(weight_delivered) if weight_delivered not in (None, '') else None,
                'weight_zachet': float(weight_zachet) if weight_zachet not in (None, '') else None,
                'price_delivery': float(price_dost) if price_dost not in (None, '') else None,
                'price_no_delivery': float(price_no_dost) if price_no_dost not in (None, '') else None,
                'oil_base': float(oil_base) if oil_base not in (None, '') else None,
                'w_fact': float(w_fact) if w_fact not in (None, '') else None,
                's_fact': float(s_fact) if s_fact not in (None, '') else None,
                'oil_fact': float(oil_fact) if oil_fact not in (None, '') else None,
                'oil_diff': float(oil_diff) if oil_diff not in (None, '') else None,
                'old_note': str(old_note).strip() if old_note else ""
            }
            
        return existing
    except Exception as e:
        print(f"Попередження: не вдалося прочитати попередній файл звіту ({e})")
        return {}

def get_history_file_paths(output_file="Лист_погодження.xlsx"):
    """Повертає список шляхів до файлу журналу історії змін (поруч зі звітом та локально біля програми)"""
    import sys
    paths = []
    try:
        if output_file:
            out_dir = os.path.dirname(os.path.abspath(output_file))
            if out_dir and os.path.exists(out_dir):
                paths.append(os.path.join(out_dir, "changes_history.json"))
    except Exception:
        pass
    try:
        if getattr(sys, 'frozen', False):
            app_dir = os.path.dirname(sys.executable)
        else:
            app_dir = os.path.dirname(os.path.abspath(__file__))
        local_p = os.path.join(app_dir, "changes_history.json")
        if local_p not in paths:
            paths.append(local_p)
    except Exception:
        pass
    return paths

def load_changes_history(output_file="Лист_погодження.xlsx"):
    """Завантажує та об'єднує історію змін (spec_price_notes, events та дату прийомок) з усіх доступних файлів історії"""
    import json
    merged = {
        "last_receipts_datetime": "",
        "last_receipts_iso": "",
        "spec_price_notes": {},
        "events": []
    }
    seen_event_ids = set()
    for p in get_history_file_paths(output_file):
        if os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                if data.get("last_receipts_iso"):
                    if not merged["last_receipts_iso"] or data["last_receipts_iso"] > merged["last_receipts_iso"]:
                        merged["last_receipts_iso"] = data["last_receipts_iso"]
                if data.get("last_receipts_datetime") and not merged["last_receipts_datetime"]:
                    merged["last_receipts_datetime"] = data["last_receipts_datetime"]
                for k, v in data.get("spec_price_notes", {}).items():
                    merged["spec_price_notes"][k] = v
                for ev in data.get("events", []):
                    ev_id = ev.get("id") or f"{ev.get('code')}_{ev.get('category')}_{ev.get('summary')}"
                    if ev_id not in seen_event_ids:
                        seen_event_ids.add(ev_id)
                        merged["events"].append(ev)
            except Exception:
                pass
    return merged

def save_changes_history(output_file, history_data):
    """Зберігає історію змін у JSON поруч зі звітом та в локальну папку програми"""
    import json
    for p in get_history_file_paths(output_file):
        try:
            with open(p, "w", encoding="utf-8") as f:
                json.dump(history_data, f, ensure_ascii=False, indent=2)
        except Exception:
            pass


def load_specifications(specs_file_path):
    """
    Завантажує дані з Файлу 2 («Реєстр специфікацій»).
    Повертає словник: {КОД_СПЕЦИФІКАЦІЇ: dict_з_параметрами}
    Ціна з доставкою: Col 23 «Цена поставки (с дост.)» (або Col 26 «Цена с доставкой»).
    Ціна без доставки: Col 27 «Цена по договору».
    """
    if not specs_file_path or not os.path.exists(specs_file_path):
        return {}
    try:
        wb = openpyxl.load_workbook(specs_file_path, data_only=True)
        ws = wb.active
    except Exception as e:
        print(f"Помилка відкриття файлу специфікацій ({specs_file_path}): {e}")
        return {}
    
    specs = {}
    
    header_row = 8
    start_row = 9
    
    for r in range(1, 15):
        row_vals = [str(ws.cell(r, c).value or '') for c in range(1, 25)]
        if any('Спецификация' in v for v in row_vals) or any('Менеджер' in v for v in row_vals):
            header_row = r
            start_row = r + 1
            break

    col_map = {}
    for c in range(1, ws.max_column + 1):
        h_parts = []
        for hr in range(max(1, header_row - 2), header_row + 1):
            val = ws.cell(hr, c).value
            if val:
                h_parts.append(str(val).strip())
        col_name = " ".join(h_parts).lower()
        col_map[c] = col_name

    col_spec = 13
    col_supplier = 4
    col_terms = 5
    col_manager = 14
    col_date = 1
    col_dost = 11      # «Доставка грн.»
    col_price_post = 23 # «Цена поставки (с дост.)»
    col_price_dost = 26 # «Цена с доставкой»
    col_price_dog = 27  # «Цена по договору» (без доставки / з місця FCA)
    col_dog_tons = 7    # «ПоДоговору»
    col_tons = 29       # «Контракты, т»
    col_oil_base = 35   # «Маслич.(сухая)»
    col_oil_fact = 34   # «Маслич.факт»
    col_w_fact = 33     # «Влажность» факт
    col_s_fact = 46     # «Сорность» факт

    for c, name in col_map.items():
        if 'спецификация' in name and 'номер' not in name:
            col_spec = c
        elif 'контрагент' in name:
            col_supplier = c
        elif 'условие' in name:
            col_terms = c
        elif 'менеджер' in name:
            col_manager = c
        elif 'срок поставки' in name:
            col_date = c
        elif 'доставка грн' in name:
            col_dost = c
        elif 'цена поставки (с дост.)' in name:
            col_price_post = c
        elif 'цена с доставкой' in name:
            col_price_dost = c
        elif 'цена по договору' in name:
            col_price_dog = c
        elif 'подоговору' in name:
            col_dog_tons = c
        elif 'маслич.(сухая)' in name:
            col_oil_base = c
        elif 'маслич.факт' in name:
            col_oil_fact = c
        elif 'влажность' in name and 'баз' not in name and 'заявл' not in name:
            col_w_fact = c
        elif 'сорность' in name and 'баз' not in name and 'заявл' not in name:
            col_s_fact = c

    for r in range(start_row, ws.max_row + 1):
        spec_cell = ws.cell(r, col_spec).value
        if not spec_cell or str(spec_cell).strip().lower().startswith('итого'):
            continue
            
        spec_text = str(spec_cell).strip()
        code, sp_num, spec_display = extract_code_and_spec_num(spec_text)
        if not code:
            continue
            
        supplier = ws.cell(r, col_supplier).value
        terms = ws.cell(r, col_terms).value
        manager = ws.cell(r, col_manager).value
        date_val = ws.cell(r, col_date).value
        
        contract_tons = ws.cell(r, col_tons).value
        if contract_tons in (None, '', 0, '0'):
            contract_tons = ws.cell(r, col_dog_tons).value
        
        # Вартість доставки грн/т (з місця до елеватора)
        dost_val = ws.cell(r, col_dost).value
        dost_cost = float(dost_val) if (dost_val is not None and str(dost_val).strip() not in ('', '-', '0')) else 0.0

        # 1. Ціна без доставки (Цена по договору / FCA з місця)
        price_no_dost_val = ws.cell(r, col_price_dog).value
        if price_no_dost_val not in (None, ''):
            price_no_dost = float(price_no_dost_val)
        else:
            price_no_dost = None

        # 2. Ціна з доставкою (Цена с доставкой / Цена поставки (с дост.))
        price_dost_val = ws.cell(r, col_price_post).value
        if price_dost_val in (None, ''):
            price_dost_val = ws.cell(r, col_price_dost).value
            
        if price_dost_val not in (None, ''):
            price_dost = float(price_dost_val)
        elif price_no_dost is not None:
            price_dost = price_no_dost + dost_cost
        else:
            price_dost = None
            
        if price_no_dost is None and price_dost is not None:
            terms_str = str(terms or '').strip().upper()
            if terms_str == 'DAP' or dost_cost == 0:
                price_no_dost = price_dost
            else:
                price_no_dost = price_dost - dost_cost
            
        base_oil = ws.cell(r, col_oil_base).value
        fact_oil = ws.cell(r, col_oil_fact).value
        fact_w = ws.cell(r, col_w_fact).value
        fact_s = ws.cell(r, col_s_fact).value
        
        date_str = ""
        if date_val:
            date_str = str(date_val).split(' ')[0].strip()

        specs[code] = {
            'code': code,
            'sp_num': sp_num,
            'spec_display': spec_display,
            'spec_full_name': spec_text,
            'supplier': str(supplier).strip() if supplier else "",
            'delivery_terms': str(terms).strip() if terms else "",
            'delivery_cost': dost_cost,
            'manager': str(manager).strip() if manager else "",
            'date': date_str,
            'contract_tons': float(contract_tons) if contract_tons not in (None, '') else None,
            'price_delivery': price_dost,
            'price_no_delivery': price_no_dost,
            'base_oil': float(base_oil) if base_oil not in (None, '') else None,
            'fact_oil': float(fact_oil) if fact_oil not in (None, '') else None,
            'fact_w': float(fact_w) if fact_w not in (None, '') else None,
            'fact_s': float(fact_s) if fact_s not in (None, '') else None,
            'row_num': r
        }

    return specs

def parse_receipts_datetime(receipts_file_path):
    """
    Витягує кінцеву дату та час формування звіту прийомок з шапки файлу prihod.xlsx
    або з його вмісту чи mtime.
    Повертає tuple: (datetime_obj, display_str).
    Якщо не вдалося визначити — повертає (None, "").
    """
    if not receipts_file_path or not os.path.exists(receipts_file_path):
        return None, ""
    try:
        wb = openpyxl.load_workbook(receipts_file_path, data_only=True)
        ws = wb.active
        for r in range(1, 10):
            for c in range(1, 10):
                val = str(ws.cell(r, c).value or '').strip()
                if not val:
                    continue
                # Шукаємо дату після дефісу / тире (кінець періоду)
                m_end = re.search(r'[-–—]\s*(\d{2}\.\d{2}\.\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)', val)
                if m_end:
                    d_part, t_part = m_end.groups()
                    day, month, year = map(int, d_part.split('.'))
                    t_parts = [int(p) for p in t_part.split(':')]
                    hour = t_parts[0]
                    minute = t_parts[1] if len(t_parts) > 1 else 0
                    second = t_parts[2] if len(t_parts) > 2 else 0
                    dt_obj = datetime(year, month, day, hour, minute, second)
                    t_short = f"{hour:02d}:{minute:02d}"
                    return dt_obj, f"{d_part} час {t_short}"

                # Якщо знайдено декілька дат у рядку — беремо останню (кінець періоду)
                all_m = re.findall(r'(\d{2}\.\d{2}\.\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)', val)
                if len(all_m) >= 2:
                    d_part, t_part = all_m[-1]
                    day, month, year = map(int, d_part.split('.'))
                    t_parts = [int(p) for p in t_part.split(':')]
                    hour = t_parts[0]
                    minute = t_parts[1] if len(t_parts) > 1 else 0
                    second = t_parts[2] if len(t_parts) > 2 else 0
                    dt_obj = datetime(year, month, day, hour, minute, second)
                    t_short = f"{hour:02d}:{minute:02d}"
                    return dt_obj, f"{d_part} час {t_short}"
    except Exception:
        pass
    try:
        mtime = os.path.getmtime(receipts_file_path)
        dt = datetime.fromtimestamp(mtime)
        return dt, dt.strftime("%d.%m.%Y час %H:%M")
    except Exception:
        pass
    return None, ""

def extract_receipts_datetime(receipts_file_path):
    """
    Повертає текстовий рядок дати та часу формування звіту прийомок 1С.
    """
    _, display_str = parse_receipts_datetime(receipts_file_path)
    return display_str

def extract_report_receipts_datetime(report_path):
    """
    Витягує дату та час прийомок з існуючого файлу звіту «Лист погодження.xlsx».
    Повертає tuple: (datetime_obj, display_str).
    """
    if not report_path or not os.path.exists(report_path):
        return None, ""
    try:
        wb = openpyxl.load_workbook(report_path, data_only=True)
        sheet_name = "Лист погодження" if "Лист погодження" in wb.sheetnames else wb.sheetnames[0]
        ws = wb[sheet_name]
        for r in range(1, 6):
            for c in range(1, 5):
                val = str(ws.cell(r, c).value or '').strip()
                if not val:
                    continue
                m = re.search(r'(\d{2}\.\d{2}\.\d{4})\s+(?:час\s+)?(\d{1,2}:\d{2}(?::\d{2})?)', val)
                if m:
                    d_str, t_str = m.groups()
                    day, month, year = map(int, d_str.split('.'))
                    t_parts = [int(p) for p in t_part.split(':')] if (t_part := t_str) else [0]
                    hour = t_parts[0]
                    minute = t_parts[1] if len(t_parts) > 1 else 0
                    second = t_parts[2] if len(t_parts) > 2 else 0
                    dt_obj = datetime(year, month, day, hour, minute, second)
                    return dt_obj, f"{d_str} час {hour:02d}:{minute:02d}"
    except Exception:
        pass
    return None, ""

def get_existing_receipts_datetime(output_file="Лист_погодження.xlsx", history_data=None):
    """
    Визначає актуальну дату/час останніх завантажених прийомок зі звіту та журналу змін.
    Повертає (datetime_obj, display_str).
    """
    best_dt = None
    best_str = ""
    
    # 1. Перевіряємо звіт на диску
    rep_dt, rep_str = extract_report_receipts_datetime(output_file)
    if rep_dt:
        best_dt = rep_dt
        best_str = rep_str

    # 2. Перевіряємо history_data
    if history_data is None:
        try:
            history_data = load_changes_history(output_file)
        except Exception:
            history_data = None
            
    if history_data:
        last_iso = history_data.get("last_receipts_iso")
        if last_iso:
            try:
                h_dt = datetime.fromisoformat(last_iso)
                if best_dt is None or h_dt > best_dt:
                    best_dt = h_dt
                    best_str = history_data.get("last_receipts_datetime") or h_dt.strftime("%d.%m.%Y час %H:%M")
            except Exception:
                pass
        
        elif history_data.get("last_receipts_datetime") and not best_str:
            best_str = history_data.get("last_receipts_datetime")

    return best_dt, best_str

def check_receipts_staleness(receipts_file, output_file="Лист_погодження.xlsx", history_data=None):
    """
    Перевіряє, чи файл прийомок не є старішим за актуальні дані у звіті/історії.
    Повертає tuple:
    (is_stale: bool, new_dt_obj, new_dt_str, exist_dt_obj, exist_dt_str, reason: str)
    """
    if not receipts_file or not os.path.exists(receipts_file):
        return False, None, "", None, "", ""

    new_dt, new_str = parse_receipts_datetime(receipts_file)
    exist_dt, exist_str = get_existing_receipts_datetime(output_file, history_data)

    if not new_dt or not exist_dt:
        return False, new_dt, new_str, exist_dt, exist_str, ""

    # Порівняння: якщо новий файл строго старіший за існуючі дані
    if new_dt < exist_dt:
        reason = f"Вибраний файл прийомок ({new_str}) старіший за актуальні дані у базі ({exist_str})"
        return True, new_dt, new_str, exist_dt, exist_str, reason

    return False, new_dt, new_str, exist_dt, exist_str, ""

def load_receipts(receipts_file_path):
    """
    Завантажує дані з Файлу 1 («Реєстр прийомок»).
    Повертає два списки: truck_rows та summary_rows.
    """
    wb = openpyxl.load_workbook(receipts_file_path, data_only=True)
    ws = wb.active
    
    data_start = 7
    vsego_start = None
    
    for r in range(1, ws.max_row + 1):
        c1 = str(ws.cell(r, 1).value or '').strip()
        c2 = str(ws.cell(r, 2).value or '').strip()
        if c1 == 'Всего' or c2 == 'Всего':
            vsego_start = r
            break
            
    truck_rows = []
    max_truck_row = (vsego_start - 1) if vsego_start else ws.max_row
    
    for r in range(data_start, max_truck_row + 1):
        num_pp = ws.cell(r, 1).value
        if num_pp is None:
            continue
            
        auto = ws.cell(r, 2).value
        date_in = ws.cell(r, 3).value
        doc_no = ws.cell(r, 5).value
        supplier = ws.cell(r, 8).value
        terms = ws.cell(r, 10).value
        spec_raw = ws.cell(r, 12).value
        netto = ws.cell(r, 24).value
        total_kg = ws.cell(r, 25).value
        zachet = ws.cell(r, 26).value
        
        w_fact = ws.cell(r, 32).value
        s_fact = ws.cell(r, 35).value
        oil_fact = ws.cell(r, 38).value
        
        date_str = ""
        if date_in:
            date_str = str(date_in).split(' ')[0].strip()
            
        code, sp_num, spec_display = extract_code_and_spec_num(spec_raw)
        
        actual_delivered_kg = netto if netto not in (None, '') else total_kg
        actual_delivered_kg = float(actual_delivered_kg) if actual_delivered_kg not in (None, '') else 0.0
        zachet_kg = float(zachet) if zachet not in (None, '') else 0.0
        diff_kg = actual_delivered_kg - zachet_kg
        
        truck_rows.append({
            'row_idx': r,
            'num_pp': num_pp,
            'auto': str(auto).strip() if auto else "",
            'date': date_str,
            'doc_no': str(doc_no).strip() if doc_no else "",
            'supplier': str(supplier).strip() if supplier else "",
            'terms': str(terms).strip() if terms else "",
            'spec_raw': str(spec_raw).strip() if spec_raw else "",
            'spec_display': spec_display,
            'code': code,
            'delivered_kg': actual_delivered_kg,
            'zachet_kg': zachet_kg,
            'diff_kg': diff_kg,
            'w_fact': float(w_fact) if w_fact not in (None, '') else None,
            's_fact': float(s_fact) if s_fact not in (None, '') else None,
            'oil_fact': float(oil_fact) if oil_fact not in (None, '') else None
        })

    summary_rows = []
    if vsego_start:
        for r in range(vsego_start + 1, ws.max_row + 1):
            spec_raw = ws.cell(r, 12).value
            if not spec_raw:
                continue
            code, sp_num, spec_display = extract_code_and_spec_num(spec_raw)
            if not code:
                continue
                
            supplier = ws.cell(r, 9).value or ws.cell(r, 8).value
            terms = ws.cell(r, 11).value
            total_delivered_kg = ws.cell(r, 25).value
            zachet_kg = ws.cell(r, 26).value
            oil_fact = ws.cell(r, 37).value or ws.cell(r, 38).value
            
            actual_delivered_kg = float(total_delivered_kg) if total_delivered_kg not in (None, '') else 0.0
            actual_zachet_kg = float(zachet_kg) if zachet_kg not in (None, '') else 0.0
            diff_kg = actual_delivered_kg - actual_zachet_kg
            
            summary_rows.append({
                'row_idx': r,
                'supplier': str(supplier).strip() if supplier else "",
                'terms': str(terms).strip() if terms else "",
                'spec_raw': str(spec_raw).strip() if spec_raw else "",
                'spec_display': spec_display,
                'code': code,
                'delivered_kg': actual_delivered_kg,
                'zachet_kg': actual_zachet_kg,
                'diff_kg': diff_kg,
                'oil_fact': float(oil_fact) if oil_fact not in (None, '') else None
            })

    return truck_rows, summary_rows

def build_aggregated_report_data(specs_db, truck_rows, summary_rows, existing_report=None, history_data=None):
    """
    Формує зведені дані для фінального звіту «Лист погодження».
    Сортує хронологічно від найбільш ранніх дат до найбільш нових.
    """
    if existing_report is None:
        existing_report = {}
    if history_data is None:
        history_data = {"spec_price_notes": {}, "events": []}
    if specs_db is None:
        specs_db = {}

    trucks_by_code = {}
    for t in truck_rows:
        code = t['code']
        if not code:
            continue
        if code not in trucks_by_code:
            trucks_by_code[code] = []
        trucks_by_code[code].append(t)

    summary_by_code = {s['code']: s for s in summary_rows}
    
    all_codes = []
    seen = set()
    for s in summary_rows:
        c = s['code']
        if c and c not in seen:
            all_codes.append(c)
            seen.add(c)
    for t in truck_rows:
        c = t['code']
        if c and c not in seen:
            all_codes.append(c)
            seen.add(c)

    report_rows = []
    
    for code in all_codes:
        spec_info = specs_db.get(code, {})
        sum_info = summary_by_code.get(code, {})
        t_list = trucks_by_code.get(code, [])
        
        supplier = sum_info.get('supplier') or (t_list[0]['supplier'] if t_list else '') or spec_info.get('supplier', '')
        spec_display = spec_info.get('spec_display') or sum_info.get('spec_display') or (t_list[0].get('spec_display') if t_list else '') or code
        
        # Визначаємо дату специфікації / прийомок
        date_str = spec_info.get('date', '')
        if not date_str and t_list:
            dates = [t['date'] for t in t_list if t['date']]
            date_str = min(dates) if dates else ''
            
        manager = spec_info.get('manager', '')
        terms = sum_info.get('terms') or (t_list[0]['terms'] if t_list else '') or spec_info.get('delivery_terms', '')
        
        # Вагові показники (у тоннах)
        if sum_info.get('delivered_kg') is not None and sum_info.get('delivered_kg') > 0:
            weight_delivered = sum_info['delivered_kg'] / 1000.0
            weight_zachet = sum_info['zachet_kg'] / 1000.0
        elif t_list:
            weight_delivered = sum(t['delivered_kg'] for t in t_list) / 1000.0
            weight_zachet = sum(t['zachet_kg'] for t in t_list) / 1000.0
        elif spec_info.get('contract_tons'):
            weight_delivered = spec_info['contract_tons']
            weight_zachet = spec_info['contract_tons']
        else:
            weight_delivered = 0.0
            weight_zachet = 0.0

        weight_diff = round(weight_delivered - weight_zachet, 3)

        # Ціни з Реєстру специфікацій
        price_delivery = spec_info.get('price_delivery')
        price_no_delivery = spec_info.get('price_no_delivery')

        # Якість
        base_oil = spec_info.get('base_oil')
        
        fact_w = None
        fact_s = None
        fact_oil = sum_info.get('oil_fact')
        
        if t_list:
            weights = [t['zachet_kg'] or t['delivered_kg'] or 1.0 for t in t_list]
            tot_w = sum(weights)
            
            w_vals = [t['w_fact'] for t in t_list if t['w_fact'] is not None]
            if len(w_vals) == len(t_list) and tot_w > 0:
                fact_w = sum(t['w_fact'] * (t['zachet_kg'] or t['delivered_kg'] or 1.0) for t in t_list) / tot_w
            elif w_vals:
                fact_w = sum(w_vals) / len(w_vals)
                
            s_vals = [t['s_fact'] for t in t_list if t['s_fact'] is not None]
            if len(s_vals) == len(t_list) and tot_w > 0:
                fact_s = sum(t['s_fact'] * (t['zachet_kg'] or t['delivered_kg'] or 1.0) for t in t_list) / tot_w
            elif s_vals:
                fact_s = sum(s_vals) / len(s_vals)

            if fact_oil is None:
                oil_vals = [t['oil_fact'] for t in t_list if t['oil_fact'] is not None]
                if len(oil_vals) == len(t_list) and tot_w > 0:
                    fact_oil = sum(t['oil_fact'] * (t['zachet_kg'] or t['delivered_kg'] or 1.0) for t in t_list) / tot_w
                elif oil_vals:
                    fact_oil = sum(oil_vals) / len(oil_vals)

        if fact_w is None:
            fact_w = spec_info.get('fact_w')
        if fact_s is None:
            fact_s = spec_info.get('fact_s')
        if fact_oil is None:
            fact_oil = spec_info.get('fact_oil')

        fact_w = round(fact_w, 2) if fact_w is not None else None
        fact_s = round(fact_s, 2) if fact_s is not None else None
        fact_oil = round(fact_oil, 2) if fact_oil is not None else None
        
        oil_diff = None
        if fact_oil is not None and base_oil is not None:
            oil_diff = round(fact_oil - base_oil, 2)

        # Аудит змін та збереження історії змін ціни без жовтого виділення на наступні рази
        note = ""
        change_type = "same"
        now_ts = datetime.now().strftime("%d.%m.%Y %H:%M")
        short_ts = datetime.now().strftime("%d.%m")
        
        saved_price_info = history_data.get('spec_price_notes', {}).get(code)
        price_changes = []
        other_changes = []
        
        if existing_report:
            if code not in existing_report:
                note = "Новий запис"
                change_type = "new"
                ev_id = f"{code}_new"
                if not any(e.get('id') == ev_id for e in history_data.get('events', [])):
                    history_data.setdefault('events', []).append({
                        'id': ev_id,
                        'timestamp': now_ts,
                        'code': code,
                        'supplier': supplier,
                        'specification': spec_display,
                        'manager': manager,
                        'category': 'new',
                        'category_label': '🆕 Новий договір',
                        'summary': f"Додано нову специфікацію {spec_display}"
                    })
            else:
                old = existing_report[code]
                old_note_text = old.get('old_note', '')
                if ('ціна' in old_note_text.lower() or 'цена' in old_note_text.lower()) and not saved_price_info:
                    cleaned_old = old_note_text.replace("Змінено: ", "").replace("💰 Зміна ціни: ", "").strip()
                    saved_price_info = {
                        'timestamp': short_ts,
                        'price_note': cleaned_old,
                        'supplier': supplier,
                        'specification': spec_display
                    }
                    history_data.setdefault('spec_price_notes', {})[code] = saved_price_info
                
                if price_delivery is not None and old.get('price_delivery') is not None:
                    if abs(price_delivery - old['price_delivery']) > 0.05:
                        diff_p = price_delivery - old['price_delivery']
                        price_changes.append(f"Ціна з дост: {old['price_delivery']:,.2f} -> {price_delivery:,.2f} ({diff_p:+,.2f})")
                elif price_delivery is not None and old.get('price_delivery') is None:
                    price_changes.append(f"Ціна з дост додана: {price_delivery:,.2f}")

                if price_no_delivery is not None and old.get('price_no_delivery') is not None:
                    if abs(price_no_delivery - old['price_no_delivery']) > 0.05:
                        diff_p = price_no_delivery - old['price_no_delivery']
                        price_changes.append(f"Ціна без дост: {old['price_no_delivery']:,.2f} -> {price_no_delivery:,.2f} ({diff_p:+,.2f})")
                    
                if weight_delivered is not None and old.get('weight_delivered') is not None:
                    if abs(weight_delivered - old['weight_delivered']) > 0.05:
                        other_changes.append(f"Завезено: {old['weight_delivered']:.2f} -> {weight_delivered:.2f} т")
                        
                if weight_zachet is not None and old.get('weight_zachet') is not None:
                    if abs(weight_zachet - old['weight_zachet']) > 0.05:
                        other_changes.append(f"Залікова: {old['weight_zachet']:.2f} -> {weight_zachet:.2f} т")

                if base_oil is not None and old.get('oil_base') is not None:
                    if abs(base_oil - old['oil_base']) > 0.01:
                        other_changes.append(f"База масла: {old['oil_base']:.2f}% -> {base_oil:.2f}%")
                        
                if fact_oil is not None and old.get('oil_fact') is not None:
                    if abs(fact_oil - old['oil_fact']) > 0.01:
                        other_changes.append(f"Факт масла: {old['oil_fact']:.2f}% -> {fact_oil:.2f}%")

                if fact_w is not None and old.get('w_fact') is not None:
                    if abs(fact_w - old['w_fact']) > 0.05:
                        other_changes.append(f"Волога: {old['w_fact']:.2f}% -> {fact_w:.2f}%")

                if fact_s is not None and old.get('s_fact') is not None:
                    if abs(fact_s - old['s_fact']) > 0.05:
                        other_changes.append(f"Сор: {old['s_fact']:.2f}% -> {fact_s:.2f}%")

                if price_changes:
                    p_text = "; ".join(price_changes)
                    saved_price_info = {
                        'timestamp': short_ts,
                        'price_note': p_text,
                        'supplier': supplier,
                        'specification': spec_display
                    }
                    history_data.setdefault('spec_price_notes', {})[code] = saved_price_info
                    ev_id = f"{code}_price_{price_delivery}_{price_no_delivery}"
                    if not any(e.get('id') == ev_id for e in history_data.get('events', [])):
                        history_data.setdefault('events', []).append({
                            'id': ev_id,
                            'timestamp': now_ts,
                            'code': code,
                            'supplier': supplier,
                            'specification': spec_display,
                            'manager': manager,
                            'category': 'price',
                            'category_label': '💰 Зміна ціни',
                            'summary': p_text
                        })

                if other_changes:
                    o_text = "; ".join(other_changes)
                    ev_id = f"{code}_wq_{round(weight_delivered or 0, 2)}_{fact_oil}"
                    if not any(e.get('id') == ev_id for e in history_data.get('events', [])):
                        history_data.setdefault('events', []).append({
                            'id': ev_id,
                            'timestamp': now_ts,
                            'code': code,
                            'supplier': supplier,
                            'specification': spec_display,
                            'manager': manager,
                            'category': 'weight_quality',
                            'category_label': '⚖️ Вага / Якість',
                            'summary': o_text
                        })

                all_cur_changes = price_changes + other_changes
                if all_cur_changes:
                    # Є зміни в поточному запуску -> виділяємо жовтим!
                    note = "Змінено: " + "; ".join(all_cur_changes)
                    if not price_changes and saved_price_info and saved_price_info.get('price_note'):
                        ts_s = f" ({saved_price_info['timestamp']})" if saved_price_info.get('timestamp') else ""
                        note += f" | 💰 Зміна ціни{ts_s}: {saved_price_info['price_note']}"
                    change_type = "changed"
                else:
                    # У поточному запуску нових змін немає -> НЕ виділяємо жовтим, але залишаємо повідомлення про зміну ціни!
                    change_type = "same"
                    if saved_price_info and saved_price_info.get('price_note'):
                        ts_s = f" ({saved_price_info['timestamp']})" if saved_price_info.get('timestamp') else ""
                        note = f"💰 Зміна ціни{ts_s}: {saved_price_info['price_note']}"
                    else:
                        note = "Без змін"
        else:
            if saved_price_info and saved_price_info.get('price_note'):
                ts_s = f" ({saved_price_info['timestamp']})" if saved_price_info.get('timestamp') else ""
                note = f"💰 Зміна ціни{ts_s}: {saved_price_info['price_note']}"
                change_type = "same"
            else:
                note = "Початковий звіт"
                change_type = "same"

        report_rows.append({
            'supplier': supplier,
            'specification': spec_display,
            'date': date_str,
            'manager': manager,
            'delivery_terms': terms,
            'contract_tons': spec_info.get('contract_tons'),
            'delivery_cost': spec_info.get('delivery_cost', 0.0),
            'weight_delivered': round(weight_delivered, 3),
            'weight_zachet': round(weight_zachet, 3),
            'weight_diff': weight_diff,
            'price_delivery': price_delivery,
            'price_no_delivery': price_no_delivery,
            'oil_base': base_oil,
            'w_fact': fact_w,
            's_fact': fact_s,
            'oil_fact': fact_oil,
            'oil_diff': oil_diff,
            'note': note,
            'change_type': change_type,
            'code': code
        })

    # Сортування: від більш ранніх дат до більш нових
    report_rows.sort(key=lambda r: (parse_date_sort_key(r['date']), r['supplier'], r['specification']))
    
    # Проставляємо порядкові номери №
    for i, r in enumerate(report_rows, 1):
        r['num'] = i

    return report_rows

def build_detailed_truck_data(specs_db, truck_rows):
    """
    Формує детальні помашинні дані (1 рядок = 1 прийомка авто).
    Сортує від найбільш ранніх дат до найбільш нових.
    """
    if specs_db is None:
        specs_db = {}
    detailed_rows = []
    
    for t in truck_rows:
        code = t['code']
        spec_info = specs_db.get(code, {})
        
        supplier = t['supplier'] or spec_info.get('supplier', '')
        manager = spec_info.get('manager', '')
        terms = t['terms'] or spec_info.get('delivery_terms', '')
        date_str = t['date']
        
        delivered_tons = round(t['delivered_kg'] / 1000.0, 3)
        zachet_tons = round(t['zachet_kg'] / 1000.0, 3)
        diff_tons = round(delivered_tons - zachet_tons, 3)
        
        price_delivery = spec_info.get('price_delivery')
        price_no_delivery = spec_info.get('price_no_delivery')
        base_oil = spec_info.get('base_oil')
        fact_oil = t.get('oil_fact')
        oil_diff = round(fact_oil - base_oil, 2) if (fact_oil is not None and base_oil is not None) else None
        
        detailed_rows.append({
            'supplier': supplier,
            'specification': t.get('spec_display') or spec_info.get('spec_display') or code,
            'date': date_str,
            'manager': manager,
            'delivery_terms': terms,
            'delivery_cost': spec_info.get('delivery_cost', 0.0),
            'weight_delivered': delivered_tons,
            'weight_zachet': zachet_tons,
            'weight_diff': diff_tons,
            'price_delivery': price_delivery,
            'price_no_delivery': price_no_delivery,
            'oil_base': base_oil,
            'w_fact': t.get('w_fact'),
            's_fact': t.get('s_fact'),
            'oil_fact': fact_oil,
            'oil_diff': oil_diff,
            'auto': t.get('auto', ''),
            'doc_no': t.get('doc_no', '')
        })
        
    detailed_rows.sort(key=lambda d: parse_date_sort_key(d['date']), reverse=True)
    for i, d in enumerate(detailed_rows, 1):
        d['num'] = i

    return detailed_rows

def build_unfulfilled_data(specs_db, truck_rows, summary_rows):
    """
    Формує перелік специфікацій із визначенням статусу:
    - 🔴 Червоні (not_started): жодної машини не приїхало (0% виконання).
    - 🟡 Жовті (in_progress): у стадії виконання (залишок довозу > 25 т та > 10% толерансу).
    - 🟢 Зелені (fulfilled): виконані (залишок довозу <= 25 т (1 машина) або не перевищує толеранс 10%).

    Повертає кортеж: (unfulfilled, all_specs_status)
    - unfulfilled: список невиконаних специфікацій (🔴 + 🟡) для аркуша Excel.
    - all_specs_status: повний список усіх специфікацій з їхніми статусами для GUI.
    """
    if specs_db is None:
        specs_db = {}
    trucks_by_code = {}
    for t in truck_rows:
        c = t['code']
        if c:
            trucks_by_code.setdefault(c, []).append(t)

    summary_by_code = {s['code']: s for s in summary_rows}
    unfulfilled = []
    all_specs_status = []

    for code, s_info in specs_db.items():
        contract_tons = s_info.get('contract_tons') or 0.0
        sum_info = summary_by_code.get(code, {})
        t_list = trucks_by_code.get(code, [])
        
        if sum_info.get('delivered_kg') is not None and sum_info.get('delivered_kg') > 0:
            deliv_tons = sum_info['delivered_kg'] / 1000.0
            zach_tons = sum_info['zachet_kg'] / 1000.0
        elif t_list:
            deliv_tons = sum(t['delivered_kg'] for t in t_list) / 1000.0
            zach_tons = sum(t['zachet_kg'] for t in t_list) / 1000.0
        else:
            deliv_tons = 0.0
            zach_tons = 0.0
            
        rem_tons = round(max(0.0, contract_tons - deliv_tons), 3)
        pct = round((deliv_tons / contract_tons * 100), 1) if contract_tons > 0 else 0.0
        truck_count = len(t_list)
        tol_10pct = round(0.10 * contract_tons, 3)

        # Специфікація виконана, якщо:
        # залишок довозу <= 25т (1 машина) АБО не перевищує толеранс 10% (або завезено >= контракту)
        is_fulfilled = (deliv_tons >= contract_tons) or (rem_tons <= 25.0) or (contract_tons > 0 and rem_tons <= tol_10pct)

        base_item = {
            'code': code,
            'spec_display': s_info.get('spec_display', code),
            'supplier': s_info.get('supplier', ''),
            'date': s_info.get('date', ''),
            'manager': s_info.get('manager', ''),
            'delivery_terms': s_info.get('delivery_terms', ''),
            'contract_tons': contract_tons,
            'delivered_tons': deliv_tons,
            'zachet_tons': zach_tons,
            'remaining_tons': rem_tons,
            'pct_fulfilled': pct,
            'truck_count': truck_count,
            'price_delivery': s_info.get('price_delivery'),
            'price_no_delivery': s_info.get('price_no_delivery'),
        }

        # 1. 🔴 Червоні: жодної машини не приїхало
        if deliv_tons <= 0.001:
            item = dict(base_item)
            item.update({
                'status': 'not_started',
                'status_label': '🔴 Не приїхало (0%)',
                'note': 'Жодної машини не надходило'
            })
            unfulfilled.append(item)
            all_specs_status.append(item)
        # 2. 🟡 Жовті: у стадії виконання (залишок > 25т ТА перевищує толеранс 10%)
        elif not is_fulfilled:
            item = dict(base_item)
            item.update({
                'status': 'in_progress',
                'status_label': '🟡 У стадії виконання',
                'note': f"Завезено {truck_count} авто ({deliv_tons:,.2f} т з {contract_tons:,.2f} т, залишок {rem_tons:,.2f} т)"
            })
            unfulfilled.append(item)
            all_specs_status.append(item)
        # 3. 🟢 Зелені: виконані (залишок <= 25т або в межах толерансу 10%)
        else:
            if rem_tons <= 0.001:
                note_str = f"Виконано на 100%: завезено {deliv_tons:,.2f} т"
            else:
                note_str = f"Виконано (толеранс): залишок {rem_tons:,.2f} т (≤ 25 т / 10%)"
            item = dict(base_item)
            item.update({
                'status': 'fulfilled',
                'status_label': '🟢 Виконано (толеранс)',
                'note': note_str
            })
            all_specs_status.append(item)

    def sort_key(item):
        status_order = 0 if item['status'] == 'not_started' else (1 if item['status'] == 'in_progress' else 2)
        d_key = parse_date_sort_key(item['date'])
        return (status_order, item['pct_fulfilled'] if status_order == 1 else d_key, d_key)

    unfulfilled.sort(key=sort_key)
    for i, u in enumerate(unfulfilled, 1):
        u['num'] = i

    all_specs_status.sort(key=sort_key)
    for i, a in enumerate(all_specs_status, 1):
        a['num'] = i

    return unfulfilled, all_specs_status

def get_ukrainian_day_of_week(date_str):
    """ Повертає скорочену назву дня тижня українською мовою (Пн, Вт, Ср, Чт, Пт, Сб, Нд) """
    if not date_str:
        return ""
    try:
        parts = str(date_str).strip().split('.')
        if len(parts) == 3:
            d, m, y = int(parts[0]), int(parts[1]), int(parts[2])
            if y < 100:
                y += 2000
            dt = datetime(y, m, d)
            days = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"]
            return days[dt.weekday()]
    except Exception:
        pass
    return ""

def build_daily_delivery_data(truck_rows):
    """
    Формує щоденну динаміку прийомок:
    - Дата прийомки та день тижня
    - Кількість авто
    - Фізичний завіз (т), залікова вага (т), натуральна скидка (т) та %
    - Середньозважені: олійність (%), вологість (%), смітна домішка (%)
    """
    daily = {}
    for t in truck_rows:
        d = t['date'] or 'Невідомо'
        if d not in daily:
            daily[d] = {
                'date': d,
                'trucks_count': 0,
                'delivered_kg': 0.0,
                'zachet_kg': 0.0,
                'oil_weighted': 0.0,
                'w_weighted': 0.0,
                's_weighted': 0.0,
            }
        daily[d]['trucks_count'] += 1
        daily[d]['delivered_kg'] += t['delivered_kg']
        daily[d]['zachet_kg'] += t['zachet_kg']
        z = t['zachet_kg']
        if t['oil_fact'] is not None and z > 0:
            daily[d]['oil_weighted'] += t['oil_fact'] * z
        if t['w_fact'] is not None and z > 0:
            daily[d]['w_weighted'] += t['w_fact'] * z
        if t['s_fact'] is not None and z > 0:
            daily[d]['s_weighted'] += t['s_fact'] * z

    days_sorted = sorted(daily.keys(), key=parse_date_sort_key)
    daily_rows = []

    for i, d in enumerate(days_sorted, 1):
        info = daily[d]
        deliv_t = round(info['delivered_kg'] / 1000.0, 3)
        zach_t = round(info['zachet_kg'] / 1000.0, 3)
        diff_t = round(deliv_t - zach_t, 3)
        diff_pct = round((diff_t / deliv_t * 100), 2) if deliv_t > 0 else 0.0
        z = info['zachet_kg']
        avg_oil = round(info['oil_weighted'] / z, 2) if z > 0 else None
        avg_w = round(info['w_weighted'] / z, 2) if z > 0 else None
        avg_s = round(info['s_weighted'] / z, 2) if z > 0 else None

        daily_rows.append({
            'num': i,
            'date': d,
            'day_of_week': get_ukrainian_day_of_week(d),
            'trucks_count': info['trucks_count'],
            'delivered_tons': deliv_t,
            'zachet_tons': zach_t,
            'diff_tons': diff_t,
            'diff_pct': diff_pct,
            'oil_fact': avg_oil,
            'w_fact': avg_w,
            's_fact': avg_s,
        })

    return daily_rows

def build_supplier_discount_data(truck_rows, specs_db=None):
    """
    Формує аналітику натуральної скидки у вазі за постачальниками:
    - Постачальник, кількість специфікацій, кількість авто
    - Фізичний завіз (т), залікова вага (т)
    - Скидка у вазі (т) та % скидки
    - Середньозважені вологість, сор, олійність
    Сортує за спаданням втрати ваги (скидки у тоннах).
    """
    if specs_db is None:
        specs_db = {}
    sups = {}
    for t in truck_rows:
        sup = t['supplier'] or 'Без назви'
        code = t['code']
        if sup not in sups:
            sups[sup] = {
                'supplier': sup,
                'codes': set(),
                'trucks_count': 0,
                'delivered_kg': 0.0,
                'zachet_kg': 0.0,
                'oil_weighted': 0.0,
                'w_weighted': 0.0,
                's_weighted': 0.0,
            }
        if code:
            sups[sup]['codes'].add(code)
        sups[sup]['trucks_count'] += 1
        sups[sup]['delivered_kg'] += t['delivered_kg']
        sups[sup]['zachet_kg'] += t['zachet_kg']
        z = t['zachet_kg']
        if t['oil_fact'] is not None and z > 0:
            sups[sup]['oil_weighted'] += t['oil_fact'] * z
        if t['w_fact'] is not None and z > 0:
            sups[sup]['w_weighted'] += t['w_fact'] * z
        if t['s_fact'] is not None and z > 0:
            sups[sup]['s_weighted'] += t['s_fact'] * z

    sup_list = list(sups.values())
    # Сортування: від найбільшої натуральної скидки до найменшої
    sup_list.sort(key=lambda s: (s['delivered_kg'] - s['zachet_kg']), reverse=True)

    discount_rows = []
    for i, s in enumerate(sup_list, 1):
        deliv_t = round(s['delivered_kg'] / 1000.0, 3)
        zach_t = round(s['zachet_kg'] / 1000.0, 3)
        diff_t = round(deliv_t - zach_t, 3)
        diff_pct = round((diff_t / deliv_t * 100), 2) if deliv_t > 0 else 0.0
        z = s['zachet_kg']
        avg_oil = round(s['oil_weighted'] / z, 2) if z > 0 else None
        avg_w = round(s['w_weighted'] / z, 2) if z > 0 else None
        avg_s = round(s['s_weighted'] / z, 2) if z > 0 else None

        discount_rows.append({
            'num': i,
            'supplier': s['supplier'],
            'specs_count': len(s['codes']),
            'trucks_count': s['trucks_count'],
            'delivered_tons': deliv_t,
            'zachet_tons': zach_t,
            'diff_tons': diff_t,
            'diff_pct': diff_pct,
            'w_fact': avg_w,
            's_fact': avg_s,
            'oil_fact': avg_oil,
        })

    return discount_rows

def format_excel_sheet(ws, title, sub_title, rows, is_filtered_sheet=False):
    """
    Універсальна функція форматування листа Excel («Лист погодження»).
    Застосовує точну ширину колонок, налаштовану користувачем.
    """
    ws.views.sheetView[0].showGridLines = True
    
    font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
    font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
    font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
    font_data = Font(name="Calibri", size=10)
    font_total = Font(name="Calibri", size=10, bold=True)
    
    fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
    fill_total = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    fill_green = PatternFill(start_color="E2EFDA", end_color="E2EFDA", fill_type="solid")
    fill_red = PatternFill(start_color="FCE4D6", end_color="FCE4D6", fill_type="solid")
    fill_note_changed = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")
    fill_note_new = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    
    align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
    align_left = Alignment(horizontal="left", vertical="center")
    align_right = Alignment(horizontal="right", vertical="center")
    
    thin_side = Side(border_style="thin", color="D9D9D9")
    border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    
    top_thick = Side(border_style="thin", color="000000")
    double_bottom = Side(border_style="double", color="000000")
    border_total = Border(left=thin_side, right=thin_side, top=top_thick, bottom=double_bottom)
    
    ws.cell(1, 1, title).font = font_title
    ws.cell(2, 1, sub_title).font = font_sub
    
    # 18 колонок (включаючи Договір (т) та Ціну без доставки)
    headers = [
        "№",
        "Назва (Контрагент)",
        "Специфікація",
        "Дата",
        "Менеджер",
        "Умови доставки",
        "Договір (т)",
        "Завезено (т)",
        "Залікова вага (т)",
        "Різниця ваги (т)",
        "Ціна поставки (з дост.)",
        "Ціна без доставки (з місця)",
        "Масло (База)",
        "Волога (Факт)",
        "Сор (Факт)",
        "Факт завозу (олійність)",
        "Разница (відхилення)",
        "Примітка"
    ]
    
    header_row = 4
    ws.row_dimensions[header_row].height = 28
    
    for col_idx, h_name in enumerate(headers, 1):
        cell = ws.cell(header_row, col_idx, h_name)
        cell.font = font_header
        cell.fill = fill_header
        cell.alignment = align_center
        cell.border = border_cell

    start_data_row = 5
    cur_row = start_data_row
    
    for r_idx, r_data in enumerate(rows, 1):
        ws.row_dimensions[cur_row].height = 20
        
        ws.cell(cur_row, 1, r_idx).alignment = align_center
        ws.cell(cur_row, 2, r_data['supplier']).alignment = align_left
        ws.cell(cur_row, 3, r_data['specification']).alignment = align_center
        ws.cell(cur_row, 4, r_data['date']).alignment = align_center
        ws.cell(cur_row, 5, r_data['manager']).alignment = align_left
        ws.cell(cur_row, 6, r_data['delivery_terms']).alignment = align_center
        
        # Договір (т)
        c_contract = ws.cell(cur_row, 7, r_data.get('contract_tons'))
        c_contract.alignment = align_right
        c_contract.number_format = "#,##0.00"

        # Вага
        c_zavoz = ws.cell(cur_row, 8, r_data['weight_delivered'])
        c_zavoz.alignment = align_right
        c_zavoz.number_format = "#,##0.00"
        
        c_zachet = ws.cell(cur_row, 9, r_data['weight_zachet'])
        c_zachet.alignment = align_right
        c_zachet.number_format = "#,##0.00"
        
        c_diff_w = ws.cell(cur_row, 10, r_data['weight_diff'])
        c_diff_w.alignment = align_right
        c_diff_w.number_format = "#,##0.00"
        
        # Ціни
        c_price_dost = ws.cell(cur_row, 11, r_data['price_delivery'])
        c_price_dost.alignment = align_right
        c_price_dost.number_format = "#,##0.00"

        c_price_no_dost = ws.cell(cur_row, 12, r_data['price_no_delivery'])
        c_price_no_dost.alignment = align_right
        c_price_no_dost.number_format = "#,##0.00"
        
        # Якість
        c_ob = ws.cell(cur_row, 13, r_data['oil_base'])
        c_ob.alignment = align_right
        c_ob.number_format = "0.00"
        
        c_wf = ws.cell(cur_row, 14, r_data['w_fact'])
        c_wf.alignment = align_right
        c_wf.number_format = "0.00"
        
        c_sf = ws.cell(cur_row, 15, r_data['s_fact'])
        c_sf.alignment = align_right
        c_sf.number_format = "0.00"
        
        c_of = ws.cell(cur_row, 16, r_data['oil_fact'])
        c_of.alignment = align_right
        c_of.number_format = "0.00"
        
        c_diff_oil = ws.cell(cur_row, 17, r_data['oil_diff'])
        c_diff_oil.alignment = align_right
        c_diff_oil.number_format = "+0.00;-0.00;0.00"
        
        if r_data['oil_diff'] is not None:
            if r_data['oil_diff'] > 0:
                c_diff_oil.fill = fill_green
            elif r_data['oil_diff'] < 0:
                c_diff_oil.fill = fill_red

        # Примітка
        c_note = ws.cell(cur_row, 18, r_data.get('note', ''))
        c_note.alignment = align_left
        
        ch_type = r_data.get('change_type')
        if ch_type == 'changed':
            c_note.fill = fill_note_changed
            c_note.font = Font(name="Calibri", size=9, bold=True, color="7F6000")
        elif ch_type == 'new':
            c_note.fill = fill_note_new
            c_note.font = Font(name="Calibri", size=9, bold=True, color="1F4E78")
        else:
            c_note.font = Font(name="Calibri", size=9, color="595959")

        for c_idx in range(1, 18):
            cell = ws.cell(cur_row, c_idx)
            cell.border = border_cell
            if c_idx != 17 or r_data['oil_diff'] is None:
                cell.font = font_data
            else:
                cell.font = Font(name="Calibri", size=10, bold=True, color="274E13" if r_data['oil_diff'] > 0 else "990000")

        c_note.border = border_cell
        cur_row += 1

    # Рядок підсумків
    if rows:
        end_data_row = cur_row - 1
        total_row = cur_row
        ws.row_dimensions[total_row].height = 22
        
        t_label = ws.cell(total_row, 2, "Всього / Середньозважені:")
        t_label.font = font_total
        t_label.alignment = Alignment(horizontal="right", vertical="center")
        
        # Сума за договорами
        t_contract = ws.cell(total_row, 7, f"=SUM(G{start_data_row}:G{end_data_row})")
        t_contract.font = font_total
        t_contract.alignment = align_right
        t_contract.number_format = "#,##0.00"

        # Сума завезено
        t_zavoz = ws.cell(total_row, 8, f"=SUM(H{start_data_row}:H{end_data_row})")
        t_zavoz.font = font_total
        t_zavoz.alignment = align_right
        t_zavoz.number_format = "#,##0.00"
        
        # Сума залікової
        t_zachet = ws.cell(total_row, 9, f"=SUM(I{start_data_row}:I{end_data_row})")
        t_zachet.font = font_total
        t_zachet.alignment = align_right
        t_zachet.number_format = "#,##0.00"
        
        # Різниця ваги
        t_diff_w = ws.cell(total_row, 10, f"=H{total_row}-I{total_row}")
        t_diff_w.font = font_total
        t_diff_w.alignment = align_right
        t_diff_w.number_format = "#,##0.00"
        
        # Середньозважена ціна поставки (з дост.)
        t_price_dost = ws.cell(total_row, 11, f"=SUMPRODUCT(I{start_data_row}:I{end_data_row}, K{start_data_row}:K{end_data_row})/I{total_row}")
        t_price_dost.font = font_total
        t_price_dost.alignment = align_right
        t_price_dost.number_format = "#,##0.00"

        # Середньозважена ціна без доставки
        t_price_no_dost = ws.cell(total_row, 12, f"=SUMPRODUCT(I{start_data_row}:I{end_data_row}, L{start_data_row}:L{end_data_row})/I{total_row}")
        t_price_no_dost.font = font_total
        t_price_no_dost.alignment = align_right
        t_price_no_dost.number_format = "#,##0.00"
        
        # Середнє базове масло
        t_oil_base = ws.cell(total_row, 13, f"=AVERAGE(M{start_data_row}:M{end_data_row})")
        t_oil_base.font = font_total
        t_oil_base.alignment = align_right
        t_oil_base.number_format = "0.00"
        
        # Середня вологість
        t_w = ws.cell(total_row, 14, f"=AVERAGE(N{start_data_row}:N{end_data_row})")
        t_w.font = font_total
        t_w.alignment = align_right
        t_w.number_format = "0.00"
        
        # Середній сор
        t_s = ws.cell(total_row, 15, f"=AVERAGE(O{start_data_row}:O{end_data_row})")
        t_s.font = font_total
        t_s.alignment = align_right
        t_s.number_format = "0.00"
        
        # Середньозважена фактична олійність
        t_oil_fact = ws.cell(total_row, 16, f"=SUMPRODUCT(I{start_data_row}:I{end_data_row}, P{start_data_row}:P{end_data_row})/I{total_row}")
        t_oil_fact.font = font_total
        t_oil_fact.alignment = align_right
        t_oil_fact.number_format = "0.00"
        
        # Підсумкова різниця олійності
        t_diff_oil = ws.cell(total_row, 17, f"=P{total_row}-M{total_row}")
        t_diff_oil.font = font_total
        t_diff_oil.alignment = align_right
        t_diff_oil.number_format = "+0.00;-0.00;0.00"

        for c_idx in range(1, 19):
            c_cell = ws.cell(total_row, c_idx)
            c_cell.fill = fill_total
            c_cell.border = border_total

    # Встановлюємо точну ширину колонок, налаштовану користувачем
    for col_letter, width in CUSTOM_COLUMN_WIDTHS.items():
        ws.column_dimensions[col_letter].width = width

def format_unfulfilled_excel_sheet(ws, title="РЕЄСТР НЕВИКОНАНИХ СПЕЦИФІКАЦІЙ (СОНЯШНИК)", sub_title="🔴 Червоні — жодної машини не приїхало (0% виконання). 🟡 Жовті — у стадії виконання (залишок довозу > 25 т та > 10% толерансу). Специфікації з залишком ≤ 25 т або ≤ 10% вважаються виконаними.", rows=None):
    """
    Форматує аркуш «Невиконані специфікації».
    Застосовує червоне підсвічування для не розпочатих поставок та жовте для поставок у процесі.
    """
    if rows is None:
        rows = []
        
    ws.views.sheetView[0].showGridLines = True
    
    font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
    font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
    font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
    font_data = Font(name="Calibri", size=10)
    font_total = Font(name="Calibri", size=10, bold=True)
    
    fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
    fill_total = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    
    # Червоний рядок (0% завезено)
    fill_red_row = PatternFill(start_color="FCE4D6", end_color="FCE4D6", fill_type="solid")
    font_red_status = Font(name="Calibri", size=10, bold=True, color="C00000")
    
    # Жовтий рядок (у стадії виконання)
    fill_yellow_row = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")
    font_yellow_status = Font(name="Calibri", size=10, bold=True, color="B25900")
    
    align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
    align_left = Alignment(horizontal="left", vertical="center")
    align_right = Alignment(horizontal="right", vertical="center")
    
    thin_side = Side(border_style="thin", color="D9D9D9")
    border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    
    top_thick = Side(border_style="thin", color="000000")
    double_bottom = Side(border_style="double", color="000000")
    border_total = Border(left=thin_side, right=thin_side, top=top_thick, bottom=double_bottom)
    
    ws.cell(1, 1, title).font = font_title
    ws.cell(2, 1, sub_title).font = font_sub
    
    headers = [
        "№",
        "Статус виконання",
        "Назва (Контрагент)",
        "Специфікація",
        "Дата",
        "Менеджер",
        "Умови доставки",
        "Договір (т)",
        "Завезено (т)",
        "Залікова вага (т)",
        "Залишок довезти (т)",
        "Виконання (%)",
        "Ціна поставки (з дост.)",
        "Ціна без доставки (з місця)",
        "Примітка / Стан"
    ]
    
    col_widths = {
        'A': 8.0,  # №
        'B': 24.0, # Статус виконання
        'C': 32.0, # Назва (Контрагент)
        'D': 16.0, # Специфікація
        'E': 13.0, # Дата
        'F': 28.0, # Менеджер
        'G': 16.0, # Умови доставки
        'H': 16.0, # Договір (т)
        'I': 16.0, # Завезено (т)
        'J': 18.0, # Залікова вага (т)
        'K': 20.0, # Залишок довезти (т)
        'L': 16.0, # Виконання (%)
        'M': 24.0, # Ціна поставки (з дост.)
        'N': 26.0, # Ціна без доставки (з місця)
        'O': 42.0  # Примітка / Стан
    }
    
    header_row = 4
    ws.row_dimensions[header_row].height = 28
    
    for col_idx, h_name in enumerate(headers, 1):
        cell = ws.cell(header_row, col_idx, h_name)
        cell.font = font_header
        cell.fill = fill_header
        cell.alignment = align_center
        cell.border = border_cell

    start_data_row = 5
    cur_row = start_data_row
    
    for r_idx, r_data in enumerate(rows, 1):
        ws.row_dimensions[cur_row].height = 20
        is_red = (r_data['status'] == 'not_started')
        row_fill = fill_red_row if is_red else fill_yellow_row
        status_font = font_red_status if is_red else font_yellow_status
        
        ws.cell(cur_row, 1, r_idx).alignment = align_center
        
        c_status = ws.cell(cur_row, 2, r_data['status_label'])
        c_status.alignment = align_center
        c_status.font = status_font
        
        ws.cell(cur_row, 3, r_data['supplier']).alignment = align_left
        ws.cell(cur_row, 4, r_data['spec_display']).alignment = align_center
        ws.cell(cur_row, 5, r_data['date']).alignment = align_center
        ws.cell(cur_row, 6, r_data['manager']).alignment = align_left
        ws.cell(cur_row, 7, r_data['delivery_terms']).alignment = align_center
        
        # Договір (т)
        c_dog = ws.cell(cur_row, 8, r_data['contract_tons'])
        c_dog.alignment = align_right
        c_dog.number_format = "#,##0.00"
        
        # Завезено (т)
        c_zav = ws.cell(cur_row, 9, r_data['delivered_tons'])
        c_zav.alignment = align_right
        c_zav.number_format = "#,##0.00"
        
        # Залікова вага (т)
        c_zach = ws.cell(cur_row, 10, r_data['zachet_tons'])
        c_zach.alignment = align_right
        c_zach.number_format = "#,##0.00"
        
        # Залишок довезти (т)
        c_rem = ws.cell(cur_row, 11, f"=H{cur_row}-I{cur_row}")
        c_rem.alignment = align_right
        c_rem.number_format = "#,##0.00"
        
        # Виконання (%)
        c_pct = ws.cell(cur_row, 12, f"=IF(H{cur_row}>0, I{cur_row}/H{cur_row}, 0)")
        c_pct.alignment = align_right
        c_pct.number_format = "0.0%"
        
        # Ціни
        c_pr_dost = ws.cell(cur_row, 13, r_data['price_delivery'])
        c_pr_dost.alignment = align_right
        c_pr_dost.number_format = "#,##0.00"

        c_pr_no_dost = ws.cell(cur_row, 14, r_data['price_no_delivery'])
        c_pr_no_dost.alignment = align_right
        c_pr_no_dost.number_format = "#,##0.00"
        
        # Примітка
        c_note = ws.cell(cur_row, 15, r_data['note'])
        c_note.alignment = align_left
        c_note.font = Font(name="Calibri", size=9, bold=True if is_red else False, color="C00000" if is_red else "595959")
        
        for c_idx in range(1, 16):
            cell = ws.cell(cur_row, c_idx)
            cell.border = border_cell
            cell.fill = row_fill
            if c_idx not in (2, 15):
                cell.font = font_data

        cur_row += 1

    # Рядок підсумків
    if rows:
        end_data_row = cur_row - 1
        total_row = cur_row
        ws.row_dimensions[total_row].height = 22
        
        t_label = ws.cell(total_row, 3, "Всього / Залишок довезти:")
        t_label.font = font_total
        t_label.alignment = Alignment(horizontal="right", vertical="center")
        
        # Сума за договорами
        t_dog = ws.cell(total_row, 8, f"=SUM(H{start_data_row}:H{end_data_row})")
        t_dog.font = font_total
        t_dog.alignment = align_right
        t_dog.number_format = "#,##0.00"
        
        # Сума завезено
        t_zav = ws.cell(total_row, 9, f"=SUM(I{start_data_row}:I{end_data_row})")
        t_zav.font = font_total
        t_zav.alignment = align_right
        t_zav.number_format = "#,##0.00"
        
        # Сума залікової
        t_zach = ws.cell(total_row, 10, f"=SUM(J{start_data_row}:J{end_data_row})")
        t_zach.font = font_total
        t_zach.alignment = align_right
        t_zach.number_format = "#,##0.00"
        
        # Сума залишку
        t_rem = ws.cell(total_row, 11, f"=H{total_row}-I{total_row}")
        t_rem.font = font_total
        t_rem.alignment = align_right
        t_rem.number_format = "#,##0.00"
        
        # Загальний відсоток виконання
        t_pct = ws.cell(total_row, 12, f"=IF(H{total_row}>0, I{total_row}/H{total_row}, 0)")
        t_pct.font = font_total
        t_pct.alignment = align_right
        t_pct.number_format = "0.0%"
        
        # Середньозважена ціна поставки (з дост.)
        t_pr_dost = ws.cell(total_row, 13, f"=SUMPRODUCT(H{start_data_row}:H{end_data_row}, M{start_data_row}:M{end_data_row})/H{total_row}")
        t_pr_dost.font = font_total
        t_pr_dost.alignment = align_right
        t_pr_dost.number_format = "#,##0.00"

        # Середньозважена ціна без доставки
        t_pr_no_dost = ws.cell(total_row, 14, f"=SUMPRODUCT(H{start_data_row}:H{end_data_row}, N{start_data_row}:N{end_data_row})/H{total_row}")
        t_pr_no_dost.font = font_total
        t_pr_no_dost.alignment = align_right
        t_pr_no_dost.number_format = "#,##0.00"

        for c_idx in range(1, 16):
            c_cell = ws.cell(total_row, c_idx)
            c_cell.fill = fill_total
            c_cell.border = border_total

    for col_letter, width in col_widths.items():
        ws.column_dimensions[col_letter].width = width

def format_daily_excel_sheet(ws, title="ГРАФІК ЗАВЕЗЕННЯ СОНЯШНИКУ (ПО ДНЯХ)", sub_title="Щоденна динаміка прийомок: кількість авто, завіз, залікова вага, натуральна скидка та якісні показники.", rows=None):
    """
    Форматує аркуш «Графік завезення (по днях)» з щоденними підсумками та формулами.
    """
    if rows is None:
        rows = []
        
    ws.views.sheetView[0].showGridLines = True
    
    font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
    font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
    font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
    font_data = Font(name="Calibri", size=10)
    font_total = Font(name="Calibri", size=10, bold=True)
    
    fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
    fill_total = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    fill_peak = PatternFill(start_color="E2EFDA", end_color="E2EFDA", fill_type="solid")
    
    align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
    align_left = Alignment(horizontal="left", vertical="center")
    align_right = Alignment(horizontal="right", vertical="center")
    
    thin_side = Side(border_style="thin", color="D9D9D9")
    border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    
    top_thick = Side(border_style="thin", color="000000")
    double_bottom = Side(border_style="double", color="000000")
    border_total = Border(left=thin_side, right=thin_side, top=top_thick, bottom=double_bottom)
    
    ws.cell(1, 1, title).font = font_title
    ws.cell(2, 1, sub_title).font = font_sub
    
    headers = [
        "№",
        "Дата прийомки",
        "День тижня",
        "Кількість авто",
        "Фізичний завіз (т)",
        "Залікова вага (т)",
        "Натуральна скидка (т)",
        "Скидка (%)",
        "Олійність факт (%)",
        "Вологість факт (%)",
        "Сорність факт (%)"
    ]
    
    col_widths = {
        'A': 8.0,  # №
        'B': 18.0, # Дата прийомки
        'C': 15.0, # День тижня
        'D': 16.0, # Кількість авто
        'E': 20.0, # Фізичний завіз (т)
        'F': 20.0, # Залікова вага (т)
        'G': 22.0, # Натуральна скидка (т)
        'H': 16.0, # Скидка (%)
        'I': 20.0, # Олійність факт (%)
        'J': 20.0, # Вологість факт (%)
        'K': 20.0  # Сорність факт (%)
    }
    
    header_row = 4
    ws.row_dimensions[header_row].height = 28
    
    for col_idx, h_name in enumerate(headers, 1):
        cell = ws.cell(header_row, col_idx, h_name)
        cell.font = font_header
        cell.fill = fill_header
        cell.alignment = align_center
        cell.border = border_cell

    start_data_row = 5
    cur_row = start_data_row
    
    for r_idx, r_data in enumerate(rows, 1):
        ws.row_dimensions[cur_row].height = 20
        ws.cell(cur_row, 1, r_idx).alignment = align_center
        ws.cell(cur_row, 2, r_data['date']).alignment = align_center
        ws.cell(cur_row, 3, r_data['day_of_week']).alignment = align_center
        
        c_trucks = ws.cell(cur_row, 4, r_data['trucks_count'])
        c_trucks.alignment = align_right
        c_trucks.number_format = "#,##0"
        
        # Фізичний завіз (т)
        c_deliv = ws.cell(cur_row, 5, r_data['delivered_tons'])
        c_deliv.alignment = align_right
        c_deliv.number_format = "#,##0.00"
        if r_data['delivered_tons'] and r_data['delivered_tons'] >= 800.0:
            c_deliv.fill = fill_peak
            
        # Залікова вага (т)
        c_zach = ws.cell(cur_row, 6, r_data['zachet_tons'])
        c_zach.alignment = align_right
        c_zach.number_format = "#,##0.00"
        
        # Натуральна скидка (т)
        c_diff = ws.cell(cur_row, 7, f"=E{cur_row}-F{cur_row}")
        c_diff.alignment = align_right
        c_diff.number_format = "#,##0.00"
        
        # Скидка (%)
        c_pct = ws.cell(cur_row, 8, f"=IF(E{cur_row}>0, G{cur_row}/E{cur_row}, 0)")
        c_pct.alignment = align_right
        c_pct.number_format = "0.00%"
        
        # Олійність
        c_oil = ws.cell(cur_row, 9, r_data['oil_fact'])
        c_oil.alignment = align_right
        c_oil.number_format = "0.00"
        
        # Вологість
        c_w = ws.cell(cur_row, 10, r_data['w_fact'])
        c_w.alignment = align_right
        c_w.number_format = "0.00"
        
        # Сор
        c_s = ws.cell(cur_row, 11, r_data['s_fact'])
        c_s.alignment = align_right
        c_s.number_format = "0.00"
        
        for c_i in range(1, 12):
            cell = ws.cell(cur_row, c_i)
            cell.font = font_data
            cell.border = border_cell
            
        cur_row += 1

    if rows:
        end_data_row = cur_row - 1
        total_row = cur_row
        ws.row_dimensions[total_row].height = 22
        
        t_label = ws.cell(total_row, 3, "РАЗОМ:")
        t_label.font = font_total
        t_label.alignment = Alignment(horizontal="right", vertical="center")
        
        # Всього авто
        t_trucks = ws.cell(total_row, 4, f"=SUM(D{start_data_row}:D{end_data_row})")
        t_trucks.font = font_total
        t_trucks.alignment = align_right
        t_trucks.number_format = "#,##0"
        
        # Всього завіз
        t_del = ws.cell(total_row, 5, f"=SUM(E{start_data_row}:E{end_data_row})")
        t_del.font = font_total
        t_del.alignment = align_right
        t_del.number_format = "#,##0.00"
        
        # Всього залікова
        t_zach = ws.cell(total_row, 6, f"=SUM(F{start_data_row}:F{end_data_row})")
        t_zach.font = font_total
        t_zach.alignment = align_right
        t_zach.number_format = "#,##0.00"
        
        # Всього скидка
        t_diff = ws.cell(total_row, 7, f"=E{total_row}-F{total_row}")
        t_diff.font = font_total
        t_diff.alignment = align_right
        t_diff.number_format = "#,##0.00"
        
        # Середній % скидки
        t_pct = ws.cell(total_row, 8, f"=IF(E{total_row}>0, G{total_row}/E{total_row}, 0)")
        t_pct.font = font_total
        t_pct.alignment = align_right
        t_pct.number_format = "0.00%"
        
        # Середньозважена олійність
        t_oil = ws.cell(total_row, 9, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, I{start_data_row}:I{end_data_row})/F{total_row}")
        t_oil.font = font_total
        t_oil.alignment = align_right
        t_oil.number_format = "0.00"
        
        # Середньозважена вологість
        t_w = ws.cell(total_row, 10, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, J{start_data_row}:J{end_data_row})/F{total_row}")
        t_w.font = font_total
        t_w.alignment = align_right
        t_w.number_format = "0.00"
        
        # Середньозважений сор
        t_s = ws.cell(total_row, 11, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, K{start_data_row}:K{end_data_row})/F{total_row}")
        t_s.font = font_total
        t_s.alignment = align_right
        t_s.number_format = "0.00"
        
        for c_idx in range(1, 12):
            c_cell = ws.cell(total_row, c_idx)
            c_cell.fill = fill_total
            c_cell.border = border_total
            
    for col_letter, width in col_widths.items():
        ws.column_dimensions[col_letter].width = width

def format_supplier_discount_excel_sheet(ws, title="АНАЛІЗ НАТУРАЛЬНОЇ СКИДКИ У ВАЗІ (СОР І ВОЛОГА)", sub_title="Рейтинг контрагентів за спаданням втрати фізичної ваги (на сорі та усушці). Виділено вологість > 8.0% та сор > 3.0%.", rows=None):
    """
    Форматує аркуш «Натуральна скидка (сор, волога)» з рейтингом втрат ваги та якісними показниками.
    """
    if rows is None:
        rows = []
        
    ws.views.sheetView[0].showGridLines = True
    
    font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
    font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
    font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
    font_data = Font(name="Calibri", size=10)
    font_total = Font(name="Calibri", size=10, bold=True)
    
    fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
    fill_total = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    fill_warn_sor = PatternFill(start_color="FCE4D6", end_color="FCE4D6", fill_type="solid") # сор > 3%
    fill_warn_w = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")   # волога > 8%
    
    align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
    align_left = Alignment(horizontal="left", vertical="center")
    align_right = Alignment(horizontal="right", vertical="center")
    
    thin_side = Side(border_style="thin", color="D9D9D9")
    border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    
    top_thick = Side(border_style="thin", color="000000")
    double_bottom = Side(border_style="double", color="000000")
    border_total = Border(left=thin_side, right=thin_side, top=top_thick, bottom=double_bottom)
    
    ws.cell(1, 1, title).font = font_title
    ws.cell(2, 1, sub_title).font = font_sub
    
    headers = [
        "№",
        "Контрагент (Постачальник)",
        "Кількість спец.",
        "Кількість авто",
        "Фізичний завіз (т)",
        "Залікова вага (т)",
        "Натуральна скидка (т)",
        "Втрата ваги (%)",
        "Вологість факт (%)",
        "Сорність факт (%)",
        "Олійність факт (%)"
    ]
    
    col_widths = {
        'A': 8.0,  # №
        'B': 34.0, # Контрагент
        'C': 16.0, # Специфікацій
        'D': 16.0, # Кількість авто
        'E': 20.0, # Фізичний завіз
        'F': 20.0, # Залікова вага
        'G': 22.0, # Натуральна скидка
        'H': 18.0, # Втрата ваги %
        'I': 20.0, # Вологість
        'J': 20.0, # Сорність
        'K': 20.0  # Олійність
    }
    
    header_row = 4
    ws.row_dimensions[header_row].height = 28
    
    for col_idx, h_name in enumerate(headers, 1):
        cell = ws.cell(header_row, col_idx, h_name)
        cell.font = font_header
        cell.fill = fill_header
        cell.alignment = align_center
        cell.border = border_cell

    start_data_row = 5
    cur_row = start_data_row
    
    for r_idx, r_data in enumerate(rows, 1):
        ws.row_dimensions[cur_row].height = 20
        ws.cell(cur_row, 1, r_idx).alignment = align_center
        ws.cell(cur_row, 2, r_data['supplier']).alignment = align_left
        ws.cell(cur_row, 3, r_data['specs_count']).alignment = align_center
        
        c_tr = ws.cell(cur_row, 4, r_data['trucks_count'])
        c_tr.alignment = align_right
        c_tr.number_format = "#,##0"
        
        # Фізичний завіз (т)
        c_deliv = ws.cell(cur_row, 5, r_data['delivered_tons'])
        c_deliv.alignment = align_right
        c_deliv.number_format = "#,##0.00"
        
        # Залікова вага (т)
        c_zach = ws.cell(cur_row, 6, r_data['zachet_tons'])
        c_zach.alignment = align_right
        c_zach.number_format = "#,##0.00"
        
        # Натуральна скидка (т)
        c_diff = ws.cell(cur_row, 7, f"=E{cur_row}-F{cur_row}")
        c_diff.alignment = align_right
        c_diff.number_format = "#,##0.00"
        
        # Втрата ваги (%)
        c_pct = ws.cell(cur_row, 8, f"=IF(E{cur_row}>0, G{cur_row}/E{cur_row}, 0)")
        c_pct.alignment = align_right
        c_pct.number_format = "0.00%"
        
        # Вологість
        c_w = ws.cell(cur_row, 9, r_data['w_fact'])
        c_w.alignment = align_right
        c_w.number_format = "0.00"
        if r_data['w_fact'] and r_data['w_fact'] > 8.0:
            c_w.fill = fill_warn_w
            
        # Сорність
        c_s = ws.cell(cur_row, 10, r_data['s_fact'])
        c_s.alignment = align_right
        c_s.number_format = "0.00"
        if r_data['s_fact'] and r_data['s_fact'] > 3.0:
            c_s.fill = fill_warn_sor
            
        # Олійність
        c_oil = ws.cell(cur_row, 11, r_data['oil_fact'])
        c_oil.alignment = align_right
        c_oil.number_format = "0.00"
        
        for c_i in range(1, 12):
            cell = ws.cell(cur_row, c_i)
            cell.font = font_data
            cell.border = border_cell
            
        cur_row += 1

    if rows:
        end_data_row = cur_row - 1
        total_row = cur_row
        ws.row_dimensions[total_row].height = 22
        
        t_label = ws.cell(total_row, 2, "РАЗОМ:")
        t_label.font = font_total
        t_label.alignment = Alignment(horizontal="right", vertical="center")
        
        # Всього спец
        t_sp = ws.cell(total_row, 3, f"=SUM(C{start_data_row}:C{end_data_row})")
        t_sp.font = font_total
        t_sp.alignment = align_center
        t_sp.number_format = "#,##0"
        
        # Всього авто
        t_trucks = ws.cell(total_row, 4, f"=SUM(D{start_data_row}:D{end_data_row})")
        t_trucks.font = font_total
        t_trucks.alignment = align_right
        t_trucks.number_format = "#,##0"
        
        # Всього завіз
        t_del = ws.cell(total_row, 5, f"=SUM(E{start_data_row}:E{end_data_row})")
        t_del.font = font_total
        t_del.alignment = align_right
        t_del.number_format = "#,##0.00"
        
        # Всього залікова
        t_zach = ws.cell(total_row, 6, f"=SUM(F{start_data_row}:F{end_data_row})")
        t_zach.font = font_total
        t_zach.alignment = align_right
        t_zach.number_format = "#,##0.00"
        
        # Всього скидка
        t_diff = ws.cell(total_row, 7, f"=E{total_row}-F{total_row}")
        t_diff.font = font_total
        t_diff.alignment = align_right
        t_diff.number_format = "#,##0.00"
        
        # Середній % скидки
        t_pct = ws.cell(total_row, 8, f"=IF(E{total_row}>0, G{total_row}/E{total_row}, 0)")
        t_pct.font = font_total
        t_pct.alignment = align_right
        t_pct.number_format = "0.00%"
        
        # Середньозважена вологість
        t_w = ws.cell(total_row, 9, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, I{start_data_row}:I{end_data_row})/F{total_row}")
        t_w.font = font_total
        t_w.alignment = align_right
        t_w.number_format = "0.00"
        
        # Середньозважений сор
        t_s = ws.cell(total_row, 10, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, J{start_data_row}:J{end_data_row})/F{total_row}")
        t_s.font = font_total
        t_s.alignment = align_right
        t_s.number_format = "0.00"
        
        # Середньозважена олійність
        t_oil = ws.cell(total_row, 11, f"=SUMPRODUCT(F{start_data_row}:F{end_data_row}, K{start_data_row}:K{end_data_row})/F{total_row}")
        t_oil.font = font_total
        t_oil.alignment = align_right
        t_oil.number_format = "0.00"
        
        for c_idx in range(1, 12):
            c_cell = ws.cell(total_row, c_idx)
            c_cell.fill = fill_total
            c_cell.border = border_total
            
    for col_letter, width in col_widths.items():
        ws.column_dimensions[col_letter].width = width

def export_to_excel(aggregated_rows, detailed_rows=None, unfulfilled_rows=None, daily_rows=None, supplier_discount_rows=None, output_path="Лист_погодження.xlsx", history_data=None, receipts_datetime="", light_mode=False):
    """
    Генерує фінальний Excel-файл.
    При light_mode=True формуються лише ключові 3 листи:
    1. «Лист погодження» (всі специфікації з поставками, вагами та цінами)
    2. «Невиконані специфікації» (🔴 Не приїхало 0% / 🟡 У стадії виконання)
    3. «Історія змін» (журнал аудиту цін та параметрів)
    """
    wb = openpyxl.Workbook()
    
    # ---------------- ЛИСТ 1: «Лист погодження» (Всі специфікації з поставками) ----------------
    ws1 = wb.active
    ws1.title = "Лист погодження"
    sheet1_sub = f"Дані прийомок 1С станом на {receipts_datetime}. Ціни з Реєстру специфікацій (з доставкою та без доставки). Фактичний завіз та залікова вага з Реєстру прийомок." if receipts_datetime else "Ціни з Реєстру специфікацій (з доставкою та без доставки). Фактичний завіз та залікова вага з Реєстру прийомок."
    format_excel_sheet(
        ws1,
        title="ЗВІТ «ЛИСТ ПОГОДЖЕННЯ» З ПОСТАЧАННЯ СОНЯШНИКУ",
        sub_title=sheet1_sub,
        rows=aggregated_rows
    )
    
    # ---------------- ЛИСТ 2: «Невиконані специфікації» (🔴 Червоні / 🟡 Жовті) ----------------
    if unfulfilled_rows is not None:
        ws_unf = wb.create_sheet(title="Невиконані специфікації")
        format_unfulfilled_excel_sheet(
            ws_unf,
            title="РЕЄСТР НЕВИКОНАНИХ СПЕЦИФІКАЦІЙ (СОНЯШНИК)",
            sub_title="🔴 Червоні — жодної машини не приїхало (0% виконання). 🟡 Жовті — у стадії виконання (залишок довозу > 25 т та > 10% толерансу). Специфікації з залишком ≤ 25 т або ≤ 10% вважаються виконаними.",
            rows=unfulfilled_rows
        )

    # ---------------- ЛИСТ 3: «Натуральна скидка (сор, волога)» ----------------
    if not light_mode and supplier_discount_rows is not None:
        ws_disc = wb.create_sheet(title="Натуральна скидка (сор, волога)")
        format_supplier_discount_excel_sheet(
            ws_disc,
            title="АНАЛІЗ НАТУРАЛЬНОЇ СКИДКИ У ВАЗІ (СОР І ВОЛОГА)",
            sub_title="Рейтинг контрагентів за спаданням втрати фізичної ваги (на сорі та усушці). Виділено вологість > 8.0% та сор > 3.0%.",
            rows=supplier_discount_rows
        )

    # ---------------- ЛИСТ 4: «Графік завезення (по днях)» ----------------
    if not light_mode and daily_rows is not None:
        ws_daily = wb.create_sheet(title="Графік завезення (по днях)")
        format_daily_excel_sheet(
            ws_daily,
            title="ГРАФІК ЗАВЕЗЕННЯ СОНЯШНИКУ (ПО ДНЯХ)",
            sub_title="Щоденна динаміка прийомок: кількість авто, завіз, залікова вага, натуральна скидка та якісні показники.",
            rows=daily_rows
        )

    # ---------------- ЛИСТ 5: «Відхилення більше 1%» (Фільтрований) ----------------
    if not light_mode:
        filtered_rows = [r for r in aggregated_rows if r['oil_diff'] is not None and abs(r['oil_diff']) >= 1.0]
        ws2 = wb.create_sheet(title="Відхилення більше 1%")
        format_excel_sheet(
            ws2,
            title="ДОГОВОРИ З ВІДХИЛЕННЯМ ОЛІЙНОСТІ БІЛЬШЕ 1% (|Разница| >= 1%)",
            sub_title="Специфікації з суттєвими бонусами або дисконтами по олійності на суху речовину.",
            rows=filtered_rows,
            is_filtered_sheet=True
        )

    # ---------------- ЛИСТ 6: «Реєстр прийомок (помашинно)» ----------------
    if not light_mode and detailed_rows:
        ws3 = wb.create_sheet(title="Реєстр прийомок (помашинно)")
        ws3.views.sheetView[0].showGridLines = True
        
        font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
        font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
        font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
        font_data = Font(name="Calibri", size=10)
        
        fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
        fill_green = PatternFill(start_color="E2EFDA", end_color="E2EFDA", fill_type="solid")
        fill_red = PatternFill(start_color="FCE4D6", end_color="FCE4D6", fill_type="solid")
        
        align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
        align_left = Alignment(horizontal="left", vertical="center")
        align_right = Alignment(horizontal="right", vertical="center")
        
        thin_side = Side(border_style="thin", color="D9D9D9")
        border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
        
        ws3.cell(1, 1, "ДЕТАЛЬНИЙ ПОМАШИННИЙ РЕЄСТР ПРИЙОМОК СОНЯШНИКУ").font = font_title
        ws3.cell(2, 1, "Зіставлення з договорами за кодом у дужках. Фізична вага завозу, залікова вага та показники якості.").font = font_sub
        
        headers_det = [
            "№",
            "Назва (Контрагент)",
            "Специфікація",
            "№ Авто",
            "ТТН / Док",
            "Дата",
            "Менеджер",
            "Умови доставки",
            "Завезено (т)",
            "Залікова вага (т)",
            "Різниця ваги (т)",
            "Ціна поставки (з дост.)",
            "Ціна без доставки (з місця)",
            "Масло (База)",
            "Волога (Факт)",
            "Сор (Факт)",
            "Факт завозу (олійність)",
            "Разница (відхилення)"
        ]
        
        ws3.row_dimensions[4].height = 28
        for col_idx, h_name in enumerate(headers_det, 1):
            cell = ws3.cell(4, col_idx, h_name)
            cell.font = font_header
            cell.fill = fill_header
            cell.alignment = align_center
            cell.border = border_cell
            
        cur_d_row = 5
        for d in detailed_rows:
            ws3.row_dimensions[cur_d_row].height = 18
            ws3.cell(cur_d_row, 1, d['num']).alignment = align_center
            ws3.cell(cur_d_row, 2, d['supplier']).alignment = align_left
            ws3.cell(cur_d_row, 3, d['specification']).alignment = align_center
            ws3.cell(cur_d_row, 4, d['auto']).alignment = align_center
            ws3.cell(cur_d_row, 5, d['doc_no']).alignment = align_center
            ws3.cell(cur_d_row, 6, d['date']).alignment = align_center
            ws3.cell(cur_d_row, 7, d['manager']).alignment = align_left
            ws3.cell(cur_d_row, 8, d['delivery_terms']).alignment = align_center
            
            c_deliv = ws3.cell(cur_d_row, 9, d['weight_delivered'])
            c_deliv.alignment = align_right
            c_deliv.number_format = "#,##0.00"
            
            c_zach = ws3.cell(cur_d_row, 10, d['weight_zachet'])
            c_zach.alignment = align_right
            c_zach.number_format = "#,##0.00"
            
            c_diff_w = ws3.cell(cur_d_row, 11, d['weight_diff'])
            c_diff_w.alignment = align_right
            c_diff_w.number_format = "#,##0.00"
            
            c_pr_dost = ws3.cell(cur_d_row, 12, d['price_delivery'])
            c_pr_dost.alignment = align_right
            c_pr_dost.number_format = "#,##0.00"

            c_pr_no_dost = ws3.cell(cur_d_row, 13, d['price_no_delivery'])
            c_pr_no_dost.alignment = align_right
            c_pr_no_dost.number_format = "#,##0.00"
            
            c_mb = ws3.cell(cur_d_row, 14, d['oil_base'])
            c_mb.alignment = align_right
            c_mb.number_format = "0.00"
            
            c_wf = ws3.cell(cur_d_row, 15, d['w_fact'])
            c_wf.alignment = align_right
            c_wf.number_format = "0.00"
            
            c_sf = ws3.cell(cur_d_row, 16, d['s_fact'])
            c_sf.alignment = align_right
            c_sf.number_format = "0.00"
            
            c_of = ws3.cell(cur_d_row, 17, d['oil_fact'])
            c_of.alignment = align_right
            c_of.number_format = "0.00"
            
            c_diff_o = ws3.cell(cur_d_row, 18, d['oil_diff'])
            c_diff_o.alignment = align_right
            c_diff_o.number_format = "+0.00;-0.00;0.00"
            if d['oil_diff'] is not None:
                if d['oil_diff'] > 0:
                    c_diff_o.fill = fill_green
                elif d['oil_diff'] < 0:
                    c_diff_o.fill = fill_red
                    
            for c_i in range(1, 19):
                cell = ws3.cell(cur_d_row, c_i)
                cell.border = border_cell
                cell.font = font_data
                
            cur_d_row += 1
            
        for col in ws3.columns:
            max_len = 0
            col_letter = get_column_letter(col[0].column)
            for cell in col:
                val = str(cell.value or '')
                if cell.row in (1, 2):
                    continue
                max_len = max(max_len, len(val))
            ws3.column_dimensions[col_letter].width = max(max_len + 3, 11)

    # 6. Лист «Історія змін» (Журнал аудиту)
    if history_data and history_data.get('events'):
        ws_hist = wb.create_sheet(title="Історія змін")
        ws_hist.views.sheetView[0].showGridLines = True
        
        font_title = Font(name="Calibri", size=14, bold=True, color="1F4E78")
        font_sub = Font(name="Calibri", size=10, italic=True, color="595959")
        font_header = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
        font_data = Font(name="Calibri", size=10)
        
        fill_header = PatternFill(start_color="1F4E78", end_color="1F4E78", fill_type="solid")
        fill_price_ev = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid") # золотистий
        fill_new_ev = PatternFill(start_color="E2EFDA", end_color="E2EFDA", fill_type="solid")   # зелений
        
        align_center = Alignment(horizontal="center", vertical="center")
        align_left = Alignment(horizontal="left", vertical="center")
        thin_side = Side(border_style="thin", color="D9D9D9")
        border_cell = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
        
        ws_hist.cell(1, 1, "ЖУРНАЛ АУДИТУ ТА ІСТОРІЯ ЗМІН (ЦІНИ, ВАГА, ДОГОВОРИ)").font = font_title
        ws_hist.cell(2, 1, "Автоматична фіксація змін між версіями файлів 1С. Зміни цін, нові договори та коригування.").font = font_sub
        
        headers_h = ["№", "Дата / Час", "Контрагент (Постачальник)", "Специфікація", "Менеджер", "Категорія", "Деталі змін (Було ➔ Стало)"]
        ws_hist.row_dimensions[4].height = 26
        for c_idx, h_text in enumerate(headers_h, 1):
            cell = ws_hist.cell(4, c_idx, h_text)
            cell.font = font_header
            cell.fill = fill_header
            cell.alignment = align_center
            cell.border = border_cell

        r_idx = 5
        # Новіші події вгорі
        rev_events = list(reversed(history_data['events']))
        for i, ev in enumerate(rev_events, 1):
            ws_hist.row_dimensions[r_idx].height = 18
            c_num = ws_hist.cell(r_idx, 1, i)
            c_time = ws_hist.cell(r_idx, 2, ev.get('timestamp', ''))
            c_sup = ws_hist.cell(r_idx, 3, ev.get('supplier', ''))
            c_spec = ws_hist.cell(r_idx, 4, ev.get('specification', ''))
            c_man = ws_hist.cell(r_idx, 5, ev.get('manager', ''))
            c_cat = ws_hist.cell(r_idx, 6, ev.get('category_label', ev.get('category', '')))
            c_sum = ws_hist.cell(r_idx, 7, ev.get('summary', ''))

            c_num.alignment = align_center
            c_time.alignment = align_center
            c_sup.alignment = align_left
            c_spec.alignment = align_center
            c_man.alignment = align_left
            c_cat.alignment = align_center
            c_sum.alignment = align_left

            cat_val = ev.get('category')
            for cell in (c_num, c_time, c_sup, c_spec, c_man, c_cat, c_sum):
                cell.font = font_data
                cell.border = border_cell
                if cat_val == 'price':
                    cell.fill = fill_price_ev
                elif cat_val == 'new':
                    cell.fill = fill_new_ev

            r_idx += 1

        for col in ws_hist.columns:
            max_len = 0
            col_letter = get_column_letter(col[0].column)
            for cell in col:
                val = str(cell.value or '')
                if cell.row in (1, 2):
                    continue
                max_len = max(max_len, len(val))
            ws_hist.column_dimensions[col_letter].width = max(max_len + 3, 11)

    wb.save(output_path)
    return output_path

def process_reports(receipts_file, specs_file, output_file="Лист_погодження.xlsx", light_mode=False, allow_older_receipts=False):
    """
    Повний конвеєр обробки двох файлів та генерації звіту з інкрементальним аналізом змін.
    При light_mode=True формується оптимізований звіт (Лист погодження, Невиконані специфікації, Історія змін),
    без розрахунку помашинних реєстрів, графіків та натуральної скидки для мінімального навантаження на ПК.
    """
    receipts_datetime = extract_receipts_datetime(receipts_file)
    existing_report = load_existing_report(output_file)
    history_data = load_changes_history(output_file)

    # Захист від завантаження застарілого приходу (оновлення назад у часі)
    is_stale, new_dt, new_str, exist_dt, exist_str, reason = check_receipts_staleness(
        receipts_file=receipts_file,
        output_file=output_file,
        history_data=history_data
    )
    if is_stale and not allow_older_receipts:
        raise StaleReceiptsError(
            f"Завантаження заблоковано: вибрано застарілий файл прийомок 1С!\n\n"
            f"• Файл прийомок ({os.path.basename(receipts_file)}): {new_str}\n"
            f"• Актуальні дані в системі: {exist_str}\n\n"
            f"Оновлення назад у часі заборонено: старіший прийом призведе до фіктивного зменшення ваги "
            f"та спотворення історії змін.\n\n"
            f"Будь ласка, виберіть актуальний файл прийомок із новою датою."
        )

    specs_db = load_specifications(specs_file)
    truck_rows, summary_rows = load_receipts(receipts_file)
    
    agg_rows = build_aggregated_report_data(specs_db, truck_rows, summary_rows, existing_report, history_data)
    unfulfilled_rows, all_specs_status = build_unfulfilled_data(specs_db, truck_rows, summary_rows)
    
    if not light_mode:
        det_rows = build_detailed_truck_data(specs_db, truck_rows)
        daily_rows = build_daily_delivery_data(truck_rows)
        discount_rows = build_supplier_discount_data(truck_rows, specs_db)
    else:
        det_rows = None
        daily_rows = None
        discount_rows = None
    
    # Зберігаємо актуальну дату прийомок в історію
    if receipts_datetime:
        history_data["last_receipts_datetime"] = receipts_datetime
    dt_obj, _ = parse_receipts_datetime(receipts_file)
    if dt_obj:
        history_data["last_receipts_iso"] = dt_obj.isoformat()

    save_changes_history(output_file, history_data)
    out = export_to_excel(
        agg_rows, det_rows, unfulfilled_rows, daily_rows, discount_rows,
        output_path=output_file,
        history_data=history_data,
        receipts_datetime=receipts_datetime,
        light_mode=light_mode
    )
    
    new_count = sum(1 for r in agg_rows if r.get('change_type') == 'new')
    changed_count = sum(1 for r in agg_rows if r.get('change_type') == 'changed')
    same_count = sum(1 for r in agg_rows if r.get('change_type') == 'same')
    filtered_count = sum(1 for r in agg_rows if r['oil_diff'] is not None and abs(r['oil_diff']) >= 1.0)
    not_started_cnt = sum(1 for u in unfulfilled_rows if u['status'] == 'not_started')
    in_progress_cnt = sum(1 for u in unfulfilled_rows if u['status'] == 'in_progress')
    fulfilled_cnt = sum(1 for a in all_specs_status if a['status'] == 'fulfilled')
    
    return {
        'specs_loaded': len(specs_db),
        'trucks_loaded': len(truck_rows),
        'summary_loaded': len(summary_rows),
        'report_rows': len(agg_rows),
        'filtered_rows': filtered_count,
        'unfulfilled_rows_count': len(unfulfilled_rows),
        'not_started_count': not_started_cnt,
        'in_progress_count': in_progress_cnt,
        'fulfilled_count': fulfilled_cnt,
        'existing_compared': len(existing_report),
        'new_records': new_count,
        'changed_records': changed_count,
        'same_records': same_count,
        'output_path': out,
        'agg_rows': agg_rows,
        'det_rows': det_rows,
        'unfulfilled_rows': unfulfilled_rows,
        'all_specs_status': all_specs_status,
        'daily_rows': daily_rows,
        'discount_rows': discount_rows,
        'history_data': history_data,
        'receipts_datetime': receipts_datetime
    }

if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="Зведення звіту «Лист погодження» з постачання соняшнику")
    parser.add_argument("--receipts", default="Реєстр_прийомок.xlsx", help="Шлях до Файлу 1 (Реєстр прийомок)")
    parser.add_argument("--specs", default="Реєстр_специфікацій.xlsx", help="Шлях до Файлу 2 (Реєстр специфікацій)")
    parser.add_argument("--output", default="Лист_погодження.xlsx", help="Шлях до вихідного Excel файлу")
    
    args = parser.parse_args()
    
    res = process_reports(
        receipts_file=args.receipts,
        specs_file=args.specs,
        output_file=args.output
    )
    print(f"Звіт збережено: {res['output_path']}")
    print(f"Завантажено специфікацій: {res['specs_loaded']}, Прийомок: {res['trucks_loaded']}")
    print(f"Зведено рядків: {res['report_rows']} (З відхиленням >= 1%: {res['filtered_rows']})")
