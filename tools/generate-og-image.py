#!/usr/bin/env python3
"""
Generate the Open Graph / social preview card for ThaiLotto (D1).

    python3 tools/generate-og-image.py

Why this file exists
--------------------
A 2026 code audit found ZERO og:image and ZERO twitter:* tags across all 204
Blade views. Sharing any link on LINE — the primary acquisition channel in
Thailand — produced a blank grey box, because LINE, Facebook, WhatsApp and
X/Twitter all build the preview from these tags and have nothing to build from.

This script writes public/images/og-default.png at 1200x630, the size every
major crawler expects (Facebook and LinkedIn document 1200x630; X/Twitter
renders it as a summary_large_image at a 1.91:1 crop, which 1200x630 is).

The card is committed to the repo, but it is binary, so this script is its
source of truth: re-run it after a palette change, or to re-render at a
different size, instead of editing the PNG by hand.

Design
------
Same language as the PWA icons in tools/generate-pwa-icons.py — a gold lottery
ball on the near-black "ink" field — so the mark a visitor sees in the browser
chrome, on the home screen and in a shared link is always the same object.

Text is deliberately kept off the outer 64px. Some platforms crop the card
aggressively and some overlay their own play button or domain chip, so the
safe area is the centred 1072x502 region.

Fonts
-----
The brand faces (Outfit for the wordmark, Plus Jakarta Sans for body) are
webfonts and are NOT installed here, so this renders with DejaVu. That is a
faithful layout but not the final typography. Drop the brand TTFs next to this
script (or pass --brand-font / --body-font) and re-run to get the real thing:

    python3 tools/generate-og-image.py \
        --brand-font ./Outfit-Bold.ttf \
        --body-font  ./PlusJakartaSans-Regular.ttf

Requires: Pillow
"""

from PIL import Image, ImageDraw, ImageFilter, ImageFont
import argparse
import os

# ── brand palette (identical to the C3 tokens and the icon generator) ─────────
INK_TOP = (0x1C, 0x17, 0x0E)   # #1C170E  --tl-ink-450
INK_BOT = (0x0B, 0x09, 0x04)   # #0B0904  --tl-ink-100
GOLD_HI = (0xFF, 0xF6, 0xD6)   # #FFF6D6  --tl-gold-100
GOLD    = (0xD4, 0xAF, 0x37)   # #D4AF37  --tl-gold
GOLD_DK = (0xB8, 0x86, 0x0B)   # #B8860B  --tl-gold-600

SS = 3   # supersample factor: draw large, downsample with LANCZOS

W, H = 1200, 630

# Font candidates, first hit wins. The brand faces are webfonts shipped to the
# browser; they are only present here if you put them there.
BRAND_CANDIDATES = [
    'Outfit-Bold.ttf', 'Outfit-SemiBold.ttf', 'Outfit-Regular.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
]
BODY_CANDIDATES = [
    'PlusJakartaSans-Regular.ttf', 'PlusJakartaSans-Medium.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
]


def pick(explicit, candidates):
    if explicit:
        if not os.path.exists(explicit):
            raise SystemExit('font not found: ' + explicit)
        return explicit
    for c in candidates:
        if os.path.exists(c):
            return c
    return None


def lerp(a, b, t):
    t = max(0.0, min(1.0, t))
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def vgrad(w, h, top, bot):
    """Vertical gradient at supersampled resolution."""
    S = (w * SS, h * SS)
    img = Image.new('RGBA', S)
    d = ImageDraw.Draw(img)
    for y in range(S[1]):
        d.line([(0, y), (S[0], y)], fill=lerp(top, bot, y / (S[1] - 1)) + (255,))
    return img


def ball(d, cx, cy, r, steps=200):
    """
    Gold lottery sphere: concentric shading from the dark rim to a pure-gold
    centre, plus a specular highlight offset to the top-left and the classic
    horizontal number band.
    """
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=GOLD + (255,))

    for i in range(steps, 0, -1):
        t = i / steps
        rr = r * t
        col = lerp(GOLD_DK, GOLD, t ** 0.6)
        d.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], outline=col + (255,), width=max(1, int(r / steps) + 2))

    # number band: a dark equatorial stripe, the detail that makes the mark
    # read as a lottery ball at any size
    band_h = r * 0.34
    d.rectangle([cx - r, cy - band_h / 2, cx + r, cy + band_h / 2], fill=INK_BOT + (255,))

    # specular highlight, top-left
    hl = Image.new('RGBA', d.im.size, (0, 0, 0, 0))
    hd = ImageDraw.Draw(hl)
    hx, hy, hr = cx - r * 0.34, cy - r * 0.38, r * 0.30
    for i in range(60, 0, -1):
        t = i / 60
        hd.ellipse([hx - hr * t, hy - hr * t, hx + hr * t, hy + hr * t],
                   fill=GOLD_HI + (int(190 * (1 - t) ** 1.4),))
    return hl


def ring(d, cx, cy, r, colour, width):
    d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=colour + (255,), width=width)


def text_w(draw, s, font):
    b = draw.textbbox((0, 0), s, font=font)
    return b[2] - b[0], b[3] - b[1]


def build(brand_path, body_path, out_path):
    img = vgrad(W, H, INK_TOP, INK_BOT)
    d = ImageDraw.Draw(img)

    # soft gold glow behind the ball, so the mark sits in light not on flat ink
    glow = Image.new('RGBA', (W * SS, H * SS), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    bcx, bcy, br = int(0.215 * W * SS), int(0.50 * H * SS), int(0.205 * H * SS)
    for i in range(120, 0, -1):
        t = i / 120
        gd.ellipse([bcx - br * 2.4 * t, bcy - br * 2.4 * t, bcx + br * 2.4 * t, bcy + br * 2.4 * t],
                   fill=GOLD + (int(26 * (1 - t) ** 2),))
    glow = glow.filter(ImageFilter.GaussianBlur(radius=60 * SS / 4))
    img = Image.alpha_composite(img, glow)
    d = ImageDraw.Draw(img)

    # hairline gold rings, echoing the icon's outer ring
    ring(d, bcx, bcy, int(br * 1.30), tuple(lerp(GOLD_DK, INK_TOP, 0.35)), max(1, int(3 * SS)))
    ring(d, bcx, bcy, int(br * 1.44), tuple(lerp(GOLD_DK, INK_TOP, 0.72)), max(1, int(2 * SS)))

    # the ball
    hl = ball(d, bcx, bcy, br)
    img = Image.alpha_composite(img, hl)
    d = ImageDraw.Draw(img)

    # ── text block, inside the safe area ( centred 1072x502 ) ────────────────
    brand_font = ImageFont.truetype(brand_path, int(96 * SS)) if brand_path else None
    body_font = ImageFont.truetype(body_path, int(34 * SS)) if body_path else None
    small_font = ImageFont.truetype(body_path, int(24 * SS)) if body_path else None

    tx = int(0.455 * W * SS)
    if brand_font:
        word = 'ThaiLotto'
        d.text((tx, int(0.335 * H * SS)), word, font=brand_font, fill=GOLD_HI + (255,), anchor='lm')

        # gold rule under the wordmark, the width of the word itself
        ww, _ = text_w(d, word, brand_font)
        ry = int(0.335 * H * SS) + int(58 * SS)
        d.line([(tx, ry), (tx + ww, ry)], fill=GOLD + (255,), width=max(1, int(3 * SS)))

    if body_font:
        d.text((tx, int(0.575 * H * SS)),
               'Thai lottery results, live draws',
               font=body_font, fill=(0xCB, 0xD5, 0xE1, 255), anchor='lm')
        d.text((tx, int(0.665 * H * SS)),
               'and prize checks in one place.',
               font=body_font, fill=(0xCB, 0xD5, 0xE1, 255), anchor='lm')

    if small_font:
        d.text((tx, int(0.815 * H * SS)),
               'thailotto.example',
               font=small_font, fill=GOLD_DK + (255,), anchor='lm')

    img = img.resize((W, H), Image.LANCZOS)

    os.makedirs(os.path.dirname(out_path) or '.', exist_ok=True)
    img.convert('RGB').save(out_path, 'PNG', optimize=True)
    return out_path


def main():
    ap = argparse.ArgumentParser(description='Generate the ThaiLotto OG/social preview card.')
    ap.add_argument('--brand-font', help='TTF for the wordmark (default: best available)')
    ap.add_argument('--body-font', help='TTF for body text (default: best available)')
    ap.add_argument('--out', default='public/images/og-default.png')
    args = ap.parse_args()

    brand = pick(args.brand_font, BRAND_CANDIDATES)
    body = pick(args.body_font, BODY_CANDIDATES)

    p = build(brand, body, args.out)
    size = os.path.getsize(p)
    print('wrote %s  (%d x %d, %d bytes)' % (p, W, H, size))
    print('  brand font: %s' % (os.path.basename(brand) if brand else 'none'))
    print('  body font : %s' % (os.path.basename(body) if body else 'none'))
    if brand and 'DejaVu' in brand:
        print('  note: brand webfont not present; re-run with --brand-font for the real typography.')


if __name__ == '__main__':
    main()
