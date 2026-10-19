import sys
import os
from pptx import Presentation
from pptx.util import Inches, Pt
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.enum.shapes import MSO_SHAPE

def create_executive_presentation(output_path):
    prs = Presentation()
    # 16:9 widescreen format
    prs.slide_width = Inches(13.333)
    prs.slide_height = Inches(7.5)
    blank_layout = prs.slide_layouts[6] # blank slide

    # Corporate Color Palette
    C_BG_DARK = RGBColor(15, 23, 42)      # Slate 900
    C_BG_LIGHT = RGBColor(248, 250, 252)  # Slate 50
    C_PRIMARY = RGBColor(2, 132, 199)     # Sky 600
    C_PRIMARY_DARK = RGBColor(3, 105, 161)# Sky 700
    C_SUCCESS = RGBColor(16, 185, 129)    # Emerald 500
    C_SUCCESS_DARK = RGBColor(5, 150, 105)# Emerald 600
    C_WARNING = RGBColor(245, 158, 11)    # Amber 500
    C_ACCENT = RGBColor(234, 88, 12)      # Orange 600
    C_PURPLE = RGBColor(139, 92, 246)     # Violet 500
    C_TEXT_DARK = RGBColor(30, 41, 59)    # Slate 800
    C_TEXT_MUTED = RGBColor(100, 116, 139)# Slate 500
    C_WHITE = RGBColor(255, 255, 255)
    C_CARD_BG = RGBColor(255, 255, 255)
    C_CARD_BORDER = RGBColor(226, 232, 240)

    def add_header(slide, title_text, category_text="ТОВ «АГРОПРОІНВЕСТ 08» • СИТУАЦІЙНИЙ ЦЕНТР"):
        # Top banner background
        top_bar = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(1.1))
        top_bar.fill.solid()
        top_bar.fill.fore_color.rgb = C_BG_DARK
        top_bar.line.color.rgb = C_BG_DARK

        # Category text
        cat_box = slide.shapes.add_textbox(Inches(0.8), Inches(0.12), Inches(11.5), Inches(0.3))
        tf_c = cat_box.text_frame
        tf_c.word_wrap = True
        p_c = tf_c.paragraphs[0]
        p_c.text = category_text.upper()
        p_c.font.size = Pt(10)
        p_c.font.bold = True
        p_c.font.color.rgb = C_PRIMARY

        # Title text
        title_box = slide.shapes.add_textbox(Inches(0.8), Inches(0.4), Inches(11.5), Inches(0.6))
        tf_t = title_box.text_frame
        tf_t.word_wrap = True
        p_t = tf_t.paragraphs[0]
        p_t.text = title_text
        p_t.font.size = Pt(20)
        p_t.font.bold = True
        p_t.font.color.rgb = C_WHITE

        # Footer
        footer = slide.shapes.add_textbox(Inches(0.8), Inches(7.1), Inches(11.733), Inches(0.35))
        tf_f = footer.text_frame
        p_f = tf_f.paragraphs[0]
        p_f.text = "Дашборд МЕЗ: dash-api08.dniprovska.net • Конфіденційно • Для керівництва заводу"
        p_f.font.size = Pt(9)
        p_f.font.color.rgb = C_TEXT_MUTED

    def add_card(slide, left, top, width, height, bg_color=C_CARD_BG, border_color=C_CARD_BORDER):
        card = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(left), Inches(top), Inches(width), Inches(height))
        card.fill.solid()
        card.fill.fore_color.rgb = bg_color
        card.line.color.rgb = border_color
        card.line.width = Pt(1)
        return card

    # =========================================================================
    # SLIDE 1: Title Slide (Dark Theme)
    # =========================================================================
    s1 = prs.slides.add_slide(blank_layout)
    bg1 = s1.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(7.5))
    bg1.fill.solid()
    bg1.fill.fore_color.rgb = C_BG_DARK
    bg1.line.color.rgb = C_BG_DARK

    # Accent decorative bar
    bar1 = s1.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0.8), Inches(1.8), Inches(0.15), Inches(3.8))
    bar1.fill.solid()
    bar1.fill.fore_color.rgb = C_PRIMARY
    bar1.line.color.rgb = C_PRIMARY

    # Title box
    tbox = s1.shapes.add_textbox(Inches(1.2), Inches(1.7), Inches(11), Inches(4.0))
    tf1 = tbox.text_frame
    tf1.word_wrap = True

    p0 = tf1.paragraphs[0]
    p0.text = "ТОВ «АГРОПРОІНВЕСТ 08»"
    p0.font.size = Pt(15)
    p0.font.bold = True
    p0.font.color.rgb = C_PRIMARY
    p0.space_after = Pt(12)

    p1 = tf1.add_paragraph()
    p1.text = "СИТУАЦІЙНИЙ ЦЕНТР ТА ЦИФРОВИЙ ДАШБОРД МЕЗ"
    p1.font.size = Pt(32)
    p1.font.bold = True
    p1.font.color.rgb = C_WHITE
    p1.space_after = Pt(14)

    p2 = tf1.add_paragraph()
    p2.text = "Комплексний онлайн-моніторинг виробництва, матеріального балансу, логістики транспорту та балансу енергоресурсів"
    p2.font.size = Pt(16)
    p2.font.color.rgb = RGBColor(203, 213, 225)
    p2.space_after = Pt(28)

    p3 = tf1.add_paragraph()
    p3.text = "Презентація для Ради Директорів та Вищого Керівництва • Сезон 2026–2027"
    p3.font.size = Pt(13)
    p3.font.color.rgb = C_SUCCESS
    p3.font.bold = True

    # =========================================================================
    # SLIDE 2: Executive Summary (4 Pillars)
    # =========================================================================
    s2 = prs.slides.add_slide(blank_layout)
    add_header(s2, "Ключова бізнес-цінність: 4 контури цифрового управління")

    pillars = [
        ("Блок А: Виробництво та Якість", C_PRIMARY, [
            "• Онлайн матеріальний баланс цеху екстракції",
            "• Вихід готової продукції (Олія, Шрот, Лузга)",
            "• Лабораторія якості насіння (товарна олійність 44,03%)",
            "• Баланс та аудит неврахованих втрат олії"
        ]),
        ("Блок Б: Логістика автотранспорту", C_SUCCESS, [
            "• Інтеграція з CRM apgk_api08 (Тільки читання)",
            "• Воронка машин: Насіння, Олія, Шрот, Лузга",
            "• Контроль нормативу простою авто (< 120 хв)",
            "• Облік складських залишків готової продукції"
        ]),
        ("Блок В: Енергетика та Ресурси", C_WARNING, [
            "• 12-річний повний архів пари (2015–2026 рр.)",
            "• Помісячний архів електроенергії за 2026 рік",
            "• Розподіл кВт, води, стоків та газу по цехах",
            "• Інтерактивні селектори вибору місяця"
        ]),
        ("Блок Г: Бенчмаркінг 2026 vs 2025", C_PURPLE, [
            "• Зіставлення фактів: Доба, Тиждень, Місяць, Сезон",
            "• Випередження бази 2025 року за виходом олії",
            "• Контроль виконання контрактів та довіз сировини",
            "• Оперативні сповіщення при ризиках втрат"
        ])
    ]

    for i, (title, color, items) in enumerate(pillars):
        left = 0.8 + i * 2.98
        add_card(s2, left, 1.4, 2.85, 5.4)
        
        # Header banner inside card
        card_hdr = s2.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(left), Inches(1.4), Inches(2.85), Inches(0.8))
        card_hdr.fill.solid()
        card_hdr.fill.fore_color.rgb = color
        card_hdr.line.color.rgb = color
        
        tb = s2.shapes.add_textbox(Inches(left + 0.1), Inches(1.45), Inches(2.65), Inches(0.7))
        p = tb.text_frame.paragraphs[0]
        p.text = title
        p.font.size = Pt(13)
        p.font.bold = True
        p.font.color.rgb = C_WHITE
        p.alignment = PP_ALIGN.CENTER
        
        # Content box
        cb = s2.shapes.add_textbox(Inches(left + 0.15), Inches(2.35), Inches(2.55), Inches(4.3))
        tf = cb.text_frame
        tf.word_wrap = True
        for j, item in enumerate(items):
            p_item = tf.paragraphs[0] if j == 0 else tf.add_paragraph()
            p_item.text = item
            p_item.font.size = Pt(11.5)
            p_item.font.color.rgb = C_TEXT_DARK
            p_item.space_after = Pt(12)

    # =========================================================================
    # SLIDE 3: Material Balance & Production
    # =========================================================================
    s3 = prs.slides.add_slide(blank_layout)
    add_header(s3, "Блок А: Матеріальний баланс та виробнича ефективність")

    # Left: Key KPIs
    add_card(s3, 0.8, 1.4, 5.7, 5.4)
    tb_kpi = s3.shapes.add_textbox(Inches(1.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_kpi = tb_kpi.text_frame
    tf_kpi.word_wrap = True

    p = tf_kpi.paragraphs[0]
    p.text = "ФАКТИЧНІ ПОКАЗНИКИ РОБОТИ (15.10.2026)"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY
    p.space_after = Pt(10)

    rows_kpi = [
        ("Перероблено соняшнику (очищене)", "308,98 т", "Добовий план: 600,0 т"),
        ("Вихід Олії нерафінованої", "149,28 т (48,31%)", "Еталон 2025: 44,45% (+3,86%)"),
        ("Вихід Шроту соняшникового", "156,13 т (50,53%)", "Еталон 2025: 41,76% (+8,77%)"),
        ("Вихід Лузги сухої", "45,95 т (14,87%)", "Паливо на власну котельню"),
        ("Сума виходу (Олія + Шрот)", "98,84%", "Висока ефективність екстракції"),
        ("Загальний баланс продукції", "113,72%", "+13,72% перевищення балансу")
    ]
    for name, val, sub in rows_kpi:
        p1 = tf_kpi.add_paragraph()
        p1.text = f"• {name}: "
        p1.font.bold = False
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        run_v = p1.add_run()
        run_v.text = val
        run_v.font.bold = True
        run_v.font.color.rgb = C_PRIMARY_DARK
        
        p2 = tf_kpi.add_paragraph()
        p2.text = f"   ↳ {sub}"
        p2.font.size = Pt(9.5)
        p2.font.color.rgb = C_TEXT_MUTED
        p2.space_after = Pt(6)

    # Right: Logic & Rules
    add_card(s3, 6.8, 1.4, 5.7, 5.4)
    tb_rules = s3.shapes.add_textbox(Inches(7.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_rules = tb_rules.text_frame
    tf_rules.word_wrap = True

    p = tf_rules.paragraphs[0]
    p.text = "КЛЮЧОВІ АЛГОРИТМИ ТА ВРАХУВАННЯ ПРОСТОЇВ"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_SUCCESS_DARK
    p.space_after = Pt(12)

    rules_text = [
        ("1. Чітке відокремлення робочих діб та простою:", "Коли за добу МЕЗ переробка становить 0 т (наприклад, 16–18.10), система виводить спеціальне сповіщення «МЕЗ: переробка на паузі», не викривляє відсотки і запобігає діленню на нуль."),
        ("2. Розмежування втрат:", "Втрати підробки елеватора (1,45% / 53,6 т) чітко відокремлені від виробничих втрат екстракції, що забезпечує прозору відповідальність між підрозділами."),
        ("3. Баланс позитивного перевищення (+):", "При переробці високоолійного соняшнику загальний вихід продукції перевищує 100%, що фіксується зеленим бейджем «Перевищення виходу продукції»."),
        ("4. Розрахунок питомих показників:", "Всі питомі енерговитрати прив'язані до фактичного очищеного насіння в переробці, що дає реальну картину собівартості тони.")
    ]
    for r_title, r_desc in rules_text:
        p1 = tf_rules.add_paragraph()
        p1.text = r_title
        p1.font.bold = True
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        p2 = tf_rules.add_paragraph()
        p2.text = r_desc
        p2.font.size = Pt(10)
        p2.font.color.rgb = C_TEXT_MUTED
        p2.space_after = Pt(8)

    # =========================================================================
    # SLIDE 4: Laboratory Quality & Oil Losses
    # =========================================================================
    s4 = prs.slides.add_slide(blank_layout)
    add_header(s4, "Блок А: Лабораторія якості та аудит втрат олії")

    cards_lab = [
        ("🧪 Товарна олійність сировини", "44,03%", "на а.с.р. 47,00% – 47,99%", C_PRIMARY, 0.8, 1.4),
        ("💧 Вологість насіння в цех", "6,20% — 6,30%", "Базовий норматив < 8,0%", C_SUCCESS, 4.8, 1.4),
        ("🍂 Смітна домішка сировини", "1,71% — 2,18%", "Базовий норматив < 2,0%", C_WARNING, 8.8, 1.4)
    ]
    for title, val, sub, col, l, t in cards_lab:
        add_card(s4, l, t, 3.7, 1.8)
        tb = s4.shapes.add_textbox(Inches(l + 0.15), Inches(t + 0.15), Inches(3.4), Inches(1.5))
        tf = tb.text_frame
        p = tf.paragraphs[0]
        p.text = title
        p.font.size = Pt(11)
        p.font.bold = True
        p.font.color.rgb = C_TEXT_DARK
        
        p_v = tf.add_paragraph()
        p_v.text = val
        p_v.font.size = Pt(24)
        p_v.font.bold = True
        p_v.font.color.rgb = col
        
        p_s = tf.add_paragraph()
        p_s.text = sub
        p_s.font.size = Pt(9.5)
        p_s.font.color.rgb = C_TEXT_MUTED

    # Bottom detailed box: Oil Loss Audit
    add_card(s4, 0.8, 3.4, 11.7, 3.4)
    tb_loss = s4.shapes.add_textbox(Inches(1.0), Inches(3.55), Inches(11.3), Inches(3.1))
    tf_l = tb_loss.text_frame
    tf_l.word_wrap = True

    p = tf_l.paragraphs[0]
    p.text = "БАЛАНС ВТРАТ ОЛІЇ ЗА ЗВІТОМ МЕЗ («СПРАВКА ПО ПЕРЕРАБОТКЕ»)"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY_DARK
    p.space_after = Pt(10)

    loss_items = [
        ("• Втрати олії в шроті (олійність шроту 0,77%):", "1 202,3 кг (за добу 15.10) • 16 260,9 кг (за місяць). Показник значно нижче технологічної межі (норма до 1,0%), що доводить ефективну роботу екстрактора."),
        ("• Втрати олії в луззі (олійність лузги 3,27%):", "1 502,1 кг (за добу 15.10) • 24 997,0 кг (за місяць). Контролюється в процесі рушкувально-війочного відділення."),
        ("• Враховані втрати олії:", "2 704,4 кг (0,87% від маси насіння). Фактичні технологічні втрати в межах норми регламенту."),
        ("• Невраховані втрати олії:", "-15 936,5 кг (-5,16% — негативний баланс втрат). Свідчить про те, що реальний вихід товарної олії перевищив попередній розрахунковий прогноз лабораторії.")
    ]
    for l_title, l_desc in loss_items:
        p1 = tf_l.add_paragraph()
        p1.text = l_title + " "
        p1.font.bold = True
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        run = p1.add_run()
        run.text = l_desc
        run.font.bold = False
        run.font.size = Pt(10.5)
        run.font.color.rgb = C_TEXT_MUTED
        p1.space_after = Pt(6)

    # =========================================================================
    # SLIDE 5: Logistics & Warehouses
    # =========================================================================
    s5 = prs.slides.add_slide(blank_layout)
    add_header(s5, "Блок Б: Логістика автотранспорту та складські залишки (СВОД)")

    # Left: Transport Funnel
    add_card(s5, 0.8, 1.4, 5.7, 5.4)
    tb_tr = s5.shapes.add_textbox(Inches(1.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_tr = tb_tr.text_frame
    tf_tr.word_wrap = True

    p = tf_tr.paragraphs[0]
    p.text = "ЕЛЕКТРОННА ЧЕРГА CRM (ТІЛЬКИ ЧИТАННЯ: apgk_api08)"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY
    p.space_after = Pt(10)

    tr_items = [
        ("🌻 Насіння соняшнику:", "Середній час перебування: ~115 хв • В межах норми"),
        ("💧 Олія нерафінована (автоцистерни):", "Середній час: ~95 хв • Швидке відвантаження"),
        ("🌾 Шрот гранульований / розсипний:", "Середній час: ~130 хв • Під контролем диспетчера"),
        ("🪵 Лузга соняшникова:", "Середній час: ~80 хв • Прямий вивіз покупцям"),
        ("⏱️ Контрольний ліміт перебування:", "120 хвилин. При перевищенні ліміту індикатор автоматично сигналізує жовтим або червоним кольором."),
        ("🛡️ Безпека інтеграції:", "Режим Read-Only гарантує повну ізоляцію виробничої бази 1С/CRM від випадкових змін.")
    ]
    for t_t, t_d in tr_items:
        p1 = tf_tr.add_paragraph()
        p1.text = t_t + " "
        p1.font.bold = True
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        run = p1.add_run()
        run.text = t_d
        run.font.bold = False
        run.font.size = Pt(10)
        run.font.color.rgb = C_TEXT_MUTED
        p1.space_after = Pt(8)

    # Right: Warehouse Stocks
    add_card(s5, 6.8, 1.4, 5.7, 5.4)
    tb_wh = s5.shapes.add_textbox(Inches(7.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_wh = tb_wh.text_frame
    tf_wh.word_wrap = True

    p = tf_wh.paragraphs[0]
    p.text = "СКЛАДСЬКІ ЗАЛИШКИ ТА ЕНЕРГЕТИЧНЕ ПАЛИВО (СВОД)"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_SUCCESS_DARK
    p.space_after = Pt(10)

    wh_items = [
        ("💧 Олія в резервуарах заводу:", "3 650,10 т", "Місткість 5 000 т • Заповнення: 73%"),
        ("🌾 Шрот на складі підлогового збер.:", "674,86 т", "Місткість 1 500 т • Заповнення: 45%"),
        ("🪵 Лузга на складі:", "166,77 т", "Місткість 500 т • Постійна ротація"),
        ("🔥 Лузга на котельню (власне паливо):", "Енергонезалежність", "Забезпечує 100% потреби в технологічній парі")
    ]
    for name, val, sub in wh_items:
        p1 = tf_wh.add_paragraph()
        p1.text = f"• {name} "
        p1.font.bold = False
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        run_v = p1.add_run()
        run_v.text = val
        run_v.font.bold = True
        run_v.font.color.rgb = C_PRIMARY_DARK
        
        p2 = tf_wh.add_paragraph()
        p2.text = f"   ↳ {sub}"
        p2.font.size = Pt(9.5)
        p2.font.color.rgb = C_TEXT_MUTED
        p2.space_after = Pt(8)

    # =========================================================================
    # SLIDE 6: 12-Year Steam Archive
    # =========================================================================
    s6 = prs.slides.add_slide(blank_layout)
    add_header(s6, "Блок В: Повний помісячний та добовий архів пари за 12 років (2015–2026 рр.)")

    # Top highlight box
    add_card(s6, 0.8, 1.4, 11.7, 1.5)
    tb_st_top = s6.shapes.add_textbox(Inches(1.0), Inches(1.5), Inches(11.3), Inches(1.3))
    tf_st = tb_st_top.text_frame
    tf_st.word_wrap = True

    p = tf_st.paragraphs[0]
    p.text = "УНІКАЛЬНИЙ АРХІВ ТЕХНОЛОГІЧНОЇ ПАРИ («УЧЕТ ПАРА НА ООО “АГРОПРОИНВЕСТ 08”»)"
    p.font.size = Pt(12)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY
    p.space_after = Pt(4)

    p_desc = tf_st.add_paragraph()
    p_desc.text = "В системі оцифровано повну історію роботи парової котельні за 12 років (з 2015 по 2026 рік). За будь-який вибраний місяць керівник може миттєво побачити подобову таблицю виробництва пари, витрати на власні потреби (СН) та розподіл по технологічних споживачах без затримок."
    p_desc.font.size = Pt(10.5)
    p_desc.font.color.rgb = C_TEXT_DARK

    # 4 Distribution pillars
    steam_cols = [
        ("Екстракція (МЕЗ)", "58,4%", "1 076,80 т / міс", "Основний споживач пари на підігрів місели та відгонку розчинника"),
        ("Підготовчий цех", "41,1%", "758,39 т / міс", "Кондиціонування та жаровні перед пресуванням м'ятки"),
        ("Власні потреби котельні (СН)", "6,9%", "136,46 т / міс", "Підігрів живильної води, деаерація та мазутне/газове господарство"),
        ("Питома норма на 1 т", "208,9 кг/т", "Еталонний норматив", "Контроль енергетичної ефективності на кожну перероблену тону")
    ]
    for i, (name, val, sub, desc) in enumerate(steam_cols):
        left = 0.8 + i * 2.98
        add_card(s6, left, 3.1, 2.85, 3.7)
        
        tb = s6.shapes.add_textbox(Inches(left + 0.15), Inches(3.25), Inches(2.55), Inches(3.4))
        tf = tb.text_frame
        tf.word_wrap = True
        
        p = tf.paragraphs[0]
        p.text = name
        p.font.size = Pt(12)
        p.font.bold = True
        p.font.color.rgb = C_TEXT_DARK
        p.space_after = Pt(6)
        
        p_v = tf.add_paragraph()
        p_v.text = val
        p_v.font.size = Pt(22)
        p_v.font.bold = True
        p_v.font.color.rgb = C_WARNING
        p_v.space_after = Pt(4)
        
        p_s = tf.add_paragraph()
        p_s.text = sub
        p_s.font.size = Pt(11)
        p_s.font.bold = True
        p_s.font.color.rgb = C_PRIMARY_DARK
        p_s.space_after = Pt(8)
        
        p_d = tf.add_paragraph()
        p_d.text = desc
        p_d.font.size = Pt(9.5)
        p_d.font.color.rgb = C_TEXT_MUTED

    # =========================================================================
    # SLIDE 7: Electricity & Departments Archive 2026
    # =========================================================================
    s7 = prs.slides.add_slide(blank_layout)
    add_header(s7, "Блок В: Помісячний баланс електроенергії, води та газу за 2026 рік")

    # Left: Departmental breakdown
    add_card(s7, 0.8, 1.4, 5.7, 5.4)
    tb_el = s7.shapes.add_textbox(Inches(1.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_el = tb_el.text_frame
    tf_el.word_wrap = True

    p = tf_el.paragraphs[0]
    p.text = "РОЗПОДІЛ ЗА ПІДРОЗДІЛАМИ ЗАВОДУ (АРХІВ 2026)"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY
    p.space_after = Pt(8)

    el_items = [
        ("• Повний помісячний архів (Січень — Жовтень 2026):", "Оцифровано всі місяці роботи заводу з переробкою від 4,5 тис. до 14,6 тис. т/міс."),
        ("• Інтерактивні випадаючі списки:", "Розміщені безпосередньо у шапці Блоку В та в заголовку таблиці підрозділів. Перемикання в 1 клік без перезавантаження."),
        ("• Питома норма споживання ел.енергії:", "54,97 кВт/т у звітний період (Пресовий цех: ~60,9 кВт/т, Дільниця екстракції: ~23,9 кВт/т)."),
        ("• Облік природного газу:", "Додано окрему колонку для котельні природного газу (робота в січні, лютому, березні, червні, липні та вересні)."),
        ("• Підсумковий рядок РАЗОМ:", "Автоматично підсумовує активну, реактивну ел.енергію, воду, стоки та розраховує середньозважену питому норму.")
    ]
    for e_t, e_d in el_items:
        p1 = tf_el.add_paragraph()
        p1.text = e_t + " "
        p1.font.bold = True
        p1.font.size = Pt(10.5)
        p1.font.color.rgb = C_TEXT_DARK
        
        run = p1.add_run()
        run.text = e_d
        run.font.bold = False
        run.font.size = Pt(10)
        run.font.color.rgb = C_TEXT_MUTED
        p1.space_after = Pt(6)

    # Right: Water & Gas
    add_card(s7, 6.8, 1.4, 5.7, 5.4)
    tb_wg = s7.shapes.add_textbox(Inches(7.0), Inches(1.55), Inches(5.3), Inches(5.0))
    tf_wg = tb_wg.text_frame
    tf_wg.word_wrap = True

    p = tf_wg.paragraphs[0]
    p.text = "ВОДОПОСТАЧАННЯ, СТОКИ ТА ПРИРОДНИЙ ГАЗ"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_SUCCESS_DARK
    p.space_after = Pt(8)

    wg_items = [
        ("💧 Споживання води за лічильником:", "1 645,0 м³/міс", "~54,8 м³/добу • Водозабір та станція 2-го підйому"),
        ("🌊 Скидання виробничих стоків:", "1 150,0 м³/міс", "Очисні споруди та каналізація МЕЗ"),
        ("💨 Витрата води на котельню:", "1 023,0 м³", "Пермеат та підживлення парових котлів"),
        ("🔥 Витрата води на екстракцію:", "544,0 м³", "Охолодження дефлегматорів та конденсаторів"),
        ("⚡ Реактивна потужність (cos φ):", "Контроль компенсації", "Моніторинг реактивної енергії запобігає штрафам обленерго")
    ]
    for name, val, sub in wg_items:
        p1 = tf_wg.add_paragraph()
        p1.text = f"• {name} "
        p1.font.bold = False
        p1.font.size = Pt(11)
        p1.font.color.rgb = C_TEXT_DARK
        
        run_v = p1.add_run()
        run_v.text = val
        run_v.font.bold = True
        run_v.font.color.rgb = C_SUCCESS_DARK
        
        p2 = tf_wg.add_paragraph()
        p2.text = f"   ↳ {sub}"
        p2.font.size = Pt(9.5)
        p2.font.color.rgb = C_TEXT_MUTED
        p2.space_after = Pt(6)

    # =========================================================================
    # SLIDE 8: Season Comparison (2026 vs 2025)
    # =========================================================================
    s8 = prs.slides.add_slide(blank_layout)
    add_header(s8, "Блок Г: Стратегічний бенчмаркінг сезонів (2026–2027 vs 2025–2026)")

    # 4 Comparative Cards
    comp_kpis = [
        ("⚙️ Переробка соняшнику", "309,0 т", "Аналог 2025: 538,1 т", "Вихід на плановий темп після запуску"),
        ("💧 Вихід олії (ефективність)", "48,31%", "Еталон 2025: 44,45%", "+3,86% вище за сезон 2025 року!"),
        ("🌾 Вихід шроту (високий протеїн)", "50,53%", "Еталон 2025: 41,76%", "+8,77% перевищення норми виходу"),
        ("⚖️ Сума цінних фракцій (Олія+Шрот)", "98,84%", "Еталон 2025: 86,21%", "+12,63% максимальне використання сировини")
    ]
    for i, (name, val, sub, desc) in enumerate(comp_kpis):
        left = 0.8 + i * 2.98
        add_card(s8, left, 1.4, 2.85, 2.5)
        
        tb = s8.shapes.add_textbox(Inches(left + 0.15), Inches(1.5), Inches(2.55), Inches(2.2))
        tf = tb.text_frame
        tf.word_wrap = True
        
        p = tf.paragraphs[0]
        p.text = name
        p.font.size = Pt(11)
        p.font.bold = True
        p.font.color.rgb = C_TEXT_DARK
        
        p_v = tf.add_paragraph()
        p_v.text = val
        p_v.font.size = Pt(22)
        p_v.font.bold = True
        p_v.font.color.rgb = C_PRIMARY_DARK
        
        p_s = tf.add_paragraph()
        p_s.text = sub
        p_s.font.size = Pt(10)
        p_s.font.color.rgb = C_SUCCESS_DARK
        p_s.font.bold = True
        
        p_d = tf.add_paragraph()
        p_d.text = desc
        p_d.font.size = Pt(9)
        p_d.font.color.rgb = C_TEXT_MUTED

    # Bottom summary
    add_card(s8, 0.8, 4.1, 11.7, 2.7)
    tb_b = s8.shapes.add_textbox(Inches(1.0), Inches(4.25), Inches(11.3), Inches(2.4))
    tf_b = tb_b.text_frame
    tf_b.word_wrap = True

    p = tf_b.paragraphs[0]
    p.text = "ВИСНОВКИ СТРАТЕГІЧНОГО АНАЛІЗУ СЕЗОНУ"
    p.font.size = Pt(13)
    p.font.bold = True
    p.font.color.rgb = C_PRIMARY_DARK
    p.space_after = Pt(8)

    points = [
        ("• Якість переробки вища за минулорічну:", "Завдяки стабільній роботі рушкувально-війочного та екстракційного цехів вихід олії на 3,86% перевищує еталон сезону 2025–2026."),
        ("• Сировинна безпека заводу:", "Залишок насіння на елеваторі забезпечує понад 21 добу безперервної роботи навіть за повної відсутності нового завезення."),
        ("• Виконання договорів поставки:", "Активно виконується 11 специфікацій на довіз насіння з середнім відсотком виконання понад 85%.")
    ]
    for p_t, p_d in points:
        p1 = tf_b.add_paragraph()
        p1.text = p_t + " "
        p1.font.bold = True
        p1.font.size = Pt(10.5)
        p1.font.color.rgb = C_TEXT_DARK
        
        run = p1.add_run()
        run.text = p_d
        run.font.bold = False
        run.font.size = Pt(10)
        run.font.color.rgb = C_TEXT_MUTED
        p1.space_after = Pt(5)

    # =========================================================================
    # SLIDE 9: Architecture & Security
    # =========================================================================
    s9 = prs.slides.add_slide(blank_layout)
    add_header(s9, "Технічна надійність, безпека та архітектура рішення")

    tech_pillars = [
        ("Швидкодія та стек", C_PRIMARY, [
            "• PHP 8.2 + MySQL / JSON State",
            "• Чистий Vanilla JS (без важких фреймворків)",
            "• Час відгуку дашборда < 200 мс",
            "• Автономна робота без сторонніх хмар"
        ]),
        ("Інформаційна безпека", C_SUCCESS, [
            "• Рольовий доступ користувачів (RBAC)",
            "• Виробничі бази 1С/CRM строго Read-Only",
            "• Повна ізоляція паролів та секретів конфігурації",
            "• Захист сесій та журнал дій користувачів"
        ]),
        ("Автоматизація та експорт", C_WARNING, [
            "• Автооновлення даних за таймером кожні 30 с",
            "• Експорт у формат Excel, PDF, CSV",
            "• Повна збірка інсталяційного пакету ZIP",
            "• Готовність до розгортання в HestiaCP"
        ])
    ]
    for i, (title, color, items) in enumerate(tech_pillars):
        left = 0.8 + i * 4.0
        add_card(s9, left, 1.4, 3.7, 5.4)
        
        card_hdr = s9.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(left), Inches(1.4), Inches(3.7), Inches(0.8))
        card_hdr.fill.solid()
        card_hdr.fill.fore_color.rgb = color
        card_hdr.line.color.rgb = color
        
        tb = s9.shapes.add_textbox(Inches(left + 0.1), Inches(1.45), Inches(3.5), Inches(0.7))
        p = tb.text_frame.paragraphs[0]
        p.text = title
        p.font.size = Pt(13)
        p.font.bold = True
        p.font.color.rgb = C_WHITE
        p.alignment = PP_ALIGN.CENTER
        
        cb = s9.shapes.add_textbox(Inches(left + 0.2), Inches(2.4), Inches(3.3), Inches(4.2))
        tf = cb.text_frame
        tf.word_wrap = True
        for j, item in enumerate(items):
            p_item = tf.paragraphs[0] if j == 0 else tf.add_paragraph()
            p_item.text = item
            p_item.font.size = Pt(11)
            p_item.font.color.rgb = C_TEXT_DARK
            p_item.space_after = Pt(12)

    # =========================================================================
    # SLIDE 10: Conclusion & Next Steps (Dark Theme)
    # =========================================================================
    s10 = prs.slides.add_slide(blank_layout)
    bg10 = s10.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(7.5))
    bg10.fill.solid()
    bg10.fill.fore_color.rgb = C_BG_DARK
    bg10.line.color.rgb = C_BG_DARK

    tbox10 = s10.shapes.add_textbox(Inches(0.8), Inches(0.8), Inches(11.7), Inches(5.8))
    tf10 = tbox10.text_frame
    tf10.word_wrap = True

    p = tf10.paragraphs[0]
    p.text = "РЕЗЮМЕ ТА НАСТУПНІ КРОКИ РОЗВИТКУ СИСТЕМИ"
    p.font.size = Pt(24)
    p.font.bold = True
    p.font.color.rgb = C_WHITE
    p.space_after = Pt(20)

    conclusions = [
        ("1. Прозорість та керованість:", "Керівництво отримує об'єктивну картину заводу в один клік — від тоннажу насіння до кубометрів газу та кілограмів пари."),
        ("2. Економія робочого часу:", "Звільнення інженерів, технологів та диспетчерів від ручного зведення Excel-таблиць (економія до 3 годин робочого часу на добу)."),
        ("3. Миттєве виявлення втрат:", "Автоматичний контроль олійності шроту/лузги та неврахованих втрат олії захищає завод від технологічних збитків."),
        ("4. Плани масштабування (Фаза 2):", "Пряме підключення датчиків SCADA/Zabbix до насосів та пресів, автоматичне прогнозування виходу продукції та аналітика витрат на базі ШІ.")
    ]
    for c_t, c_d in conclusions:
        p1 = tf10.add_paragraph()
        p1.text = c_t + " "
        p1.font.bold = True
        p1.font.size = Pt(13)
        p1.font.color.rgb = C_PRIMARY
        
        run = p1.add_run()
        run.text = c_d
        run.font.bold = False
        run.font.size = Pt(12)
        run.font.color.rgb = RGBColor(226, 232, 240)
        p1.space_after = Pt(14)

    prs.save(output_path)
    print(f"Presentation saved successfully to: {output_path}")

if __name__ == "__main__":
    out_file = os.path.join(os.path.dirname(__file__), "..", "Презентація_Дашборд_МЕЗ_Керівництву.pptx")
    out_file = os.path.abspath(out_file)
    create_executive_presentation(out_file)
