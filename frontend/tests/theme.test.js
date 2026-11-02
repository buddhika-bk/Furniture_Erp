import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../assets/js/theme.js',import.meta.url),'utf8');
function boot({saved=null,dark=false,blocked=false}={}){
 const listeners={},windowListeners={},media={matches:dark,addEventListener:(_,f)=>media.change=f};
 const buttons=[0,1].map(()=>({attrs:{},setAttribute(k,v){this.attrs[k]=v;},addEventListener(_,f){this.click=f;}}));
 const root={dataset:{},style:{}};
 const document={documentElement:root,querySelector:()=>({setAttribute(){}}),querySelectorAll:()=>buttons,addEventListener:(name,f)=>listeners[name]=f};
 const localStorage={getItem(){if(blocked)throw Error('blocked');return saved;},setItem(_,v){if(blocked)throw Error('blocked');saved=v;}};
 vm.runInNewContext(source,{document,localStorage,matchMedia:()=>media,window:{addEventListener:(name,f)=>windowListeners[name]=f}});
 listeners.DOMContentLoaded();return {root,buttons,media,windowListeners,saved:()=>saved};
}
test('saved theme wins over system and both controls stay synchronized',()=>{
 const app=boot({saved:'light',dark:true});assert.equal(app.root.dataset.theme,'light');
 app.buttons[0].click();assert.equal(app.saved(),'dark');
 for(const b of app.buttons)assert.equal(b.attrs['aria-label'],'Switch to light mode');
 assert.equal(boot({saved:app.saved()}).root.dataset.theme,'dark');
});
test('system changes apply until the user explicitly chooses a theme',()=>{
 const app=boot({dark:true});assert.equal(app.root.dataset.theme,'dark');
 app.media.change({matches:false});assert.equal(app.root.dataset.theme,'light');
 app.buttons[1].click();app.media.change({matches:false});assert.equal(app.root.dataset.theme,'dark');
});
test('theme remains usable when browser storage is unavailable',()=>{
 const app=boot({blocked:true});app.buttons[0].click();assert.equal(app.root.dataset.theme,'dark');
});
test('theme changes in another tab update current controls',()=>{
 const app=boot();app.windowListeners.storage({key:'webpoint_theme',newValue:'dark'});
 assert.equal(app.root.dataset.theme,'dark');assert.equal(app.buttons[1].attrs['aria-pressed'],'true');
});
test('multiple charts use distinct gradient IDs and safely escape tooltip labels',()=>{
 const window={};vm.runInNewContext(readFileSync(new URL('../assets/js/ui.js',import.meta.url),'utf8'),{window,document:{}});
 const a=window.UI.lineChart([{day:'<script>bad</script>',value:10}],'day',[{key:'value',color:'#6677ff'}]);
 const b=window.UI.lineChart([{day:'day two',value:20}],'day',[{key:'value',color:'#6677ff'}]);
 assert.notEqual(a.match(/linearGradient id="([^"]+)/)[1],b.match(/linearGradient id="([^"]+)/)[1]);
 assert.ok(a.includes('&lt;script&gt;'));assert.ok(!a.includes('<script>'));
});
