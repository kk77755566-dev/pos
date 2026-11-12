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
pay_body = {"session_id": session_id, "payment_method": "cash", "amount": bill["total"], "idempotency_key": "ci-payment-unique-001"}
payment = api("payments", "POST", pay_body, 201)
assert payment["session_closed"] is True
assert api("payments", "POST", pay_body)["replayed"] is True

api(f"payments/{payment['payment_id']}/refund", "POST", {
    "amount": 100, "reason": "CI partial refund", "idempotency_key": "ci-refund-unique-001"
}, 201)

api("attendance/clock-in", "POST", {"cast_id": 1}, 200)
api("attendance/clock-out", "POST", {"cast_id": 1}, 200)
attendance = api("attendance")["data"]
assert attendance and attendance[0]["clock_out_at"]
business_date = attendance[0]["business_date"]
api(f"casts/1/payroll/confirm?business_date={business_date}", "POST", {}, 200)
api("expenses", "POST", {"business_date": business_date, "category": "CI", "amount": 200, "description": "test"}, 201)
closing = api("closings/run", "POST", {"business_date": business_date}, 200)
assert closing["status"] == "closed" and abs(float(closing["difference"])) < 0.01
api("sessions", "POST", {"table_id": 1, "customer_count": 1, "idempotency_key": "ci-after-close-001"}, 409)
print("InfinityFree PHP POS API integration: OK")
