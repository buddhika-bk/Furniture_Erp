/** Progressive effects: data and navigation remain usable without animation. */
export function mountMotion() {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const precise = matchMedia('(hover: hover) and (pointer: fine)');
  const seen = new WeakSet();
  const animations = new Set();
  const selector = '.application-tile,.module-link,.stat,.chart-card,.rep-hero,.resource-heading';
  const tooltip = document.createElement('div');
  tooltip.className = 'chart-tooltip'; tooltip.hidden = true;
  document.body.append(tooltip);
  const animate = (el,frames,options) => {
    const animation=el.animate(frames,options);animations.add(animation);
    animation.onfinish=()=>animations.delete(animation);
  };
  const observer=new IntersectionObserver(entries=>{
    for(const {target,isIntersecting} of entries){
      if(!isIntersecting)continue;observer.unobserve(target);
      if(reduced.matches)continue;
      if(target.matches(selector))target.classList.add('glass-enter');
      if(target.matches('path.draw,polyline.draw')){
        const length=target.getTotalLength();
        animate(target,[{strokeDasharray:`${length} ${length}`,strokeDashoffset:length},{strokeDasharray:`${length} ${length}`,strokeDashoffset:0}],{duration:1100,easing:'cubic-bezier(.2,.7,.2,1)'});
      }
      if(target.matches('.vbar'))animate(target,[{opacity:0,transform:'scaleY(.05)'},{opacity:1,transform:'scaleY(1)'}],{duration:650,delay:Number(target.dataset.motionIndex||0)*35,easing:'cubic-bezier(.2,.7,.2,1)'});
      if(target.matches('.spin-in'))animate(target,[{opacity:0,transform:'scale(.92)'},{opacity:1,transform:'scale(1)'}],{duration:850,easing:'ease-out'});
      if(target.matches('.hbar .fill'))animate(target,[{width:0},{width:target.style.getPropertyValue('--w')}],{duration:850,easing:'cubic-bezier(.2,.7,.2,1)'});
    }
  },{threshold:.08});
  let scanFrame=0;
  const scan=()=>{
    scanFrame=0;
    document.querySelectorAll(`${selector},path.draw,polyline.draw,.vbar,.spin-in,.hbar .fill`).forEach((el,i)=>{
      if(seen.has(el))return;seen.add(el);
      if(el.matches(selector)){el.classList.add('glass-reactive');el.style.setProperty('--enter-delay',`${Math.min(i%6,4)*35}ms`);}
      if(el.matches('.vbar')){el.style.transformBox='fill-box';el.style.transformOrigin='center bottom';el.dataset.motionIndex=i%10;}
      observer.observe(el);
    });
  };
  const mutations=new MutationObserver(records=>{
    for(const record of records)for(const removed of record.removedNodes){
      if(!(removed instanceof Element))continue;
      observer.unobserve(removed);
      removed.querySelectorAll(`${selector},path.draw,polyline.draw,.vbar,.spin-in,.hbar .fill`).forEach(el=>observer.unobserve(el));
    }
    if(!scanFrame)scanFrame=requestAnimationFrame(scan);
  });
  for(const id of ['app','modalRoot']){const root=document.getElementById(id);if(root)mutations.observe(root,{childList:true,subtree:true});}
  scan();
  let active,frame=0,x=0,y=0;
  const clear=()=>{active?.classList.remove('glass-hover');active=null;tooltip.hidden=true;};
  document.addEventListener('pointermove',event=>{
    x=event.clientX;y=event.clientY;
    const target=event.target instanceof Element?event.target:null;
    const card=precise.matches&&!reduced.matches?target?.closest('.glass-reactive'):null;
    if(card!==active){active?.classList.remove('glass-hover');active=card;active?.classList.add('glass-hover');}
    const point=target?.closest('[data-chart-tip]');
    tooltip.hidden=!point;
    if(point){tooltip.textContent=point.dataset.chartTip;tooltip.style.left=Math.max(8,Math.min(x+15,innerWidth-tooltip.offsetWidth-12))+'px';tooltip.style.top=Math.max(8,Math.min(y+18,innerHeight-tooltip.offsetHeight-12))+'px';}
    if(frame||!active)return;
    frame=requestAnimationFrame(()=>{frame=0;if(!active?.isConnected)return;const rect=active.getBoundingClientRect();active.style.setProperty('--glow-x',`${x-rect.left}px`);active.style.setProperty('--glow-y',`${y-rect.top}px`);});
  },{passive:true});
  document.addEventListener('focusin',event=>{
    const point=event.target.closest?.('[data-chart-tip]');if(!point)return;
    const r=point.getBoundingClientRect();tooltip.textContent=point.dataset.chartTip;tooltip.hidden=false;
    tooltip.style.left=Math.max(8,Math.min(r.left,innerWidth-tooltip.offsetWidth-12))+'px';tooltip.style.top=Math.max(8,r.top-tooltip.offsetHeight-8)+'px';
  });
  document.addEventListener('focusout',()=>tooltip.hidden=true);
  document.addEventListener('keydown',e=>{if(e.key==='Escape')tooltip.hidden=true;});
  document.addEventListener('pointerleave',clear);window.addEventListener('blur',clear);window.addEventListener('hashchange',clear);
  reduced.addEventListener('change',()=>{if(reduced.matches){for(const a of animations)a.cancel();animations.clear();clear();}});
}
