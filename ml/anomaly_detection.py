
import sys
import json
import numpy as np

from sklearn.ensemble import IsolationForest


def detect_anomaly(bills):

    # Validate input
    if not isinstance(bills, list) or len(bills) < 6:
        return {
            "success": False,
            "message": "Anomaly detection requires at least 6 valid bills."
        }

    try:
        # Extract valid electricity units
        units = []

        for bill in bills:
            value = bill.get("units_kwh")

            if value is None:
                continue

            try:
                value = float(value)

                if np.isfinite(value) and value > 0:
                    units.append(value)

            except (ValueError, TypeError):
                continue

        if len(units) < 6:
            return {
                "success": False,
                "message": "At least 6 valid electricity readings are required."
            }

        # Latest bill is tested against previous bills
        historical_data = np.array(units[:-1]).reshape(-1, 1)
        latest_units = float(units[-1])

        if len(historical_data) < 5:
            return {
                "success": False,
                "message": "Not enough historical bills for analysis."
            }

        # Train Isolation Forest
        model = IsolationForest(
            n_estimators=100,
            contamination="auto",
            random_state=42
        )

        model.fit(historical_data)

        # Predict latest bill
        prediction = model.predict(
            np.array([[latest_units]])
        )[0]

        # Calculate average historical consumption
        average_units = float(np.mean(historical_data))

        # Calculate percentage change
        if average_units > 0:
            percentage_change = (
                (latest_units - average_units) / average_units
            ) * 100
        else:
            percentage_change = 0

        # Determine result
        is_anomaly = prediction == -1

        return {
            "success": True,
            "status": "Anomaly" if is_anomaly else "Normal",
            "is_anomaly": bool(is_anomaly),
            "latest_units": round(latest_units, 2),
            "average_units": round(average_units, 2),
            "percentage_change": round(percentage_change, 2),
            "message": (
                "Unusual electricity consumption detected."
                if is_anomaly
                else "Electricity consumption appears normal."
            ),
            "algorithm": "Isolation Forest"
        }

    except Exception as error:

        return {
            "success": False,
            "message": str(error)
        }


if __name__ == "__main__":

    try:
        # Receive JSON input from PHP
        input_data = json.load(sys.stdin)

        bills = input_data.get("bills", [])

        result = detect_anomaly(bills)

        print(json.dumps(result))

    except Exception as error:

        print(json.dumps({
            "success": False,
            "message": str(error)
        }))
