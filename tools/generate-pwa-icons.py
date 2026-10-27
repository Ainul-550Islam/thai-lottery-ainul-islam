#!/usr/bin/env python3
"""
Generate the PWA icon set and favicon for ThaiLotto (C4).

The icons are committed to public/icons/, but they are binary, so this script
is kept as their source of truth: run it to regenerate them byte-for-byte
identical (same Pillow version) or to re-brand them after a palette change.

    python3 tools/generate-pwa-icons.py

Design
------
A gold lottery ball on the near-black "ink" background, matching the brand
palette centralised in step C3. The ball carries the classic horizontal number
band so the mark still reads as a lottery ball when scaled down to 16px.

Maskable variants are full-bleed and keep every meaningful pixel inside a
centred circle of 80% of the canvas — the safe zone Chrome documents — so no
OS mask can clip the mark.

Requires: Pillow
"""

from PIL import Image, ImageDraw, ImageFilter
import math
import os

# ── brand palette (identical to the C3 tokens) ──────────────────────────────
INK_TOP = (0x1C, 0x17, 0x0E)   # #1C170E  --tl-ink-450
INK_BOT = (0x0B, 0x09, 0x04)   # #0B0904  --tl-ink-100
GOLD_HI = (0xFF, 0xF6, 0xD6)   # #FFF6D6  --tl-gold-100
GOLD    = (0xD4, 0xAF, 0x37)   # #D4AF37  --tl-gold
GOLD_DK = (0xB8, 0x86, 0x0B)   # #B8860B  --tl-gold-600

SS = 4   # supersample factor: draw large, downsample with LANCZOS


def lerp(a, b, t):
    t = max(0.0, min(1.0, t))
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def vgrad(size, top, bot):
    """Vertical gradient as an RGBA image at supersampled resolution."""
    S = size * SS
    img = Image.new('RGBA', (S, S))
    d = ImageDraw.Draw(img)
    for y in range(S):
        d.line([(0, y), (S, y)], fill=lerp(top, bot, y / (S - 1)) + (255,))
    return img


def sphere(d, cx, cy, r, steps=180):
    """
    Draw a gold sphere: concentric shading (dark rim -> pure gold centre) and
    a specular highlight offset toward the top-left to imply a light source.
    Returns the highlight layer and where to paste it.
    """
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=GOLD + (255,))
    for i in range(steps, 0, -1):
        t = i / steps                       # 1 = rim, 0 = centre
        rad = r * t
        col = lerp(GOLD, GOLD_DK, t ** 1.5)
        d.ellipse([cx - rad, cy - rad, cx + rad, cy + rad], fill=col + (255,))

    hl_size = int(r * 2.4)
    hl = Image.new('RGBA', (hl_size, hl_size), (0, 0, 0, 0))
    hd = ImageDraw.Draw(hl)
    mx = my = hl_size / 2
    for i in range(60, 0, -1):
        t = i / 60
        rad = (hl_size / 2) * t
        hd.ellipse([mx - rad, my - rad, mx + rad, my + rad],
                   fill=GOLD_HI + (int(150 * (1 - t) ** 1.4),))
    pos = (int(cx - r * 0.34 - hl_size / 2), int(cy - r * 0.38 - hl_size / 2))
    return hl, pos


def make_icon(size, maskable=False, rounded=True):
    S = size * SS
    grad = vgrad(size, INK_TOP, INK_BOT)

    if maskable:
        # Full bleed: the OS may crop to any shape, so the background has to
        # reach every edge or the mask reveals transparent gaps.
        base = grad
    else:
        shape = Image.new('L', (S, S), 0)
        sd = ImageDraw.Draw(shape)
        pad = int(S * 0.045)
        r0 = int((S - 2 * pad) * 0.22)
        sd.rounded_rectangle([pad, pad, S - pad, S - pad], radius=r0, fill=255)
        base = Image.new('RGBA', (S, S), (0, 0, 0, 0))
        base.paste(grad, (0, 0), shape)

    d = ImageDraw.Draw(base)
    cx = cy = S / 2
    # 0.62 keeps the mark inside the 80%-diameter safe circle when maskable.
    R = S * (0.62 if maskable else 0.72) / 2

    # gold ring
    d.ellipse([cx - R, cy - R, cx + R, cy + R],
              outline=GOLD_DK + (255,), width=max(int(S * 0.022), SS))

    # the ball
    br = R * 0.74
    hl, hpos = sphere(d, cx, cy, br)
    hl = hl.filter(ImageFilter.GaussianBlur(br * 0.10))
    base.alpha_composite(hl, hpos)

    # the number band
    band_h = br * 0.52
    half = math.sqrt(max(0.0, br * br - (band_h / 2) ** 2))
    d.rounded_rectangle([cx - half, cy - band_h / 2, cx + half, cy + band_h / 2],
                        radius=band_h / 2, fill=INK_BOT + (255,))

    return base.resize((size, size), Image.LANCZOS)


def make_favicon(size):
    """
    Favicons are circular: at 16px a rounded square loses its corners to
    antialiasing and reads as a blob, whereas a disc stays clean.
    """
    S = size * SS
    grad = Image.new('RGBA', (S, S))
    gd = ImageDraw.Draw(grad)
    for y in range(S):
        gd.line([(0, y), (S, y)], fill=lerp(INK_TOP, INK_BOT, y / (S - 1)) + (255,))

    shape = Image.new('L', (S, S), 0)
    ImageDraw.Draw(shape).ellipse([0, 0, S, S], fill=255)

    img = Image.new('RGBA', (S, S), (0, 0, 0, 0))
    img.paste(grad, (0, 0), shape)

    d = ImageDraw.Draw(img)
    cx = cy = S / 2
    R = S * 0.66 / 2
    d.ellipse([cx - R, cy - R, cx + R, cy + R], fill=GOLD + (255,))
    for i in range(120, 0, -1):
        t = i / 120
        rad = R * t
        d.ellipse([cx - rad, cy - rad, cx + rad, cy + rad],
                  fill=lerp(GOLD, GOLD_DK, t ** 1.5) + (255,))
    bh = R * 0.50
    half = math.sqrt(max(0.0, R * R - (bh / 2) ** 2))
    d.rounded_rectangle([cx - half, cy - bh / 2, cx + half, cy + bh / 2],
                        radius=bh / 2, fill=INK_BOT + (255,))
    return img.resize((size, size), Image.LANCZOS)


TARGETS = [
    ('icon-192.png',          192, False),
    ('icon-512.png',          512, False),
    ('icon-maskable-192.png', 192, True),
    ('icon-maskable-512.png', 512, True),
    ('apple-touch-icon.png',  180, True),   # iOS applies its own mask
    ('favicon-32.png',         32, True),
    ('favicon-16.png',         16, True),
]


def main():
    out = os.path.join(os.path.dirname(__file__), '..', 'public', 'icons')
    out = os.path.normpath(out)
    os.makedirs(out, exist_ok=True)

    for name, size, maskable in TARGETS:
        img = make_icon(size, maskable=maskable)
        p = os.path.join(out, name)
        img.save(p, 'PNG', optimize=True)
        print(f'  {name:<24} {size}x{size}  {os.path.getsize(p):>6} B')

    sizes = [16, 32, 48]
    imgs = [make_favicon(s) for s in sizes]
    ico = os.path.normpath(os.path.join(out, '..', 'favicon.ico'))
    imgs[0].save(ico, format='ICO', sizes=[(s, s) for s in sizes],
                 append_images=imgs[1:])
    print(f'  {"favicon.ico":<24} {sizes}      {os.path.getsize(ico):>6} B')


if __name__ == '__main__':
    main()
