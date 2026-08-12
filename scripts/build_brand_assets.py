from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
BRANDING = ROOT / "public" / "assets" / "branding"
# Official Binhi Rural Bank website asset: https://www.binhiruralbank.com/
SOURCE = BRANDING / "binhi-official-cloud-source.png"


def crop_transparent_padding(image: Image.Image) -> Image.Image:
    result = image.convert("RGBA")
    bounds = result.getchannel("A").getbbox()

    return result.crop(bounds) if bounds else result


def create_light_wordmark(wordmark: Image.Image) -> Image.Image:
    result = wordmark.copy()
    pixels = result.load()
    leaf_boundary = 162

    for y in range(result.height):
        for x in range(result.width):
            red, green, blue, alpha = pixels[x, y]
            if alpha and (x >= leaf_boundary or max(red, green, blue) <= 32):
                pixels[x, y] = (255, 255, 255, alpha)

    return result


def create_leaf_icon(wordmark: Image.Image) -> Image.Image:
    leaf_boundary = 162
    leaf = wordmark.crop((0, 0, leaf_boundary, wordmark.height))
    pixels = leaf.load()

    for y in range(leaf.height):
        for x in range(leaf.width):
            red, green, blue, alpha = pixels[x, y]
            if alpha and max(red, green, blue) <= 32:
                pixels[x, y] = (0, 0, 0, 0)

    bounds = leaf.getchannel("A").getbbox()
    if bounds:
        leaf = leaf.crop(bounds)

    leaf.thumbnail((96, 108), Image.Resampling.LANCZOS)
    icon = Image.new("RGBA", (128, 128), (255, 255, 255, 0))
    icon.alpha_composite(leaf, ((128 - leaf.width) // 2, (128 - leaf.height) // 2))

    return icon


def main() -> None:
    BRANDING.mkdir(parents=True, exist_ok=True)
    wordmark = crop_transparent_padding(Image.open(SOURCE))
    light_wordmark = create_light_wordmark(wordmark)
    leaf = create_leaf_icon(wordmark)

    wordmark.save(BRANDING / "binhi-rural-bank-wordmark.png", optimize=True)
    light_wordmark.save(BRANDING / "binhi-rural-bank-wordmark-light.png", optimize=True)
    leaf.save(BRANDING / "favicon-leaf-128x128.png", optimize=True)
    leaf.resize((32, 32), Image.Resampling.LANCZOS).save(BRANDING / "favicon-leaf-32x32.png", optimize=True)
    leaf.resize((16, 16), Image.Resampling.LANCZOS).save(BRANDING / "favicon-leaf-16x16.png", optimize=True)
    leaf.save(ROOT / "public" / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])


if __name__ == "__main__":
    main()
