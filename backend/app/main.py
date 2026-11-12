"""Initial API foundation for Girls Bar POS.

This module deliberately starts with health, login and table-list endpoints.
Business writes are added only after database integration tests are available.
"""
from __future__ import annotations

import os
import uuid
import json
from decimal import Decimal, ROUND_HALF_UP
from datetime import datetime, timedelta, timezone
from typing import Any

import bcrypt
import jwt
import pymysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
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
allowed_origins = [origin.strip() for origin in os.getenv("CORS_ORIGINS", "").split(",") if origin.strip()]
if allowed_origins:
    app.add_middleware(
        CORSMiddleware, allow_origins=allowed_origins, allow_credentials=False,
        allow_methods=["GET", "POST", "PUT", "OPTIONS"],
        allow_headers=["Authorization", "Content-Type"],
    )
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


class SessionCreateRequest(BaseModel):
    table_id: int = Field(gt=0)
    customer_count: int = Field(default=1, ge=1, le=100)
    started_at: datetime | None = None
    idempotency_key: str = Field(min_length=8, max_length=100)


class SessionSetRequest(BaseModel):
    set_menu_id: int = Field(gt=0)
    started_at: datetime | None = None


@app.post("/api/sessions")
def create_session(body: SessionCreateRequest, user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    store_id = user["store_id"]
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, table_id, customer_count, status FROM table_sessions WHERE store_id=%s AND idempotency_key=%s",
                (store_id, body.idempotency_key),
            )
            old = cur.fetchone()
            if old:
                conn.commit()
                return {"session_id": old["id"], "table_id": old["table_id"], "status": old["status"], "replayed": True}

            cur.execute(
                "SELECT id, status FROM tables WHERE store_id=%s AND id=%s AND is_active=1 FOR UPDATE",
                (store_id, body.table_id),
            )
            table = cur.fetchone()
            if not table:
                raise HTTPException(status_code=404, detail="Table not found")
            if table["status"] != "available":
                raise HTTPException(status_code=409, detail="Table is not available")

            cur.execute(
                """SELECT id FROM table_sessions
                    WHERE store_id=%s AND table_id=%s AND status IN ('open','checkout')
                    LIMIT 1 FOR UPDATE""",
                (store_id, body.table_id),
            )
            if cur.fetchone():
                raise HTTPException(status_code=409, detail="Table already has an active session")

            cur.execute(
                """INSERT INTO table_sessions
                   (store_id, table_id, customer_count, started_at, status,
                    created_by_user_id, idempotency_key)
                   VALUES (%s,%s,%s,COALESCE(%s,UTC_TIMESTAMP(3)),'open',%s,%s)""",
                (store_id, body.table_id, body.customer_count, body.started_at,
                 user["user_id"], body.idempotency_key),
            )
            session_id = cur.lastrowid
            cur.execute("UPDATE tables SET status='occupied' WHERE store_id=%s AND id=%s",
                        (store_id, body.table_id))
            conn.commit()
            return {"session_id": session_id, "table_id": body.table_id, "status": "open", "replayed": False}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Session could not be saved") from None
    finally:
        conn.close()


@app.post("/api/sessions/{session_id}/sets")
def add_session_set(session_id: int, body: SessionSetRequest,
                    user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, status FROM table_sessions WHERE store_id=%s AND id=%s FOR UPDATE",
                (user["store_id"], session_id),
            )
            session = cur.fetchone()
            if not session or session["status"] != "open":
                raise HTTPException(status_code=409, detail="Session is not open")

            cur.execute(
                """SELECT id, name, duration_minutes, price, is_extension
                     FROM set_menus
                    WHERE store_id=%s AND id=%s AND is_active=1 FOR UPDATE""",
                (user["store_id"], body.set_menu_id),
            )
            menu = cur.fetchone()
            if not menu:
                raise HTTPException(status_code=404, detail="Set menu not found")

            start = body.started_at
            if start is None:
                cur.execute("SELECT UTC_TIMESTAMP(3) AS now_utc")
                start = cur.fetchone()["now_utc"]
            else:
                if start.tzinfo is None:
                    raise HTTPException(status_code=422, detail="started_at must include timezone")
                start = start.astimezone(timezone.utc).replace(tzinfo=None)

            end = start + timedelta(minutes=int(menu["duration_minutes"]))
            cur.execute(
                """INSERT INTO session_set_items
                   (store_id, session_id, set_menu_id, name_snapshot,
                    duration_minutes_snapshot, price_snapshot, started_at, ends_at, status)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,'active')""",
                (user["store_id"], session_id, menu["id"], menu["name"],
                 menu["duration_minutes"], menu["price"], start, end),
            )
            set_item_id = cur.lastrowid
            conn.commit()
            return {
                "session_set_item_id": set_item_id,
                "name": menu["name"],
                "is_extension": bool(menu["is_extension"]),
                "price": str(menu["price"]),
                "started_at": start.isoformat(),
                "ends_at": end.isoformat(),
            }
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Set registration failed") from None
    finally:
        conn.close()


class ClockInRequest(BaseModel):
    cast_id: int = Field(gt=0)
    business_date: str = Field(pattern=r"^\d{4}-\d{2}-\d{2}$")


class ClockOutRequest(BaseModel):
    attendance_id: int = Field(gt=0)
    break_minutes: int = Field(default=0, ge=0, le=1440)


def require_management(role: str):
    if role not in {"owner", "manager"}:
        raise HTTPException(status_code=403, detail="Manager permission required")


@app.post("/api/attendance/clock-in")
def clock_in(body: ClockInRequest, user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM casts WHERE store_id=%s AND id=%s AND is_active=1 FOR UPDATE",
                        (user["store_id"], body.cast_id))
            if not cur.fetchone():
                raise HTTPException(status_code=404, detail="Cast not found")
            cur.execute(
                "SELECT id FROM attendance WHERE store_id=%s AND cast_id=%s AND clock_out_at IS NULL LIMIT 1 FOR UPDATE",
                (user["store_id"], body.cast_id),
            )
            if cur.fetchone():
                raise HTTPException(status_code=409, detail="Cast is already clocked in")
            cur.execute(
                """INSERT INTO attendance
                   (store_id,cast_id,business_date,clock_in_at,status,created_by_user_id)
                   VALUES (%s,%s,%s,UTC_TIMESTAMP(3),'working',%s)""",
                (user["store_id"], body.cast_id, body.business_date, user["user_id"]),
            )
            attendance_id = cur.lastrowid
            conn.commit()
            return {"attendance_id": attendance_id, "cast_id": body.cast_id, "status": "working"}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Clock-in could not be saved") from None
    finally:
        conn.close()


@app.post("/api/attendance/clock-out")
def clock_out(body: ClockOutRequest, user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id, clock_in_at, clock_out_at FROM attendance
                    WHERE store_id=%s AND id=%s FOR UPDATE""",
                (user["store_id"], body.attendance_id),
            )
            row = cur.fetchone()
            if not row:
                raise HTTPException(status_code=404, detail="Attendance record not found")
            if row["clock_out_at"] is not None:
                raise HTTPException(status_code=409, detail="Already clocked out")
            cur.execute(
                """UPDATE attendance SET clock_out_at=UTC_TIMESTAMP(3),
                       break_minutes=%s,status='completed',updated_by_user_id=%s
                    WHERE store_id=%s AND id=%s""",
                (body.break_minutes, user["user_id"], user["store_id"], body.attendance_id),
            )
            conn.commit()
            return {"attendance_id": body.attendance_id, "status": "completed"}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Clock-out could not be saved") from None
    finally:
        conn.close()


@app.get("/api/casts/{cast_id}/payroll")
def get_cast_payroll(cast_id: int, business_date: str,
                     user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT a.id, a.clock_in_at, a.clock_out_at, a.break_minutes,
                          c.hourly_wage,
                          COALESCE((SELECT p.hourly_wage FROM cast_pay_rules p
                                    WHERE p.store_id=a.store_id AND p.cast_id=a.cast_id
                                      AND p.valid_from <= a.business_date
                                      AND (p.valid_to IS NULL OR p.valid_to >= a.business_date)
                                    ORDER BY p.valid_from DESC LIMIT 1), c.hourly_wage) AS wage
                     FROM attendance a JOIN casts c
                       ON c.store_id=a.store_id AND c.id=a.cast_id
                    WHERE a.store_id=%s AND a.cast_id=%s AND a.business_date=%s
                    ORDER BY a.clock_in_at""",
                (user["store_id"], cast_id, business_date),
            )
            shifts = cur.fetchall()
            if not shifts:
                raise HTTPException(status_code=404, detail="No attendance for this date")
            if any(s["clock_out_at"] is None for s in shifts):
                raise HTTPException(status_code=409, detail="Clock-out is missing; payroll is not final")
            cur.execute(
                """SELECT COALESCE(SUM(a.back_amount),0) AS back_total
                     FROM order_item_back_allocations a
                     JOIN order_items oi ON oi.store_id=a.store_id AND oi.id=a.order_item_id
                     JOIN orders o ON o.store_id=oi.store_id AND o.id=oi.order_id
                     JOIN table_sessions ts ON ts.store_id=o.store_id AND ts.id=o.session_id
                    WHERE a.store_id=%s AND a.cast_id=%s AND a.status='active'
                      AND DATE(ts.started_at)=%s""",
                (user["store_id"], cast_id, business_date),
            )
            back_total = Decimal(str(cur.fetchone()["back_total"] or 0))
            cur.execute(
                "SELECT work_rounding_minutes, work_rounding_mode FROM store_settings WHERE store_id=%s",
                (user["store_id"],),
            )
            settings = cur.fetchone()
            if not settings:
                raise HTTPException(status_code=409, detail="Store settings are missing")
            raw_minutes = 0
            hourly_total = Decimal("0")
            for shift in shifts:
                duration = shift["clock_out_at"] - shift["clock_in_at"]
                minutes = max(0, int(duration.total_seconds() // 60) - int(shift["break_minutes"]))
                raw_minutes += minutes
                unit = int(settings["work_rounding_minutes"])
                if settings["work_rounding_mode"] == "floor":
                    payable = (minutes // unit) * unit
                elif settings["work_rounding_mode"] == "ceil":
                    payable = ((minutes + unit - 1) // unit) * unit
                else:
                    payable = int((Decimal(minutes) / Decimal(unit)).quantize(Decimal("1"), rounding=ROUND_HALF_UP)) * unit
                hourly_total += Decimal(str(shift["wage"])) * Decimal(payable) / Decimal(60)
            hourly_total = money(hourly_total)
            return {
                "cast_id": cast_id, "business_date": business_date,
                "shift_count": len(shifts), "work_minutes_raw": raw_minutes,
                "hourly_pay": str(hourly_total), "back_pay": str(money(back_total)),
                "total_pay": str(money(hourly_total + back_total)),
                "rounding_minutes": int(settings["work_rounding_minutes"]),
                "rounding_mode": settings["work_rounding_mode"],
                "provisional": True,
                "notice": "深夜・時間外割増、最低賃金、法定休憩等の確認前の暫定集計です。",
            }
    finally:
        conn.close()


class PaymentRequest(BaseModel):
    session_id: int = Field(gt=0)
    payment_method: str = Field(pattern=r"^(cash|credit_card|qr|electronic_money|transfer|other)$")
    amount: Decimal = Field(gt=0)
    idempotency_key: str = Field(min_length=8, max_length=100)


def session_bill(cur, store_id: int, session_id: int):
    cur.execute(
        """SELECT COALESCE(SUM(price_snapshot),0) AS sets_total
             FROM session_set_items
            WHERE store_id=%s AND session_id=%s AND status IN ('active','completed')""",
        (store_id, session_id),
    )
    sets_total = Decimal(str(cur.fetchone()["sets_total"] or 0))
    cur.execute(
        """SELECT COALESCE(SUM(oi.quantity * oi.unit_price_snapshot),0) AS items_total
             FROM orders o JOIN order_items oi
               ON oi.store_id=o.store_id AND oi.order_id=o.id
            WHERE o.store_id=%s AND o.session_id=%s
              AND o.status='confirmed' AND oi.status='active'""",
        (store_id, session_id),
    )
    items_total = Decimal(str(cur.fetchone()["items_total"] or 0))
    cur.execute("SELECT * FROM store_settings WHERE store_id=%s", (store_id,))
    settings = cur.fetchone()
    if not settings:
        raise HTTPException(status_code=409, detail="Store settings are missing")

    subtotal = sets_total + items_total
    if settings["service_enabled"]:
        basis = subtotal
        if settings["service_basis"] == "sets_only":
            basis = sets_total
        elif settings["service_basis"] == "items_only":
            basis = items_total
        if settings["service_mode"] == "fixed":
            service = Decimal(str(settings["service_fixed_amount"]))
        else:
            service = money(basis * Decimal(str(settings["service_rate"])) / Decimal("100"))
    else:
        service = Decimal("0.00")

    taxable = subtotal + (service if settings["service_taxable"] else Decimal("0"))
    if settings["tax_enabled"]:
        rate = Decimal(str(settings["tax_rate"]))
        non_taxable_service = service if not settings["service_taxable"] else Decimal("0")
        if settings["tax_mode"] == "exclusive":
            tax = money(taxable * rate / Decimal("100"))
            total = taxable + tax + non_taxable_service
        else:
            tax = money(taxable * rate / (Decimal("100") + rate)) if rate else Decimal("0")
            total = taxable + non_taxable_service
    else:
        tax = Decimal("0.00")
        total = subtotal + service
    return {
        "sets_total": money(sets_total), "items_total": money(items_total),
        "subtotal": money(subtotal), "service": money(service),
        "tax": money(tax), "total": money(total),
    }


@app.get("/api/sessions/{session_id}/bill")
def get_session_bill(session_id: int, user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM table_sessions WHERE store_id=%s AND id=%s",
                        (user["store_id"], session_id))
            if not cur.fetchone():
                raise HTTPException(status_code=404, detail="Session not found")
            bill = session_bill(cur, user["store_id"], session_id)
            cur.execute(
                """SELECT COALESCE(SUM(amount),0) AS paid FROM payments
                    WHERE store_id=%s AND session_id=%s AND status='completed'""",
                (user["store_id"], session_id),
            )
            paid = money(Decimal(str(cur.fetchone()["paid"] or 0)))
            return {k: str(v) for k, v in bill.items()} | {
                "paid": str(paid), "balance": str(money(bill["total"] - paid))
            }
    finally:
        conn.close()


@app.post("/api/payments")
def register_payment(body: PaymentRequest, user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    store_id = user["store_id"]
    amount = money(Decimal(str(body.amount)))
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id, table_id, status FROM table_sessions
                    WHERE store_id=%s AND id=%s FOR UPDATE""",
                (store_id, body.session_id),
            )
            session = cur.fetchone()
            if not session or session["status"] not in {"open", "checkout"}:
                raise HTTPException(status_code=409, detail="Session cannot accept payment")
            cur.execute(
                "SELECT id FROM payments WHERE store_id=%s AND idempotency_key=%s",
                (store_id, body.idempotency_key),
            )
            old = cur.fetchone()
            if old:
                conn.commit()
                return {"payment_id": old["id"], "replayed": True}

            bill = session_bill(cur, store_id, body.session_id)
            cur.execute(
                """SELECT COALESCE(SUM(amount),0) AS paid FROM payments
                    WHERE store_id=%s AND session_id=%s AND status='completed'""",
                (store_id, body.session_id),
            )
            paid = Decimal(str(cur.fetchone()["paid"] or 0))
            balance = money(bill["total"] - paid)
            if amount > balance:
                raise HTTPException(status_code=422, detail=f"Payment exceeds remaining balance {balance}")

            cur.execute(
                """INSERT INTO payments
                   (store_id,session_id,payment_method,amount,status,idempotency_key,
                    paid_at,created_by_user_id)
                   VALUES (%s,%s,%s,%s,'completed',%s,UTC_TIMESTAMP(3),%s)""",
                (store_id, body.session_id, body.payment_method, str(amount),
                 body.idempotency_key, user["user_id"]),
            )
            payment_id = cur.lastrowid
            new_paid = paid + amount
            new_balance = money(bill["total"] - new_paid)
            if new_balance == 0:
                cur.execute(
                    "UPDATE table_sessions SET status='closed',ended_at=UTC_TIMESTAMP(3) WHERE store_id=%s AND id=%s",
                    (store_id, body.session_id),
                )
                cur.execute(
                    "UPDATE tables SET status='available' WHERE store_id=%s AND id=%s",
                    (store_id, session["table_id"]),
                )
            else:
                cur.execute(
                    "UPDATE table_sessions SET status='checkout' WHERE store_id=%s AND id=%s",
                    (store_id, body.session_id),
                )
                cur.execute(
                    "UPDATE tables SET status='checkout' WHERE store_id=%s AND id=%s",
                    (store_id, session["table_id"]),
                )
            conn.commit()
            return {
                "payment_id": payment_id, "replayed": False,
                "paid_total": str(money(new_paid)), "balance": str(new_balance),
                "session_status": "closed" if new_balance == 0 else "checkout",
            }
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Payment could not be saved") from None
    finally:
        conn.close()


class DailyCloseRequest(BaseModel):
    business_date: str = Field(pattern=r"^\d{4}-\d{2}-\d{2}$")


@app.post("/api/closings/run")
def run_daily_closing(body: DailyCloseRequest, user: dict[str, Any] = Depends(current_user)):
    """Close a business date only when all blocking integrity checks pass."""
    require_management(user["role"])
    store_id = user["store_id"]
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO daily_closings
                   (store_id,business_date,status,settings_snapshot,started_at)
                   VALUES (%s,%s,'processing',JSON_OBJECT(),UTC_TIMESTAMP(3))
                   ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)""",
                (store_id, body.business_date),
            )
            closing_id = cur.lastrowid
            cur.execute(
                "SELECT id,status FROM daily_closings WHERE store_id=%s AND id=%s FOR UPDATE",
                (store_id, closing_id),
            )
            closing = cur.fetchone()
            if closing["status"] == "closed":
                conn.commit()
                return {"closing_id": closing_id, "status": "closed", "replayed": True, "errors": []}

            cur.execute(
                "SELECT * FROM store_settings WHERE store_id=%s FOR UPDATE",
                (store_id,),
            )
            settings = cur.fetchone()
            if not settings:
                raise HTTPException(status_code=409, detail="Store settings are missing")

            cur.execute(
                """SELECT id,table_id FROM table_sessions
                    WHERE store_id=%s AND DATE(started_at)=%s
                      AND status IN ('open','checkout') FOR UPDATE""",
                (store_id, body.business_date),
            )
            open_sessions = cur.fetchall()
            errors = [
                {"code": "OPEN_SESSION", "description": f"未精算または営業中の卓セッション ID={s['id']}", "type": "table_session", "id": s["id"]}
                for s in open_sessions
            ]

            cur.execute(
                """SELECT id,cast_id FROM attendance
                    WHERE store_id=%s AND business_date=%s AND clock_out_at IS NULL
                    FOR UPDATE""",
                (store_id, body.business_date),
            )
            open_attendance = cur.fetchall()
            errors.extend([
                {"code": "MISSING_CLOCK_OUT", "description": f"退勤未登録の出勤記録 ID={a['id']}", "type": "attendance", "id": a["id"]}
                for a in open_attendance
            ])

            cur.execute(
                """SELECT DISTINCT a.cast_id
                     FROM attendance a
                    WHERE a.store_id=%s AND a.business_date=%s
                      AND NOT EXISTS (
                        SELECT 1 FROM payroll_details p
                         WHERE p.store_id=a.store_id AND p.cast_id=a.cast_id
                           AND p.business_date=a.business_date
                           AND p.status IN ('confirmed','paid')
                      )""",
                (store_id, body.business_date),
            )
            missing_payroll = cur.fetchall()
            errors.extend([
                {"code": "PAYROLL_NOT_CONFIRMED", "description": f"給与未確定のキャスト ID={p['cast_id']}", "type": "cast", "id": p["cast_id"]}
                for p in missing_payroll
            ])

            cur.execute(
                """SELECT COALESCE(SUM(amount),0) AS total,
                          COALESCE(SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END),0) AS cash_total,
                          COALESCE(SUM(CASE WHEN payment_method='credit_card' THEN amount ELSE 0 END),0) AS card_total,
                          COALESCE(SUM(CASE WHEN payment_method NOT IN ('cash','credit_card') THEN amount ELSE 0 END),0) AS other_total
                     FROM payments p JOIN table_sessions s
                       ON s.store_id=p.store_id AND s.id=p.session_id
                    WHERE p.store_id=%s AND DATE(s.started_at)=%s AND p.status='completed'""",
                (store_id, body.business_date),
            )
            sales = cur.fetchone()
            cur.execute(
                "SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE store_id=%s AND business_date=%s",
                (store_id, body.business_date),
            )
            expenses = Decimal(str(cur.fetchone()["total"] or 0))
            cur.execute(
                """SELECT COALESCE(SUM(total_pay),0) AS total
                     FROM payroll_details
                    WHERE store_id=%s AND business_date=%s AND status IN ('confirmed','paid')""",
                (store_id, body.business_date),
            )
            payroll = Decimal(str(cur.fetchone()["total"] or 0))

            cur.execute(
                """SELECT COALESCE(SUM(oi.quantity * oi.unit_price_snapshot),0) AS items
                     FROM orders o JOIN order_items oi
                       ON oi.store_id=o.store_id AND oi.order_id=o.id
                    WHERE o.store_id=%s AND DATE(o.ordered_at)=%s
                      AND o.status='confirmed' AND oi.status='active'""",
                (store_id, body.business_date),
            )
            item_sales = Decimal(str(cur.fetchone()["items"] or 0))
            cur.execute(
                """SELECT COALESCE(SUM(price_snapshot),0) AS sets
                     FROM session_set_items
                    WHERE store_id=%s AND DATE(started_at)=%s
                      AND status IN ('active','completed')""",
                (store_id, body.business_date),
            )
            set_sales = Decimal(str(cur.fetchone()["sets"] or 0))

            cur.execute(
                """SELECT id,table_id FROM table_sessions
                    WHERE store_id=%s AND DATE(started_at)=%s AND status='closed'""",
                (store_id, body.business_date),
            )
            closed_sessions = cur.fetchall()
            service_total = Decimal("0.00")
            tax_total = Decimal("0.00")
            for s in closed_sessions:
                bill = session_bill(cur, store_id, s["id"])
                service_total += bill["service"]
                tax_total += bill["tax"]
                cur.execute(
                    """SELECT COALESCE(SUM(amount),0) AS paid FROM payments
                        WHERE store_id=%s AND session_id=%s AND status='completed'""",
                    (store_id, s["id"]),
                )
                paid = Decimal(str(cur.fetchone()["paid"] or 0))
                if money(paid) != money(bill["total"]):
                    errors.append({
                        "code": "PAYMENT_MISMATCH",
                        "description": f"会計金額と入金額が一致しません。卓セッション ID={s['id']}",
                        "type": "table_session", "id": s["id"],
                    })

            # Preserve the history of prior failed attempts; resolve them before recording this run.
            cur.execute(
                """UPDATE closing_errors SET resolved_at=UTC_TIMESTAMP(3)
                    WHERE store_id=%s AND daily_closing_id=%s AND resolved_at IS NULL""",
                (store_id, closing_id),
            )
            for error in errors:
                cur.execute(
                    """INSERT INTO closing_errors
                       (store_id,daily_closing_id,error_code,description,reference_type,reference_id)
                       VALUES (%s,%s,%s,%s,%s,%s)""",
                    (store_id, closing_id, error["code"], error["description"], error["type"], error["id"]),
                )

            snapshot = {
                "tax_enabled": bool(settings["tax_enabled"]),
                "tax_rate": str(settings["tax_rate"]),
                "tax_mode": settings["tax_mode"],
                "service_enabled": bool(settings["service_enabled"]),
                "service_rate": str(settings["service_rate"]),
                "service_mode": settings["service_mode"],
                "service_fixed_amount": str(settings["service_fixed_amount"]),
                "service_taxable": bool(settings["service_taxable"]),
                "service_basis": settings["service_basis"],
            }
            sales_total = Decimal(str(sales["total"] or 0))
            cur.execute(
                """UPDATE daily_closings SET status=%s,
                       sales_subtotal=%s, service_total=%s, tax_total=%s, sales_total=%s,
                       cash_total=%s,card_total=%s,other_total=%s,
                       payroll_total=%s,expense_total=%s,difference=%s,
                       settings_snapshot=%s,closed_at=%s
                    WHERE store_id=%s AND id=%s""",
                ("blocked" if errors else "closed",
                 str(money(set_sales + item_sales)), str(money(service_total)),
                 str(money(tax_total)), str(money(sales_total)),
                 str(money(Decimal(str(sales["cash_total"] or 0)))),
                 str(money(Decimal(str(sales["card_total"] or 0)))),
                 str(money(Decimal(str(sales["other_total"] or 0)))),
                 str(money(payroll)), str(money(expenses)),
                 str(money(sales_total - expenses - payroll)),
                 json.dumps(snapshot, ensure_ascii=False),
                 None if errors else datetime.now(timezone.utc).replace(tzinfo=None),
                 store_id, closing_id),
            )
            cur.execute(
                """INSERT INTO audit_logs
                   (store_id,user_id,action,target_type,target_id,after_json)
                   VALUES (%s,%s,%s,%s,%s,%s)""",
                (store_id, user["user_id"], "daily_close_blocked" if errors else "daily_close_confirmed",
                 "daily_closing", closing_id,
                 json.dumps({"business_date": body.business_date, "errors": len(errors)}, ensure_ascii=False)),
            )
            conn.commit()
            return {
                "closing_id": closing_id,
                "business_date": body.business_date,
                "status": "blocked" if errors else "closed",
                "sales_subtotal": str(money(set_sales + item_sales)),
                "received_total": str(money(sales_total)),
                "payroll_total": str(money(payroll)),
                "expense_total": str(money(expenses)),
                "difference": str(money(sales_total - expenses - payroll)),
                "errors": errors,
                "replayed": False,
            }
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Daily closing could not be completed") from None
    finally:
        conn.close()


@app.get("/api/closings/{business_date}")
def get_daily_closing(business_date: str, user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT * FROM daily_closings WHERE store_id=%s AND business_date=%s",
                (user["store_id"], business_date),
            )
            closing = cur.fetchone()
            if not closing:
                raise HTTPException(status_code=404, detail="Daily closing not found")
            cur.execute(
                """SELECT error_code,description,reference_type,reference_id,resolved_at
                     FROM closing_errors
                    WHERE store_id=%s AND daily_closing_id=%s ORDER BY id""",
                (user["store_id"], closing["id"]),
            )
            errors = cur.fetchall()
            for key in ("settings_snapshot",):
                if isinstance(closing.get(key), (str, bytes)):
                    closing[key] = json.loads(closing[key])
            for key, value in list(closing.items()):
                if isinstance(value, Decimal):
                    closing[key] = str(value)
                elif isinstance(value, datetime):
                    closing[key] = value.isoformat()
            return {"closing": closing, "errors": errors}
    finally:
        conn.close()


@app.get("/api/products")
def list_products(user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id,product_name,selling_price,back_rate,back_type
                     FROM products WHERE store_id=%s AND is_active=1
                    ORDER BY category_id,id""",
                (user["store_id"],),
            )
            return cur.fetchall()
    finally:
        conn.close()


@app.get("/api/set-menus")
def list_set_menus(user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id,name,duration_minutes,price,is_extension
                     FROM set_menus WHERE store_id=%s AND is_active=1
                    ORDER BY sort_order,id""",
                (user["store_id"],),
            )
            return cur.fetchall()
    finally:
        conn.close()


@app.get("/api/casts")
def list_casts(user: dict[str, Any] = Depends(current_user)):
    if user["role"] not in {"owner", "manager", "staff"}:
        raise HTTPException(status_code=403, detail="Insufficient permission")
    conn = db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id,cast_code,cast_name,hourly_wage
                     FROM casts WHERE store_id=%s AND is_active=1
                    ORDER BY cast_name,id""",
                (user["store_id"],),
            )
            return cur.fetchall()
    finally:
        conn.close()


class OrderCancelRequest(BaseModel):
    reason: str = Field(min_length=3, max_length=500)


@app.post("/api/orders/{order_id}/cancel")
def cancel_order(order_id: int, body: OrderCancelRequest,
                 user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                """SELECT id,session_id,status FROM orders
                    WHERE store_id=%s AND id=%s FOR UPDATE""",
                (user["store_id"], order_id),
            )
            order = cur.fetchone()
            if not order:
                raise HTTPException(status_code=404, detail="Order not found")
            if order["status"] == "cancelled":
                conn.commit()
                return {"order_id": order_id, "status": "cancelled", "replayed": True}

            cur.execute(
                """SELECT id FROM payments
                    WHERE store_id=%s AND session_id=%s AND status='completed'
                    LIMIT 1 FOR UPDATE""",
                (user["store_id"], order["session_id"]),
            )
            if cur.fetchone():
                raise HTTPException(status_code=409, detail="Cannot cancel an order after payment has been recorded; refund workflow is required")

            cur.execute(
                """UPDATE order_items SET status='cancelled',cancelled_at=UTC_TIMESTAMP(3)
                    WHERE store_id=%s AND order_id=%s AND status='active'""",
                (user["store_id"], order_id),
            )
            cur.execute(
                """UPDATE order_item_back_allocations a
                    JOIN order_items oi ON oi.store_id=a.store_id AND oi.id=a.order_item_id
                    SET a.status='reversed'
                    WHERE a.store_id=%s AND oi.order_id=%s AND a.status='active'""",
                (user["store_id"], order_id),
            )
            cur.execute(
                "UPDATE orders SET status='cancelled' WHERE store_id=%s AND id=%s",
                (user["store_id"], order_id),
            )
            cur.execute(
                """INSERT INTO audit_logs
                   (store_id,user_id,action,target_type,target_id,after_json)
                   VALUES (%s,%s,'order_cancelled','order',%s,%s)""",
                (user["store_id"], user["user_id"], order_id,
                 json.dumps({"reason": body.reason}, ensure_ascii=False)),
            )
            conn.commit()
            return {"order_id": order_id, "status": "cancelled", "replayed": False}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503, detail="Order cancellation failed") from None
    finally:
        conn.close()


@app.post("/api/casts/{cast_id}/payroll/confirm")
def confirm_cast_payroll(cast_id: int, business_date: str,
                         user: dict[str, Any] = Depends(current_user)):
    require_management(user["role"])
    conn = db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM casts WHERE store_id=%s AND id=%s AND is_active=1 FOR UPDATE",
                (user["store_id"], cast_id),
            )
            if not cur.fetchone():
                raise HTTPException(status_code=404, detail="Cast not found")
            cur.execute(
                """SELECT id,clock_in_at,clock_out_at,break_minutes FROM attendance
                    WHERE store_id=%s AND cast_id=%s AND business_date=%s
                    ORDER BY clock_in_at FOR UPDATE""",
                (user["store_id"], cast_id, business_date),
            )
            shifts = cur.fetchall()
            if not shifts:
                raise HTTPException(status_code=404, detail="No attendance for this date")
            if any(s["clock_out_at"] is None for s in shifts):
                raise HTTPException(status_code=409, detail="Clock-out is missing")
            cur.execute(
                """SELECT p.id FROM payroll_details p
                    WHERE p.store_id=%s AND p.cast_id=%s AND p.business_date=%s
                    FOR UPDATE""",
                (user["store_id"], cast_id, business_date),
            )
            if cur.fetchone():
                raise HTTPException(status_code=409, detail="Payroll already exists; use a controlled adjustment process")

            cur.execute(
                """SELECT c.hourly_wage,
                          COALESCE((SELECT r.hourly_wage FROM cast_pay_rules r
                                    WHERE r.store_id=c.store_id AND r.cast_id=c.id
                                      AND r.valid_from<=%s
                                      AND (r.valid_to IS NULL OR r.valid_to>=%s)
                                    ORDER BY r.valid_from DESC LIMIT 1),c.hourly_wage) AS wage
                     FROM casts c WHERE c.store_id=%s AND c.id=%s""",
                (business_date,business_date,user["store_id"],cast_id),
            )
            wage_row = cur.fetchone()
            cur.execute(
                "SELECT work_rounding_minutes,work_rounding_mode FROM store_settings WHERE store_id=%s",
                (user["store_id"],),
            )
            settings = cur.fetchone()
            if not settings:
                raise HTTPException(status_code=409, detail="Store settings are missing")
            raw_minutes=0
            payable_minutes=0
            hourly_total=Decimal("0")
            for shift in shifts:
                minutes=max(0,int((shift["clock_out_at"]-shift["clock_in_at"]).total_seconds()//60)-int(shift["break_minutes"]))
                raw_minutes+=minutes
                unit=int(settings["work_rounding_minutes"])
                mode=settings["work_rounding_mode"]
                if mode=="floor": payable=(minutes//unit)*unit
                elif mode=="ceil": payable=((minutes+unit-1)//unit)*unit
                else: payable=int((Decimal(minutes)/Decimal(unit)).quantize(Decimal("1"),rounding=ROUND_HALF_UP))*unit
                payable_minutes+=payable
                hourly_total+=Decimal(str(wage_row["wage"]))*Decimal(payable)/Decimal(60)

            cur.execute(
                """SELECT a.id,a.back_amount
                     FROM order_item_back_allocations a
                     JOIN order_items oi ON oi.store_id=a.store_id AND oi.id=a.order_item_id
                     JOIN orders o ON o.store_id=oi.store_id AND o.id=oi.order_id
                     JOIN table_sessions ts ON ts.store_id=o.store_id AND ts.id=o.session_id
                    WHERE a.store_id=%s AND a.cast_id=%s AND a.status='active'
                      AND DATE(ts.started_at)=%s
                    ORDER BY a.id FOR UPDATE""",
                (user["store_id"],cast_id,business_date),
            )
            allocations=cur.fetchall()
            back_total=sum((Decimal(str(a["back_amount"])) for a in allocations),Decimal("0"))
            hourly_total=money(hourly_total)
            back_total=money(back_total)
            total=money(hourly_total+back_total)
            cur.execute(
                """INSERT INTO payroll_details
                   (store_id,cast_id,business_date,work_minutes_raw,work_minutes_payable,
                    rounding_minutes_snapshot,rounding_mode_snapshot,hourly_wage_snapshot,
                    hourly_pay,back_pay,adjustment,total_pay,status)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,0,%s,'confirmed')""",
                (user["store_id"],cast_id,business_date,raw_minutes,payable_minutes,
                 settings["work_rounding_minutes"],settings["work_rounding_mode"],
                 str(wage_row["wage"]),str(hourly_total),str(back_total),str(total)),
            )
            payroll_id=cur.lastrowid
            for allocation in allocations:
                cur.execute(
                    """INSERT INTO payroll_back_details
                       (store_id,payroll_detail_id,back_allocation_id,back_amount)
                       VALUES (%s,%s,%s,%s)""",
                    (user["store_id"],payroll_id,allocation["id"],str(allocation["back_amount"])),
                )
            cur.execute(
                """INSERT INTO audit_logs
                   (store_id,user_id,action,target_type,target_id,after_json)
                   VALUES (%s,%s,'payroll_confirmed','payroll_detail',%s,%s)""",
                (user["store_id"],user["user_id"],payroll_id,
                 json.dumps({"cast_id":cast_id,"business_date":business_date,"total_pay":str(total)},ensure_ascii=False)),
            )
            conn.commit()
            return {"payroll_id":payroll_id,"cast_id":cast_id,"business_date":business_date,
                    "work_minutes_raw":raw_minutes,"work_minutes_payable":payable_minutes,
                    "hourly_pay":str(hourly_total),"back_pay":str(back_total),
                    "total_pay":str(total),"status":"confirmed"}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503,detail="Payroll confirmation failed") from None
    finally:
        conn.close()


class ProductCreate(BaseModel):
    product_name: str = Field(min_length=1, max_length=120)
    selling_price: Decimal = Field(ge=0)
    back_rate: Decimal = Field(default=0, ge=0, le=100)
    back_type: str = Field(default="percentage", pattern=r"^(percentage|fixed_per_unit|none)$")


class SetMenuCreate(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    duration_minutes: int = Field(gt=0, le=1440)
    price: Decimal = Field(ge=0)
    is_extension: bool = False


class CastCreate(BaseModel):
    cast_code: str = Field(min_length=1, max_length=40)
    cast_name: str = Field(min_length=1, max_length=120)
    hourly_wage: Decimal = Field(ge=0)


class TableCreate(BaseModel):
    table_name: str = Field(min_length=1, max_length=40)
    capacity: int = Field(default=4, ge=1, le=100)


def require_owner_or_manager(role: str):
    if role not in {"owner", "manager"}:
        raise HTTPException(status_code=403, detail="Manager permission required")


@app.post("/api/products")
def create_product(body: ProductCreate, user: dict[str, Any] = Depends(current_user)):
    require_owner_or_manager(user["role"])
    conn=db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO products (store_id,product_name,selling_price,back_rate,back_type)
                   VALUES (%s,%s,%s,%s,%s)""",
                (user["store_id"],body.product_name,str(body.selling_price),str(body.back_rate),body.back_type),
            )
            product_id=cur.lastrowid
            cur.execute(
                """INSERT INTO audit_logs (store_id,user_id,action,target_type,target_id)
                   VALUES (%s,%s,'product_created','product',%s)""",
                (user["store_id"],user["user_id"],product_id),
            )
            return {"product_id":product_id}
    except pymysql.MySQLError:
        raise HTTPException(status_code=409,detail="Product could not be created; check duplicate or database constraints") from None
    finally:
        conn.close()


@app.post("/api/set-menus")
def create_set_menu(body: SetMenuCreate, user: dict[str, Any] = Depends(current_user)):
    require_owner_or_manager(user["role"])
    conn=db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO set_menus (store_id,name,duration_minutes,price,is_extension)
                   VALUES (%s,%s,%s,%s,%s)""",
                (user["store_id"],body.name,body.duration_minutes,str(body.price),body.is_extension),
            )
            menu_id=cur.lastrowid
            cur.execute(
                """INSERT INTO audit_logs (store_id,user_id,action,target_type,target_id)
                   VALUES (%s,%s,'set_menu_created','set_menu',%s)""",
                (user["store_id"],user["user_id"],menu_id),
            )
            return {"set_menu_id":menu_id}
    except pymysql.MySQLError:
        raise HTTPException(status_code=409,detail="Set menu could not be created") from None
    finally:
        conn.close()


@app.post("/api/casts")
def create_cast(body: CastCreate, user: dict[str, Any] = Depends(current_user)):
    require_owner_or_manager(user["role"])
    conn=db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO casts (store_id,cast_code,cast_name,hourly_wage)
                   VALUES (%s,%s,%s,%s)""",
                (user["store_id"],body.cast_code,body.cast_name,str(body.hourly_wage)),
            )
            cast_id=cur.lastrowid
            cur.execute(
                """INSERT INTO audit_logs (store_id,user_id,action,target_type,target_id)
                   VALUES (%s,%s,'cast_created','cast',%s)""",
                (user["store_id"],user["user_id"],cast_id),
            )
            return {"cast_id":cast_id}
    except pymysql.MySQLError:
        raise HTTPException(status_code=409,detail="Cast could not be created; cast code may already exist") from None
    finally:
        conn.close()


@app.post("/api/tables")
def create_table(body: TableCreate, user: dict[str, Any] = Depends(current_user)):
    require_owner_or_manager(user["role"])
    conn=db_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO tables (store_id,table_name,capacity)
                   VALUES (%s,%s,%s)""",
                (user["store_id"],body.table_name,body.capacity),
            )
            table_id=cur.lastrowid
            cur.execute(
                """INSERT INTO audit_logs (store_id,user_id,action,target_type,target_id)
                   VALUES (%s,%s,'table_created','table',%s)""",
                (user["store_id"],user["user_id"],table_id),
            )
            return {"table_id":table_id}
    except pymysql.MySQLError:
        raise HTTPException(status_code=409,detail="Table could not be created; table name may already exist") from None
    finally:
        conn.close()


class StoreSettingsUpdate(BaseModel):
    tax_enabled: bool
    tax_rate: Decimal = Field(ge=0, le=100)
    tax_mode: str = Field(pattern=r"^(inclusive|exclusive)$")
    service_enabled: bool
    service_rate: Decimal = Field(ge=0, le=100)
    service_mode: str = Field(pattern=r"^(rate|fixed)$")
    service_fixed_amount: Decimal = Field(ge=0)
    service_taxable: bool
    service_basis: str = Field(pattern=r"^(sets_only|items_only|all_subtotal|configured)$")
    work_rounding_minutes: int = Field(ge=1, le=60)
    work_rounding_mode: str = Field(pattern=r"^(floor|half_up|ceil)$")


@app.put("/api/settings")
def update_store_settings(body: StoreSettingsUpdate, user: dict[str, Any] = Depends(current_user)):
    require_owner_or_manager(user["role"])
    if body.work_rounding_minutes not in {1,5,10,15,30,60}:
        raise HTTPException(status_code=422,detail="Unsupported work rounding unit")
    conn=db_connection()
    try:
        conn.begin()
        with conn.cursor() as cur:
            cur.execute(
                """UPDATE store_settings SET tax_enabled=%s,tax_rate=%s,tax_mode=%s,
                       service_enabled=%s,service_rate=%s,service_mode=%s,
                       service_fixed_amount=%s,service_taxable=%s,service_basis=%s,
                       work_rounding_minutes=%s,work_rounding_mode=%s,updated_by_user_id=%s
                    WHERE store_id=%s""",
                (body.tax_enabled,str(body.tax_rate),body.tax_mode,
                 body.service_enabled,str(body.service_rate),body.service_mode,
                 str(body.service_fixed_amount),body.service_taxable,body.service_basis,
                 body.work_rounding_minutes,body.work_rounding_mode,user["user_id"],user["store_id"]),
            )
            if cur.rowcount == 0:
                cur.execute("SELECT store_id FROM store_settings WHERE store_id=%s", (user["store_id"],))
                if not cur.fetchone():
                    raise HTTPException(status_code=409,detail="Store settings row is missing")
            cur.execute(
                """INSERT INTO audit_logs (store_id,user_id,action,target_type,after_json)
                   VALUES (%s,%s,'store_settings_updated','store_settings',%s)""",
                (user["store_id"],user["user_id"],json.dumps(body.model_dump(mode="json"),ensure_ascii=False)),
            )
        conn.commit()
        return {"updated":True}
    except HTTPException:
        conn.rollback()
        raise
    except pymysql.MySQLError:
        conn.rollback()
        raise HTTPException(status_code=503,detail="Settings update failed") from None
    finally:
        conn.close()
