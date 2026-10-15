#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Скрипт повної синхронізації вкладки «СЕМЕНА» з актуального Лист_погодження.xlsx та changes_history.json.
Впорядковує Реєстр прийомок (помашинно) та Історію змін від найновіших до найстаріших (сьогоднішня дата зверху).
"""
import sys, os, json, re, openpyxl

def main():
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    excel_path = os.path.join(base_dir, 'database', 'Лист_погодження.xlsx')
    hist_path = os.path.join(base_dir, 'database', 'changes_history.json')
    out_json_path = os.path.join(base_dir, 'database', 'semena_data.json')

    net_xlsx = r'\\10.61.43.251\6143 бухгалтерия\Documents\закупки\Лист_погодження.xlsx'
    if os.path.exists(net_xlsx):
        try:
            import shutil
            shutil.copy2(net_xlsx, excel_path)
        except Exception:
            pass

    net_hist = r'\\10.61.43.251\6143 бухгалтерия\Documents\закупки\changes_history.json'
    if os.path.exists(net_hist):
        try:
            import shutil
            shutil.copy2(net_hist, hist_path)
        except Exception:
            pass

    if not os.path.exists(excel_path):
        print(f"Помилка: файл {excel_path} не знайдено!")
        return 1

    wb = openpyxl.load_workbook(excel_path, data_only=True)

    def find_sheet(workbook, keywords):
        for s in workbook.sheetnames:
            low = s.lower().replace('_', ' ')
            for kw in keywords:
                if kw in low:
                    return workbook[s]
        return None

    hist_data = {"events": [], "spec_price_notes": {}}
    if os.path.exists(hist_path):
        with open(hist_path, 'r', encoding='utf-8') as f:
            hist_data = json.load(f)

    def norm_date(val):
        if not val: return ''
        s = str(val).strip().split(' ')[0]
        m = re.match(r'^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$', s)
        if m:
            d = m.group(1).zfill(2)
            mo = m.group(2).zfill(2)
            y = m.group(3)
            if len(y) == 2: y = '20' + y
            return f'{d}.{mo}.{y}'
        return s

    def date_sort_key(d_str):
        nd = norm_date(d_str)
        m = re.match(r'^(\d{2})\.(\d{2})\.(\d{4})$', nd)
        if m:
            return f'{m.group(3)}-{m.group(2)}-{m.group(1)}'
        return '0000-00-00'

    def dt_sort_key(dt_str):
        s = str(dt_str or '').strip()
        m = re.search(r'(\d{2})\.(\d{2})\.(\d{4})(?:[\sчас]+(\d{1,2}):(\d{2}))?', s)
        if m:
            y, mo, d = m.group(3), m.group(2), m.group(1)
            h = m.group(4).zfill(2) if m.group(4) else '00'
            mi = m.group(5).zfill(2) if m.group(5) else '00'
            return f'{y}-{mo}-{d} {h}:{mi}'
        return '0000-00-00 00:00'

    def float_val(v, default=0.0):
        if v is None: return default
        if isinstance(v, (int, float)): return float(v)
        s = str(v).replace('\xa0', '').replace(' ', '').replace(',', '.')
        s = re.sub(r'[^0-9.\-+]', '', s)
        if not s or s in ('-', '+'): return default
        try: return float(s)
        except: return default

    # 1. Sheet: Лист погодження
    ws_agg = find_sheet(wb, ['погоджен', 'зведен']) or wb.active
    subtitle = str(ws_agg.cell(2, 1).value or '')
    rep_dt_m = re.search(r'станом на\s*(\d{2}\.\d{2}\.\d{4}(?:\s*час\s*\d{1,2}:\d{2})?)', subtitle)
    report_dt = rep_dt_m.group(1) if rep_dt_m else hist_data.get('last_receipts_datetime', '14.10.2026 час 22:12')

    agg_rows = []
    for r in range(5, ws_agg.max_row + 1):
        num = ws_agg.cell(r, 1).value
        sup = str(ws_agg.cell(r, 2).value or '').strip()
        if not num or not sup or 'всього' in sup.lower() or 'разом' in sup.lower():
            continue
        spec = str(ws_agg.cell(r, 3).value or '').strip()
        d_raw = ws_agg.cell(r, 4).value
        manager = str(ws_agg.cell(r, 5).value or '').strip()
        terms = str(ws_agg.cell(r, 6).value or '').strip()
        contract = float_val(ws_agg.cell(r, 7).value)
        delivered = float_val(ws_agg.cell(r, 8).value)
        credit_w = float_val(ws_agg.cell(r, 9).value)
        w_diff = round(delivered - credit_w, 3)
        p_deliv = float_val(ws_agg.cell(r, 11).value)
        p_exw = float_val(ws_agg.cell(r, 12).value)
        oil_base = float_val(ws_agg.cell(r, 13).value, 48.0)
        moisture = float_val(ws_agg.cell(r, 14).value)
        trash = float_val(ws_agg.cell(r, 15).value)
        oil_fact = float_val(ws_agg.cell(r, 16).value)
        oil_diff = round(oil_fact - oil_base, 2) if oil_fact > 0 else 0.0
        note = str(ws_agg.cell(r, 18).value or '').strip()
        if note == 'Без змін': note = ''

        code = ''
        cm = re.search(r'([A-Za-zА-Яа-яІіЇїЄєҐґ]\s*[-–]\s*\d+)', spec)
        if cm: code = cm.group(1).replace(' ', '').replace('–', '-').upper()

        agg_rows.append({
            'num': int(num),
            'supplier': sup,
            'spec': spec,
            'specification': spec,
            'code': code,
            'date': norm_date(d_raw),
            'spec_date': norm_date(d_raw),
            'manager': manager,
            'terms': terms,
            'delivery_terms': terms,
            'contract': round(contract, 2),
            'contract_t': round(contract, 2),
            'contract_tons': round(contract, 2),
            'delivered': round(delivered, 3),
            'delivered_t': round(delivered, 3),
            'weight_delivered': round(delivered, 3),
            'credit_weight': round(credit_w, 3),
            'accounting_t': round(credit_w, 3),
            'weight_zachet': round(credit_w, 3),
            'weight_diff': w_diff,
            'price_delivered': round(p_deliv, 2),
            'price_with_deliv': round(p_deliv, 2),
            'price_delivery': round(p_deliv, 2),
            'price_exw': round(p_exw, 2),
            'price_no_deliv': round(p_exw, 2),
            'price_no_delivery': round(p_exw, 2),
            'oil_base': round(oil_base, 2),
            'moisture': round(moisture, 2),
            'w_fact': round(moisture, 2),
            'trash': round(trash, 2),
            's_fact': round(trash, 2),
            'oil_fact': round(oil_fact, 2),
            'oil_diff': oil_diff,
            'note': note,
            'change_type': 'changed' if ('Змінено' in note or 'Ціна' in note) else ('new' if 'Новий' in note else 'same')
        })

    # 2. Sheet: Невиконані специфікації
    ws_unf = find_sheet(wb, ['невиконан', 'невыполнен'])
    unf_rows = []
    if ws_unf:
        for r in range(5, ws_unf.max_row + 1):
            num = ws_unf.cell(r, 1).value
            status_raw = str(ws_unf.cell(r, 2).value or '').strip()
            sup = str(ws_unf.cell(r, 3).value or '').strip()
            if not num or not sup: continue
            spec = str(ws_unf.cell(r, 4).value or '').strip()
            d_raw = ws_unf.cell(r, 5).value
            manager = str(ws_unf.cell(r, 6).value or '').strip()
            terms = str(ws_unf.cell(r, 7).value or '').strip()
            contract = float_val(ws_unf.cell(r, 8).value)
            delivered = float_val(ws_unf.cell(r, 9).value)
            credit_w = float_val(ws_unf.cell(r, 10).value)
            rem = round(max(0.0, contract - delivered), 3)
            pct = round((delivered / contract) * 100, 1) if contract > 0 else 0.0
            p_deliv = float_val(ws_unf.cell(r, 13).value)
            p_exw = float_val(ws_unf.cell(r, 14).value)
            note = str(ws_unf.cell(r, 15).value or '').strip()

            is_pending = (delivered <= 0.001) or ('не приїхало' in status_raw.lower())
            cat = 'not_started' if is_pending else 'in_progress'

            unf_rows.append({
                'category': cat,
                'status': 'pending' if is_pending else 'in_progress',
                'status_code': 'pending' if is_pending else 'in_progress',
                'status_raw': status_raw,
                'status_label': status_raw,
                'supplier': sup,
                'spec': spec,
                'spec_display': spec,
                'date': norm_date(d_raw),
                'spec_date': norm_date(d_raw),
                'manager': manager,
                'terms': terms,
                'delivery_terms': terms,
                'contract': round(contract, 2),
                'contract_t': round(contract, 2),
                'contract_tons': round(contract, 2),
                'delivered': round(delivered, 3),
                'delivered_t': round(delivered, 3),
                'delivered_tons': round(delivered, 3),
                'credit_weight': round(credit_w, 3),
                'zachet_tons': round(credit_w, 3),
                'remaining': rem,
                'remaining_t': rem,
                'remaining_tons': rem,
                'pct': pct,
                'pct_fulfilled': pct,
                'price_delivered': round(p_deliv, 2),
                'price_with_deliv': round(p_deliv, 2),
                'price_exw': round(p_exw, 2),
                'note': note,
                'num': int(num)
            })

    # 3. Sheet: Графік завезення (по днях)
    ws_daily = find_sheet(wb, ['графік', 'график', 'по днях'])
    daily_rows = []
    if ws_daily:
        for r in range(5, ws_daily.max_row + 1):
            num = ws_daily.cell(r, 1).value
            d_raw = ws_daily.cell(r, 2).value
            if not num or not d_raw: continue
            dow = str(ws_daily.cell(r, 3).value or '').strip()
            cars = int(float_val(ws_daily.cell(r, 4).value))
            deliv = float_val(ws_daily.cell(r, 5).value)
            credit_w = float_val(ws_daily.cell(r, 6).value)
            disc = round(deliv - credit_w, 3)
            disc_pct = round((disc / deliv) * 100, 2) if deliv > 0 else 0.0
            oil = float_val(ws_daily.cell(r, 9).value)
            moist = float_val(ws_daily.cell(r, 10).value)
            trash = float_val(ws_daily.cell(r, 11).value)
            d_norm = norm_date(d_raw)

            daily_rows.append({
                'num': int(num),
                'date': d_norm,
                'weekday': dow,
                'day_name': dow,
                'day_of_week': dow,
                'trucks': cars,
                'cars': cars,
                'trucks_count': cars,
                'delivered': round(deliv, 3),
                'delivered_t': round(deliv, 3),
                'delivered_tons': round(deliv, 3),
                'credit_weight': round(credit_w, 3),
                'accounting_t': round(credit_w, 3),
                'zachet_tons': round(credit_w, 3),
                'discount': disc,
                'diff_t': disc,
                'diff_tons': disc,
                'discount_pct': disc_pct,
                'diff_pct': disc_pct,
                'oil': round(oil, 2),
                'oil_fact': round(oil, 2),
                'moisture': round(moist, 2),
                'w_fact': round(moist, 2),
                'trash': round(trash, 2),
                's_fact': round(trash, 2)
            })

    # 4. Sheet: Натуральна скидка (сор, волога)
    ws_disc = find_sheet(wb, ['натуральн', 'скидк'])
    disc_rows = []
    if ws_disc:
        for r in range(5, ws_disc.max_row + 1):
            num = ws_disc.cell(r, 1).value
            sup = str(ws_disc.cell(r, 2).value or '').strip()
            if not num or not sup or 'разом' in sup.lower(): continue
            sp_cnt = int(float_val(ws_disc.cell(r, 3).value))
            cars = int(float_val(ws_disc.cell(r, 4).value))
            deliv = float_val(ws_disc.cell(r, 5).value)
            credit_w = float_val(ws_disc.cell(r, 6).value)
            disc = round(deliv - credit_w, 3)
            disc_pct = round((disc / deliv) * 100, 2) if deliv > 0 else 0.0
            moist = float_val(ws_disc.cell(r, 9).value)
            trash = float_val(ws_disc.cell(r, 10).value)
            oil = float_val(ws_disc.cell(r, 11).value)

            disc_rows.append({
                'num': int(num),
                'supplier': sup,
                'specs_count': sp_cnt,
                'trucks': cars,
                'cars': cars,
                'trucks_count': cars,
                'delivered': round(deliv, 3),
                'delivered_t': round(deliv, 3),
                'credit_weight': round(credit_w, 3),
                'accounting_t': round(credit_w, 3),
                'discount': disc,
                'diff_t': disc,
                'discount_pct': disc_pct,
                'diff_pct': disc_pct,
                'moisture': round(moist, 2),
                'trash': round(trash, 2),
                'oil': round(oil, 2)
            })

    # 5. Sheet: Реєстр прийомок (помашинно)
    ws_truck = find_sheet(wb, ['реєстр', 'реестр', 'помашин'])
    truck_rows = []
    if ws_truck:
        for r in range(5, ws_truck.max_row + 1):
            num = ws_truck.cell(r, 1).value
            sup = str(ws_truck.cell(r, 2).value or '').strip()
            if not num or not sup: continue
            spec = str(ws_truck.cell(r, 3).value or '').strip()
            car_num = str(ws_truck.cell(r, 4).value or '').strip()
            ttn = str(ws_truck.cell(r, 5).value or '').strip()
            d_raw = ws_truck.cell(r, 6).value
            manager = str(ws_truck.cell(r, 7).value or '').strip()
            terms = str(ws_truck.cell(r, 8).value or '').strip()
            deliv = float_val(ws_truck.cell(r, 9).value)
            credit_w = float_val(ws_truck.cell(r, 10).value)
            w_diff = round(deliv - credit_w, 3)
            p_deliv = float_val(ws_truck.cell(r, 12).value)
            p_exw = float_val(ws_truck.cell(r, 13).value)
            oil_base = float_val(ws_truck.cell(r, 14).value, 48.0)
            moist = float_val(ws_truck.cell(r, 15).value)
            trash = float_val(ws_truck.cell(r, 16).value)
            oil_fact = float_val(ws_truck.cell(r, 17).value)
            oil_diff = round(oil_fact - oil_base, 2) if oil_fact > 0 else 0.0

            truck_rows.append({
                'num': int(num),
                'supplier': sup,
                'spec': spec,
                'car_num': car_num,
                'ttn': ttn,
                'date': norm_date(d_raw),
                'manager': manager,
                'terms': terms,
                'delivered_t': round(deliv, 3),
                'accounting_t': round(credit_w, 3),
                'weight_diff': w_diff,
                'price_with_deliv': round(p_deliv, 2) if p_deliv > 0 else None,
                'price_no_deliv': round(p_exw, 2) if p_exw > 0 else None,
                'oil_base': round(oil_base, 2),
                'moisture': round(moist, 2) if moist > 0 else None,
                'trash': round(trash, 2) if trash > 0 else None,
                'oil_fact': round(oil_fact, 2) if oil_fact > 0 else None,
                'oil_diff': oil_diff if oil_fact > 0 else None
            })

    # Сортуємо Реєстр прийомок помашинно від нових до старих (сьогоднішня дата зверху таблиці)
    truck_rows.sort(key=lambda t: (date_sort_key(t['date']), t['num']), reverse=True)

    # 6. Sheet: Історія змін & changes_history.json
    events_list = hist_data.get('events', [])
    sheet_events = []
    ws_hist = find_sheet(wb, ['історі', 'истори', 'аудит'])
    if ws_hist:
        for r in range(5, ws_hist.max_row + 1):
            num = ws_hist.cell(r, 1).value
            ts = str(ws_hist.cell(r, 2).value or '').strip()
            sup = str(ws_hist.cell(r, 3).value or '').strip()
            if not ts or not sup: continue
            spec = str(ws_hist.cell(r, 4).value or '').strip()
            man = str(ws_hist.cell(r, 5).value or '').strip()
            cat_lbl = str(ws_hist.cell(r, 6).value or '').strip()
            det = str(ws_hist.cell(r, 7).value or '').strip()
            code = ''
            cm = re.search(r'([A-Za-zА-Яа-яІіЇїЄєҐґ]\s*[-–]\s*\d+)', spec)
            if cm: code = cm.group(1).replace(' ', '').replace('–', '-').upper()
            cat = 'price' if 'цін' in cat_lbl.lower() else ('new' if 'новий' in cat_lbl.lower() else 'weight_quality')
            sheet_events.append({
                'id': f'{code}_{ts}_{cat}',
                'timestamp': ts,
                'code': code,
                'supplier': sup,
                'specification': spec,
                'manager': man,
                'category': cat,
                'category_label': cat_lbl,
                'summary': det
            })

    seen_ids = set()
    all_events = []
    for e in events_list + sheet_events:
        c = str(e.get('code') or '').strip().upper()
        s = str(e.get('summary') or e.get('details') or '').strip()
        t = str(e.get('timestamp') or e.get('dt') or '').strip()
        eid = f"{c}_{t}_{s}"
        if eid not in seen_ids:
            seen_ids.add(eid)
            all_events.append(e)

    # Сортуємо події історії змін від найновіших до найстаріших (сьогоднішня дата зверху таблиці)
    all_events.sort(key=lambda e: dt_sort_key(e.get('timestamp') or e.get('dt')), reverse=True)

    history_rows = []
    for e in all_events[:200]:
        history_rows.append({
            'dt': str(e.get('timestamp') or e.get('dt') or ''),
            'supplier': str(e.get('supplier') or ''),
            'spec': str(e.get('specification') or e.get('spec') or ''),
            'manager': str(e.get('manager') or ''),
            'category': str(e.get('category_label') or e.get('category') or ''),
            'details': str(e.get('summary') or e.get('details') or '')
        })

    # Summary calculations
    tot_contract = round(sum(r['contract'] for r in agg_rows), 2)
    tot_deliv = round(sum(r['delivered'] for r in agg_rows), 2)
    tot_acc = round(sum(r['credit_weight'] for r in agg_rows), 2)
    tot_diff = round(tot_deliv - tot_acc, 2)
    not_started = sum(1 for u in unf_rows if u['category'] == 'not_started')
    in_prog = sum(1 for u in unf_rows if u['category'] == 'in_progress')
    fulfilled = sum(1 for r in agg_rows if r['contract'] > 0 and (r['delivered'] >= r['contract'] or (r['contract'] - r['delivered']) <= 25.0 or (r['contract'] - r['delivered']) <= 0.1 * r['contract']))
    unf_rem_t = round(sum(u['remaining'] for u in unf_rows), 2)
    z_sum = sum(t['accounting_t'] for t in truck_rows if t['accounting_t'] > 0)
    avg_oil = round(sum(t['oil_fact'] * t['accounting_t'] for t in truck_rows if t['oil_fact'] and t['accounting_t']) / z_sum, 2) if z_sum > 0 else 49.3
    avg_moist = round(sum(t['moisture'] * t['accounting_t'] for t in truck_rows if t['moisture'] and t['accounting_t']) / z_sum, 2) if z_sum > 0 else 7.0
    avg_trash = round(sum(t['trash'] * t['accounting_t'] for t in truck_rows if t['trash'] and t['accounting_t']) / z_sum, 2) if z_sum > 0 else 3.2

    summary = {
        'total_specs': len(agg_rows),
        'unique_suppliers': len(set(r['supplier'] for r in agg_rows if r['supplier'])),
        'total_contract_t': tot_contract,
        'total_delivered_t': tot_deliv,
        'total_accounting_t': tot_acc,
        'total_diff_t': tot_diff,
        'total_trucks': len(truck_rows),
        'not_started_count': not_started,
        'in_progress_count': in_prog,
        'fulfilled_count': fulfilled,
        'unfulfilled_rem_t': unf_rem_t,
        'avg_oil_pct': avg_oil,
        'avg_moisture_pct': avg_moist,
        'avg_trash_pct': avg_trash,
        'deviations_count': sum(1 for r in agg_rows if r.get('note'))
    }

    final_data = {
        'updated_at': report_dt,
        'report_datetime': report_dt,
        'last_receipts_iso': hist_data.get('last_receipts_iso', '2026-10-14T22:12:00'),
        'source_subtitle': subtitle,
        'summary': summary,
        'agg_rows': agg_rows,
        'unfulfilled_rows': unf_rows,
        'unf_rows': agg_rows,
        'daily_rows': daily_rows,
        'discount_rows': disc_rows,
        'disc_rows': disc_rows,
        'truck_rows': truck_rows,
        'history_events': all_events[:200],
        'history_rows': history_rows
    }

    with open(out_json_path, 'w', encoding='utf-8') as f:
        json.dump(final_data, f, ensure_ascii=False, indent=2)

    # Також зберігаємо об'єднану історію у changes_history.json
    hist_data['events'] = all_events
    with open(hist_path, 'w', encoding='utf-8') as f:
        json.dump(hist_data, f, ensure_ascii=False, indent=2)

    print("Синхронізація успішна!")
    print(f"Звіт станом на: {report_dt}")
    print(f"Завантажено специфікацій: {len(agg_rows)}")
    print(f"Завантажено авто (помашинно): {len(truck_rows)}, перша дата: {truck_rows[0]['date'] if truck_rows else 'N/A'}")
    print(f"Завантажено подій в історію змін: {len(history_rows)}, перша дата: {history_rows[0]['dt'] if history_rows else 'N/A'}")
    print(f"Перший рядок історії змін: {history_rows[0] if history_rows else 'N/A'}")
    return 0

if __name__ == '__main__':
    sys.exit(main())
