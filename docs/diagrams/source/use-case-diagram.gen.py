# -*- coding: utf-8 -*-
"""Generate a standard UML Use Case Diagram (stickman actors) as SVG + HTML."""
import html, math, textwrap

UC = {
 "U01":"Register Account","U02":"Log In","U03":"Reset Password",
 "U08":"Submit Ticket","U09":"Attach Files",
 "U04":"Log Out","U05":"Change Password","U06":"Manage Profile","U07":"View Dashboard",
 "U10":"Track Ticket Status","U11":"Comment on Ticket",
 "U14":"Use AI Troubleshooting","U15":"Search Knowledge Base","U16":"View AI Recommendations",
 "U27":"Manage Notifications",
 "U13":"Resolve Ticket","U22":"Scan QR Code","U23":"Perform Maintenance","U24":"Schedule Preventive Maintenance",
 "U12":"Assign Ticket","U17":"Manage Assets","U18":"Manage Inventory","U19":"Manage PC Units",
 "U21":"Manage QR Codes","U32":"Generate Reports",
 "U20":"Manage Procurement","U25":"Manage Locations","U26":"Manage Floor Plan","U28":"Manage Announcements",
 "U29":"Manage Users","U30":"Manage Roles & Permissions","U31":"Approve Registration",
 "U33":"View Analytics","U34":"View Audit Logs","U35":"Manage System Settings",
}
# positioning bands (top -> bottom): key, list of ucs
POS_BANDS = [
 ("pub",   ["U01","U02","U03"]),
 ("teach", ["U08","U09"]),
 ("user",  ["U04","U05","U06","U07","U10","U11","U14","U15","U16","U27"]),
 ("tech",  ["U13","U22","U23","U24"]),
 ("shared",["U12","U17","U18","U19","U21","U32"]),
 ("admin", ["U20","U25","U26","U28","U29","U30","U31","U33","U34","U35"]),
]
# human actors (stickmen): name, abstract, band-anchor spec
# common use cases performed by every signed-in role (no abstract actor;
# connected directly to each concrete role for thesis-panel readability)
COMMON = ["U04","U05","U06","U07","U10","U11","U14","U15","U16","U27"]
# association actor -> use cases
ASSOC = {
 "Public Visitor":      ["U01","U02","U03"],
 "Teacher / Requester": COMMON+["U08"],
 "Technician":          COMMON+["U13","U22","U23","U24","U12","U17","U18","U19","U21","U32"],
 "Administrator":       COMMON+["U12","U17","U18","U19","U21","U32","U20","U25","U26","U28","U29","U30","U31","U33","U34","U35"],
}
SYS = [
 ("AI Provider",          ["U14","U15","U16"]),
 ("Email Service",        ["U03","U27","U29","U31"]),
 ("Notification Service", ["U12","U23","U24","U27","U28"]),
 ("QR Scanner",           ["U22"]),
]
GEN = []  # actor generalization removed (roles connect directly to common use cases)
EXTEND = [("U09","U08")]

# ---- geometry ----
COLS=2
UCW,UCH=252,56; COL_GAP=44; ROW_GAP=16; BAND_GAP=30; TITLE_H=54; PAD=28
LEFT_ACTOR_X=78; RIGHT_ACTOR_DX=122; ACTOR_H=94
BX0=306
if COLS==2:
    col_x=[BX0+50+UCW/2, BX0+50+UCW+COL_GAP+UCW/2]; BX1=col_x[1]+UCW/2+50
else:
    col_x=[BX0+120+UCW/2]; BX1=col_x[0]+UCW/2+120

pos={}; band_y={}
y=110+TITLE_H
for key,ucs in POS_BANDS:
    rows=math.ceil(len(ucs)/COLS); top=y
    for i,u in enumerate(ucs):
        c=i%COLS; r=i//COLS
        pos[u]=(col_x[c], top+UCH/2+r*(UCH+ROW_GAP))
    if COLS==2 and len(ucs)%2==1:
        u=ucs[-1]; r=(len(ucs)-1)//2
        pos[u]=((col_x[0]+col_x[1])/2, top+UCH/2+r*(UCH+ROW_GAP))
    bh=rows*(UCH+ROW_GAP)-ROW_GAP
    band_y[key]=(top, top+bh, top+bh/2)
    y=top+bh+BAND_GAP

BY0=92; BY1=y-BAND_GAP+PAD; total_h=int(math.ceil(BY1+PAD+40)); total_w=int(math.ceil(BX1+RIGHT_ACTOR_DX+96))

# left actor Y positions (no abstract User actor)
c=lambda k: band_y[k][2]
left_y={
 "Public Visitor": c("pub"),
 "Teacher / Requester": (band_y["teach"][2]+band_y["user"][2])/2,
 "Technician": (band_y["tech"][2]+band_y["shared"][2])/2,
 "Administrator": (band_y["shared"][2]+band_y["admin"][1])/2 - 20,
}
left_pos={n:(LEFT_ACTOR_X,left_y[n]) for n in left_y}

# right system actor Y = mean of connected ucs, de-overlapped
sys_pos={}
for nm,ucs in SYS:
    sys_pos[nm]=(BX1+RIGHT_ACTOR_DX, sum(pos[u][1] for u in ucs)/len(ucs))
order=sorted([s[0] for s in SYS], key=lambda n:sys_pos[n][1]); mg=ACTOR_H+40
for i in range(1,len(order)):
    a,b=order[i-1],order[i]
    if sys_pos[b][1]-sys_pos[a][1]<mg: sys_pos[b]=(sys_pos[b][0],sys_pos[a][1]+mg)

FONT='Segoe UI, Helvetica, Arial, sans-serif'; S=[]
esc=lambda t: html.escape(t)
wrap=lambda s,w=20: textwrap.wrap(s,width=w) or [s]

def arm_anchor(cx,top): return top+13*2+11
def stickman(cx,cyc,name,abstract=False):
    top=cyc-ACTOR_H/2; hr=13; head=top+hr; col="#1b3a5b"; sw=2.4
    d=' stroke-dasharray="5 3"' if abstract else ''
    bt=head+hr; bb=bt+30
    S.append(f'<circle cx="{cx}" cy="{head}" r="{hr}" fill="#fff" stroke="{col}" stroke-width="{sw}"{d}/>')
    S.append(f'<line x1="{cx}" y1="{bt}" x2="{cx}" y2="{bb}" stroke="{col}" stroke-width="{sw}"{d}/>')
    S.append(f'<line x1="{cx-19}" y1="{bt+11}" x2="{cx+19}" y2="{bt+11}" stroke="{col}" stroke-width="{sw}"{d}/>')
    S.append(f'<line x1="{cx}" y1="{bb}" x2="{cx-16}" y2="{bb+24}" stroke="{col}" stroke-width="{sw}"{d}/>')
    S.append(f'<line x1="{cx}" y1="{bb}" x2="{cx+16}" y2="{bb+24}" stroke="{col}" stroke-width="{sw}"{d}/>')
    ny=bb+24+22; ls=[name] if len(name)<=17 else wrap(name,15)
    if abstract:
        S.append(f'<text x="{cx}" y="{ny-2}" text-anchor="middle" font-family="{FONT}" font-size="12" font-style="italic" fill="#7a6010">&#171;abstract&#187;</text>'); ny+=16
    for i,l in enumerate(ls):
        S.append(f'<text x="{cx}" y="{ny+i*17}" text-anchor="middle" font-family="{FONT}" font-size="15" font-weight="600" fill="#5a3d0a">{esc(l)}</text>')

def sysactor(cx,cy,name):
    w=152; ls=wrap(name,17); hh=max(56,26+len(ls)*17); x=cx-w/2; yy=cy-hh/2
    S.append(f'<rect x="{x}" y="{yy}" width="{w}" height="{hh}" rx="4" fill="#EAECEE" stroke="#5D6D7E" stroke-width="1.8"/>')
    S.append(f'<text x="{cx}" y="{yy+19}" text-anchor="middle" font-family="{FONT}" font-size="12" font-style="italic" fill="#41505e">&#171;actor&#187;</text>')
    for i,l in enumerate(ls):
        S.append(f'<text x="{cx}" y="{yy+38+i*16}" text-anchor="middle" font-family="{FONT}" font-size="13.5" font-weight="600" fill="#212F3D">{esc(l)}</text>')

def usecase(u):
    cx,cy=pos[u]; ls=wrap(UC[u],20); ty=cy-(len(ls)-1)*8.5
    S.append(f'<ellipse cx="{cx}" cy="{cy}" rx="{UCW/2}" ry="{UCH/2}" fill="#EAF0FB" stroke="#2E4A86" stroke-width="1.5"/>')
    for i,l in enumerate(ls):
        S.append(f'<text x="{cx}" y="{ty+i*17+5.5}" text-anchor="middle" font-family="{FONT}" font-size="14.5" fill="#16233c">{esc(l)}</text>')

def ell_pt(u,tx,ty):
    cx,cy=pos[u]; rx,ry=UCW/2,UCH/2; dx,dy=tx-cx,ty-cy
    if not dx and not dy: return cx,cy
    k=1.0/math.sqrt((dx/rx)**2+(dy/ry)**2); return cx+dx*k, cy+dy*k

# ---- compose (background, boundary) ----
S.append(f'<rect x="0" y="0" width="{total_w}" height="{total_h}" fill="#fff"/>')
S.append(f'<text x="{total_w/2}" y="34" text-anchor="middle" font-family="{FONT}" font-size="20" font-weight="700" fill="#1b2a4a">SccIT — System Use Case Diagram</text>')
S.append(f'<rect x="{BX0}" y="{BY0}" width="{BX1-BX0}" height="{BY1-BY0}" rx="6" fill="#fff" stroke="#3a4657" stroke-width="2"/>')
S.append(f'<text x="{(BX0+BX1)/2}" y="{BY0+30}" text-anchor="middle" font-family="{FONT}" font-size="16" font-weight="700" fill="#20304d">AI-Powered School IT Asset &amp; Service Management System</text>')

# association lines (under nodes)
for name,ucs in ASSOC.items():
    cx,cyc=left_pos[name]; ax=cx+19; ay=arm_anchor(cx,cyc-ACTOR_H/2)
    for u in ucs:
        px,py=ell_pt(u,ax,ay); S.append(f'<line x1="{ax:.1f}" y1="{ay:.1f}" x2="{px:.1f}" y2="{py:.1f}" stroke="#8a94a6" stroke-width="1.25"/>')
for nm,ucs in SYS:
    sx,sy=sys_pos[nm]; ax=sx-76
    for u in ucs:
        px,py=ell_pt(u,ax,sy); S.append(f'<line x1="{ax:.1f}" y1="{sy:.1f}" x2="{px:.1f}" y2="{py:.1f}" stroke="#8a94a6" stroke-width="1.25"/>')

# (actor generalization removed by request — roles connect directly to common use cases)

# extend (dashed, arrow -> base); label offset to the side to stay legible
for a,b in EXTEND:
    pa=ell_pt(a,*pos[b]); pb=ell_pt(b,*pos[a]); mx,my=(pa[0]+pb[0])/2,(pa[1]+pb[1])/2
    S.append(f'<line x1="{pa[0]:.1f}" y1="{pa[1]:.1f}" x2="{pb[0]:.1f}" y2="{pb[1]:.1f}" stroke="#9a6ab0" stroke-width="1.5" stroke-dasharray="6 4" marker-end="url(#oarrow)"/>')
    lx=mx+58
    S.append(f'<rect x="{lx-30}" y="{my-9}" width="60" height="17" fill="#fff" stroke="none"/>')
    S.append(f'<text x="{lx}" y="{my+4}" text-anchor="middle" font-family="{FONT}" font-size="12" font-style="italic" fill="#7a3ea0">&#171;extend&#187;</text>')

# nodes on top
for u in UC: usecase(u)
for n in left_pos: stickman(*left_pos[n], n, abstract=(n=="User"))
for nm,ucs in SYS: sysactor(*sys_pos[nm], nm)

svg=f'''<svg xmlns="http://www.w3.org/2000/svg" width="{total_w}" height="{total_h}" viewBox="0 0 {total_w} {total_h}" font-family="{FONT}">
<defs>
<marker id="oarrow" markerWidth="12" markerHeight="12" refX="9" refY="4" orient="auto"><path d="M1,1 L9,4 L1,7" fill="none" stroke="#9a6ab0" stroke-width="1.3"/></marker>
<marker id="tri" markerWidth="17" markerHeight="14" refX="13" refY="6" orient="auto"><path d="M1,1 L13,6 L1,11 Z" fill="#fff" stroke="#4a5568" stroke-width="1.3"/></marker>
</defs>
{chr(10).join(S)}
</svg>'''
open("use-case-diagram.svg","w",encoding="utf-8").write(svg)
open("uc.html","w",encoding="utf-8").write("<!doctype html><html><head><meta charset='utf-8'><style>*{margin:0;padding:0}</style></head><body>"+svg+"</body></html>")
open("dim.txt","w").write(f"{total_w} {total_h}")
print("SVG",total_w,"x",total_h)
