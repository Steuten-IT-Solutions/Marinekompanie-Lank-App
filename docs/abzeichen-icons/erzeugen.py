"""Erzeugt die stilisierten Abzeichen-SVGs der Dienstgrade (viewBox 0 0 64 80).

Aufruf: python3 erzeugen.py <Zielordner>   (z. B. python3 docs/abzeichen-icons/erzeugen.py docs/abzeichen-icons)

Alle Icons nutzen dieselben Farben und Maße (siehe Konstanten). Erlaubt sind nur Elemente und Attribute,
die der SvgSanitizer des Kerns unverändert durchlässt: keine Skripte, keine Verweise nach außen, keine Rasterbilder,
keine Kommentare.
"""
import math
import os
import sys

OUT = sys.argv[1]

NAVY = '#14234a'
GOLD = '#e8b931'
STRIPE = '#8597c4'
EDGE = '#dfe6f2'   # heller Rand: macht die dunkle Grundfläche im Dunkelmodus sichtbar
EW = 1.5           # Randbreite
W = 4.5            # Breite eines Goldbands
G = 2              # Abstand Rand -> Band, Band -> Band
S = 2              # Breite des hellblauen Mittelstreifens bei Doppelbändern
TAN = math.tan(math.radians(40))  # Neigung der Winkel bei Portepee/Bootsmann/Fähnrich


def f(v):
    s = f'{v:.2f}'.rstrip('0').rstrip('.')
    return '0' if s == '-0' else s


def pts(ps):
    return ' '.join(f'{f(x)},{f(y)}' for x, y in ps)


# --- Geometrie: Gerade = (Punkt, Richtung) ------------------------------------------
def line(p, q):
    return (p, (q[0] - p[0], q[1] - p[1]))


def off(l, d):
    """Parallelverschiebung um d nach links (bezogen auf die Richtung)."""
    (px, py), (dx, dy) = l
    n = math.hypot(dx, dy)
    nx, ny = dy / n, -dx / n  # linke Normale im SVG-Koordinatensystem (y nach unten)
    return ((px + nx * d, py + ny * d), (dx, dy))


def X(a, b):
    (p, r), (q, s) = a, b
    den = r[0] * s[1] - r[1] * s[0]
    t = ((q[0] - p[0]) * s[1] - (q[1] - p[1]) * s[0]) / den
    return (p[0] + t * r[0], p[1] + t * r[1])


def band(e1, e2, d1, d2, c1, c2):
    """Winkel-Band zwischen den Abständen d1..d2 von den Kanten e1/e2, abgeschnitten an c1/c2."""
    a1, a2 = off(e1, d1), off(e2, d1)
    b1, b2 = off(e1, d2), off(e2, d2)
    return [X(a1, c1), X(a1, a2), X(a2, c2), X(b2, c2), X(b1, b2), X(b1, c1)]


def inset(poly, d):
    """Polygon (gegen den Uhrzeigersinn im Bild = links innen) um d nach innen versetzen."""
    n = len(poly)
    ls = [off(line(poly[i], poly[(i + 1) % n]), d) for i in range(n)]
    return [X(ls[i - 1], ls[i]) for i in range(n)]


def vline(x):
    return ((x, 0), (0, 1))


def hline(y):
    return ((0, y), (1, 0))


# --- Bausteine -----------------------------------------------------------------------
def base(poly):
    return f'<polygon points="{pts(poly)}" fill="{NAVY}" stroke="{EDGE}" stroke-width="{EW}" stroke-linejoin="round"/>'


def outline(poly):
    return f'<polygon points="{pts(poly)}" fill="none" stroke="{EDGE}" stroke-width="{EW}" stroke-linejoin="round"/>'


def gold(poly):
    return f'<polygon points="{pts(poly)}" fill="{GOLD}"/>'


def stripe(poly):
    return f'<polygon points="{pts(poly)}" fill="{STRIPE}"/>'


def star(cx, cy, r, ri=None, n=5, rot=-90, color=GOLD):
    ri = ri or r * 0.42
    ps = []
    for i in range(2 * n):
        a = math.radians(rot + i * 180 / n)
        rr = r if i % 2 == 0 else ri
        ps.append((cx + rr * math.cos(a), cy + rr * math.sin(a)))
    return f'<polygon points="{pts(ps)}" fill="{color}"/>'


def star_patch(cx, cy, r=10):
    return (f'<circle cx="{f(cx)}" cy="{f(cy)}" r="{f(r)}" fill="{NAVY}" stroke="{EDGE}" stroke-width="{EW}"/>'
            + star(cx, cy + r * 0.06, r * 0.66))


def rosette(cx, cy, r):
    return (star(cx, cy, r, r * 0.55, n=12, rot=-90)
            + f'<circle cx="{f(cx)}" cy="{f(cy)}" r="{f(r * 0.32)}" fill="{NAVY}"/>'
            + f'<circle cx="{f(cx)}" cy="{f(cy)}" r="{f(r * 0.16)}" fill="{GOLD}"/>')


# Dreieck (Gefreite) -------------------------------------------------------------------
def triangle_badge(n_v, rosette_on=False):
    tl, tr, b = (6, 13), (58, 13), (32, 67)
    poly = [tl, b, tr]  # gegen den Uhrzeigersinn im Bild -> links = innen
    e1, e2 = line(tl, b), line(b, tr)
    top = hline(13 + 0.01)
    out = [base(poly)]
    d = G
    for _ in range(n_v):
        out.append(gold(band(e1, e2, d, d + W, top, top)))
        d += W + G
    if rosette_on:
        out.append(rosette(32, 20, 6.2))
    return out + [outline(poly)]


# Sechseck (Maate) ---------------------------------------------------------------------
HEX = [(32, 4), (4, 30), (4, 50), (32, 76), (60, 50), (60, 30)]


def hex_badge(double_top=False, star_on=False):
    out = [base(HEX)]
    o, i = inset(HEX, G), inset(HEX, G + W)
    out.append(f'<path d="M{pts(o)}Z M{pts(i)}Z" fill="{GOLD}" fill-rule="evenodd"/>')
    if double_top:
        e1, e2 = line(HEX[0], HEX[1]), line(HEX[5], HEX[0])  # links der Richtung = innen
        c1, c2 = vline(4 + G + W), vline(60 - G - W)
        out.append(stripe(band(e2, e1, G + W, G + W + S, c2, c1)))
        out.append(gold(band(e2, e1, G + W + S, G + 2 * W + S, c2, c1)))
    if star_on:
        out.append(f'<circle cx="32" cy="50" r="10" fill="none" stroke="{STRIPE}" stroke-width="1.2"/>')
        out.append(star(32, 50.6, 7.2))
    return out + [outline(HEX)]


# Winkel-Aufnäher (Portepee) ------------------------------------------------------------
def chevron_patch(cx, top, h, thick):
    """^-förmiger Aufnäher: Spitze oben, senkrechte Seiten, unten Einkerbung. thick = senkrechte Dicke."""
    rise = h * TAN
    A = (cx, top)
    L1, R1 = (cx - h, top + rise), (cx + h, top + rise)
    L2, R2 = (cx - h, top + rise + thick), (cx + h, top + rise + thick)
    I = (cx, top + thick)
    return [A, L1, L2, I, R2, R1]


def chevrons_down_from_top(poly, cx, h, specs):
    """Bänder parallel zur oberen Spitze. specs: Liste (d1, d2, art)."""
    A, L1, R1 = poly[0], poly[1], poly[5]
    e1, e2 = line(A, L1), line(R1, A)
    c1, c2 = vline(cx - h + 0.01), vline(cx + h - 0.01)
    return [(gold if k == 'g' else stripe)(band(e2, e1, d1, d2, c2, c1)) for d1, d2, k in specs]


def chevrons_up_from_notch(poly, cx, h, specs):
    """Bänder parallel zur unteren Einkerbung, nach oben versetzt."""
    L2, I, R2 = poly[2], poly[3], poly[4]
    e1, e2 = line(L2, I), line(I, R2)   # links = oberhalb
    c1, c2 = vline(cx - h + 0.01), vline(cx + h - 0.01)
    return [(gold if k == 'g' else stripe)(band(e1, e2, d1, d2, c1, c2)) for d1, d2, k in specs]


DOUBLE = [(G, G + W, 'g'), (G + W, G + W + S, 's'), (G + W + S, G + 2 * W + S, 'g')]
SINGLE = [(G, G + W, 'g')]


COS = math.cos(math.atan(TAN))


def schlaufe(cx, ty, s, cid):
    """Schlaufe: Raute (Spitze oben bei ty), die Bänder kreuzen sich unten an der Raute (X) und laufen
    im Winkel der Winkel-Bänder schräg nach außen. Gibt die Elemente und die Höhe des Kreuzungspunkts zurück."""
    k, kt = TAN, 1.25
    T = (cx, ty)
    R, L = (cx + s, ty + s * kt), (cx - s, ty + s * kt)
    Xy = ty + s * kt + s * k
    far = 30
    BL, BR = (cx - far, Xy + far * k), (cx + far, Xy + far * k)
    a = f'M{f(BL[0])},{f(BL[1])} L{f(R[0])},{f(R[1])} L{f(T[0])},{f(T[1])} L{f(L[0])},{f(L[1])} L{f(BR[0])},{f(BR[1])}'
    m = ((L[0] + cx) / 2, (L[1] + Xy) / 2)
    under = f'M{f(m[0])},{f(m[1])} L{f(BR[0])},{f(BR[1])}'
    over = f'M{f(L[0])},{f(L[1])} L{f(BR[0])},{f(BR[1])}'
    return [
        f'<g clip-path="url(#{cid})" fill="none" stroke-width="{W}" stroke-linejoin="miter" stroke-miterlimit="10">'
        f'<path d="{a}" stroke="{GOLD}"/>'
        f'<path d="{under}" stroke="{NAVY}" stroke-width="{W + 2.4}"/>'
        f'<path d="{over}" stroke="{GOLD}"/>'
        '</g>'
    ], Xy


def clip_def(cid, poly):
    return f'<defs><clipPath id="{cid}"><polygon points="{pts(poly)}"/></clipPath></defs>'


def schlaufe_rank(n_bands, star_on, top_patch=None):
    """Hauptmaat mit Portepee bis Oberfähnrich: größte Breite wählen, die in die Zeichenfläche passt."""
    h = 26.0
    while True:
        parts, bottom = schlaufe_build(n_bands, star_on, top_patch, h)
        if bottom <= 78.8 or h <= 12:
            return centered(parts, 0, bottom)
        h -= 0.5


def schlaufe_build(n_bands, star_on, top_patch, h):
    s = max(6.5, 0.4 * h)
    rise = h * TAN
    D = {0: 0, 1: G + W, 2: G + 2 * W + S}[n_bands]
    out = []
    if top_patch is not None:
        th = (2 * G + (W if top_patch == 1 else 2 * W + S)) / COS
        tp = chevron_patch(32, 0, h, th)
        top_parts = [base(tp)] + chevrons_down_from_top(tp, 32, h, SINGLE if top_patch == 1 else DOUBLE) + [outline(tp)]
        notch = th
        ty = notch + (W / 2) / COS + 1.6
        ptop = notch + 1.5 - 6
    else:
        top_parts = []
        ptop = 0
        ty = ptop + (G + W / 2) / COS + 1.2
    Xy = ty + s * 1.25 + s * TAN
    yI = Xy + (D + W / 2 + G) / COS
    poly = [(32, ptop), (32 - h, ptop + rise), (32 - h, yI + rise), (32, yI), (32 + h, yI + rise), (32 + h, ptop + rise)]
    cid = 'schlaufe'
    parts = [clip_def(cid, poly), base(poly)]
    sl, _ = schlaufe(32, ty, s, cid)
    parts += sl
    if n_bands == 1:
        parts += chevrons_up_from_notch(poly, 32, h, SINGLE)
    elif n_bands == 2:
        parts += chevrons_up_from_notch(poly, 32, h, DOUBLE)
    parts.append(outline(poly))
    parts += top_parts
    bottom = yI + rise
    if star_on:
        r = 8.5
        cy = yI + r - 0.5
        parts.append(star_patch(32, cy, r))
        bottom = max(bottom, cy + r)
    return parts, bottom


def centered(parts, top, bottom):
    dy = (80 - (bottom - top)) / 2 - top
    return [f'<g transform="translate(0 {f(dy)})">'] + parts + ['</g>']


def badge_07():
    th = (2 * G + 2 * W + S) / COS + 4
    p = chevron_patch(32, 0, 26, th)
    return centered([base(p)] + chevrons_down_from_top(p, 32, 26, DOUBLE) + [outline(p)], 0, 26 * TAN + th)


def badge_08():
    th = (2 * G + 2 * W + S) / COS + 3
    p = chevron_patch(32, 0, 24, th)
    b = 24 * TAN + th
    r = 11
    cy = b + r - 2
    return centered([base(p)] + chevrons_down_from_top(p, 32, 24, DOUBLE) + [outline(p), star_patch(32, cy, r)], 0, cy + r)


def badge_15():
    return [star_patch(32, 30, 17),
            f'<rect x="3" y="56" width="58" height="11" rx="1" fill="{GOLD}" stroke="{NAVY}" stroke-width="{EW}"/>',
            f'<path d="M5,59.5 H59 M5,63.5 H59" stroke="#b98d17" stroke-width="0.8" fill="none"/>']


def star_bars(n):
    """Wie der Leutnant (Stern-Aufnäher über goldenem Streifen), mit n Streifen (Oberleutnant 2, Kapitänleutnant 3,
    Kapitän 4). Streifen 58 breit und je 9 hoch mit zwei eingeprägten Linien, 2,5 Abstand; der Stern wird nur kleiner,
    wenn sonst der Platz nicht reicht, und das Ganze steht mittig in der Zeichenfläche."""
    h, gap, sgap = 9, 2.5, 5
    bars = n * h + (n - 1) * gap
    r = min(17, (80 - 4 - bars - sgap) / 2)
    top = (80 - (2 * r + sgap + bars)) / 2
    parts = [star_patch(32, top + r, r)]
    y = top + 2 * r + sgap
    for _ in range(n):
        parts.append(f'<rect x="3" y="{f(y)}" width="58" height="{h}" rx="1" fill="{GOLD}" stroke="{NAVY}" stroke-width="{EW}"/>')
        parts.append(f'<path d="M5,{f(y + 3)} H59 M5,{f(y + 6)} H59" stroke="#b98d17" stroke-width="0.8" fill="none"/>')
        y += h + gap
    return parts


RANKS = [
    ('01-gefreiter', 'Gefreiter', lambda: triangle_badge(1)),
    ('02-obergefreiter', 'Obergefreiter', lambda: triangle_badge(2)),
    ('03-hauptgefreiter', 'Hauptgefreiter', lambda: triangle_badge(2, True)),
    ('04-maat', 'Maat', lambda: hex_badge()),
    ('05-obermaat', 'Obermaat', lambda: hex_badge(True)),
    ('06-hauptmaat', 'Hauptmaat', lambda: hex_badge(True, True)),
    ('07-maat-mit-portepee', 'Maat mit Portepee', badge_07),
    ('08-obermaat-mit-portepee', 'Obermaat mit Portepee', badge_08),
    ('09-hauptmaat-mit-portepee', 'Hauptmaat mit Portepee', lambda: schlaufe_rank(0, False)),
    ('10-bootsmann', 'Bootsmann', lambda: schlaufe_rank(1, False)),
    ('11-oberbootsmann', 'Oberbootsmann', lambda: schlaufe_rank(2, False)),
    ('12-hauptbootsmann', 'Hauptbootsmann', lambda: schlaufe_rank(2, True)),
    ('13-faehnrich', 'Fähnrich', lambda: schlaufe_rank(2, True, 1)),
    ('14-oberfaehnrich', 'Oberfähnrich', lambda: schlaufe_rank(2, True, 2)),
    ('15-leutnant', 'Leutnant', badge_15),
    ('16-oberleutnant', 'Oberleutnant', lambda: star_bars(2)),
    ('17-kapitaenleutnant', 'Kapitänleutnant', lambda: star_bars(3)),
    ('18-kapitaen', 'Kapitän', lambda: star_bars(4)),
]

os.makedirs(OUT, exist_ok=True)
for name, title, fn in RANKS:
    body = '\n  '.join(fn())
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 80" width="64" height="80">\n'
           f'  <title>{title}</title>\n  {body}\n</svg>\n')
    with open(os.path.join(OUT, name + '.svg'), 'w', encoding='utf-8') as fh:
        fh.write(svg)
print('ok', len(RANKS))
