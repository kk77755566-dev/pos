"""Initial API foundation for Girls Bar POS.

This module deliberately starts with health, login and table-list endpoints.
Business writes are added only after database integration tests are available.
"""
from __future__ import annotations

import os
import uuid
from decimal import Decimal, ROUND_HALF_UP
from datetime import datetime, timedelta, timezone
from typing import Any

import bcrypt
import jwt
import pymysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, Field, condecimal

load_dotenv()

APP_ENV = os.getenv("APP_ENV", "development")
JWT_SECRET = os.getenv("JWT_SECRET", "")
JWT_ISSUER = os.getenv("JWT_ISSUER", "girlsbar-pos")
JWT_EXPIRE_MINUTES = int(os.getenv("JWT_EXPIRE_MINUTES", "480"))
DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "3306")),
    "user": os.getenv("DB_USER", ""),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "girlsbar_pos"),
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
    "connect_timeout": int(os.getenv("DB_CONNECT_TIMEOUT", "5")),
    "autocommit": True,
}

app = FastAPI(title="Girls Bar POS API", version="0.1.0")
bearer = HTTPBearer(auto_error=False)


def db_connection():
    if not DB_CONFIG["user"] or not DB_CONFIG["password"]:
        raise HTTPException(status_code=503, detail="Database credentials are not configured")
    try:
        return pymysql.connect(**DB_CONFIG)
    except pymysql.MySQLError:
        raise HTTPException(status_code=503, detail="Database is unavailable") from None


class LoginRequest(BaseModel):
    store_code: str = Field(min_length=1, max_length=40)
    login_id: str = Field(min_length=1, max_length=120)
    password: str = Field(min_length=1, max_length=200)


class LoginResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_in: int
    user: dict[str, Any]


def verify_password(plain: str, encoded: str) -> bool:
    try:
        return bcrypt.checkpw(plain.encode("utf-8"), encoded.encode("utf-8"))
    except (ValueError, TypeError):
        return False


def create_access_token(user: dict[str, Any]) -> str:
    if len(JWT_SECRET) < 32:
        raise HTTPException(status_code=503, detail="JWT_SECRET must contain at least 32 characters")
    now = datetime.now(timezone.utc)
    payload = {
        "iss": JWT_ISSUER,
        "sub": str(user["user_id"]),
        "store_id": int(user["store_id"]),
        "role": user["role_code"],
        "iat": now,
        "exp": now + timedelta(minutes=JWT_EXPIRE_MINUTES),
    }
    return jwt.encode(payload, JWT_SECRET, algorithm="HS256")


def current_user(
    credentials: HTTPAuthorizationCredentials | None = Depends(bearer),
) -> dict[str, Any]:
    if credentials is None or not JWT_SECRET:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Authentication required")
    try:
        payload = jwt.decode(
            credentials.credentials,
            JWT_SECRET,
            algorithms=["HS256"],
            issuer=JWT_ISSUER,
        )
        return {
            "user_id": int(payload["sub"]),
            "store_id": int(payload["store_id"]),
            "role": str(payload["role"]),
        }
    except (jwt.PyJWTError, KeyError, TypeError, ValueError):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired token") from None


@app.get("/health")
def health():
    return {"status": "ok", "service": "girlsbar-pos-api", "version": app.version}


@app.get("/health/db")
def database_health():
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT 1 AS ok")
            row = cur.fetchone()
        return {"status": "ok", "database": row["ok"]}
    finally:
        conn.close()


@app.post("/api/auth/login", response_model=LoginResponse)
def login(body: LoginRequest):
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT u.id AS user_id, u.store_id, u.login_id, u.display_name,
                       u.password_hash, r.role_code, s.store_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                  JOIN stores s ON s.id = u.store_id
                 WHERE s.store_code = %s
                   AND u.login_id = %s
                   AND u.is_active = 1
                   AND s.is_active = 1
                 LIMIT 1
                """,
                (body.store_code, body.login_id),
            )
            user = cur.fetchone()
    finally:
        conn.close()

    if not user or not verify_password(body.password, user["password_hash"]):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Login failed")

    token = create_access_token(user)
    return LoginResponse(
        access_token=token,
        expires_in=JWT_EXPIRE_MINUTES * 60,
        user={
            "id": user["user_id"],
            "store_id": user["store_id"],
            "store_name": user["store_name"],
            "login_id": user["login_id"],
            "display_name": user["display_name"],
            "role": user["role_code"],
        },
    )


@app.get("/api/tables")
def list_tables(user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT t.id, t.table_name, t.capacity, t.status, t.sort_order,
                       s.id AS session_id, s.customer_count, s.started_at
                  FROM tables t
                  LEFT JOIN table_sessions s
                    ON s.store_id = t.store_id
                   AND s.table_id = t.id
                   AND s.status IN ('open', 'checkout')
                 WHERE t.store_id = %s AND t.is_active = 1
                 ORDER BY t.sort_order, t.id
                """,
                (user["store_id"],),
            )
            return cur.fetchall()
    finally:
        conn.close()


class BackAllocationInput(BaseModel):
    cast_id: int = Field(gt=0)
    allocation_rate: Decimal = Field(ge=0, le=100)


class OrderItemInput(BaseModel):
    product_id: int = Field(gt=0)
    quantity: Decimal = Field(gt=0, le=9999)
    allocations: list[BackAllocationInput] = Field(default_factory=list)


class OrderCreateRequest(BaseModel):
    session_id: int = Field(gt=0)
    idempotency_key: str = Field(min_length=8, max_length=100)
    items: list[OrderItemInput] = Field(min_length=1, max_length=100)


def money(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


@app.post("/api/orders")
def create_order(body: OrderCreateRequest, user: dict[str, Any] = Depends(current_user)):
    """Create an order and its per-item cast back allocations atomically."""
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")

    store_id = user["store_id"]
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            # Idempotent retry: return the previously committed order.
            cur.execute(
                "SELECT id, order_number, status FROM orders WHERE store_id=%s AND idempotency_key=%s",
                (store_id, body.idempotency_key),
            )
            existing = cur.fetchone()
            if existing:
                conn.commit()
                return {"order_id": existing["id"], "order_number": existing["order_number"],
                        "status": existing["status"], "replayed": True}

            cur.execute(
                "SELECT id, status FROM table_sessions WHERE store_id=%s AND id=%s FOR UPDATE",
                (store_id, body.session_id),
            )
            session = cur.fetchone()
            if not session or session["status"] != "open":
                raise HTTPException(status_code=409, detail="Table session is not open")

            order_number = uuid.uuid4().hex[:20].upper()
            cur.execute(
                """INSERT INTO orders
                   (store_id, session_id, order_number, entered_by_user_id,
                    status, ordered_at, idempotency_key)
                   VALUES (%s,%s,%s,%s,'confirmed',UTC_TIMESTAMP(3),%s)""",
                (store_id, body.session_id, order_number, user["user_id"], body.idempotency_key),
            )
            order_id = cur.lastrowid
            created_items = []

            for item in body.items:
                cur.execute(
                    """SELECT id, product_name, selling_price, back_rate, back_type
                         FROM products
                        WHERE store_id=%s AND id=%s AND is_active=1
                        FOR UPDATE""",
                    (store_id, item.product_id),
                )
                product = cur.fetchone()
                if not product:
                    raise HTTPException(status_code=404, detail=f"Product {item.product_id} not found")

                quantity = Decimal(str(item.quantity))
                unit_price = Decimal(str(product["selling_price"]))
                back_rate = Decimal(str(product["back_rate"]))
                back_type = product["back_type"]
                gross = unit_price * quantity
                if back_type == "percentage":
                    back_total = money(gross * back_rate / Decimal("100"))
                elif back_type == "fixed_per_unit":
                    back_total = money(back_rate * quantity)
                else:
                    back_total = Decimal("0.00")

                allocation_rate_total = sum(
                    (Decimal(str(a.allocation_rate)) for a in item.allocations),
                    Decimal("0"),
                )
                if allocation_rate_total > Decimal("100"):
                    raise HTTPException(status_code=422, detail="Back allocation rates must total 100% or less")

                cur.execute(
                    """INSERT INTO order_items
                       (store_id, order_id, product_id, product_name_snapshot,
                        quantity, unit_price_snapshot, back_rate_snapshot,
                        back_type_snapshot, back_amount_total, status)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'active')""",
                    (store_id, order_id, product["id"], product["product_name"],
                     str(quantity), str(unit_price), str(back_rate), back_type, str(back_total)),
                )
                item_id = cur.lastrowid
                allocations_created = []

                # Allocation rates are shares of the item's calculated back pool.
                # The final recipient receives the remaining cents to avoid rounding drift.
                remaining = back_total
                for index, allocation in enumerate(item.allocations):
                    cur.execute(
                        "SELECT id FROM casts WHERE store_id=%s AND id=%s AND is_active=1",
                        (store_id, allocation.cast_id),
                    )
                    if not cur.fetchone():
                        raise HTTPException(status_code=404, detail=f"Cast {allocation.cast_id} not found")

                    rate = Decimal(str(allocation.allocation_rate))
                    if index == len(item.allocations) - 1:
                        allocated_amount = min(remaining, money(back_total * rate / Decimal("100")))
                        if allocation_rate_total == Decimal("100"):
                            allocated_amount = remaining
                    else:
                        allocated_amount = min(remaining, money(back_total * rate / Decimal("100")))
                    remaining -= allocated_amount

                    cur.execute(
                        """INSERT INTO order_item_back_allocations
                           (store_id, order_item_id, cast_id, allocation_rate, back_amount, status)
                           VALUES (%s,%s,%s,%s,%s,'active')""",
                        (store_id, item_id, allocation.cast_id, str(rate), str(allocated_amount)),
                    )
                    allocations_created.append({
                        "cast_id": allocation.cast_id,
                        "allocation_rate": str(rate),
                        "back_amount": str(allocated_amount),
                    })

                created_items.append({
                    "order_item_id": item_id,
                    "product_name": product["product_name"],
                    "quantity": str(quantity),
                    "unit_price": str(unit_price),
                    "back_amount_total": str(back_total),
                    "allocations": allocations_created,
                })

            conn.commit()
            return {
                "order_id": order_id,
                "order_number": order_number,
                "status": "confirmed",
                "replayed": False,
                "items": created_items,
            }
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Order could not be saved; retry with the same idempotency key") from None
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()
