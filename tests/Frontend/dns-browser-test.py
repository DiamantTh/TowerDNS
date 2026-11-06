# SPDX-License-Identifier: AGPL-3.0-or-later
import json, os, socket, subprocess, tempfile, time, shutil
from pathlib import Path
root=str(Path(__file__).resolve().parents[2])
profile=tempfile.mkdtemp(prefix='towerdns-firefox-')
port=28570
with open(profile+'/user.js','w') as f: f.write('user_pref("marionette.port", %d);\nuser_pref("marionette.enabled", true);\n' % port)
server=subprocess.Popen(['php','-S','127.0.0.1:28571',root+'/tests/Frontend/dns-browser-router.php'],cwd=root,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
env=os.environ.copy(); env['MOZ_DISABLE_CONTENT_SANDBOX']='1'; env['MOZ_HEADLESS']='1'
browser=subprocess.Popen(['firefox','--headless','--marionette','--no-remote','--profile',profile],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
sock=None
try:
 for _ in range(100):
  try: sock=socket.create_connection(('127.0.0.1',port),timeout=1); break
  except OSError: time.sleep(.2)
 if sock is None: raise RuntimeError('Firefox Marionette did not start')
 sock.settimeout(30)
 def receive():
  prefix=b''
  while True:
   c=sock.recv(1)
   if c==b':': break
   if not c: raise RuntimeError('Marionette disconnected')
   prefix+=c
  n=int(prefix); data=b''
  while len(data)<n: data+=sock.recv(n-len(data))
  return json.loads(data)
 def command(i,name,params):
  data=json.dumps([0,i,name,params],separators=(',',':')).encode(); sock.sendall(str(len(data)).encode()+b':'+data)
  reply=receive()
  if reply[2]: raise RuntimeError(str(reply[2]))
  return reply[3]
 receive()
 command(1,'WebDriver:NewSession',{'capabilities':{'alwaysMatch':{}}})
 i=1
 def execute(script):
  global i
  i+=1
  return command(i,'WebDriver:ExecuteScript',{'script':script,'args':[]})['value']
 def navigate(page, readonly=False, locale='en-GB'):
  global i
  i+=1
  command(i,'WebDriver:Navigate',{'url':'http://127.0.0.1:28571/fixture?page='+page+'&locale='+locale+('&readonly=1' if readonly else '')})
  for _ in range(40):
   time.sleep(.1)
   if execute('return !!document.querySelector("main .box")'): return
  raise RuntimeError('DNS page failed to render')
 for locale in ['en-GB','de-DE']:
  navigate('zones/list', locale=locale)
  assert execute('return [...document.querySelectorAll(".resource-title")].map(e=>e.textContent)') == ['a.example','b.example','z.example']
  execute('const e=document.querySelector("input[type=search]"); e.value="B.EXAMPLE"; e.dispatchEvent(new Event("input",{bubbles:true}));')
  time.sleep(.1)
  assert execute('return document.querySelectorAll(".zone-card").length') == 1
  execute('const e=document.querySelector("input[type=search]"); e.value=""; e.dispatchEvent(new Event("input",{bubbles:true})); const s=document.querySelector("section[aria-label] select"); s.value="2"; s.dispatchEvent(new Event("change",{bubbles:true}));')
  time.sleep(.1)
  assert execute('return document.querySelectorAll(".zone-card").length') == 2
  navigate('zones/list&provider=2', locale=locale)
  time.sleep(.1)
  assert execute('return [...document.querySelectorAll(".resource-title")].map(e=>e.textContent)') == ['b.example','z.example']
  navigate('zones/records', locale=locale)
  assert execute('return document.querySelectorAll("tbody tr").length') == 2
  execute('document.querySelectorAll(".actions button")[2].click();')
  time.sleep(.2)
  assert execute('return document.querySelector("textarea[name=rdata]").value') == '192.0.2.1\n192.0.2.2'
  assert execute('return document.querySelector("input[name=name]").readOnly')
  assert execute('return document.querySelector("input[name=ttl]").value') == '600'
  execute('window.confirm=(text)=>{window.confirmText=text;return false;}; document.querySelectorAll(".actions form button")[1].click();')
  assert 'a.example' in execute('return window.confirmText')
  assert 'www' in execute('return window.confirmText')
  execute('const e=document.querySelector("input[type=search]"); e.value="DKIM1"; e.dispatchEvent(new Event("input",{bubbles:true}));')
  time.sleep(.1)
  assert execute('return document.querySelectorAll("tbody tr").length') == 1
  i+=1; command(i,'WebDriver:SetWindowRect',{'width':390,'height':844})
  assert execute('return document.documentElement.scrollWidth <= window.innerWidth + 1')
  i+=1; command(i,'WebDriver:SetWindowRect',{'width':1200,'height':900})
  assert execute('return document.querySelector(".rdata-value").textContent.length') > 4000
  navigate('zones/records', readonly=True, locale=locale)
  assert execute('return [...document.querySelectorAll(".actions button")].every(e=>e.disabled)')
  navigate('zones/dnssec', locale=locale)
  assert execute('return [...document.querySelectorAll("a")].some(e=>e.getAttribute("href") === "/records.php?account=42&zone=7")')
  assert 'a.example' in execute('return document.querySelector("main").textContent')
  execute('window.confirm=(text)=>{window.confirmText=text;return false;}; document.querySelector("button[value=disable]").click();')
  assert 'a.example' in execute('return window.confirmText')
  print(locale+': zone search/filter/order, RRset prefill, contextual delete, long TXT, readonly actions and DNSSEC navigation passed')

finally:
 if sock: sock.close()
 browser.terminate(); server.terminate()
 try: browser.wait(timeout=5)
 except subprocess.TimeoutExpired: browser.kill()
 shutil.rmtree(profile,ignore_errors=True)
