import http.cookiejar
import os
import re
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8766/"
TOKEN = os.environ["POS_SETUP_TOKEN"]
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def request(path, method="GET", fields=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    headers = {"Accept": "text/html"}
    if data is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
    with opener.open(req) as response:
        return response.status, response.read().decode("utf-8", errors="replace")

status, page = request("setup.php")
assert status == 200 and "初回登録" in page
csrf = re.search(r'name="csrf" value="([^"]+)"', page).group(1)
status, result = request("setup.php", "POST", {
    "csrf": csrf,
    "setup_token": TOKEN,
    "store_code": "CI-SETUP-STORE",
    "store_name": "CI 初回設定テスト",
    "login_id": "setup-owner",
    "display_name": "CI Owner",
    "password": "ci-setup-owner-password-123",
})
assert status == 200 and "登録が完了しました" in result

# The setup endpoint must reject any subsequent attempt.
try:
    request("setup.php")
except Exception as exc:
    # urllib raises HTTPError for the expected 410.
    assert getattr(exc, "code", None) == 410, str(exc)
else:
    raise AssertionError("setup.php remained available after first setup")

# Confirm that the created owner can authenticate through the real login page.
status, login_page = request("index.php")
csrf_login = re.search(r'name="_csrf" value="([^"]+)"', login_page).group(1)
status, login_result = request("index.php", "POST", {
    "_csrf": csrf_login,
    "action": "login",
    "store_code": "CI-SETUP-STORE",
    "login_id": "setup-owner",
    "password": "ci-setup-owner-password-123",
})
assert status == 200 and "POS" in login_result
print("InfinityFree first-store setup and owner login: OK")
