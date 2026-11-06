# SPDX-License-Identifier: AGPL-3.0-or-later
import json, os, socket, subprocess, tempfile, time, shutil
from pathlib import Path
root=str(Path(__file__).resolve().parents[2])
profile=tempfile.mkdtemp(prefix='towerdns-firefox-')
port=28570
with open(profile+'/user.js','w') as f: f.write('user_pref("marionette.port", %d);\nuser_pref("marionette.enabled", true);\n' % port)
server=subprocess.Popen(['php','-S','127.0.0.1:28571',root+'/tests/Frontend/account-provider-browser-router.php'],cwd=root,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
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
  navigate('provider_accounts/list', locale=locale)
  assert 'Example Account' in execute('return document.querySelector("main").textContent')
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
  print(locale+': provider defaults, secret reset, account context, duplicate-submit guard, readonly connections and mobile layout passed')

finally:
 if sock: sock.close()
 browser.terminate(); server.terminate()
 try: browser.wait(timeout=5)
 except subprocess.TimeoutExpired: browser.kill()
 shutil.rmtree(profile,ignore_errors=True)
