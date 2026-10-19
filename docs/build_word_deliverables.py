# -*- coding: utf-8 -*-
"""Produce the publication-quality Word deliverables for the SccIT suite.

Presentation layer only — the Markdown sources are never modified.
  1. pandoc renders each .md with the shared reference.docx template
  2. post-processing applies figure / table / navigation production rules
  3. structural verification is printed

Run:  python docs/build_word_deliverables.py
"""
import os, re, zipfile, shutil
import pypandoc
from docx import Document
from docx.shared import Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

OUT = "../deliverables/word"
PUB = "_publish"   # publication copies: developer artifacts stripped, diagrams rendered
REF = "reference.docx"
SLATE = "44546A"
DOCS = [
    ("Software Requirements Specification", "SRS", "Software Requirements Specification (SRS)"),
    ("Software Design Description",         "SDD", "Software Design Description (SDD)"),
    ("Software Project Management Plan",    "SPMP", "Software Project Management Plan (SPMP)"),
]

def el(tag, **a):
    e = OxmlElement(tag)
    for k, v in a.items():
        e.set(qn(k), v)
    return e

def new_par(text="", style=None):
    p = OxmlElement('w:p')
    return p

def field_run(par, instr):
    r = par.add_run()
    r._r.append(el('w:fldChar', **{'w:fldCharType': 'begin'}))
    t = el('w:instrText', **{'xml:space': 'preserve'}); t.text = instr
    r._r.append(t)
    r._r.append(el('w:fldChar', **{'w:fldCharType': 'separate'}))
    tr = OxmlElement('w:r'); tt = OxmlElement('w:t'); tt.text = "…"; tr.append(tt)
    r._r.addnext(tr)
    e = OxmlElement('w:r'); e.append(el('w:fldChar', **{'w:fldCharType': 'end'}))
    tr.addnext(e)
    return r

# ---------------------------------------------------------------- figures
FIG_RE = re.compile(r'^\s*Figure\s+(\d+)\.\s*(.*)$', re.S)

def process_figures(doc):
    """Centre images, keep them with their caption, and convert the bold
    'Figure N. Title.' lead-in into a real Caption paragraph with a SEQ field
    (so Word's List of Figures and numbering update automatically)."""
    figs = 0
    for p in list(doc.paragraphs):
        if p._p.findall('.//' + qn('w:drawing')):
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            p.paragraph_format.keep_with_next = True
            p.paragraph_format.space_before = Pt(6)
            p.paragraph_format.space_after = Pt(2)
            pPr = p._p.get_or_add_pPr()
            pPr.append(el('w:keepLines', **{'w:val': '1'}))
    # Caption = any paragraph reading "Figure N. <Title>. <description…>".
    # Split deterministically on text: first sentence = title, remainder = description.
    for p in list(doc.paragraphs):
        if p._p.findall('.//' + qn('w:drawing')):
            continue
        text = (p.text or "").strip()
        fm = re.match(r'^Figure\s+\d+\.\s*([^.]*\.)\s*(.*)$', text, re.S)
        if not fm:
            continue
        title, rest = fm.group(1).strip(), fm.group(2).strip()
        from docx.text.paragraph import Paragraph
        # 1) new SEQ-numbered caption paragraph inserted ABOVE the description
        capp = OxmlElement('w:p'); p._p.addprevious(capp)
        cp = Paragraph(capp, p._parent)
        try:
            cp.style = doc.styles['Image Caption']
        except KeyError:
            cp.style = doc.styles['Caption']
        cp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        cp.add_run("Figure ")
        field_run(cp, r' SEQ Figure \* ARABIC ')
        cp.add_run(". " + title)
        # 2) strip the "Figure N. <Title>." prefix from the original paragraph,
        #    leaving the descriptive text AND its hyperlinks intact
        need = text[:text.find(title) + len(title)].strip()
        acc = ""
        for child in list(p._p):
            if child.tag == qn('w:pPr'):
                continue
            if child.tag != qn('w:r') or len(acc) >= len(need):
                break
            rt = "".join((t.text or "") for t in child.findall(qn('w:t')))
            p._p.remove(child)
            acc += rt
        # 3) demote the remainder to a small, justified description paragraph
        p.style = doc.styles['Normal']
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
        p.paragraph_format.space_after = Pt(12)
        for r in p.runs:
            r.font.size = Pt(9.5)
            r.font.color.rgb = RGBColor.from_string(SLATE)
        figs += 1
    return figs

# ---------------------------------------------------------------- tables
ID_RE = re.compile(r'^[A-Z][A-Z0-9]*(?:[-–][A-Z0-9]+)+[a-z]?$')

TBL_TOTAL = 9360          # 6.5in text area in dxa
CHAR_CAP  = 60            # cap so one verbose column can't starve the rest
CHAR_DXA  = 128           # ~bold 9.5pt Calibri glyph width (IDs render bold)
CELL_PAD  = 320           # cell margins + borders
MIN_CAP   = 2800          # a very long token never demands more than this
COL_FLOOR = 780           # absolute narrowest column (Word breaks after "-" otherwise)

def autofit_columns(tbl):
    """pandoc emits equal fixed column widths regardless of content; re-weight
    them by what the cells actually hold. Every column is guaranteed enough
    room for its longest unbreakable word (IDs, dates, headers) so Word never
    breaks mid-token."""
    rows = tbl.rows
    if not rows:
        return
    ncols = len(rows[0].cells)
    if ncols < 2:
        return
    weights = [0] * ncols
    mins = [0] * ncols
    for r_i, row in enumerate(rows):
        cells = row.cells
        if len(cells) != ncols:
            return                                    # spans — leave untouched
        for j, c in enumerate(cells):
            longest = max((len(p.text) for p in c.paragraphs), default=0)
            word = max((len(w) for p in c.paragraphs
                        for w in (p.text or "").split()), default=0)
            if r_i == 0:                              # header should not wrap
                longest = max(longest, len(c.text) + 2)
                word = max(word, len(c.text.strip()))
            weights[j] = max(weights[j], min(longest, CHAR_CAP))
            mins[j] = max(mins[j], COL_FLOOR,
                          min(word * CHAR_DXA + CELL_PAD, MIN_CAP))
    weights = [max(w, 4) for w in weights]
    total_w = sum(weights)
    widths = [TBL_TOTAL * w / total_w for w in weights]
    if sum(mins) >= TBL_TOTAL:                        # degenerate: scale mins
        s = TBL_TOTAL / sum(mins)
        widths = [m * s for m in mins]
    else:
        for _ in range(6):                            # waterfall mins into place
            shortfall = sum(m - w for w, m in zip(widths, mins) if w < m)
            if shortfall < 1:
                break
            surplus = sum(w - m for w, m in zip(widths, mins) if w > m)
            widths = [m if w < m else w - (w - m) * shortfall / surplus
                      for w, m in zip(widths, mins)]
    widths = [int(w) for w in widths]
    widths[-1] += TBL_TOTAL - sum(widths)             # absorb rounding
    grid = tbl._tbl.find(qn('w:tblGrid'))
    if grid is not None:
        cols = grid.findall(qn('w:gridCol'))
        if len(cols) == ncols:
            for gc, w in zip(cols, widths):
                gc.set(qn('w:w'), str(w))
    for row in rows:
        for c, w in zip(row.cells, widths):
            tcPr = c._tc.get_or_add_tcPr()
            for old in tcPr.findall(qn('w:tcW')):
                tcPr.remove(old)
            tcPr.append(el('w:tcW', **{'w:w': str(w), 'w:type': 'dxa'}))

def process_tables(doc):
    """Header row repeats on every page, rows never split, header text is
    white-on-navy (direct formatting — paragraph styles override the table
    style's conditional colors), IDs bold, content-weighted column widths."""
    n = 0
    for tbl in doc.tables:
        try:
            tbl.style = doc.styles['Table']
        except KeyError:
            pass
        tblPr = tbl._tbl.tblPr
        for old in tblPr.findall(qn('w:tblLook')):
            tblPr.remove(old)
        tblPr.append(el('w:tblLook', **{'w:val': '04A0', 'w:firstRow': '1', 'w:lastRow': '0',
                                        'w:firstColumn': '0', 'w:lastColumn': '0',
                                        'w:noHBand': '0', 'w:noVBand': '1'}))
        autofit_columns(tbl)
        for i, row in enumerate(tbl.rows):
            trPr = row._tr.get_or_add_trPr()
            for tag in ('w:cantSplit', 'w:tblHeader'):             # dedupe (pandoc may already set these)
                for old in trPr.findall(qn(tag)):
                    trPr.remove(old)
            trPr.append(el('w:cantSplit', **{'w:val': '1'}))       # no row splits across pages
            if i == 0:
                trPr.append(el('w:tblHeader', **{'w:val': '1'}))   # repeat header on every page
                for cell in row.cells:                             # direct white bold — outranks Compact
                    for par in cell.paragraphs:
                        for r in par.runs:
                            r.bold = True
                            r.font.color.rgb = RGBColor.from_string('FFFFFF')
            else:
                first = row.cells[0]
                txt = first.text.strip()
                if ID_RE.match(txt):
                    for par in first.paragraphs:
                        for r in par.runs:
                            r.bold = True
        n += 1
    return n

def caption_tables(doc):
    """Insert 'Table N. <nearest heading>' captions (Caption style + SEQ field)."""
    body = doc.element.body
    from docx.text.paragraph import Paragraph
    from docx.table import Table
    heading = ""
    made = 0
    for child in list(body):
        if child.tag == qn('w:p'):
            p = Paragraph(child, doc)
            if p.style is not None and (p.style.name or "").startswith("Heading"):
                heading = re.sub(r'^\s*[\d.]+\s*', '', p.text).strip()
        elif child.tag == qn('w:tbl'):
            cap = OxmlElement('w:p')
            child.addprevious(cap)
            cp = Paragraph(cap, doc)
            try:
                cp.style = doc.styles['Table Caption']
            except KeyError:
                cp.style = doc.styles['Caption']
            cp.alignment = WD_ALIGN_PARAGRAPH.LEFT
            cp.paragraph_format.keep_with_next = True
            cp.add_run("Table ")
            field_run(cp, r' SEQ Table \* ARABIC ')
            cp.add_run(". " + (heading or "Reference table"))
            made += 1
    return made

# ---------------------------------------------------------------- pagination
def _set_flag(p, tag):
    pPr = p._p.get_or_add_pPr()
    if not pPr.findall(qn(tag)):
        pPr.append(el(tag, **{'w:val': '1'}))

def _is_list_item(p):
    pPr = p._p.pPr
    if pPr is not None and pPr.find(qn('w:numPr')) is not None:
        return True
    name = (p.style.name or "") if p.style is not None else ""
    return name in ("Compact", "List Paragraph", "List Bullet", "List Number")

def apply_pagination(doc):
    """Professional pagination pass: widow/orphan control document-wide,
    lead-in sentences keep with the list they introduce, bullet/numbered lists
    never strand a single item across a page boundary, block quotes and list
    items never split mid-paragraph, figure captions stay with their notes."""
    # 1) widow/orphan control as a document default (covers every style)
    styles_el = doc.styles.element
    dd = styles_el.find(qn('w:docDefaults'))
    if dd is None:
        dd = el('w:docDefaults'); styles_el.insert(0, dd)
    ppd = dd.find(qn('w:pPrDefault'))
    if ppd is None:
        ppd = el('w:pPrDefault'); dd.append(ppd)
    ppr = ppd.find(qn('w:pPr'))
    if ppr is None:
        ppr = el('w:pPr'); ppd.append(ppr)
    if ppr.find(qn('w:widowControl')) is None:
        ppr.append(el('w:widowControl', **{'w:val': '1'}))

    paras = list(doc.paragraphs)
    n = len(paras)
    lists = leads = 0
    for p in paras:
        name = (p.style.name or "") if p.style is not None else ""
        text = (p.text or "").strip()
        if _is_list_item(p) or name == "Block Text":
            _set_flag(p, 'w:keepLines')            # never split one item/quote
        if name == "Source Code":                  # justify stretches soft-broken
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT  # code lines — keep them ragged
        # a lead-in ("… the following:") stays with what it introduces
        if (not _is_list_item(p) and not name.startswith("Heading")
                and text.endswith(":")):
            p.paragraph_format.keep_with_next = True
            leads += 1
        # figure captions keep with the descriptive note that follows them
        if name in ("Image Caption", "Caption"):
            p.paragraph_format.keep_with_next = True
    # 2) group consecutive list items into runs and keep them sensibly
    i = 0
    while i < n:
        if _is_list_item(paras[i]):
            j = i
            while j + 1 < n and _is_list_item(paras[j + 1]):
                j += 1
            run = paras[i:j + 1]
            if len(run) <= 6:                      # short lists stay together
                for p in run[:-1]:
                    p.paragraph_format.keep_with_next = True
            else:                                  # long lists: no stranded item
                run[0].paragraph_format.keep_with_next = True
                run[-2].paragraph_format.keep_with_next = True
            lists += 1
            i = j + 1
        else:
            i += 1
    return lists, leads

# ---------------------------------------------------------------- navigation
def replace_manual_nav(doc):
    """Swap the source's manual Table of Contents / List of Figures for real,
    auto-updating Word fields, and add a List of Tables."""
    from docx.text.paragraph import Paragraph
    body = doc.element.body
    children = list(body)
    def is_heading(c):
        return c.tag == qn('w:p') and Paragraph(c, doc).style is not None and \
               (Paragraph(c, doc).style.name or "").startswith("Heading")
    def find_heading(txt):
        for i, c in enumerate(children):
            if is_heading(c) and Paragraph(c, doc).text.strip().lower().startswith(txt):
                return i
        return None

    inserted = []
    toc_i = find_heading("table of contents")
    if toc_i is not None:
        # delete everything from after the heading to the next heading
        j = toc_i + 1
        while j < len(children) and not is_heading(children[j]):
            body.remove(children[j]); j += 1
        anchor = children[toc_i]
        for instr, label in ((r' TOC \o "1-3" \h \z \u ', "TOC"),):
            p = OxmlElement('w:p'); anchor.addnext(p)
            par = Paragraph(p, doc); par.style = doc.styles['Normal']
            field_run(par, instr); inserted.append(label)
    lof_i = find_heading("list of figures")
    children = list(body)
    def find_heading2(txt):
        for i, c in enumerate(children):
            if is_heading(c) and Paragraph(c, doc).text.strip().lower().startswith(txt):
                return i
        return None
    lof_i = find_heading2("list of figures")
    if lof_i is not None:
        j = lof_i + 1
        while j < len(children) and not is_heading(children[j]):
            body.remove(children[j]); j += 1
        anchor = children[lof_i]
        p = OxmlElement('w:p'); anchor.addnext(p)
        par = Paragraph(p, doc); par.style = doc.styles['Normal']
        field_run(par, r' TOC \h \z \c "Figure" '); inserted.append("LOF")
        # List of Tables immediately after
        h = OxmlElement('w:p'); par._p.addnext(h)
        hp = Paragraph(h, doc); hp.style = doc.styles['Heading 3']; hp.add_run("List of Tables")
        p2 = OxmlElement('w:p'); h.addnext(p2)
        par2 = Paragraph(p2, doc); par2.style = doc.styles['Normal']
        field_run(par2, r' TOC \h \z \c "Table" '); inserted.append("LOT")
    elif toc_i is not None:
        anchor = children[toc_i]
        cur = anchor.getnext()                       # the TOC field paragraph
        if len(doc.inline_shapes):                   # only if the doc really has figures
            h = OxmlElement('w:p'); cur.addnext(h)
            hp = Paragraph(h, doc); hp.style = doc.styles['Heading 2']; hp.add_run("List of Figures")
            p1 = OxmlElement('w:p'); h.addnext(p1)
            par1 = Paragraph(p1, doc); par1.style = doc.styles['Normal']
            field_run(par1, r' TOC \h \z \c "Figure" '); inserted.append("LOF"); cur = p1
        h = OxmlElement('w:p'); cur.addnext(h)
        hp = Paragraph(h, doc); hp.style = doc.styles['Heading 2']; hp.add_run("List of Tables")
        p2 = OxmlElement('w:p'); h.addnext(p2)
        par2 = Paragraph(p2, doc); par2.style = doc.styles['Normal']
        field_run(par2, r' TOC \h \z \c "Table" '); inserted.append("LOT")
    return inserted

def set_header(doc, title):
    from docx.enum.text import WD_ALIGN_PARAGRAPH as A
    for s in doc.sections:
        h = s.header; h.is_linked_to_previous = False
        p = h.paragraphs[0] if h.paragraphs else h.add_paragraph()
        for r in list(p.runs): r._r.getparent().remove(r._r)
        p.alignment = A.RIGHT
        r = p.add_run(title + "  ·  Version 1.0")
        r.font.size = Pt(8.5); r.font.name = 'Calibri'
        r.font.color.rgb = RGBColor.from_string(SLATE)

def set_meta(path, title):
    d = Document(path); cp = d.core_properties
    cp.title = title; cp.author = "Engineering (Beemo)"
    cp.subject = "AI-Powered School IT Asset & Service Management System (SccIT)"
    cp.category = "Software Engineering Documentation"
    cp.comments = "Version 1.0 baseline — Waiting for Client Approval"
    cp.keywords = "SccIT; ITSM; ITAM; SRS; SDD; SPMP; Version 1.0"
    d.save(path)

def force_update_fields(path):
    """Tell Word to refresh TOC/LOF/LOT/SEQ fields when the document opens."""
    tmp = path + ".tmp"
    zin = zipfile.ZipFile(path, 'r')
    zout = zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED)
    for item in zin.infolist():
        data = zin.read(item.filename)
        if item.filename == 'word/settings.xml':
            s = data.decode('utf-8')
            if 'w:updateFields' not in s:
                s = s.replace('<w:settings ', '<w:settings ', 1)
                s = re.sub(r'(<w:settings[^>]*>)', r'\1<w:updateFields w:val="true"/>', s, count=1)
            data = s.encode('utf-8')
        zout.writestr(item, data)
    zin.close(); zout.close()
    shutil.move(tmp, path)

def main():
    os.makedirs(OUT, exist_ok=True)
    args = ["--resource-path=.", "--standalone", f"--reference-doc={REF}",
            "--from=markdown-implicit_figures", "--dpi=300"]
    print(f"{'doc':6}{'figures':>9}{'tables':>8}{'tbl-caps':>10}{'lists':>7}{'leads':>7}  nav")
    for md, tag, title in DOCS:
        path = os.path.join(OUT, md + ".docx")
        pypandoc.convert_file(os.path.join(PUB, md + ".md"), "docx", outputfile=path, extra_args=args)
        doc = Document(path)
        figs = process_figures(doc)
        tcaps = caption_tables(doc)
        tbls = process_tables(doc)
        nav = replace_manual_nav(doc)
        lists, leads = apply_pagination(doc)
        set_header(doc, title)
        doc.save(path)
        set_meta(path, title)
        force_update_fields(path)
        print(f"{tag:6}{figs:>9}{tbls:>8}{tcaps:>10}{lists:>7}{leads:>7}  {'+'.join(nav) or '-'}")

if __name__ == "__main__":
    main()
