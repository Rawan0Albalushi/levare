"""Build the spare-parts catalog from the store workbook and the critical list.

The merge follows the same rules as the original single-page catalog so the
API returns the same parts, locations, and critical-list fields.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
import warnings
from datetime import datetime
from pathlib import Path

import openpyxl

warnings.filterwarnings("ignore", category=UserWarning, module="openpyxl")

ROOT = Path(__file__).resolve().parents[1]
EXCEL_EPOCH = datetime(1899, 12, 30)
SHEET_CAT = [(re.compile(r"pump", re.I), "pump"), (re.compile(r"seal", re.I), "seal"), (re.compile(r"motor", re.I), "motor"), (re.compile(r"shaft|housing", re.I), "shaft")]
PN_RE = re.compile(r"\b\d{6,9}\b")
EXCEL_ERRORS = {"#N/A", "#VALUE!", "#REF!", "#DIV/0!", "#NAME?", "#NULL!", "#NUM!", "#GETTING_DATA"}


def clean(value) -> str:
    return re.sub(r"\s+", " ", "" if value is None else str(value)).strip()


def multi(value) -> str:
    text = "" if value is None else str(value)
    return clean(re.sub(r"\r?\n", " / ", text))


def xnum(value):
    text = str(value if value is not None else "").replace(",", "")
    total = 0.0
    first = re.match(r"^\s*[\d.]+", text)
    if first:
        total += float(first.group(0) or 0)
    for match in re.finditer(r"\+\s*([\d.]+)", text):
        total += float(match.group(1) or 0)
    total = round(total * 100) / 100
    return int(total) if total == int(total) else total


def xdate(value) -> str:
    return re.sub(r"^(\d{1,2}/\d{1,2}/)(\d{2})$", r"\g<1>20\2", clean(value))


def format_number(value, fmt: str) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return "" if value.strip().upper() in EXCEL_ERRORS else value
    if isinstance(value, bool):
        return "TRUE" if value else "FALSE"
    if isinstance(value, datetime):
        number_format = (fmt or "").lower()
        if "mmm" in number_format:
            return value.strftime("%d-%b-%y")
        # m/d/yy keeps a two-digit year; the other date formats are shown with the century.
        if re.search(r"(?<![m])m/d/yy", number_format):
            return f"{value.month}/{value.day}/{value.year % 100:02d}"
        return f"{value.month}/{value.day}/{value.year}"
    if isinstance(value, (int, float)):
        number_format = fmt or "General"
        if "%" in number_format:
            percent = float(value) * 100
            rounded = round(percent)
            if abs(percent - rounded) < 1e-6:
                return f"{int(rounded)}%"
            return f"{percent:.2f}%"
        if "#,##0.00" in number_format or re.search(r"0\.00", number_format):
            return f"{float(value):,.2f}"
        if float(value).is_integer():
            return str(int(value))
        return f"{float(value):.10f}".rstrip("0").rstrip(".")
    return str(value)


def alts_of(note: str, pn: str) -> list[str]:
    return list(dict.fromkeys(code for code in PN_RE.findall(note) if code != pn))


def ensure_xlsx(path: Path) -> Path:
    if path.suffix.lower() != ".xlsb":
        return path
    destination = ROOT / "storage" / "app" / "store-source.xlsx"
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists() and destination.stat().st_mtime >= path.stat().st_mtime:
        return destination
    script = f"""
$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false
$excel.ScreenUpdating = $false
$wb = $excel.Workbooks.Open('{path}')
if (Test-Path -LiteralPath '{destination}') {{ Remove-Item -LiteralPath '{destination}' -Force }}
$wb.SaveAs('{destination}', 51)
$wb.Close($false)
$excel.Quit()
"""
    subprocess.run(["powershell", "-NoProfile", "-Command", script], check=True)
    return destination


def formatted_rows(worksheet) -> list[list[str]]:
    rows = []
    for cells in worksheet.iter_rows():
        rows.append([format_number(cell.value, cell.number_format) for cell in cells])
    return rows


def parse_store(path: Path) -> list[dict]:
    workbook = openpyxl.load_workbook(ensure_xlsx(path), data_only=False, read_only=True)
    try:
        for name in workbook.sheetnames:
            parsed = parse_store_rows(formatted_rows(workbook[name]))
            if parsed is not None:
                return parsed
    finally:
        workbook.close()
    return []


def parse_store_rows(rows: list[list]) -> list[dict] | None:
    header_index = -1
    for index, row in enumerate(rows):
        cells = [clean(cell).lower() for cell in row]
        if any(re.search(r"item\s*code", cell) for cell in cells) and any(re.search(r"desc", cell) for cell in cells):
            header_index = index
            header = cells
            break
    if header_index < 0:
        return None

    def column(pattern: str) -> int:
        regex = re.compile(pattern, re.I)
        for index, cell in enumerate(header):
            if regex.search(cell):
                return index
        return -1

    columns = {
        "id": column(r"item\s*code"),
        "pn": column(r"part\s*(num|no|number)"),
        "d": column(r"desc"),
        "u": column(r"^uom|^unit"),
        "q": column(r"^quantity$|^qty$"),
        "cond": column(r"condition"),
        "st": column(r"^status"),
        "ref": column(r"reference"),
        "loc": column(r"^location"),
        "dt": column(r"update"),
        "ty": column(r"^type"),
    }
    if columns["q"] < 0:
        columns["q"] = column(r"quantity|qty")

    tag_columns = []
    if columns["ty"] >= 0:
        for index in range(columns["ty"] + 1, min(len(header), columns["ty"] + 6)):
            if not header[index]:
                tag_columns.append(index)

    def cell(row, key) -> str:
        index = columns[key]
        if index < 0 or index >= len(row):
            return ""
        return clean(row[index] if index < len(row) else "")

    items: dict[str, dict] = {}
    for row in rows[header_index + 1 :]:
        description = cell(row, "d")
        if not description:
            continue
        item_code = cell(row, "id")
        part_number = cell(row, "pn")
        if re.fullmatch(r"(n/?a|-|0)?", part_number, re.I):
            part_number = ""
        key = f"i{item_code}" if item_code else f"p{part_number}" if part_number else f"d{description}"
        item = items.get(key)
        if item is None:
            item = {"id": item_code, "pn": part_number, "d": description, "u": cell(row, "u").upper(), "t": "", "L": []}
            items[key] = item
        if not item["pn"] and part_number:
            item["pn"] = part_number
        tag_values = []
        type_value = cell(row, "ty")
        if type_value:
            tag_values.append(type_value)
        for index in tag_columns:
            if index >= len(row) or row[index] is None:
                continue
            text = clean(row[index])
            if text:
                tag_values.append(text)
        joined = " · ".join(dict.fromkeys(tag_values))
        known = [piece.lower() for piece in item["t"].split(" · ")] if item["t"] else []
        if joined and joined.lower() not in known:
            item["t"] = f"{item['t']} · {joined}" if item["t"] else joined
        location = cell(row, "loc")
        quantity_raw = cell(row, "q")
        if location or quantity_raw:
            item["L"].append([
                location,
                xnum(quantity_raw),
                quantity_raw,
                cell(row, "dt"),
                cell(row, "cond"),
                cell(row, "st"),
                cell(row, "ref"),
            ])
    return list(items.values())


def sheet_rows(worksheet) -> list[list[str]]:
    rows = []
    for cells in worksheet.iter_rows():
        row = []
        for cell in cells:
            value = cell.value
            if isinstance(value, str) and value.startswith("="):
                value = None
            row.append(format_number(value, cell.number_format) if value is not None else "")
        rows.append(row)
    return rows


def parse_critical(path: Path) -> dict:
    calculated = openpyxl.load_workbook(path, data_only=True)
    formatted = openpyxl.load_workbook(path, data_only=False)
    crit: dict[str, dict] = {}
    found = False
    for name in formatted.sheetnames:
        match = next((item for item in SHEET_CAT if item[0].search(name)), None)
        if match is None:
            continue
        found = True
        category = match[1]
        value_sheet = calculated[name]
        format_sheet = formatted[name]
        rows = []
        for value_row, format_row in zip(value_sheet.iter_rows(), format_sheet.iter_rows()):
            row = []
            for value_cell, format_cell in zip(value_row, format_row):
                value = value_cell.value
                if value is None:
                    row.append("")
                else:
                    row.append(format_number(value, format_cell.number_format))
            rows.append(row)
        for row in rows[1:]:
            padded = row + [""] * 16
            part_number = clean(padded[1])
            description = clean(padded[2])
            if not description and not re.search(r"\d", part_number):
                continue
            key = part_number if re.search(r"\d", part_number) else f"d:{description}"
            if key in crit:
                if category not in crit[key]["cats"]:
                    crit[key]["cats"].append(category)
                continue
            record = {"cats": [category], "d": description}
            mapping = [("pr", 0), ("cs", 3), ("mn", 4), ("ru", 5), ("se", 6), ("p90", 7), ("p6", 8), ("bk", 9), ("us", 10)]
            for field, index in mapping:
                text = multi(padded[index])
                if text:
                    record[field] = text.upper() if field == "pr" else text
            note = " · ".join(piece for piece in [multi(padded[11]), multi(padded[12])] if piece)
            if note:
                record["no"] = note
            if re.search(r"worn|warn", padded[13], re.I):
                record["w"] = 1
            crit[key] = record
    if not found:
        return {}
    all_name = next((name for name in formatted.sheetnames if re.match(r"^all", name.strip(), re.I)), None)
    if all_name:
        value_sheet = calculated[all_name]
        format_sheet = formatted[all_name]
        rows = []
        for value_row, format_row in zip(value_sheet.iter_rows(), format_sheet.iter_rows()):
            row = []
            for value_cell, format_cell in zip(value_row, format_row):
                value = value_cell.value
                row.append("" if value is None else format_number(value, format_cell.number_format))
            rows.append(row)
        for row in rows[1:]:
            padded = row + [""] * 8
            part_number = clean(padded[1])
            if not re.search(r"\d", part_number):
                continue
            if part_number not in crit:
                crit[part_number] = {"cats": [], "d": clean(padded[2])}
                if clean(padded[0]):
                    crit[part_number]["pr"] = clean(padded[0]).upper()
            record = crit[part_number]
            if not record.get("cs") and clean(padded[3]):
                record["cs"] = clean(padded[3])
            note = clean(padded[5])
            if note:
                record["an"] = note
                alternatives = alts_of(note, part_number)
                if alternatives:
                    record["alt"] = alternatives
    return crit


def merge_data(store: list[dict], crit: dict) -> list[dict]:
    parts = [
        {"id": item["id"], "pn": item["pn"], "d": item["d"], "u": item["u"], "t": item.get("t") or "", "L": [list(location) for location in item["L"]]}
        for item in store
    ]
    by_pn = {part["pn"]: part for part in parts if part["pn"]}
    no_pn = {part["d"]: part for part in parts if not part["pn"]}
    for key, record in crit.items():
        part_number = "" if key.startswith("d:") else key
        item = by_pn.get(part_number) if part_number else no_pn.get(record.get("d"))
        if item is None:
            item = {"id": "", "pn": part_number, "d": record.get("d", ""), "u": "EA", "t": "", "L": []}
            parts.append(item)
            if part_number:
                by_pn[part_number] = item
        for field, value in record.items():
            if field not in ("d", "cats"):
                item[field] = value
        if record.get("cats"):
            item["cats"] = list(record["cats"])
        item["cr"] = 1
    return parts


def classify(path: Path) -> str:
    if path.suffix.lower() == ".xlsb":
        return "store"
    workbook = openpyxl.load_workbook(path, read_only=True, data_only=False)
    try:
        if any(pattern.search(name) for name in workbook.sheetnames for pattern, _category in SHEET_CAT):
            return "critical"
    finally:
        workbook.close()
    return "store"


def resolve_sources(files: list[Path]) -> tuple[Path, Path]:
    default_store = ROOT / "STORE SPARE PARTS 4.10.26.xlsb"
    default_critical = ROOT / "Critical Parts List 30.7.26.xlsx"
    if not files:
        return default_store, default_critical
    kinds = [(path, classify(path)) for path in files]
    store = next((path for path, kind in kinds if kind == "store"), None)
    critical = next((path for path, kind in kinds if kind == "critical"), None)
    return store or default_store, critical or default_critical


def main() -> int:
    args = sys.argv[1:]
    destination = ROOT / "database" / "data" / "parts.json"
    files: list[Path] = []
    index = 0
    while index < len(args):
        if args[index] == "--out":
            destination = Path(args[index + 1])
            index += 2
            continue
        files.append(Path(args[index]))
        index += 1
    # Positional form: store critical destination
    if len(files) == 3 and files[2].suffix.lower() == ".json":
        destination = files[2]
        files = files[:2]
    store_path, critical_path = resolve_sources(files)
    store = parse_store(store_path)
    crit = parse_critical(critical_path)
    parts = merge_data(store, crit)
    payload = {
        "source": {
            "store": store_path.stem,
            "critical": critical_path.stem,
        },
        "parts": parts,
    }
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"parts {len(parts)} store {len(store)} critical {len(crit)} -> {destination}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
