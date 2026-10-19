# -*- coding: utf-8 -*-
"""بناء مستند البحث النهائي."""
import os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from docx_tools import new_document, header_footer, page_break
import content_front, content_social, content_religious, content_moral, content_back

OUT = os.path.join(HERE, "بحث_العادات_الاجتماعية_والدينية_والأخلاقية_عند_العرب_قبل_الإسلام.docx")

doc = new_document()
header_footer(doc,
              "العادات الاجتماعية والدينية والأخلاقية عند العرب قبل الإسلام — بحث موثَّق بالشواهد",
              "بحث العادات الجاهلية")

content_front.cover(doc)
content_front.guide(doc)
content_front.toc_page(doc)
content_front.introduction(doc)
content_front.plan_table(doc)
page_break(doc)
content_social.build(doc)
content_religious.build(doc)
content_moral.build(doc)
content_moral.conclusion(doc)
content_back.editions_note(doc)
content_back.sources(doc)
content_back.appendix_a(doc)
content_back.appendix_b(doc)
content_back.appendix_c(doc)
content_back.closing_word(doc)

doc.save(OUT)
print("SAVED:", OUT)
print("paragraphs:", len(doc.paragraphs), "tables:", len(doc.tables))
