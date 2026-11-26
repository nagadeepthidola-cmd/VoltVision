
import sys
import os
import re
import json

import pytesseract
from PIL import Image, ImageEnhance, ImageFilter, ImageOps


# --------------------------------------------------
# TESSERACT OCR LOCATION (WINDOWS)
# --------------------------------------------------
pytesseract.pytesseract.tesseract_cmd = (
    os.getenv("TESSERACT_CMD")
    or (
        r"C:\Program Files\Tesseract-OCR\tesseract.exe"
        if os.name == "nt"
        else "/usr/bin/tesseract"
    )
)


# --------------------------------------------------
# IMAGE PREPROCESSING
# --------------------------------------------------
def preprocess_image(image):
    image = image.convert("L")

    # Upscale small bill images
    width, height = image.size
    image = image.resize((width * 2, height * 2))

    image = ImageOps.autocontrast(image)
    image = ImageEnhance.Contrast(image).enhance(2)
    image = image.filter(ImageFilter.SHARPEN)

    return image


# --------------------------------------------------
# OCR TEXT EXTRACTION
# --------------------------------------------------
def get_ocr_text(image):
    configurations = [
        "--oem 3 --psm 6",
        "--oem 3 --psm 4",
        "--oem 3 --psm 11"
    ]

    texts = []

    for config in configurations:
        try:
            text = pytesseract.image_to_string(
                image,
                config=config
            )
            texts.append(text)
        except Exception:
            continue

    return "\n".join(texts)


# --------------------------------------------------
# NORMALIZE OCR TEXT
# --------------------------------------------------
def normalize_text(text):
    text = text.replace("₹", " ")
    text = text.replace("Rs.", " ")
    text = text.replace("Rs ", " ")

    return text


# --------------------------------------------------
# EXTRACT AMOUNT
# --------------------------------------------------
def extract_amount(raw_text):

    lines = [
        line.strip()
        for line in raw_text.splitlines()
        if line.strip()
    ]

    # Higher priority labels first
    amount_labels = [
        r"NET\s*BILL",
        r"BILL\s*AMOUNT",
        r"BILL\s*AFTER\s*SUB",
        r"TOTAL\s*AMOUNT\s*PAYABLE",
        r"AMOUNT\s*PAYABLE",
        r"TOTAL\s*BILL",
        r"AMOUNT\s*DUE"
    ]

    for label in amount_labels:
        for index, line in enumerate(lines):

            if re.search(label, line, re.IGNORECASE):

                # Search amount on the same line
                numbers = re.findall(
                    r"\d[\d,]*\.?\d{0,2}",
                    line
                )

                if numbers:
                    try:
                        value = float(
                            numbers[-1].replace(",", "")
                        )

                        if value >= 0:
                            return value
                    except ValueError:
                        pass

                # Sometimes OCR puts amount on next line
                if index + 1 < len(lines):
                    numbers = re.findall(
                        r"\d[\d,]*\.?\d{0,2}",
                        lines[index + 1]
                    )

                    if numbers:
                        try:
                            value = float(
                                numbers[-1].replace(",", "")
                            )

                            if value >= 0:
                                return value
                        except ValueError:
                            pass

    return None


# --------------------------------------------------
# EXTRACT UNITS
# --------------------------------------------------
def extract_units(raw_text):

    patterns = [
        r"UNITS\s*CONSUMED\s*[:\-]?\s*(\d+(?:\.\d+)?)",
        r"TOTAL\s*UNITS\s*[:\-]?\s*(\d+(?:\.\d+)?)",
        r"ENERGY\s*CONSUMED\s*[:\-]?\s*(\d+(?:\.\d+)?)",
        r"CONSUMPTION\s*[:\-]?\s*(\d+(?:\.\d+)?)",
        r"UNITS\s*\(KWH\)\s*[:\-]?\s*(\d+(?:\.\d+)?)"
    ]

    for pattern in patterns:
        match = re.search(
            pattern,
            raw_text,
            re.IGNORECASE
        )

        if match:
            try:
                return float(match.group(1))
            except ValueError:
                continue

    return None


# --------------------------------------------------
# EXTRACT DATE
# --------------------------------------------------
def extract_date(raw_text, label):

    pattern = (
        label +
        r"\s*[:\-]?\s*"
        r"(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})"
    )

    match = re.search(
        pattern,
        raw_text,
        re.IGNORECASE
    )

    if match:
        return match.group(1)

    return None


# --------------------------------------------------
# EXTRACT METER READING
# --------------------------------------------------
def extract_reading(raw_text, reading_type):

    patterns = {
        "current": [
            r"CURRENT\s*READING\s*[:\-]?\s*(\d+)",
            r"METER\s*READING\s*\(CURRENT\)\s*[:\-]?\s*(\d+)"
        ],
        "previous": [
            r"PREVIOUS\s*READING\s*[:\-]?\s*(\d+)",
            r"METER\s*READING\s*\(PREVIOUS\)\s*[:\-]?\s*(\d+)"
        ]
    }

    for pattern in patterns[reading_type]:
        match = re.search(
            pattern,
            raw_text,
            re.IGNORECASE
        )

        if match:
            try:
                return int(match.group(1))
            except ValueError:
                continue

    return None


# --------------------------------------------------
# MAIN OCR FUNCTION
# --------------------------------------------------
def extract_bill_data(image_path):

    if not os.path.exists(image_path):
        return {
            "error": "Image file not found: " + image_path
        }

    try:

        image = Image.open(image_path)
        image = preprocess_image(image)

        raw_text = get_ocr_text(image)
        raw_text = normalize_text(raw_text)

        units = extract_units(raw_text)
        amount = extract_amount(raw_text)

        bill_date = extract_date(
            raw_text,
            r"BILL\s*DATE"
        )

        due_date = extract_date(
            raw_text,
            r"DUE\s*DATE"
        )

        current_reading = extract_reading(
            raw_text,
            "current"
        )

        previous_reading = extract_reading(
            raw_text,
            "previous"
        )

        return {
            "units_kwh": units,
            "amount": amount,
            "bill_date": bill_date,
            "due_date": due_date,
            "current_reading": current_reading,
            "previous_reading": previous_reading,
            "raw_text": raw_text
        }

    except Exception as error:

        return {
            "error": str(error)
        }


# --------------------------------------------------
# MAIN PROGRAM
# --------------------------------------------------
if __name__ == "__main__":

    if len(sys.argv) < 2:

        print(json.dumps({
            "error": "Usage: python ml/ocr.py <image_path>"
        }))

        sys.exit(1)

    image_path = sys.argv[1]

    result = extract_bill_data(image_path)

    print(json.dumps(result, indent=4))

    if "error" in result:
        sys.exit(1)
