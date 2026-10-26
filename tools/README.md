# Trip and term spreadsheets

`trips_xlsx.py` turns the CSV files from the plugin's export abilities into a formatted Excel workbook, and turns the edited workbook back into CSV. It runs on your own computer. It never contacts the website, and the plugin never reads Excel files.

## Why a script and not a plugin feature

Reading an uploaded `.xlsx` on the live server would mean unpacking a zip and parsing XML from a file that someone sent. That is a larger attack surface than the job deserves. The CSV import is already safe, so Excel stays outside the server.

## The loop

1. Export with the abilities `export-trips-csv` and `export-terms-csv`. Save the results as `trips.csv`, `days.csv` and `terms.csv`.
2. Build the workbook:

   ```text
   python3 tools/trips_xlsx.py to-xlsx --trips trips.csv --days days.csv --terms terms.csv -o edit.xlsx
   ```

3. Edit in Excel, Numbers or LibreOffice. A "Read me" sheet in the workbook repeats the rules below.
4. Turn it back into CSV:

   ```text
   python3 tools/trips_xlsx.py to-csv edit.xlsx --out-dir ./csv
   ```

5. Upload `./csv/trips.csv` and `./csv/days.csv` to `import-trips-csv`, and `./csv/terms.csv` to `import-terms-csv`. Do a dry run first and read the report before running it for real.

You can pass any one of `--trips`, `--days` or `--terms`; only the sheets you give are built.

## Rules

- A blank cell leaves the current value unchanged. Type `[clear]` to clear a value.
- Grey columns identify a row or show reference values. They are ignored on import, so do not rely on editing them.
- Months are numbers joined by a vertical bar, for example `6|7|8`.
- `permit_required` is `yes` or `no`. `difficulty` is `easy`, `moderate` or `strenuous`, and the Terms sheet offers these as a drop-down.
- A trip with rows on the Days sheet has its whole day list replaced.
- Every cell is stored as text, so Excel cannot turn `007` into `7`, or `6|7` into a date, or run a leading `=` as a formula.
- Excel cells hold at most 32,767 characters. A longer field is refused with a message naming it; edit that one in WordPress.

## Requirements and tests

Python 3 and `openpyxl` (`pip install openpyxl`).

```text
python3 -m unittest discover -s tools
```
