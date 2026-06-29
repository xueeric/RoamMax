#!/usr/bin/env python3
"""Generate RoamMax favicons and social PNG assets from the brand mark."""

from __future__ import annotations

import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

REPO_ROOT = Path(__file__).resolve().parents[2]
PUBLIC = REPO_ROOT / "website" / "public"
BRAND_DIR = PUBLIC / "assets" / "brand"

BLACK = (0, 0, 0)
WHITE = (255, 255, 255)
CANVAS = (249, 250, 251)  # #f9fafb
MUTED = (156, 163, 175)  # #9ca3af


def draw_mark(size: int, padding_ratio: float = 0.0) -> Image.Image:
    """Render the orbital brand mark on a transparent square canvas."""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    inset = int(size * padding_ratio)
    diameter = size - (inset * 2)
    x0 = inset
    y0 = inset
    x1 = x0 + diameter
    y1 = y0 + diameter

    draw.ellipse((x0, y0, x1, y1), fill=BLACK + (255,))

    line_length = diameter * 0.5625
    line_thickness = max(2, round(diameter * 0.0625))
    cx = size / 2
    cy = size / 2
    half = line_length / 2
    angle = math.radians(45)
    dx = half * math.cos(angle)
    dy = half * math.sin(angle)
    draw.line(
        (cx - dx, cy - dy, cx + dx, cy + dy),
        fill=WHITE + (255,),
        width=line_thickness,
    )
    return img


def paste_center(base: Image.Image, overlay: Image.Image, box: tuple[int, int, int, int]) -> None:
    x0, y0, x1, y1 = box
    target_w = x1 - x0
    target_h = y1 - y0
    resized = overlay.resize((target_w, target_h), Image.Resampling.LANCZOS)
    base.paste(resized, (x0, y0), resized)


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "/System/Library/Fonts/Supplemental/Avenir Next.ttc",
        "/System/Library/Fonts/Supplemental/Avenir Next Bold.ttf",
        "/System/Library/Fonts/Helvetica.ttc",
        "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
        "/Library/Fonts/Arial Bold.ttf",
    ]
    if bold:
        candidates = candidates[1:2] + candidates
    for path in candidates:
        if Path(path).exists():
            try:
                return ImageFont.truetype(path, size=size, index=1 if bold and path.endswith(".ttc") else 0)
            except OSError:
                continue
    return ImageFont.load_default()


def load_mono_font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "/System/Library/Fonts/Menlo.ttc",
        "/System/Library/Fonts/Supplemental/Courier New.ttf",
    ]
    for path in candidates:
        if Path(path).exists():
            try:
                return ImageFont.truetype(path, size=size)
            except OSError:
                continue
    return ImageFont.load_default()


def save_png(img: Image.Image, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if img.mode != "RGBA":
        img = img.convert("RGBA")
    img.save(path, format="PNG", optimize=True)
    print(f"wrote {path.relative_to(REPO_ROOT)}")


def save_square_mark(size: int, filename: str, padding_ratio: float = 0.0) -> None:
    save_png(draw_mark(size, padding_ratio), BRAND_DIR / filename)


def save_social_profile(size: int, filename: str) -> None:
    """Square profile image with mark centered on brand canvas."""
    img = Image.new("RGBA", (size, size), CANVAS + (255,))
    mark_size = int(size * 0.62)
    mark = draw_mark(mark_size, padding_ratio=0.0)
    offset = (size - mark_size) // 2
    img.paste(mark, (offset, offset), mark)
    save_png(img, BRAND_DIR / filename)


def save_social_og(width: int, height: int, filename: str) -> None:
    """Open Graph / Facebook link preview card."""
    img = Image.new("RGBA", (width, height), CANVAS + (255,))
    draw = ImageDraw.Draw(img)

    mark_size = int(min(width, height) * 0.34)
    mark = draw_mark(mark_size)
    mark_x = int(width * 0.08)
    mark_y = (height - mark_size) // 2
    img.paste(mark, (mark_x, mark_y), mark)

    text_x = mark_x + mark_size + int(width * 0.05)
    title_font = load_font(int(height * 0.17), bold=True)
    domain_font = load_mono_font(int(height * 0.055))
    tagline_font = load_font(int(height * 0.065))

    draw.text((text_x, int(height * 0.28)), "ROAMMAX", fill=BLACK + (255,), font=title_font)
    draw.text((text_x, int(height * 0.52)), "roammax.ca", fill=MUTED + (255,), font=domain_font)
    draw.text(
        (text_x, int(height * 0.66)),
        "Starlink hardware rental",
        fill=BLACK + (255,),
        font=tagline_font,
    )

    save_png(img, BRAND_DIR / filename)


def save_social_banner(width: int, height: int, filename: str) -> None:
    """Wide banner for Facebook page cover and similar."""
    img = Image.new("RGBA", (width, height), CANVAS + (255,))
    draw = ImageDraw.Draw(img)

    mark_size = int(height * 0.58)
    mark = draw_mark(mark_size)
    mark_x = int(width * 0.06)
    mark_y = (height - mark_size) // 2
    img.paste(mark, (mark_x, mark_y), mark)

    text_x = mark_x + mark_size + int(width * 0.04)
    title_font = load_font(int(height * 0.28), bold=True)
    domain_font = load_mono_font(int(height * 0.09))

    draw.text((text_x, int(height * 0.24)), "ROAMMAX", fill=BLACK + (255,), font=title_font)
    draw.text((text_x, int(height * 0.58)), "roammax.ca", fill=MUTED + (255,), font=domain_font)

    save_png(img, BRAND_DIR / filename)


def save_favicon_ico() -> None:
    sizes = [16, 32, 48]
    images = [draw_mark(size).convert("RGBA") for size in sizes]
    ico_path = PUBLIC / "favicon.ico"
    images[0].save(
        ico_path,
        format="ICO",
        sizes=[(size, size) for size in sizes],
        append_images=images[1:],
    )
    print(f"wrote {ico_path.relative_to(REPO_ROOT)}")


def main() -> None:
    BRAND_DIR.mkdir(parents=True, exist_ok=True)

    save_square_mark(16, "favicon-16x16.png")
    save_square_mark(32, "favicon-32x32.png")
    save_square_mark(48, "favicon-48x48.png")
    save_square_mark(180, "apple-touch-icon.png")
    save_square_mark(192, "icon-192x192.png")
    save_square_mark(512, "icon-512x512.png")

    save_social_profile(400, "social-profile-400.png")
    save_social_profile(512, "social-profile-512.png")
    save_social_og(1200, 630, "social-og-1200x630.png")
    save_social_banner(820, 312, "social-banner-820x312.png")

    save_favicon_ico()

    # Root-level copies for common web conventions.
    for src_name, dest_name in [
        ("apple-touch-icon.png", PUBLIC / "apple-touch-icon.png"),
        ("favicon-32x32.png", PUBLIC / "favicon-32x32.png"),
        ("favicon-16x16.png", PUBLIC / "favicon-16x16.png"),
    ]:
        src = BRAND_DIR / src_name
        dest = dest_name
        dest.write_bytes(src.read_bytes())
        print(f"wrote {dest.relative_to(REPO_ROOT)}")


if __name__ == "__main__":
    main()
