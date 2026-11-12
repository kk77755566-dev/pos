import http.cookiejar
import json
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8765/"
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def request(path, method="GET", data=None, form=False, csrf=None, expected=200):
    headers = {"Accept": "application/json"}
    payload = None
    if data is not None:
        if form:
            payload = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            payload = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
    if csrf and method != "GET":
        headers["X-CSRF-Token"] = csrf
    req = urllib.request.Request(BASE + path, data=payload, headers=headers, method=method)
    try:
        with opener.open(req) as response:
            status = response.status
            raw = response.read()
    except urllib.error.HTTPError as exc:
        status = exc.code
        raw = exc.read()
    try:
        result = json.loads(raw.decode() or "{}")
    except Exception:
        result = raw.decode(errors="replace")
    if status != expected:
        raise AssertionError(f"{method} {path}: expected {expected}, got {status}: {result}")
    return result

def api(route, method="GET", data=None, expected=200):
    path, sep, query = route.partition("?")
    url = "api.php?r=" + urllib.parse.quote(path, safe="")
    if sep:
        url += "&" + query
    return request(url, method, data, csrf=TOKEN if method != "GET" else None, expected=expected)

csrf = request("api.php?r=csrf")["csrf"]
request("index.php", "POST", {
    "_csrf": csrf, "action": "login", "store_code": "CI-POS-TEST",
    "login_id": "owner", "password": "ci-owner-password-123"
}, form=True, expected=200)
TOKEN = request("api.php?r=csrf")["csrf"]

for route in ("tables", "products", "sets", "casts", "settings"):
    assert "data" in api(route) or route == "settings"

api("settings", "PUT", {
    "tax_enabled": True, "tax_rate": 10, "tax_mode": "exclusive",
    "service_enabled": False, "service_rate": 0, "service_mode": "rate",
    "service_fixed_amount": 0, "service_taxable": True, "service_basis": "all_subtotal",
    "rounding_mode": "half_up", "work_rounding_minutes": 15, "work_rounding_mode": "floor"
})
new_table = api("tables", "POST", {"table_name": "B卓", "capacity": 3}, 201)["id"]
api(f"tables/{new_table}", "PUT", {"table_name": "B卓改", "capacity": 3, "is_active": True})
api(f"tables/{new_table}", "PUT", {"table_name": "B卓改", "capacity": 3, "is_active": False})
new_product = api("products", "POST", {"product_name": "テスト商品", "selling_price": 300, "back_rate": 10, "back_type": "percentage"}, 201)["id"]
api(f"products/{new_product}", "PUT", {"product_name": "テスト商品改", "selling_price": 350, "back_rate": 12, "back_type": "percentage", "is_active": True})
api(f"products/{new_product}", "PUT", {"product_name": "テスト商品改", "selling_price": 350, "back_rate": 12, "back_type": "percentage", "is_active": False})
new_set = api("sets", "POST", {"name": "テスト延長", "duration_minutes": 30, "price": 250, "is_extension": True}, 201)["id"]
api(f"sets/{new_set}", "PUT", {"name": "テスト延長改", "duration_minutes": 30, "price": 300, "is_extension": True, "is_active": True})
api(f"sets/{new_set}", "PUT", {"name": "テスト延長改", "duration_minutes": 30, "price": 300, "is_extension": True, "is_active": False})
new_cast = api("casts", "POST", {"cast_code": "C003", "cast_name": "キャストC", "hourly_wage": 1400}, 201)["id"]
api(f"casts/{new_cast}", "PUT", {"cast_code": "C003", "cast_name": "キャストC改", "hourly_wage": 1500, "is_active": True})
api(f"casts/{new_cast}/account", "POST", {"login_id": "castc", "password": "ci-cast-password-123"}, 201)

session_key = "ci-session-unique-001"
opened = api("sessions", "POST", {"table_id": 1, "customer_count": 2, "idempotency_key": session_key}, 201)
session_id = opened["data"]["session_id"]
replay = api("sessions", "POST", {"table_id": 1, "customer_count": 2, "idempotency_key": session_key})
assert replay["replayed"] is True

set_body = {"set_menu_id": 1, "idempotency_key": "ci-set-unique-001"}
api(f"sessions/{session_id}/sets", "POST", set_body, 201)
assert api(f"sessions/{session_id}/sets", "POST", set_body)["replayed"] is True

order_body = {
    "session_id": session_id, "idempotency_key": "ci-order-unique-001",
    "items": [{"product_id": 1, "quantity": 1, "allocations": [
        {"cast_id": 1, "allocation_rate": 60}, {"cast_id": 2, "allocation_rate": 40}
    ]}]
}
api("orders", "POST", order_body, 201)
assert api("orders", "POST", order_body)["replayed"] is True

bill = api(f"sessions/{session_id}/bill")
assert bill["total"] > 0 and bill["balance"] == bill["total"]
discount = api(f"sessions/{session_id}/discounts", "POST", {
    "discount_type": "fixed", "discount_value": 100, "reason": "CI discount",
    "idempotency_key": "ci-discount-unique-001"
}, 201)
discounted_bill = api(f"sessions/{session_id}/bill")
assert discounted_bill["discount"] == 100
assert discounted_bill["total"] < bill["total"]
pay_body = {"session_id": session_id, "payment_method": "cash", "amount": discounted_bill["total"], "idempotency_key": "ci-payment-unique-001"}
payment = api("payments", "POST", pay_body, 201)
assert payment["session_closed"] is True
assert api("payments", "POST", pay_body)["replayed"] is True

# Once payment starts, later store-setting edits must not alter this session's bill.
api("settings", "PUT", {
    "tax_enabled": True, "tax_rate": 20, "tax_mode": "exclusive",
    "service_enabled": True, "service_rate": 5, "service_mode": "rate",
    "service_fixed_amount": 0, "service_taxable": True, "service_basis": "all_subtotal",
    "rounding_mode": "ceil", "work_rounding_minutes": 15, "work_rounding_mode": "floor"
})
locked_bill = api(f"sessions/{session_id}/bill")
assert locked_bill["snapshot_locked"] is True
assert locked_bill["total"] == discounted_bill["total"], (discounted_bill, locked_bill)

api(f"payments/{payment['payment_id']}/refund", "POST", {
    "amount": 100, "reason": "CI partial refund", "idempotency_key": "ci-refund-unique-001"
}, 201)

# Verify store-configured whole-yen rounding is applied consistently to the bill.
round_product = api("products", "POST", {
    "product_name": "端数テスト商品", "selling_price": 333,
    "back_rate": 0, "back_type": "none"
}, 201)["id"]
round_session = api("sessions", "POST", {
    "table_id": 1, "customer_count": 1, "idempotency_key": "ci-round-session-001"
}, 201)["data"]["session_id"]
api("orders", "POST", {
    "session_id": round_session, "idempotency_key": "ci-round-order-001",
    "items": [{"product_id": round_product, "quantity": 1, "allocations": []}]
}, 201)
for mode, expected_total in (("floor", 366), ("half_up", 366), ("ceil", 367)):
    api("settings", "PUT", {
        "tax_enabled": True, "tax_rate": 10, "tax_mode": "exclusive",
        "service_enabled": False, "service_rate": 0, "service_mode": "rate",
        "service_fixed_amount": 0, "service_taxable": True, "service_basis": "all_subtotal",
        "rounding_mode": mode, "work_rounding_minutes": 15, "work_rounding_mode": "floor"
    })
    rounded_bill = api("sessions/" + str(round_session) + "/bill")
    assert rounded_bill["total"] == expected_total, (mode, rounded_bill)
round_payment = api("payments", "POST", {
    "session_id": round_session, "payment_method": "cash", "amount": 367,
    "idempotency_key": "ci-round-payment-001"
}, 201)
assert round_payment["session_closed"] is True

api("attendance/clock-in", "POST", {"cast_id": 1}, 200)
api("attendance/clock-out", "POST", {"cast_id": 1}, 200)
attendance = api("attendance")["data"]
assert attendance and attendance[0]["clock_out_at"]
business_date = attendance[0]["business_date"]
api(f"casts/1/payroll/confirm?business_date={business_date}", "POST", {}, 200)

# Cast accounts can see only their own attendance/payroll and clock themselves in/out.
cast_jar = http.cookiejar.CookieJar()
cast_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cast_jar))
def cast_request(path, method="GET", data=None, form=False, csrf=None, expected=200):
    headers = {"Accept": "application/json"}
    payload = None
    if data is not None:
        payload = urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()
        headers["Content-Type"] = "application/x-www-form-urlencoded" if form else "application/json"
    if csrf and method != "GET":
        headers["X-CSRF-Token"] = csrf
    req = urllib.request.Request(BASE + path, data=payload, headers=headers, method=method)
    try:
        with cast_opener.open(req) as response:
            status, raw = response.status, response.read()
    except urllib.error.HTTPError as exc:
        status, raw = exc.code, exc.read()
    try:
        result = json.loads(raw.decode() or "{}")
    except Exception:
        result = raw.decode(errors="replace")
    if status != expected:
        raise AssertionError(f"cast {method} {path}: expected {expected}, got {status}: {result}")
    return result

cast_csrf = cast_request("api.php?r=csrf")["csrf"]
cast_request("index.php", "POST", {
    "_csrf": cast_csrf, "action": "login", "store_code": "CI-POS-TEST",
    "login_id": "castc", "password": "ci-cast-password-123"
}, form=True, expected=200)
cast_csrf = cast_request("api.php?r=csrf")["csrf"]
cast_request("api.php?r=cast/clock-in", "POST", {}, csrf=cast_csrf)
cast_request("api.php?r=cast/clock-out", "POST", {}, csrf=cast_csrf)
api(f"casts/{new_cast}/payroll/confirm?business_date={business_date}", "POST", {}, 200)
cast_home = cast_request("api.php?r=cast/me")
assert cast_home["cast_id"] == new_cast
assert cast_home["attendance"] and cast_home["attendance"][0]["clock_out_at"]
assert cast_home["payroll"] and cast_home["payroll"][0]["status"] == "confirmed"
cast_request("api.php?r=settings", expected=403)
cast_request(f"api.php?r=sessions/{session_id}/bill", expected=403)

api("expenses", "POST", {"business_date": business_date, "category": "CI", "amount": 200, "description": "test"}, 201)
closing = api("closings/run", "POST", {"business_date": business_date}, 200)
assert closing["status"] == "closed" and abs(float(closing["difference"])) < 0.01
report = api(f"reports/sales?from={business_date}&to={business_date}")
assert report["days"] == 1 and report["summary"]["sales_total"] > 0
api("sessions", "POST", {"table_id": 1, "customer_count": 1, "idempotency_key": "ci-after-close-001"}, 409)
print("InfinityFree PHP POS API integration: OK")
