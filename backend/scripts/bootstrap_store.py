"""Create the first store and owner account in an already initialized empty DB.

Run once after database/schema.sql:
  python -m backend.scripts.bootstrap_store
Credentials are read from environment; never commit the real values.
"""
import os
import sys
from pathlib import Path

import bcrypt
import pymysql
from dotenv import load_dotenv

load_dotenv()

required = ["DB_HOST", "DB_USER", "DB_PASSWORD", "DB_NAME", "INITIAL_STORE_CODE",
            "INITIAL_STORE_NAME", "INITIAL_OWNER_LOGIN", "INITIAL_OWNER_PASSWORD"]
missing = [key for key in required if not os.getenv(key)]
if missing:
    sys.exit("Missing environment variables: " + ", ".join(missing))

if len(os.environ["INITIAL_OWNER_PASSWORD"]) < 12:
    sys.exit("INITIAL_OWNER_PASSWORD must be at least 12 characters")

conn = pymysql.connect(
    host=os.environ["DB_HOST"],
    port=int(os.getenv("DB_PORT", "3306")),
    user=os.environ["DB_USER"],
    password=os.environ["DB_PASSWORD"],
    database=os.environ["DB_NAME"],
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=False,
)
try:
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM stores WHERE store_code=%s", (os.environ["INITIAL_STORE_CODE"],))
        if cur.fetchone():
            raise RuntimeError("Store code already exists; bootstrap stopped without changes")

        cur.execute("SELECT id FROM roles WHERE role_code='owner'")
        role = cur.fetchone()
        if not role:
            raise RuntimeError("Owner role is missing. Apply database/schema.sql first.")

        cur.execute(
            "INSERT INTO stores (store_code, store_name) VALUES (%s, %s)",
            (os.environ["INITIAL_STORE_CODE"], os.environ["INITIAL_STORE_NAME"]),
        )
        store_id = cur.lastrowid

        password_hash = bcrypt.hashpw(
            os.environ["INITIAL_OWNER_PASSWORD"].encode("utf-8"),
            bcrypt.gensalt(rounds=12),
        ).decode("utf-8")
        cur.execute(
            """INSERT INTO users
               (store_id, role_id, login_id, password_hash, display_name)
               VALUES (%s, %s, %s, %s, %s)""",
            (store_id, role["id"], os.environ["INITIAL_OWNER_LOGIN"],
             password_hash, os.getenv("INITIAL_OWNER_DISPLAY_NAME", "オーナー")),
        )
        user_id = cur.lastrowid
        cur.execute(
            """INSERT INTO store_settings
               (store_id, updated_by_user_id, tax_rate, tax_mode,
                service_enabled, service_rate, work_rounding_minutes,
                work_rounding_mode, auto_close_enabled)
               VALUES (%s, %s, 10.0000, 'exclusive', 0, 0.0000,
                       15, 'floor', 1)""",
            (store_id, user_id),
        )
    conn.commit()
    print(f"Created store_id={store_id} and owner user_id={user_id}.")
    print("Keep the owner password secret; remove bootstrap credentials from the environment.")
except Exception:
    conn.rollback()
    raise
finally:
    conn.close()
