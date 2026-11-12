from datetime import datetime, timezone
from decimal import Decimal

from fastapi.testclient import TestClient

from app.main import app


def test_basic_pos_flow_with_mysql():
    client = TestClient(app)
    login = client.post("/api/auth/login", json={
        "store_code": "CI-STORE",
        "login_id": "owner",
        "password": "ci-owner-password-123",
    })
    assert login.status_code == 200, login.text
    client.headers.update({"Authorization": "Bearer " + login.json()["access_token"]})

    table = client.post("/api/tables", json={"table_name": "CI-A", "capacity": 4})
    assert table.status_code == 200, table.text
    cast = client.post("/api/casts", json={
        "cast_code": "CI-C1", "cast_name": "CI Cast", "hourly_wage": "1500"
    })
    assert cast.status_code == 200, cast.text
    product = client.post("/api/products", json={
        "product_name": "CI Drink", "selling_price": "1000",
        "back_rate": "10", "back_type": "percentage"
    })
    assert product.status_code == 200, product.text
    menu = client.post("/api/set-menus", json={
        "name": "CI Set", "duration_minutes": 60, "price": "3000",
        "is_extension": False
    })
    assert menu.status_code == 200, menu.text

    session = client.post("/api/sessions", json={
        "table_id": table.json()["table_id"], "customer_count": 1,
        "idempotency_key": "ci-session-key-0001"
    })
    assert session.status_code == 200, session.text
    session_id = session.json()["session_id"]

    set_response = client.post(f"/api/sessions/{session_id}/sets", json={
        "set_menu_id": menu.json()["set_menu_id"]
    })
    assert set_response.status_code == 200, set_response.text

    order = client.post("/api/orders", json={
        "session_id": session_id,
        "idempotency_key": "ci-order-key-00001",
        "items": [{
            "product_id": product.json()["product_id"],
            "quantity": "2",
            "allocations": [{
                "cast_id": cast.json()["cast_id"], "allocation_rate": "100"
            }]
        }]
    })
    assert order.status_code == 200, order.text
    assert order.json()["items"][0]["back_amount_total"] == "200.00"

    bill = client.get(f"/api/sessions/{session_id}/bill")
    assert bill.status_code == 200, bill.text
    payment = client.post("/api/payments", json={
        "session_id": session_id, "payment_method": "cash",
        "amount": bill.json()["balance"], "idempotency_key": "ci-pay-key-000001"
    })
    assert payment.status_code == 200, payment.text
    assert payment.json()["session_status"] == "closed"
    refund = client.post(f"/api/payments/{payment.json()['payment_id']}/refund", json={
        "amount": "500", "reason": "CI partial refund",
        "idempotency_key": "ci-refund-key-0001"
    })
    assert refund.status_code == 200, refund.text
    assert refund.json()["remaining_refundable"] == str(Decimal(bill.json()["total"]) - Decimal("500.00"))

    business_date = datetime.now(timezone.utc).date().isoformat()
    clock_in = client.post("/api/attendance/clock-in", json={
        "cast_id": cast.json()["cast_id"], "business_date": business_date
    })
    assert clock_in.status_code == 200, clock_in.text
    clock_out = client.post("/api/attendance/clock-out", json={
        "attendance_id": clock_in.json()["attendance_id"], "break_minutes": 0
    })
    assert clock_out.status_code == 200, clock_out.text
    payroll = client.post(
        f"/api/casts/{cast.json()['cast_id']}/payroll/confirm",
        params={"business_date": business_date},
    )
    assert payroll.status_code == 200, payroll.text
    assert payroll.json()["status"] == "confirmed"

    closing = client.post("/api/closings/run", json={"business_date": business_date})
    assert closing.status_code == 200, closing.text
    assert closing.json()["status"] == "closed", closing.json()
