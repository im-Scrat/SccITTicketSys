#!/usr/bin/env python
"""Render a project report from Markdown to Word.

    python scripts/build_project_report.py projectreports/<name>.md [more.md ...]
    python scripts/build_project_report.py --all

Deliberately **not** the docs/build_word_deliverables.py pipeline. That one
exists to produce the client's SRS/SDD/SPMP: it carries a reference template,
TOC and SEQ fields, figure and table caption numbering, and a pagination pass
tuned for a bound document. An engineering report needs none of that, and
coupling the two would mean every report change risked the deliverables.

What this does instead is small and predictable: headings, paragraphs, bullet
and numbered lists, GitHub-flavoured tables, fenced code blocks, horizontal
rules, and inline bold / italic / code. YAML front matter becomes the title
block. Anything it does not recognise is emitted as plain text rather than
dropped, so a report can never silently lose a paragraph on its way to Word.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

REPORTS = Path(__file__).resolve().parent.parent / "projectreports"

INK = RGBColor(0x1A, 0x1A, 0x1A)
MUTED = RGBColor(0x5A, 0x5A, 0x5A)
ACCENT = RGBColor(0x1F, 0x3A, 0x5F)
CODE_BG = "F4F4F5"
HEADER_BG = "1F3A5F"

BODY_FONT = "Calibri"
MONO_FONT = "Consolas"


# --------------------------------------------------------- document setup --

def base_document() -> Document:
    doc = Document()

    normal = doc.styles["Normal"]
    normal.font.name = BODY_FONT
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = INK
    normal.paragraph_format.space_after = Pt(8)
    normal.paragraph_format.line_spacing = 1.15

    # East-Asian font mapping, or Word substitutes its own for some glyphs.
    rpr = normal.element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:eastAsia"), BODY_FONT)

    for level, size in ((1, 20), (2, 15), (3, 12.5), (4, 11)):
        style = doc.styles["Heading {}".format(level)]
        style.font.name = BODY_FONT
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = ACCENT
        style.paragraph_format.space_before = Pt(16 if level <= 2 else 12)
        style.paragraph_format.space_after = Pt(6)
        style.paragraph_format.keep_with_next = True

    for section in doc.sections:
        section.left_margin = Inches(0.9)
        section.right_margin = Inches(0.9)
        section.top_margin = Inches(0.9)
        section.bottom_margin = Inches(0.9)

    return doc


def shade_cell(cell, fill: str) -> None:
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    cell._tc.get_or_add_tcPr().append(shd)


def shade_paragraph(par, fill: str) -> None:
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    par._p.get_or_add_pPr().append(shd)


# --------------------------------------------------------- inline parsing --

INLINE = re.compile(r"(\*\*.+?\*\*|`[^`]+`|\*[^*]+\*|_[^_]+_)")


def add_inline(paragraph, text: str, base_bold: bool = False) -> None:
    """Render bold, italic and inline code inside a paragraph.

    Markers are stripped rather than escaped: a report read in Word should not
    show the asterisks its author typed.
    """
    for part in INLINE.split(text):
        if not part:
            continue

        if part.startswith("**") and part.endswith("**") and len(part) > 4:
            run = paragraph.add_run(part[2:-2])
            run.bold = True
        elif part.startswith("`") and part.endswith("`") and len(part) > 2:
            run = paragraph.add_run(part[1:-1])
            run.font.name = MONO_FONT
            run.font.size = Pt(9.5)
        elif len(part) > 2 and part[0] in "*_" and part[-1] == part[0]:
            run = paragraph.add_run(part[1:-1])
            run.italic = True
        else:
            run = paragraph.add_run(part)
            run.bold = base_bold

        if base_bold:
            run.bold = True


# ------------------------------------------------------------- converters --

def rule(doc: Document) -> None:
    par = doc.add_paragraph()
    par.paragraph_format.space_before = Pt(6)
    par.paragraph_format.space_after = Pt(10)
    p_pr = par._p.get_or_add_pPr()
    borders = OxmlElement("w:pBdr")
    bottom = OxmlElement("w:bottom")
    bottom.set(qn("w:val"), "single")
    bottom.set(qn("w:sz"), "6")
    bottom.set(qn("w:color"), "D4D4D8")
    borders.append(bottom)
    p_pr.append(borders)


def add_front_matter(doc: Document, meta: dict) -> None:
    heading = doc.add_paragraph()
    heading.paragraph_format.space_after = Pt(4)
    run = heading.add_run(meta.get("title", "Project report"))
    run.font.size = Pt(24)
    run.font.bold = True
    run.font.color.rgb = ACCENT

    for key in ("project", "phase", "date", "author", "status"):
        if key not in meta:
            continue
        line = doc.add_paragraph()
        line.paragraph_format.space_after = Pt(2)
        label = line.add_run("{}:  ".format(key.title()))
        label.font.size = Pt(9.5)
        label.font.bold = True
        label.font.color.rgb = MUTED
        value = line.add_run(str(meta[key]))
        value.font.size = Pt(9.5)
        value.font.color.rgb = MUTED

    rule(doc)


def add_code_block(doc: Document, lines: list) -> None:
    par = doc.add_paragraph()
    par.paragraph_format.space_before = Pt(6)
    par.paragraph_format.space_after = Pt(10)
    par.paragraph_format.left_indent = Inches(0.15)
    shade_paragraph(par, CODE_BG)

    for index, line in enumerate(lines):
        if index:
            par.add_run().add_break()
        run = par.add_run(line)
        run.font.name = MONO_FONT
        run.font.size = Pt(9)


def add_table(doc: Document, rows: list) -> None:
    header, body = rows[0], rows[1:]

    table = doc.add_table(rows=1, cols=len(header))
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = True

    for cell, text in zip(table.rows[0].cells, header):
        shade_cell(cell, HEADER_BG)
        par = cell.paragraphs[0]
        par.paragraph_format.space_after = Pt(2)
        add_inline(par, text, base_bold=True)
        for run in par.runs:
            run.font.size = Pt(9.5)
            run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)

    for row in body:
        cells = table.add_row().cells
        for cell, text in zip(cells, row):
            par = cell.paragraphs[0]
            par.paragraph_format.space_after = Pt(2)
            add_inline(par, text)
            for run in par.runs:
                run.font.size = Pt(9.5)

    doc.add_paragraph().paragraph_format.space_after = Pt(4)


def split_row(line: str) -> list:
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


def is_divider(line: str) -> bool:
    return bool(re.fullmatch(r"\|[\s:|-]+\|", line.strip()))


# ---------------------------------------------------------------- the pass --

def convert(md_path: Path) -> Path:
    lines = md_path.read_text(encoding="utf-8").split("\n")

    meta = {}
    if lines and lines[0].strip() == "---":
        end = next((i for i in range(1, len(lines)) if lines[i].strip() == "---"), None)
        if end is not None:
            for raw in lines[1:end]:
                if ":" in raw:
                    key, _, value = raw.partition(":")
                    meta[key.strip()] = value.strip()
            lines = lines[end + 1:]

    doc = base_document()
    if meta:
        add_front_matter(doc, meta)

    i = 0
    seen_title = bool(meta)

    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            i += 1
            continue

        # Fenced code.
        if stripped.startswith("```"):
            block = []
            i += 1
            while i < len(lines) and not lines[i].strip().startswith("```"):
                block.append(lines[i])
                i += 1
            i += 1
            add_code_block(doc, block)
            continue

        # Horizontal rule.
        if re.fullmatch(r"(-{3,}|\*{3,}|_{3,})", stripped):
            rule(doc)
            i += 1
            continue

        # Table.
        if stripped.startswith("|") and i + 1 < len(lines) and is_divider(lines[i + 1]):
            rows = [split_row(stripped)]
            i += 2
            while i < len(lines) and lines[i].strip().startswith("|"):
                rows.append(split_row(lines[i]))
                i += 1
            add_table(doc, rows)
            continue

        # Heading.
        heading = re.match(r"^(#{1,6})\s+(.*)$", stripped)
        if heading:
            level = len(heading.group(1))
            body = heading.group(2)
            # The front matter already printed the title; a repeated H1 would
            # simply say the same thing twice on page one.
            if level == 1 and seen_title:
                i += 1
                continue
            seen_title = seen_title or level == 1
            par = doc.add_paragraph(style="Heading {}".format(min(level, 4)))
            add_inline(par, body)
            i += 1
            continue

        # Blockquote.
        if stripped.startswith(">"):
            par = doc.add_paragraph()
            par.paragraph_format.left_indent = Inches(0.25)
            add_inline(par, stripped.lstrip("> ").strip())
            for run in par.runs:
                run.font.color.rgb = MUTED
            i += 1
            continue

        # Bulleted list.
        bullet = re.match(r"^(\s*)[-*+]\s+(.*)$", line)
        if bullet:
            depth = len(bullet.group(1)) // 2
            style = "List Bullet" if depth == 0 else "List Bullet {}".format(min(depth + 1, 3))
            par = doc.add_paragraph(style=style)
            par.paragraph_format.space_after = Pt(3)
            add_inline(par, bullet.group(2))
            i += 1
            continue

        # Numbered list.
        number = re.match(r"^(\s*)\d+[.)]\s+(.*)$", line)
        if number:
            depth = len(number.group(1)) // 2
            style = "List Number" if depth == 0 else "List Number {}".format(min(depth + 1, 3))
            par = doc.add_paragraph(style=style)
            par.paragraph_format.space_after = Pt(3)
            add_inline(par, number.group(2))
            i += 1
            continue

        # Paragraph: join the run of non-blank, non-structural lines.
        buffer = [stripped]
        i += 1
        while i < len(lines):
            nxt = lines[i].strip()
            structural = (
                not nxt
                or nxt.startswith("#")
                or nxt.startswith("|")
                or nxt.startswith(">")
                or nxt.startswith("```")
                or re.match(r"^(\s*)([-*+]|\d+[.)])\s+", lines[i])
                or re.fullmatch(r"(-{3,}|\*{3,}|_{3,})", nxt)
            )
            if structural:
                break
            buffer.append(nxt)
            i += 1

        par = doc.add_paragraph()
        par.alignment = WD_ALIGN_PARAGRAPH.LEFT
        add_inline(par, " ".join(buffer))

    out = md_path.with_suffix(".docx")
    doc.save(out)
    return out


def main(argv: list) -> int:
    if not argv or argv[0] in ("-h", "--help"):
        print(__doc__)
        return 0

    if argv[0] == "--all":
        targets = sorted(REPORTS.glob("*.md"))
    else:
        targets = [Path(a) for a in argv]

    if not targets:
        print("No report sources found.")
        return 1

    for md in targets:
        if not md.exists():
            print("  skip  {} (not found)".format(md))
            continue
        out = convert(md)
        print("  built {}  ({:,} bytes)".format(out.name, out.stat().st_size))

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
