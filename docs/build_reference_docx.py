# -*- coding: utf-8 -*-
"""Build docs/reference.docx — the shared publication template for the SccIT
documentation suite (SRS / SDD / SPMP).  Presentation only: no technical content.

Run:  python docs/build_reference_docx.py
"""
import subprocess, pypandoc
from docx import Document
from docx.shared import Pt, Inches, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

REF = "reference.docx"

# ---- palette (accessible contrast on white; never the sole carrier of meaning)
NAVY   = "1F3864"   # H1 / title
BLUE   = "2E5496"   # H2 / H3
SLATE  = "44546A"   # H4 / captions
HDRBG  = "1F3864"   # table header fill
BANDBG = "F2F5FA"   # banded row fill
RULE   = "BFBFBF"   # table borders

BODY_FONT = "Cambria"      # serif body — long-form readability
HEAD_FONT = "Calibri Light"
MONO_FONT = "Consolas"

def el(tag, **attrs):
    e = OxmlElement(tag)
    for k, v in attrs.items():
        e.set(qn(k), v)
    return e

def set_style(doc, name, *, font=None, size=None, bold=None, italic=None, color=None,
              space_before=None, space_after=None, line=None, keep_next=None,
              page_break_before=None, align=None, outline=None):
    try:
        st = doc.styles[name]
    except KeyError:
        return None
    f = st.font
    if font: f.name = font
    if size: f.size = Pt(size)
    if bold is not None: f.bold = bold
    if italic is not None: f.italic = italic
    if color: f.color.rgb = RGBColor.from_string(color)
    pf = getattr(st, "paragraph_format", None)
    if pf is None:                     # character style (e.g. Verbatim Char)
        if font:
            rPr = st.element.get_or_add_rPr()
            rf = rPr.find(qn('w:rFonts'))
            if rf is None:
                rf = el('w:rFonts'); rPr.insert(0, rf)
            for a in ('w:ascii', 'w:hAnsi', 'w:cs'):
                rf.set(qn(a), font)
        return st
    if space_before is not None: pf.space_before = Pt(space_before)
    if space_after is not None: pf.space_after = Pt(space_after)
    if line is not None:
        pf.line_spacing = line
        pf.line_spacing_rule = WD_LINE_SPACING.MULTIPLE
    if keep_next is not None: pf.keep_with_next = keep_next
    if align is not None: pf.alignment = align
    pPr = st.element.get_or_add_pPr()
    # widow/orphan control everywhere
    pPr.append(el('w:widowControl', **{'w:val': '1'}))
    if page_break_before:
        pPr.append(el('w:pageBreakBefore', **{'w:val': '1'}))
    if keep_next:
        pPr.append(el('w:keepLines', **{'w:val': '1'}))
    # east-asian/complex font binding so Word doesn't substitute
    if font:
        rPr = st.element.get_or_add_rPr()
        rf = rPr.find(qn('w:rFonts'))
        if rf is None:
            rf = el('w:rFonts'); rPr.insert(0, rf)
        for a in ('w:ascii', 'w:hAnsi', 'w:cs', 'w:eastAsia'):
            rf.set(qn(a), font)
    return st

def add_field(par, instr):
    """Insert a Word field (e.g. PAGE, NUMPAGES) into a paragraph."""
    r = par.add_run()
    r._r.append(el('w:fldChar', **{'w:fldCharType': 'begin'}))
    t = el('w:instrText', **{'xml:space': 'preserve'}); t.text = instr
    r._r.append(t)
    r._r.append(el('w:fldChar', **{'w:fldCharType': 'separate'}))
    r._r.append(el('w:fldChar', **{'w:fldCharType': 'end'}))
    return r

def style_table_style(doc):
    """Give pandoc's 'Table' style a professional look: header band, row banding,
    consistent hairline borders and comfortable cell padding."""
    styles_el = doc.styles.element
    tbl = None
    for s in styles_el.findall(qn('w:style')):
        if s.get(qn('w:styleId')) == 'Table':
            tbl = s; break
    if tbl is None:
        return False
    for child in list(tbl):
        if child.tag in (qn('w:tblPr'), qn('w:tblStylePr'), qn('w:rPr')):
            tbl.remove(child)
    # base run font for tables (sans, slightly smaller for density)
    rPr = el('w:rPr')
    rf = el('w:rFonts'); [rf.set(qn(a), 'Calibri') for a in ('w:ascii','w:hAnsi','w:cs')]
    rPr.append(rf); rPr.append(el('w:sz', **{'w:val': '19'}))  # 9.5pt
    tbl.append(rPr)

    tblPr = el('w:tblPr')
    borders = el('w:tblBorders')
    for side in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        borders.append(el('w:%s' % side, **{'w:val': 'single', 'w:sz': '4',
                                            'w:space': '0', 'w:color': RULE}))
    tblPr.append(borders)
    mar = el('w:tblCellMar')
    for side, w in (('top', '72'), ('bottom', '72'), ('left', '108'), ('right', '108')):
        mar.append(el('w:%s' % side, **{'w:w': w, 'w:type': 'dxa'}))
    tblPr.append(mar)
    tbl.append(tblPr)

    # header row: navy fill, white bold, repeat on every page
    hdr = el('w:tblStylePr', **{'w:type': 'firstRow'})
    hp = el('w:pPr'); hp.append(el('w:keepNext', **{'w:val': '1'})); hdr.append(hp)
    hr = el('w:rPr'); hr.append(el('w:b'));
    col = el('w:color', **{'w:val': 'FFFFFF'}); hr.append(col); hdr.append(hr)
    htc = el('w:tcPr'); htc.append(el('w:shd', **{'w:val': 'clear', 'w:color': 'auto', 'w:fill': HDRBG}))
    hdr.append(htc); tbl.append(hdr)

    # banded rows (light tint — decorative only, never sole meaning)
    band = el('w:tblStylePr', **{'w:type': 'band1Horz'})
    btc = el('w:tcPr'); btc.append(el('w:shd', **{'w:val': 'clear', 'w:color': 'auto', 'w:fill': BANDBG}))
    band.append(btc); tbl.append(band)
    return True

def main():
    # start from pandoc's own reference doc so every style pandoc needs exists
    pandoc = pypandoc.get_pandoc_path()
    with open(REF, "wb") as f:
        f.write(subprocess.run([pandoc, "--print-default-data-file", "reference.docx"],
                               capture_output=True).stdout)
    doc = Document(REF)

    # ---------------- page setup (text area 6.50 x 9.00 in) ----------------
    for s in doc.sections:
        s.page_width, s.page_height = Inches(8.5), Inches(11)
        s.left_margin = s.right_margin = s.top_margin = s.bottom_margin = Inches(1)
        s.header_distance = s.footer_distance = Inches(0.5)
        s.different_first_page_header_footer = True

    # ---------------- body & headings ----------------
    set_style(doc, 'Normal', font=BODY_FONT, size=11, color='202124',
              space_after=8, line=1.15, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    set_style(doc, 'Title', font=HEAD_FONT, size=30, bold=True, color=NAVY,
              space_after=6, align=WD_ALIGN_PARAGRAPH.CENTER)
    set_style(doc, 'Subtitle', font=HEAD_FONT, size=14, italic=True, color=SLATE,
              space_after=18, align=WD_ALIGN_PARAGRAPH.CENTER)
    set_style(doc, 'Heading 1', font=HEAD_FONT, size=20, bold=True, color=NAVY,
              space_before=0, space_after=10, keep_next=True, page_break_before=True)
    set_style(doc, 'Heading 2', font=HEAD_FONT, size=15, bold=True, color=BLUE,
              space_before=16, space_after=6, keep_next=True)
    set_style(doc, 'Heading 3', font=HEAD_FONT, size=12.5, bold=True, color=BLUE,
              space_before=12, space_after=4, keep_next=True)
    set_style(doc, 'Heading 4', font=HEAD_FONT, size=11, bold=True, italic=True,
              color=SLATE, space_before=10, space_after=4, keep_next=True)
    for n in ('Heading 5', 'Heading 6'):
        set_style(doc, n, font=HEAD_FONT, size=10.5, bold=True, color=SLATE,
                  space_before=8, space_after=3, keep_next=True)

    # captions: centred, small, slate — used for figures and tables
    for n in ('Caption', 'Image Caption', 'Table Caption'):
        set_style(doc, n, font='Calibri', size=9.5, italic=True, bold=False, color=SLATE,
                  space_before=4, space_after=12, align=WD_ALIGN_PARAGRAPH.CENTER, line=1.0)
    # table captions sit above the table -> keep with it
    set_style(doc, 'Table Caption', keep_next=True, space_before=10, space_after=3)

    # quotes / notes
    set_style(doc, 'Block Text', font=BODY_FONT, size=10.5, color=SLATE,
              space_before=6, space_after=10, line=1.15)
    # code
    for n in ('Source Code', 'Verbatim Char', 'HTML Preformatted'):
        set_style(doc, n, font=MONO_FONT, size=8.5, color='24292F')
    set_style(doc, 'TOC Heading', font=HEAD_FONT, size=20, bold=True, color=NAVY,
              page_break_before=True, keep_next=True, space_after=10)

    style_table_style(doc)

    # ---------------- footer: Page X of Y ----------------
    for s in doc.sections:
        for foot in (s.footer, s.first_page_footer):
            foot.is_linked_to_previous = False
            p = foot.paragraphs[0] if foot.paragraphs else foot.add_paragraph()
            p.text = ""
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            r = p.add_run("Page "); r.font.size = Pt(9); r.font.name = 'Calibri'; r.font.color.rgb = RGBColor.from_string(SLATE)
            add_field(p, " PAGE ")
            r = p.add_run(" of "); r.font.size = Pt(9); r.font.name = 'Calibri'; r.font.color.rgb = RGBColor.from_string(SLATE)
            add_field(p, " NUMPAGES ")
        # header (title is injected per-document during post-processing)
        for head in (s.header,):
            head.is_linked_to_previous = False
            p = head.paragraphs[0] if head.paragraphs else head.add_paragraph()
            p.text = ""
            p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        s.first_page_header.is_linked_to_previous = False
        s.first_page_header.paragraphs[0].text = ""

    doc.save(REF)
    print("built", REF)

if __name__ == "__main__":
    main()
