import csv

file_path = "ml/dataset.csv"

try:
    with open(file_path, "r", encoding="utf-8-sig") as file:
        reader = csv.reader(file)

        columns = next(reader)
        print("\n===== DATASET COLUMNS =====")
        print(columns)

        print("\n===== FIRST 5 ROWS =====")
        for i, row in enumerate(reader):
            print(row)
            if i == 4:
                break

except FileNotFoundError:
    print("Dataset file not found! ml folder lo dataset.csv check cheyyi.")