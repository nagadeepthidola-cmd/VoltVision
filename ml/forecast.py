
import sys
import json
import numpy as np
from sklearn.linear_model import LinearRegression


def forecast_consumption(bills):

    try:
        if not isinstance(bills, list) or len(bills) < 3:
            return {
                "success": False,
                "message": "At least 3 valid electricity bills are required."
            }

        units = []

        for bill in bills:
            try:
                value = float(bill["units_kwh"])

                if np.isfinite(value) and value > 0:
                    units.append(value)

            except (ValueError, TypeError, KeyError):
                continue

        if len(units) < 3:
            return {
                "success": False,
                "message": "At least 3 valid consumption readings are required."
            }

        # Chronological sequence of bill readings
        X = np.arange(len(units)).reshape(-1, 1)
        y = np.array(units)

        model = LinearRegression()
        model.fit(X, y)

        # Predict next billing period
        next_index = np.array([[len(units)]])
        predicted_units = float(model.predict(next_index)[0])

        predicted_units = max(0, predicted_units)

        average_units = float(np.mean(y))
        latest_units = float(y[-1])

        if predicted_units > latest_units:
            trend = "Increasing"
        elif predicted_units < latest_units:
            trend = "Decreasing"
        else:
            trend = "Stable"

        change = (
            ((predicted_units - latest_units) / latest_units) * 100
            if latest_units > 0 else 0
        )

        return {
            "success": True,
            "predicted_units": round(predicted_units, 2),
            "average_units": round(average_units, 2),
            "latest_units": round(latest_units, 2),
            "change_percentage": round(change, 2),
            "trend": trend,
            "algorithm": "Linear Regression",
            "message": "Next billing period consumption forecast generated successfully."
        }

    except Exception as error:
        return {
            "success": False,
            "message": str(error)
        }


if __name__ == "__main__":
    try:
        input_data = json.load(sys.stdin)
        result = forecast_consumption(input_data.get("bills", []))
        print(json.dumps(result))

    except Exception as error:
        print(json.dumps({
            "success": False,
            "message": str(error)
        }))
