import fs from "node:fs";
import vm from "node:vm";

const html = fs.readFileSync(new URL("./index.html", import.meta.url), "utf8");
const scripts = [...html.matchAll(/<script>([\\s\\S]*?)<\\/script>/gi)];
if (scripts.length !== 1) throw new Error("Expected exactly one inline application script");
new vm.Script(scripts[0][1], { filename: "frontend/index.html:inline-script" });

const ids = new Set([...html.matchAll(/id="([^"]+)"/g)].map(match => match[1]));
const required = ["apiurl","loginbtn","store","loginid","password","tables","tableid","people","seat","sessionid","setmenu","addset","ordersession","product","quantity","cast","order","billsession","bill","payamount","method","pay","payrolldate","payrollcast","confirmPayroll","closedate","close","addtable","addcast","addproduct","addmenu","taxenabled","taxrate","taxmode","serviceenabled","servicemode","servicerate","servicefixed","servicebasis","servicetaxable","roundminutes","roundmode","savesettings","paymentid","refundamount","refundreason","refund"];
const missing = required.filter(id => !ids.has(id));
if (missing.length) throw new Error("Missing frontend element IDs: " + missing.join(", "));
console.log("Frontend JavaScript syntax and required element IDs: OK");
