import os
import sys
import glob
from datetime import datetime

OUTPUT_FILENAME = "ALL_PROJECT_CODE_AND_DOCUMENTATION.txt"

# List of files to exclude (large binary, zip, and huge data dumps)
EXCLUDE_EXTENSIONS = {".zip", ".xlsx", ".xls", ".pyc", ".png", ".jpg", ".jpeg", ".ico"}
EXCLUDE_FILES = {
    OUTPUT_FILENAME,
    # SECURITY: never bundle credentials / password data
    "config.php",
    ".env",
    os.path.join("database", "users.json"),
    os.path.join("database", "semena_data.json"), # 1.3 MB raw json dump
    os.path.join("database", "steam_data.json"),  # 780 KB raw json dump
    os.path.join("database", "semena_raw_prihod.json"), # 335 KB raw json dump
    os.path.join("database", "changes_history.json"), # 99 KB raw json dump
    os.path.join("database", "semena_raw_spec.json"), # 59 KB raw json dump
    os.path.join("database", "energy_manual_entries.json"),
}

DOCUMENTATION_HEADER = """================================================================================
           ПОВНИЙ АРХІВ ТА ВИХІДНИЙ КОД ПРОЄКТУ: ДАШБОРД ЗАВОДУ МЕЗ
               ТОВ «АГРОПРОІНВЕСТ 08» (dash-api08.dniprovska.net)
================================================================================
Дата формування: {date}
Призначення: Повна фіксація всіх розроблених модулів, правил, правок, формул,
інтеграцій та вихідного коду для перевірки без витрати токенів нейромережі.

--------------------------------------------------------------------------------
РОЗДІЛ 1. ПОВНИЙ ЗВІТ ПРО ВИКОНАНУ РОБОТУ, ПРАВИЛА ТА ВИПРАВЛЕННЯ
--------------------------------------------------------------------------------

1. БЛОК А: МАТЕРІАЛЬНИЙ БАЛАНС ТА ВИРОБНИЦТВО (МЕЗ)
   - Правило простою (16, 17, 18 жовтня):
     Коли в добовому звіті МЕЗ вказано 0 т переробки соняшнику (завод на паузі/простої),
     система автоматично відображає коректні статуси:
     * Вихід продукції: 0,0 т (Олія: 0 т, Шрот: 0 т, Лузга: 0 т).
     * Відображається плашка-попередження «МЕЗ: переробка на паузі (0 т)».
     * Графік виходу продукції не ламається, відсотки не діляться на нуль (0%).
     * Втрати підробки елеватора відокремлені від виробничих втрат МЕЗ.
   - Показники за 15.10.2026:
     * Перероблено очищеного насіння: 308,98 т.
     * Вироблено олії: 149,278 т (48,31%).
     * Вироблено шроту: 156,128 т (50,53%).
     * Вироблено лузги: 45,950 т (14,87%).
     * Сума виходу (Олія + Шрот): 98,84% (+12,63% до бази 2025).
     * Загальний баланс виходу продукції: 113,72% (перевищення виходу +13,72%).
   - Показники за 14.10.2026:
     * Перероблено очищеного соняшнику: 596,805 т.
     * Вироблено олії: 266,13 т (44,59%).
     * Вироблено шроту: 247,93 т (41,54%).
     * Вироблено лузги: 89,88 т (15,06%).
   - Лабораторія якості насіння у переробці (Справка по переработке):
     * Товарна олійність за 14.10: строго 44,03% (на а.с.р. 47,99%).
     * Товарна олійність за 15.10: 44,03% (на а.с.р. 47,00%).
     * Вологість: 6,20% — 6,30% (< 8%, в нормі).
     * Смітна домішка: 1,96% — 2,18%.
   - Баланс втрат олії:
     * Втрати олії в шроті (0,77% олійності шроту): 1 202,3 кг (15.10) / 16 260,9 кг (місяць).
     * Втрати олії в луззі (3,27% олійності лузги): 1 502,1 кг (15.10) / 24 997,0 кг (місяць).
     * Невраховані втрати олії: -15 936,5 кг (-5,16% — негативні втрати завдяки високому фактичному виходу).

2. БЛОК Б: ЛОГІСТИКА ТРАНСПОРТУ ТА ВОРОНКА (CRM-СИСТЕМА)
   - Інтеграція з базою apgk_api08 (режим «Тільки читання»):
     * Воронка автотранспорту по категоріях: «Всі», «Насіння», «Олія», «Шрот», «Лузга».
     * Розрахунок середнього часу перебування машин на території заводу.
     * Відображення кількості авто в черзі, на завантаженні/розвантаженні та виїхавших.
   - Складські залишки (СВОД, database/svod_stocks.json; нижче — резервні значення в index.php,
     якщо файл відсутній):
     * Олія (резервуари): 3 650,10 т.
     * Шрот на складі: 674,86 т.
     * Лузга на складі: 166,77 т.
     * Витрата лузги на котельню: автоматичний облік використання як палива.

3. БЛОК В: ЕНЕРГОРЕСУРСИ ЗАВОДУ (ЕЛЕКТРОЕНЕРГІЯ, ВОДА, ПАРА, СТОКИ, ГАЗ)
   - Інтерактивний архів пари за 12 років (2015–2026 рр.):
     * Повний добовий журнал за всі місяці року («Учет пара на ООО “Агропроинвест 08”»).
     * Розподіл пари по цехах: котельня (виробництво), власні потреби (СН), цех екстракції,
       цех підготовки, елеватор, опалення.
     * Питома витрата пари на 1 т переробленого насіння (кг/т).
     * Інтерактивний випадаючий список вибору місяця без перезавантаження сторінки.
   - Інтерактивний архів електроенергії за 2026 рік (Січень — Жовтень):
     * Помісячний випадаючий список розміщено безпосередньо у шапці Блоку В та в таблиці цехів.
     * Розподіл електроенергії (активна кВт, реактивна кВАр·год, питома кВт/т, вода, стоки, газ)
       по підрозділах — список згенеровано автоматично з database/energy_departments_archive.json:
{departments}
     * Колонка «Природний газ» — дані з архіву (Котельня природного газу).
     * Автоматичний підсумковий рядок «РАЗОМ (Місяць Рік)» з сумами та середньою нормою.
   - Форма збереження показників енергетика:
     * Модальне вікно внесення показників за лічильниками (активна, реактивна, вода, пара).
     * API обробник `api/save_energy_entry.php` з перевіркою прав та збереженням у БД/JSON.

4. БЛОК Г: ПОРІВНЯННЯ СЕЗОНІВ 2026 vs 2025
   - Порівняння доби, тижня, місяця та повного сезону 2026–2027 vs 2025–2026.
   - Картки KPI з прогрес-барами та кольоровими трендами.

5. АВТОМАТИЗАЦІЯ ТА РОЗГОРТАННЯ:
   - Збірка дистрибутиву `hestiacp_dashboard_zavod.zip` скриптом `scripts/build_zip.py`.
   - Повна синхронізація з GitHub-репозиторієм (`main` branch).

--------------------------------------------------------------------------------
РОЗДІЛ 2. СПИСОК ФАЙЛІВ У ЦЬОМУ АРХІВІ
--------------------------------------------------------------------------------
"""

def get_project_files(root_dir):
    files_to_bundle = []
    
    # Priority order for critical files
    priority_order = [
        "index.php",
        os.path.join("includes", "metrics_calculator.php"),
        os.path.join("includes", "semena_helper.php"),
        os.path.join("includes", "db.php"),
        os.path.join("includes", "helpers.php"),
        os.path.join("includes", "auth.php"),
        os.path.join("includes", "crm_client.php"),
        os.path.join("includes", "zabbix_client.php"),
        os.path.join("includes", "xlsx_parser.php"),
        os.path.join("api", "metrics.php"),
        os.path.join("api", "save_energy_entry.php"),
        os.path.join("api", "import.php"),
        os.path.join("api", "export.php"),
        os.path.join("api", "logistics.php"),
        os.path.join("api", "equipment.php"),
        os.path.join("assets", "js", "dashboard.js"),
        os.path.join("assets", "js", "charts.js"),
        os.path.join("assets", "css", "dashboard.css"),
        "login.php",
        "logout.php",
        "semena.php",
        "energo.php",
        "install.php",
        "config.sample.php",
        os.path.join("cron", "sync_logistics.php"),
        os.path.join("cron", "sync_zabbix.php"),
        os.path.join("database", "schema.sql"),
        os.path.join("database", "seed_data.sql"),
        os.path.join("database", "energy_departments_archive.json"),
        os.path.join("database", "production_oil_losses.json"),
        os.path.join("database", "svod_stocks.json"),
        os.path.join("database", "users.json"),
        os.path.join("scripts", "build_zip.py"),
        os.path.join("scripts", "sunflower_processor.py"),
        os.path.join("scripts", "sync_semena_data.py"),
        os.path.join("scripts", "process_1c.py"),
        os.path.join("scripts", "build_16_10_data.py"),
        os.path.join("scripts", "verify_order.py"),
        os.path.join("scripts", "parse_xls.py"),
        "docker-compose.yml",
        "Dockerfile",
        "README.md",
    ]

    existing_priority = []
    for rel_path in priority_order:
        full_path = os.path.join(root_dir, rel_path)
        if os.path.isfile(full_path) and rel_path not in EXCLUDE_FILES:
            existing_priority.append(rel_path)

    # Collect other relevant files
    other_files = []
    for dirpath, dirnames, filenames in os.walk(root_dir):
        # Skip git and cache dirs
        if ".git" in dirpath or "__pycache__" in dirpath or ".gemini" in dirpath:
            continue
        for fname in filenames:
            ext = os.path.splitext(fname)[1].lower()
            if ext in EXCLUDE_EXTENSIONS:
                continue
            full = os.path.join(dirpath, fname)
            rel = os.path.relpath(full, root_dir)
            if rel in EXCLUDE_FILES or rel in existing_priority:
                continue
            other_files.append(rel)

    return existing_priority + sorted(other_files)

def build_departments_text(root_dir):
    import json
    path = os.path.join(root_dir, "database", "energy_departments_archive.json")
    try:
        with open(path, encoding="utf-8") as f:
            arch = json.load(f)
    except Exception as e:
        return f"       (не вдалося прочитати архів: {e})"
    names = []
    per_month = []
    for key in sorted(arch.keys()):
        m = arch[key]
        depts = m.get("departments", [])
        per_month.append(f"{key}: {len(depts)}")
        for d in depts:
            if d.get("name") and d["name"] not in names:
                names.append(d["name"])
    lines = [f"       {i}) {n}" for i, n in enumerate(names, 1)]
    lines.append("       Кількість підрозділів по місяцях: " + ", ".join(per_month))
    return "\n".join(lines)

def generate_bundle(root_dir, output_file):
    files = get_project_files(root_dir)
    print(f"Total files to bundle: {len(files)}")
    
    with open(output_file, "w", encoding="utf-8") as out:
        # Write Header
        now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        out.write(DOCUMENTATION_HEADER.format(date=now_str, departments=build_departments_text(root_dir)))
        
        # Write File Index
        for idx, rel in enumerate(files, 1):
            full = os.path.join(root_dir, rel)
            size = os.path.getsize(full)
            out.write(f"{idx:02d}. {rel} ({size:,} байт)\n")
        out.write("\n" + "=" * 80 + "\n\n")

        # Write each file
        for idx, rel in enumerate(files, 1):
            full = os.path.join(root_dir, rel)
            size = os.path.getsize(full)
            print(f"[{idx}/{len(files)}] Packaging: {rel} ({size} bytes)")
            
            separator = "=" * 80
            out.write(f"{separator}\n")
            out.write(f"ФАЙЛ [{idx}/{len(files)}]: {rel}\n")
            out.write(f"Шлях: {rel} | Розмір: {size:,} байт\n")
            out.write(f"{separator}\n\n")
            
            try:
                with open(full, "r", encoding="utf-8", errors="replace") as f:
                    content = f.read()
                out.write(content)
                if not content.endswith("\n"):
                    out.write("\n")
            except Exception as e:
                out.write(f"ПОМИЛКА ЧИТАННЯ ФАЙЛУ: {e}\n")
            
            out.write("\n\n")

    print(f"\nBundle successfully created at: {output_file}")
    print(f"Final file size: {os.path.getsize(output_file):,} bytes")

if __name__ == "__main__":
    project_dir = os.path.abspath(os.path.dirname(__file__) + "/..")
    output_path = os.path.join(project_dir, OUTPUT_FILENAME)
    generate_bundle(project_dir, output_path)
