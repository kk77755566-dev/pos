"""Initial API foundation for Girls Bar POS.

This module deliberately starts with health, login and table-list endpoints.
Business writes are added only after database integration tests are available.
"""
from __future__ import annotations

import os
from datetime import datetime, timedelta, timezone
from typing import Any

import bcrypt
import jwt
import pymysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, Field

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
