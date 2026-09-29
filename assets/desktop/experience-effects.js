(() => {
  'use strict';
  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const clamp=(v,min=0,max=1)=>Math.max(min,Math.min(max,v));
  const mix=(a,b,t)=>a+(b-a)*t;
  const defs=[
    ['experience.fade','Fade','opacity'],
    ['experience.zoom','Zoom','transform'],
    ['experience.parallax','Parallax','transform'],
    ['experience.slide-up','Slide Up','transform'],
    ['experience.slide-left','Slide Left','transform'],
    ['experience.blur','Blur','filter'],
    ['experience.rotate','Rotate','transform'],
    ['experience.scale','Scale','transform'],
    ['experience.crossfade','Crossfade','opacity'],
    ['experience.depth','Depth Push','transform'],
  ];

  const apply=(element,effectId,progress,settings={})=>{
    if(!(element instanceof HTMLElement))return;
    const p=clamp(progress);
    const intensity=Math.max(.05,Math.min(3,Number(settings.intensity)||1));
    const reverse=Boolean(settings.reverse);
    const t=reverse?1-p:p;
    switch(effectId){
      case 'experience.fade': element.style.opacity=String(t); break;
      case 'experience.zoom': element.style.transform=`scale(${mix(0.82,1.18,t*intensity/Math.max(1,intensity))})`; break;
      case 'experience.parallax': element.style.transform=`translate3d(0,${mix(48,-48,t)*intensity}px,0)`; break;
      case 'experience.slide-up': element.style.transform=`translate3d(0,${mix(90,0,t)*intensity}px,0)`; element.style.opacity=String(t); break;
      case 'experience.slide-left': element.style.transform=`translate3d(${mix(90,0,t)*intensity}px,0,0)`; element.style.opacity=String(t); break;
      case 'experience.blur': element.style.filter=`blur(${mix(18,0,t)*intensity}px)`; element.style.opacity=String(t); break;
      case 'experience.rotate': element.style.transform=`rotate(${mix(-8,8,t)*intensity}deg)`; break;
      case 'experience.scale': element.style.transform=`scale(${mix(.7,1,t)})`; break;
      case 'experience.crossfade': element.style.opacity=String(Math.sin(Math.PI*t)); break;
      case 'experience.depth': element.style.transform=`perspective(900px) translateZ(${mix(-180,70,t)*intensity}px) scale(${mix(.86,1,t)})`; element.style.opacity=String(clamp(t*1.25)); break;
      default: break;
    }
  };

  for(const [id,label,property] of defs){
    desktop.registerEffect({id,category:'experience-animation',label,property,apply});
  }

  const presets=Object.freeze([
    {id:'cinematic-reveal',label:'Cinematic Reveal',effects:[{id:'experience.fade'},{id:'experience.zoom',settings:{intensity:.7}}]},
    {id:'deep-parallax',label:'Deep Parallax',effects:[{id:'experience.parallax',settings:{intensity:1.4}},{id:'experience.depth'}]},
    {id:'soft-entry',label:'Soft Entry',effects:[{id:'experience.slide-up',settings:{intensity:.55}},{id:'experience.fade'}]},
    {id:'dream-blur',label:'Dream Blur',effects:[{id:'experience.blur'},{id:'experience.scale',settings:{intensity:.4}}]},
    {id:'side-reveal',label:'Side Reveal',effects:[{id:'experience.slide-left'},{id:'experience.fade'}]},
  ]);

  const applyStack=(element,effects,progress)=>{
    element.style.removeProperty('opacity');
    element.style.removeProperty('transform');
    element.style.removeProperty('filter');
    for(const item of Array.isArray(effects)?effects:[]){
      const def=desktop.getRegistry('effect')?.get(String(item?.id||''));
      if(def?.category==='experience-animation'&&typeof def.apply==='function')def.apply(element,progress,item.settings||{});
    }
  };

  window.DaveTunesEffectsLibrary=Object.freeze({presets,apply,applyStack,definitions:()=>defs.map(([id,label])=>({id,label}))});
})();