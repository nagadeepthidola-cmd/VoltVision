import os
import joblib
import numpy as np
import pandas as pd

from sklearn.model_selection import train_test_split
from sklearn.compose import ColumnTransformer
from sklearn.preprocessing import OneHotEncoder
from sklearn.pipeline import Pipeline
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, r2_score

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATASET_PATH = os.path.join(BASE_DIR, "appliance_dataset.csv")
MODEL_PATH = os.path.join(BASE_DIR, "appliance_electricity_model.pkl")

# Assumed average appliance power ratings in watts.
# These are project assumptions, not readings from a specific household.
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


def make_synthetic_dataset(rows=30000, seed=42):
    """Create transparent synthetic training examples using appliance energy math."""
    rng = np.random.default_rng(seed)
    month = rng.integers(1, 13, rows)
    days = np.array([pd.Period(f"2026-{m:02d}").days_in_month for m in month])

    data = {
        "Fan": rng.integers(0, 8, rows),
        "Refrigerator": rng.integers(0, 4, rows),
        "AirConditioner": rng.integers(0, 5, rows),
        "Television": rng.integers(0, 6, rows),
        "Monitor": rng.integers(0, 6, rows),
        "MotorPump": rng.integers(0, 4, rows),
        "FanDailyHours": rng.uniform(0, 16, rows).round(2),
        "RefrigeratorDailyHours": rng.uniform(0, 12, rows).round(2),
        "AirConditionerDailyHours": rng.uniform(0, 14, rows).round(2),
        "TelevisionDailyHours": rng.uniform(0, 12, rows).round(2),
        "MonitorDailyHours": rng.uniform(0, 12, rows).round(2),
        "MotorPumpDailyHours": rng.uniform(0, 8, rows).round(2),
        "Month": month,
        "DaysInMonth": days,
        "City": rng.choice(["Hyderabad", "Vijayawada", "Visakhapatnam", "Tuni", "Chennai", "Mumbai", "Bengaluru"], rows),
        "Company": rng.choice(["Tata Power", "APEPDCL", "APSPDCL", "BESCOM", "TANGEDCO", "MSEDCL"], rows),
        "TariffRate": rng.uniform(4.0, 12.0, rows).round(2),
    }
    frame = pd.DataFrame(data)

    units = np.zeros(rows, dtype=float)
    for appliance, watts in POWER_WATTS.items():
        qty = frame[appliance].to_numpy()
        hours = frame[HOUR_COLUMNS[appliance]].to_numpy()
        units += qty * watts * hours * frame["DaysInMonth"].to_numpy() / 1000.0

    # Small bounded variation represents minor household-level differences.
    # This is still synthetic data and must be described as such in the project.
    variation = rng.normal(1.0, 0.015, rows)
    frame["ElectricityBill"] = (units * frame["TariffRate"].to_numpy() * variation).clip(min=0).round(2)
    return frame


def main():
    df = make_synthetic_dataset()
    df.to_csv(DATASET_PATH, index=False)
    print("Synthetic appliance dataset created:", DATASET_PATH)
    print("Rows:", len(df))
    print("NOTE: Training data is synthetic and based on stated wattage assumptions.")

    X = df.drop(columns=["ElectricityBill"])
    y = df["ElectricityBill"]
    categorical = ["City", "Company"]

    preprocessor = ColumnTransformer(
        transformers=[("categorical", OneHotEncoder(handle_unknown="ignore"), categorical)],
        remainder="passthrough",
    )
    model = RandomForestRegressor(
        n_estimators=250,
        min_samples_leaf=2,
        random_state=42,
        n_jobs=-1,
    )
    pipeline = Pipeline([
        ("preprocessing", preprocessor),
        ("model", model),
    ])

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42
    )
    print("\nTraining appliance-based model...")
    pipeline.fit(X_train, y_train)
    predictions = pipeline.predict(X_test)

    mae = mean_absolute_error(y_test, predictions)
    r2 = r2_score(y_test, predictions)
    print("\n===== APPLIANCE MODEL RESULTS =====")
    print("Training rows:", len(X_train))
    print("Testing rows:", len(X_test))
    print("MAE (synthetic test data):", round(mae, 2))
    print("R2 Score (synthetic test data):", round(r2, 4))

    names = pipeline.named_steps["preprocessing"].get_feature_names_out()
    importance = pipeline.named_steps["model"].feature_importances_
    importance_df = pd.DataFrame({"Feature": names, "Importance": importance})
    print("\nTop 15 features:")
    print(importance_df.sort_values("Importance", ascending=False).head(15).to_string(index=False))

    joblib.dump(pipeline, MODEL_PATH)
    print("\nNew model saved:", MODEL_PATH)
    print("Original electricity_model.pkl is not modified.")


if __name__ == "__main__":
    main()
