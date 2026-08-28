import zipfile
import xml.etree.ElementTree as ET

path = r"C:\Users\Rey\Desktop\business.docx"
namespaces = {
    "w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main",
}

with zipfile.ZipFile(path) as archive:
    root = ET.fromstring(archive.read("word/document.xml"))
    paragraphs = root.findall(".//w:body/w:p", namespaces)
    media = [name for name in archive.namelist() if name.startswith("word/media/")]
    print(f"paragraphs={len(paragraphs)} media={len(media)}")
    for index, paragraph in enumerate(paragraphs, 1):
        text = "".join(node.text or "" for node in paragraph.findall(".//w:t", namespaces)).strip()
        page_break = bool(paragraph.findall('.//w:br[@w:type="page"]', namespaces))
        image_count = len(paragraph.findall(".//w:drawing", namespaces))
        if text or page_break or image_count:
            print(index, "PAGE_BREAK" if page_break else "", repr(text), f"images={image_count}")
