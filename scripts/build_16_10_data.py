# -*- coding: utf-8 -*-
import os, sys, json, re, csv, io
import openpyxl

def main():
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    
    # 1. Update changes_history.json with the 10 newest events from 16.10.2026 12:58
    hist_path = os.path.join(base_dir, 'database', 'changes_history.json')
    with open(hist_path, 'r', encoding='utf-8') as f:
        hist_data = json.load(f)
        
    hist_data['last_receipts_datetime'] = "16.10.2026 час 08:02"
    hist_data['last_receipts_iso'] = "2026-10-16T08:02:00"
    
    # Update spec_price_notes for event 3 (А-1230)
    hist_data['spec_price_notes']['А-1230'] = {
        "timestamp": "16.10",
        "price_note": "Ціна з дост: 13,999.20 -> 13,372.20 (-627.00); Ціна без дост: 13,999.20 -> 13,372.20 (-627.00)",
        "supplier": "ФЕДОРІВСЬКИЙ ЦЕНТР ПО",
        "specification": "А-1230 (Сп.1)"
    }
    
    new_16_events = [
        {
            "id": "А-1245_new_2026-10-16_12:58",
            "timestamp": "16.10.2026 12:58",
            "code": "А-1245",
            "supplier": "РОЗА-АГРО ТОВ",
            "specification": "А-1245 (Сп.2)",
            "manager": "",
            "category": "new",
            "category_label": "🆕 Новий договір",
            "summary": "Додано нову специфікацію А-1245 (Сп.2)"
        },
        {
            "id": "А-1206_wq_125.02_51.48_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-1206",
            "supplier": "Узун С(Ф)Г",
            "specification": "А-1206 (Сп.1)",
            "manager": "Приліпко Максим Олегович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "Завезено: 26.68 -> 125.02 т; Залікова: 26.68 -> 125.02 т; Факт масла: 52.07% -> 51.48%; Волога: 7.60% -> 7.65%"
        },
        {
            "id": "А-1230_price_13372.2_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-1230",
            "supplier": "ФЕДОРІВСЬКИЙ ЦЕНТР ПО",
            "specification": "А-1230 (Сп.1)",
            "manager": "Приліпко Максим Олегович",
            "category": "price",
            "category_label": "💰 Зміна ціни",
            "summary": "Ціна з дост: 13,999.20 -> 13,372.20 (-627.00); Ціна без дост: 13,999.20 -> 13,372.20 (-627.00)"
        },
        {
            "id": "А-1119_wq_220.30_48.04_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-1119",
            "supplier": "Шевченківське АПК ТОВ",
            "specification": "А-1119 (Сп.1)",
            "manager": "Чкан Олексій Володимирович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "Завезено: 145.07 -> 220.30 т; Залікова: 144.58 -> 219.72 т; Факт масла: 48.92% -> 48.04%; Волога: 6.84% -> 6.76%; Сор: 3.31% -> 3.23%"
        },
        {
            "id": "А-863_wq_470.47_48.40_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-863",
            "supplier": "Скорук А.А. ФГ",
            "specification": "А-863 (Сп.1)",
            "manager": "Філімонов Максим Юрійович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "Завезено: 380.97 -> 470.47 т; Залікова: 380.97 -> 470.47 т; Факт масла: 48.32% -> 48.40%; Волога: 5.00% -> 5.29%; Сор: 5.05% -> 4.80%"
        },
        {
            "id": "А-940_wq_base_46.36_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-940",
            "supplier": "ДЕМЕТРААГРОТЕХ ФГ",
            "specification": "А-940 (Сп.2)",
            "manager": "Філімонов Максим Юрійович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "База масла: 48.00% -> 46.36%"
        },
        {
            "id": "А-999_wq_base_49.54_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-999",
            "supplier": "СОНЯЧНЕ 2007 ПАТ",
            "specification": "А-999 (Сп.2)",
            "manager": "Чкан Олексій Володимирович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "База масла: 52.10% -> 49.54%"
        },
        {
            "id": "А-984_wq_base_50.38_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-984",
            "supplier": "ВІЛЬНЯНСЬК СВК ТОВ",
            "specification": "А-984 (Сп.1)",
            "manager": "Приліпко Максим Олегович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "База масла: 51.50% -> 50.38%"
        },
        {
            "id": "А-909_wq_base_48.26_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-909",
            "supplier": "УКРАГРОХІМ ТОВ",
            "specification": "А-909 (Сп.1)",
            "manager": "Філімонов Максим Юрійович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "База масла: 49.50% -> 48.26%"
        },
        {
            "id": "А-905_wq_base_47.84_16.10",
            "timestamp": "16.10.2026 12:58",
            "code": "А-905",
            "supplier": "ЛІДЕР СФГ  22162186",
            "specification": "А-905 (Сп.1)",
            "manager": "Чкан Олексій Володимирович",
            "category": "weight_quality",
            "category_label": "⚖️ Вага / Якість",
            "summary": "База масла: 48.90% -> 47.84%"
        }
    ]
    
    # Prepend new events if not already present
    existing_ids = {e.get('id') for e in hist_data.get('events', [])}
    events_to_add = [e for e in new_16_events if e['id'] not in existing_ids]
    hist_data['events'] = events_to_add + hist_data.get('events', [])
    
    with open(hist_path, 'w', encoding='utf-8') as f:
        json.dump(hist_data, f, ensure_ascii=False, indent=2)
    print(f"Updated {hist_path} with {len(events_to_add)} new events. Total events: {len(hist_data['events'])}")

if __name__ == '__main__':
    main()
