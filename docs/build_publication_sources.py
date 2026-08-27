# -*- coding: utf-8 -*-
"""Extract the SDD's inline Mermaid diagrams into version-controlled sources,
render them as publication figures, and emit publication copies of the three
documents with all developer-only artifacts removed.

The technical Markdown in docs/*.md is NEVER modified: it stays the maintainable
source of truth.  Publication copies land in docs/_publish/ and are what pandoc
turns into the client/thesis deliverables.

Run:  python docs/build_publication_sources.py
"""
import os, re, subprocess, shutil
from PIL import Image

SRC = "diagrams/source"
FIG = "diagrams"
PUB = "_publish"
MAXW, MAXH = 6.50, 8.40          # printable area, leaving room for the caption

def slug(s):
    s = re.sub(r'^[\d.]+\s*', '', s).strip().lower()
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')[:40]

def sanitize(body):
    """Mermaid treats ';' as a statement separator, so semicolons inside sequence
    message text break the parse (one SDD diagram was already unrenderable).
    Swap them for a middot in message text only — wording/meaning unchanged."""
    if not body.lstrip().startswith("sequenceDiagram"):
        return body
    out = []
    for ln in body.split("\n"):
        if ("->>" in ln or "-->>" in ln or "->" in ln) and ":" in ln:
            head, msg = ln.split(":", 1)
            msg = msg.replace(";", " ·")
            ln = head + ":" + msg
        out.append(ln)
    return "\n".join(out)

def render(mmd_path, png_path, svg_path):
    for out in (svg_path, png_path):
        args = ["npx", "-y", "@mermaid-js/mermaid-cli", "-i", mmd_path, "-o", out]
        if out.endswith(".png"):
            args += ["--scale", "3", "--backgroundColor", "white"]
        subprocess.run(args, capture_output=True, shell=True)

def set_dpi(png):
    im = Image.open(png); w, h = im.size; asp = h / w
    tw = MAXW if MAXW * asp <= MAXH else MAXH / asp
    im.save(png, dpi=(round(w / tw), round(w / tw)))
    return tw, tw * asp

# ---------------------------------------------------------------- SDD
def extract_sdd():
    """Pull every inline mermaid diagram out of the SDD, render it, and return a
    publication copy where each source block is replaced by its rendered figure."""
    text = open("Software Design Description.md", encoding="utf-8").read()
    out, pos, n, made = [], 0, 0, []
    pat = re.compile(r"```mermaid\n(.*?)```", re.S)
    for m in pat.finditer(text):
        body = m.group(1)
        head = re.findall(r"^#{2,4} (.+)$", text[:m.start()], re.M)
        title = re.sub(r'^[\d.]+\s*', '', head[-1]).strip() if head else f"Diagram {n+1}"
        n += 1
        base = f"sdd-{n:02d}-{slug(title)}"
        mmd = f"{SRC}/{base}.mmd"
        open(mmd, "w", encoding="utf-8").write(sanitize(body))
        png, svg = f"{FIG}/{base}.png", f"{FIG}/{base}.svg"
        render(mmd, png, svg)
        if os.path.exists(png):
            w, h = set_dpi(png)
            made.append((base, title, round(w, 2), round(h, 2)))
            fig = f"![{title}]({FIG}/{base}.png)\n\n**Figure {n}. {title}.**"
        else:
            raise RuntimeError(f"render FAILED for {base} — refusing to drop a diagram")
        out.append(text[pos:m.start()] + fig)
        pos = m.end()
    out.append(text[pos:])
    res = "".join(out)
    # drop developer-only convention notes (how the diagrams are authored)
    res = "\n".join(l for l in res.split("\n") if "use Mermaid" not in l)
    return res, made

# ---------------------------------------------------------------- SRS
def clean_srs():
    """Remove Appendix B (diagram sources + rendering commands) and the ASCII
    sketch that Figure 1 supersedes.  Nothing else is touched."""
    t = open("Software Requirements Specification.md", encoding="utf-8").read()
    removed = []
    # Appendix B — whole section (developer-only)
    i = t.find("## Appendix B — Diagram Sources (Mermaid & PlantUML)")
    j = t.find("---\n\n*End of Software Requirements Specification")
    if i != -1 and j != -1:
        t = t[:i] + t[j:]; removed.append("Appendix B (Mermaid/PlantUML sources + render commands)")
        # the rule that led into Appendix B now stacks on the end-line's own rule
        t = t.replace("---\n\n---\n\n*End of", "---\n\n*End of")
    # ToC entry + pointers to Appendix B
    t = re.sub(r"^37\. \[Appendix B.*\n", "", t, flags=re.M)
    t = re.sub(r"\s*;?\s*UML sources? in \[Appendix B\]\([^)]*\)", "", t)
    t = re.sub(r"\s*Sources are catalogued and reproduced in \[Appendix B\]\([^)]*\)[^.]*\.", "", t)
    t = re.sub(r"\[Appendix B\]\([^)]*\)", "the diagram source repository", t)
    # ASCII sketch in §9.1 (superseded by Figure 1)
    m = re.search(r"### 9\.1 Context \(text diagram\)\n\n(> [^\n]*\n\n)?```\n.*?```\n", t, re.S)
    if m:
        t = t[:m.start()] + "### 9.1 Context\n\nThe system context is shown in **Figure 1** ([§6.1](#61-product-perspective)).\n" + t[m.end():]
        removed.append("§9.1 ASCII text-diagram (superseded by Figure 1)")
    # high-res source pointers aimed at maintainers
    t = re.sub(r"\s*High-resolution vector source: \[`[^`]*`\]\([^)]*\)\.?", "", t)
    return t, removed

def main():
    os.makedirs(PUB, exist_ok=True); os.makedirs(SRC, exist_ok=True)
    sdd, made = extract_sdd()
    open(f"{PUB}/Software Design Description.md", "w", encoding="utf-8").write(sdd)
    print(f"SDD: extracted + rendered {len(made)} Mermaid diagrams -> figures")
    for b, t, w, h in made:
        print(f"   Fig  {t[:44]:46} {w:.2f} x {h:.2f} in   ({b}.png)")
    srs, removed = clean_srs()
    open(f"{PUB}/Software Requirements Specification.md", "w", encoding="utf-8").write(srs)
    print("\nSRS removed:", "; ".join(removed) or "nothing")
    shutil.copy("Software Project Management Plan.md", f"{PUB}/Software Project Management Plan.md")
    print("SPMP: no developer artifacts present (0 code fences) — copied as-is")

if __name__ == "__main__":
    main()
