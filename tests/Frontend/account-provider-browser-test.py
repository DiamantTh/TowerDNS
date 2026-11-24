# SPDX-License-Identifier: AGPL-3.0-or-later
import json, os, socket, subprocess, tempfile, time, shutil
from pathlib import Path
root=str(Path(__file__).resolve().parents[2])
profile=tempfile.mkdtemp(prefix='towerdns-firefox-')
port=28572
with open(profile+'/user.js','w') as f: f.write('user_pref("marionette.port", %d);\nuser_pref("marionette.enabled", true);\n' % port)
server_log=tempfile.TemporaryFile()
server=subprocess.Popen(['php','-S','127.0.0.1:28573','-t',root+'/httpdocs',root+'/tests/Frontend/account-provider-browser-router.php'],cwd=root,stdout=server_log,stderr=subprocess.STDOUT)
env=os.environ.copy(); env.pop('MOZ_DISABLE_CONTENT_SANDBOX', None); env['MOZ_HEADLESS']='1'
browser_log=tempfile.TemporaryFile()
browser=subprocess.Popen(['firefox','--headless','--marionette','--no-remote','--profile',profile],env=env,stdout=browser_log,stderr=subprocess.STDOUT)
sock=None
try:
 for _ in range(100):
  if server.poll() is not None:
   server_log.seek(0)
   raise RuntimeError('PHP fixture server failed to start: ' + server_log.read()[-2000:].decode(errors='replace'))
  if browser.poll() is not None: break
  try: sock=socket.create_connection(('127.0.0.1',port),timeout=1); break
  except OSError: time.sleep(.2)
 if sock is None:
  browser_log.seek(0); browser_output=browser_log.read()[-6000:].decode(errors='replace')
  raise RuntimeError('Firefox Marionette did not start (exit=%s). Recent browser output:\n%s' % (browser.poll(), browser_output or '<no browser output>'))
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
  command(i,'WebDriver:Navigate',{'url':'http://127.0.0.1:28573/fixture?page='+page+'&locale='+locale+('&readonly=1' if readonly else '')})
  for _ in range(40):
   time.sleep(.1)
   if execute('return !!document.querySelector("main .box")'): return
  raise RuntimeError('Account/provider fixture failed to render: ' + page)
 for locale in ['en-GB','de-DE']:
  navigate('provider_accounts/list', locale=locale)
  assert 'Example Account' in execute('return document.querySelector("main").textContent')
  assert execute('return [...document.querySelectorAll(".provider-card")][1].querySelectorAll("form").length') == 0
  assert execute('return document.querySelector(".provider-card a").getAttribute("href")') == '/zones.php?account=42&provider=9'
  assert execute('return document.querySelector("form[action^=\\"/accounts.php?id=42&view=providers\\"] input[name=request_id]").value.length') == 64
  assert execute('return document.querySelector(".provider-card form[action$=test]") !== null')
  assert 'secret-ciphertext-fixture' not in execute('return document.documentElement.innerHTML')
  assert execute('return document.querySelector("textarea[name=zones]").value') == 'example.test'
  execute('document.querySelector("input[name=token]").value="temporary-secret"; const s=document.querySelector("select[name=provider_type]"); s.value="second"; s.dispatchEvent(new Event("change",{bubbles:true}));')
  time.sleep(.2)
  assert execute('return document.querySelector("input[name=token]").value') == ''
  assert execute('return document.querySelector("input[name=url]").value') == 'https://second.example'
  execute('window.confirm=(text)=>{window.confirmText=text;return false;}; document.querySelector("form[action$=deactivate] button").click();')
  assert 'Example Account' in execute('return window.confirmText')
  assert 'Primary' in execute('return window.confirmText')
  execute('document.addEventListener("submit", e=>e.preventDefault(), {once:true}); document.querySelector("select[name=provider_type]").form.dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));')
  time.sleep(.1)
  assert execute('return document.querySelector("select[name=provider_type]").form.querySelector("button").disabled')
  assert execute('const e=new Event("submit",{bubbles:true,cancelable:true}); document.querySelector("select[name=provider_type]").form.dispatchEvent(e); return e.defaultPrevented;')
  execute('window.dispatchEvent(new Event("pageshow"));')
  time.sleep(.1)
  assert not execute('return document.querySelector("select[name=provider_type]").form.querySelector("button").disabled')
  i+=1; command(i,'WebDriver:SetWindowRect',{'width':390,'height':844})
  assert execute('return document.documentElement.scrollWidth <= innerWidth+1')
  i+=1; command(i,'WebDriver:SetWindowRect',{'width':1200,'height':900})
  navigate('provider_accounts/list', readonly=True, locale=locale)
  assert execute('return document.querySelectorAll("main form").length') == 0
  assert 'Primary' in execute('return document.querySelector("main").textContent')
  navigate('accounts/list', readonly=True, locale=locale)
  assert execute('return document.querySelectorAll(".resource-card").length') == 2
  assert execute('return document.querySelector(".resource-card[aria-current=true]").textContent.includes("Example Account")')
  assert execute('return [...document.querySelectorAll(".resource-card")][1].querySelector("form") === null')
  navigate('accounts/edit', readonly=True, locale=locale)
  assert execute('return document.querySelectorAll("main form").length') == 0
  assert execute('return [...document.querySelectorAll("main a")].some(e=>e.getAttribute("href") === "/accounts.php?id=42&view=providers")')
  navigate('accounts/edit&inactive=1', locale=locale)
  assert execute('return !!document.querySelector("button[value=activate]")')
  assert execute('return !document.querySelector("button[value=delete]")')
  navigate('accounts/members', locale=locale)
  assert execute('return !document.querySelector("form[action$=ownership]")')
  navigate('admin/monitoring', locale=locale)
  assert execute('return document.querySelector("input[name=recipient]").value') == 'ops@example.test'
  expected = 'Zustellung unklar' if locale == 'de-DE' else 'Delivery uncertain'
  assert expected in execute('return document.querySelector("main").textContent')
  execute('document.querySelector("input[name=recipient]").focus()')
  i+=1
  command(i,'WebDriver:PerformActions',{'actions':[{'type':'key','id':'keyboard','actions':[{'type':'keyDown','value':'\ue004'},{'type':'keyUp','value':'\ue004'}]}]})
  assert execute('return document.activeElement.name') == 'locale'
  navigate('admin/monitoring', readonly=True, locale=locale)
  assert execute('return document.querySelectorAll("main form").length') == 0
  assert 'ops@example.test' not in execute('return document.documentElement.innerHTML')
  print(locale+': monitoring status, delivery error, keyboard navigation and read-only rendering passed')
  print(locale+': provider defaults, secret reset, account context, duplicate-submit guard, readonly connections, account lifecycle/ownership controls and mobile layout passed')

finally:
 if sock: sock.close()
 browser.terminate(); server.terminate()
 try: browser.wait(timeout=5)
 except subprocess.TimeoutExpired: browser.kill()
 try: server.wait(timeout=5)
 except subprocess.TimeoutExpired: server.kill(); server.wait()
 browser_log.close()
 server_log.close()
 shutil.rmtree(profile,ignore_errors=True)
