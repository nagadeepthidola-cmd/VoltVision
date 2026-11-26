
import sys
import json
import os
import math
import joblib
import pandas as pd


# --------------------------------------------------
# CONFIGURATION
# --------------------------------------------------

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

MODEL_PATH = os.path.join(
    BASE_DIR,
    "appliance_electricity_model.pkl"
)

POWER_WATTS = {
    "Fan": 75,
    "Refrigerator": 150,
    "AirConditioner": 1500,
    "Television": 100,
    "Monitor": 50,
    "MotorPump": 750,
}

HOUR_COLUMNS = {
    "Fan": "FanDailyHours",
    "Refrigerator": "RefrigeratorDailyHours",
    "AirConditioner": "AirConditionerDailyHours",
    "Television": "TelevisionDailyHours",
    "Monitor": "MonitorDailyHours",
    "MotorPump": "MotorPumpDailyHours",
}

COLUMNS = [
    "Fan",
    "Refrigerator",
    "AirConditioner",
    "Television",
    "Monitor",
    "MotorPump",

    "FanDailyHours",
    "RefrigeratorDailyHours",
    "AirConditionerDailyHours",
    "TelevisionDailyHours",
    "MonitorDailyHours",
    "MotorPumpDailyHours",

    "Month",
    "DaysInMonth",
    "City",
    "Company",
    "TariffRate"
]


# --------------------------------------------------
# JSON RESPONSE
# --------------------------------------------------

def send_response(data):
    print(json.dumps(data, ensure_ascii=False, allow_nan=False))


# --------------------------------------------------
# VALIDATION HELPERS
# --------------------------------------------------

def get_number(data, key):
    value = data.get(key)

    if isinstance(value, bool):
        raise ValueError(f"{key} must be a valid number.")

    try:
        number = float(value)
    except (TypeError, ValueError):
        raise ValueError(f"{key} must be a valid number.")

    if not math.isfinite(number):
        raise ValueError(f"{key} must be a finite number.")

    return number


def validate_input(data):

    if not isinstance(data, dict):
        raise ValueError("Input JSON must contain an object.")

    missing_columns = [
        column for column in COLUMNS
        if column not in data
    ]

    if missing_columns:
        raise ValueError(
            "Missing input fields: " +
            ", ".join(missing_columns)
        )

    # Appliance quantity validation.
    for appliance in POWER_WATTS:

        quantity = get_number(data, appliance)

        if quantity < 0 or quantity > 50:
            raise ValueError(
                f"{appliance} quantity must be between 0 and 50."
            )

        if quantity != int(quantity):
            raise ValueError(
                f"{appliance} quantity must be a whole number."
            )

    # Daily usage hours validation.
    for appliance, hours_column in HOUR_COLUMNS.items():

        hours = get_number(data, hours_column)

        if hours < 0 or hours > 24:
            raise ValueError(
                f"{hours_column} must be between 0 and 24."
            )

    # At least one appliance is required.
    total_quantity = sum(
        int(get_number(data, appliance))
        for appliance in POWER_WATTS
    )

    if total_quantity <= 0:
        raise ValueError(
            "At least one appliance quantity is required."
        )

    # Month validation.
    month = get_number(data, "Month")

    if month != int(month) or month < 1 or month > 12:
        raise ValueError("Month must be between 1 and 12.")

    # Days in month validation.
    days = get_number(data, "DaysInMonth")

    if days != int(days) or days < 28 or days > 31:
        raise ValueError("DaysInMonth must be between 28 and 31.")

    # Tariff validation.
    tariff = get_number(data, "TariffRate")

    if tariff <= 0 or tariff > 100:
        raise ValueError("TariffRate must be greater than 0 and at most 100.")

    # City validation.
    city = data.get("City")

    if not isinstance(city, str) or not city.strip():
        raise ValueError("City is required.")

    if len(city.strip()) > 100:
        raise ValueError("City must not exceed 100 characters.")

    # Electricity company validation.
    company = data.get("Company")

    if not isinstance(company, str) or not company.strip():
        raise ValueError("Company is required.")

    if len(company.strip()) > 100:
        raise ValueError("Company must not exceed 100 characters.")


# --------------------------------------------------
# ELECTRICITY UNIT CALCULATION
# --------------------------------------------------

def calculate_units(data):

    days = int(get_number(data, "DaysInMonth"))

    total_units = 0.0

    for appliance, watts in POWER_WATTS.items():

        quantity = get_number(data, appliance)

        daily_hours = get_number(
            data,
            HOUR_COLUMNS[appliance]
        )

        monthly_units = (
            quantity *
            watts *
            daily_hours *
            days
        ) / 1000.0

        total_units += monthly_units

    if not math.isfinite(total_units):
        raise ValueError("Invalid electricity consumption calculation.")

    return round(total_units, 2)


# --------------------------------------------------
# MAIN PREDICTION
# --------------------------------------------------

def main():

    if len(sys.argv) != 2:
        raise ValueError(
            "Input JSON file path missing. "
            "Usage: python predict.py input.json"
        )

    input_file = sys.argv[1]

    if not os.path.isfile(input_file):
        raise FileNotFoundError(
            "Input JSON file not found."
        )

    if not os.path.isfile(MODEL_PATH):
        raise FileNotFoundError(
            "Appliance model not found: "
            "appliance_electricity_model.pkl. "
            "Run train_model.py first."
        )

    # Read input JSON.
    with open(
        input_file,
        "r",
        encoding="utf-8"
    ) as file:

        data = json.load(file)

    # Validate input.
    validate_input(data)

    # Calculate electricity units.
    estimated_units = calculate_units(data)

    if estimated_units <= 0:
        raise ValueError(
            "Estimated electricity consumption must be greater than zero."
        )

    # Prepare model input.
    input_row = {}

    for column in COLUMNS:

        if column in ("City", "Company"):
            input_row[column] = str(data[column]).strip()

        else:
            input_row[column] = get_number(data, column)

    input_df = pd.DataFrame(
        [input_row],
        columns=COLUMNS
    )

    # Load trained model.
    model = joblib.load(MODEL_PATH)

    # Generate prediction.
    prediction_result = model.predict(input_df)

    if len(prediction_result) != 1:
        raise ValueError(
            "Unexpected prediction output from ML model."
        )

    predicted_bill = float(prediction_result[0])

    if not math.isfinite(predicted_bill):
        raise ValueError(
            "ML model returned an invalid prediction."
        )

    if predicted_bill < 0:
        predicted_bill = 0.0

    predicted_bill = round(predicted_bill, 2)

    # Return successful JSON response.
    send_response({
        "success": True,
        "estimated_units": estimated_units,
        "predicted_bill": predicted_bill
    })


# --------------------------------------------------
# EXECUTION
# --------------------------------------------------

if __name__ == "__main__":

    try:
        main()

    except Exception as error:

        send_response({
            "success": False,
            "error": str(error)
        })

        sys.exit(1)
