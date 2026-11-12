import fs from "node:fs";
import vm from "node:vm";

const html = fs.readFileSync(new URL("./index.html", import.meta.url), "utf8");
const start = html.indexOf("<script>");
const end = html.indexOf("</script>", start + 8);
if (start < 0 || end < 0 || html.indexOf("<script>", start + 8) >= 0) {
  throw new Error("Expected exactly one inline application script");
}
const script = html.slice(start + 8, end);
new vm.Script(script, { filename: "frontend/index.html:inline-script" });

const ids = new Set([...html.matchAll(/id="([^"]+)"/g)].map(match => match[1]));
const required = ["apiurl","loginbtn","store","loginid","password","tables","tableid","people","seat","sessionid","setmenu","addset","ordersession","product","quantity","cast","order","billsession","bill","payamount","method","pay","payrolldate","payrollcast","confirmPayroll","closedate","close","addtable","addcast","addproduct","addmenu","taxenabled","taxrate","taxmode","serviceenabled","servicemode","servicerate","servicefixed","servicebasis","servicetaxable","roundminutes","roundmode","savesettings","paymentid","refundamount","refundreason","refund"];
const missing = required.filter(id => !ids.has(id));
if (missing.length) throw new Error("Missing frontend element IDs: " + missing.join(", "));
console.log("Frontend JavaScript syntax and required element IDs: OK");
