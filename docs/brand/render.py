"""Render the FieldPilot brand images from the sources in this folder.

The logo is the original FieldPilot artwork (icon-source.gif, 24 frames) with its
colours shifted to the brand palette: emerald becomes lime, sky becomes cyan. Nothing
is redrawn. The banners and the social preview are HTML rendered with Playwright.

Needs Python 3, Pillow and Playwright:
    pip install pillow playwright
    python docs/brand/render.py

Writes .wordpress-org/icon-256x256.gif, icon-128x128.gif, banner-772x250.jpg and
banner-1544x500.jpg, docs/social-preview-1280x640.jpg, docs/fieldpilot-logo.gif,
docs/brand/icon.png and assets/images/fieldpilot-icon.png.
"""

import colorsys
import io
import pathlib

from PIL import Image, ImageSequence
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent
WPORG = ROOT / ".wordpress-org"
WPORG.mkdir(exist_ok=True)

LIME_HUE, CYAN_HUE = 78 / 360, 181 / 360


def recolor_pixels(rgba):
    """Shift the emerald and sky hues of a frame to lime and cyan; leave the rest."""
    px = rgba.load()
    w, h = rgba.size
    cache = {}
    for y in range(h):
        for x in range(w):
            p = px[x, y]
            if p not in cache:
                r, g, b, a = p
                hue, s, v = colorsys.rgb_to_hsv(r / 255, g / 255, b / 255)
                deg = hue * 360
                if s > 0.25 and v > 0.25:
                    if 120 <= deg < 172:
                        hue, s = LIME_HUE, min(1.0, s * 1.1)
                    elif 172 <= deg <= 215:
                        hue = CYAN_HUE
                rr, gg, bb = colorsys.hsv_to_rgb(hue, s, v)
                cache[p] = (int(rr * 255), int(gg * 255), int(bb * 255), a)
            px[x, y] = cache[p]
    return rgba


def to_gif_frames(frames):
    """Palette frames sharing one 255 colour palette, index 255 kept for transparency."""
    shared = frames[-1].convert("RGB").quantize(colors=255, method=Image.Quantize.MEDIANCUT)
    pal = shared.getpalette()[: 255 * 3] + [0, 0, 0]
    out = []
    for f in frames:
        q = f.convert("RGB").quantize(palette=shared, dither=Image.Dither.NONE)
        q.putpalette(pal)
        clear = f.getchannel("A").point(lambda a: 255 if a == 0 else 0)
        q.paste(255, mask=clear)
        out.append(q)
    return out


def save_gif(frames, durations, path):
    frames[0].save(path, save_all=True, append_images=frames[1:], duration=durations, loop=0,
                   disposal=2, transparency=255, optimize=False)
    print("wrote", path.relative_to(ROOT), f"({path.stat().st_size // 1024} KB, {len(frames)} frames)")


def logo():
    """The original 24 frame GIF, recoloured pixel for pixel. Nothing is redrawn or resampled at 256."""
    src = Image.open(HERE / "icon-source.gif")
    durations = [f.info.get("duration", 70) for f in ImageSequence.Iterator(src)]
    frames = [recolor_pixels(f.convert("RGBA")) for f in ImageSequence.Iterator(Image.open(HERE / "icon-source.gif"))]
    save_gif(to_gif_frames(frames), durations, WPORG / "icon-256x256.gif")
    save_gif(to_gif_frames(frames), durations, ROOT / "docs" / "fieldpilot-logo.gif")
    small = [f.resize((128, 128), Image.LANCZOS) for f in frames]
    save_gif(to_gif_frames(small), durations, WPORG / "icon-128x128.gif")
    still = frames[-1]
    still.save(HERE / "icon.png", "PNG", optimize=True)
    still.resize((64, 64), Image.LANCZOS).save(ROOT / "assets" / "images" / "fieldpilot-icon.png", "PNG", optimize=True)
    print("wrote docs/brand/icon.png and assets/images/fieldpilot-icon.png")


def shoot(page, url, width, height):
    page.set_viewport_size({"width": width, "height": height})
    page.goto(url)
    page.wait_for_load_state("networkidle")
    page.evaluate("document.fonts.ready")
    page.wait_for_timeout(300)
    return Image.open(io.BytesIO(page.screenshot(type="png")))


def save_jpg(img, path):
    img.convert("RGB").save(path, "JPEG", quality=88, optimize=True, progressive=True)
    print("wrote", path.relative_to(ROOT))


logo()

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--force-color-profile=srgb"])
    for scale, name in ((1, "banner-772x250.jpg"), (2, "banner-1544x500.jpg")):
        page = browser.new_page(device_scale_factor=scale)
        save_jpg(shoot(page, (HERE / "banner.html").as_uri(), 772, 250), WPORG / name)
        page.close()
    page = browser.new_page(device_scale_factor=1)
    save_jpg(shoot(page, (HERE / "social.html").as_uri(), 1280, 640), ROOT / "docs" / "social-preview-1280x640.jpg")
    page.close()
    browser.close()
