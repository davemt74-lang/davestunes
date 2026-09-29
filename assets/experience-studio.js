(() => {
  'use strict';
  const boot=window.DaveTunesStudioBoot;
  const root=document.querySelector('[data-studio]');
  if(!boot||!root)return;

  const state={snapshot:null,selected:null,drag:null,dirty:false};
  const q=s=>root.querySelector(s);
  const qa=s=>[...root.querySelectorAll(s)];
  const filmstrip=q('[data-studio-filmstrip]');
  const nodeLayer=q('[data-studio-nodes]');
  const edgeLayer=q('[data-studio-edges]');
  const inspector=q('[data-studio-inspector]');
  const canvas=q('[data-studio-canvas]');
  const status=q('[data-studio-flow-status]');

  const post=async(action,payload={})=>{
    const body=new FormData();
    body.set('action',action);
    body.set('_csrf',boot.csrf);
    for(const [key,value] of Object.entries(payload)){
      if(value===undefined||value===null)continue;
      body.set(key,typeof value==='object'?JSON.stringify(value):String(value));
    }
    const response=await fetch('/experience-manage.php',{method:'POST',credentials:'same-origin',body,headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Experience change failed.');
    state.dirty=true;
    return json;
  };

  const load=async()=>{
    const url=new URL('/experience-studio-data.php',location.origin);
    url.searchParams.set('experience_id',String(boot.experienceId));
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Studio could not load.');
    state.snapshot=json;
    q('[data-studio-version]').textContent='Draft v'+json.draft.number;
    render();
    return json;
  };

  const manifest=()=>state.snapshot?.draft?.manifest||{scenes:[],flow:{nodes:[],edges:[]}};
  const versionId=()=>state.snapshot?.draft?.id||0;
  const slug=value=>String(value||'').toLowerCase().trim().replace(/[^a-z0-9._-]+/g,'-').replace(/^-|-$/g,'').slice(0,90);

  const button=(text,action,className='')=>{
    const b=document.createElement('button');b.type='button';b.textContent=text;b.dataset.action=action;if(className)b.className=className;return b;
  };

  const selectScene=key=>{state.selected={type:'scene',key};renderSelection();};
  const selectNode=key=>{state.selected={type:'node',key};renderSelection();};

  const renderFilmstrip=()=>{
    filmstrip.replaceChildren();
    const scenes=manifest().scenes||[];
    scenes.forEach((scene,index)=>{
      const card=document.createElement('button');
      card.type='button';card.className='studio-scene-card';
      card.dataset.sceneKey=scene.key;
      if(state.selected?.type==='scene'&&state.selected.key===scene.key)card.setAttribute('aria-current','true');
      if(scene.enabled===false)card.dataset.disabled='true';
      const n=document.createElement('span');n.className='studio-scene-number';n.textContent=String(index+1).padStart(2,'0');
      const title=document.createElement('strong');title.textContent=scene.title||scene.key;
      const meta=document.createElement('span');meta.textContent=(scene.layers?.length||0)+' layers · '+(scene.enabled===false?'disabled':'enabled');
      card.append(n,title,meta);
      card.addEventListener('click',()=>selectScene(scene.key));
      card.draggable=true;
      card.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/studio-scene',scene.key);});
      card.addEventListener('dragover',e=>e.preventDefault());
      card.addEventListener('drop',async e=>{
        e.preventDefault();
        const from=e.dataTransfer.getData('text/studio-scene');
        if(!from||from===scene.key)return;
        const reordered=[...scenes];
        const fromIndex=reordered.findIndex(s=>s.key===from);
        const toIndex=reordered.findIndex(s=>s.key===scene.key);
        const [moved]=reordered.splice(fromIndex,1);reordered.splice(toIndex,0,moved);
        await Promise.all(reordered.map((s,i)=>post('update-scene',{version_id:versionId(),scene_key:s.key,sort_order:(i+1)*10})));
        await load();
      });
      filmstrip.append(card);
    });
  };

  const nodePosition=(node)=>{
    const w=Math.max(1,canvas.clientWidth-170),h=Math.max(1,canvas.clientHeight-90);
    return {x:Math.max(0,Math.min(w,Number(node.x)||0)),y:Math.max(0,Math.min(h,Number(node.y)||0))};
  };

  const renderEdges=()=>{
    edgeLayer.replaceChildren();
    const nodes=new Map((manifest().flow?.nodes||[]).map(n=>[n.key,n]));
    const rect=canvas.getBoundingClientRect();
    edgeLayer.setAttribute('viewBox','0 0 '+Math.max(1,rect.width)+' '+Math.max(1,rect.height));
    for(const edge of manifest().flow?.edges||[]){
      const from=nodes.get(edge.from),to=nodes.get(edge.to);if(!from||!to)continue;
      const a=nodePosition(from),b=nodePosition(to);
      const path=document.createElementNS('http://www.w3.org/2000/svg','path');
      const ax=a.x+155,ay=a.y+34,bx=b.x,by=b.y+34;
      const bend=Math.max(50,Math.abs(bx-ax)*.45);
      path.setAttribute('d',`M ${ax} ${ay} C ${ax+bend} ${ay}, ${bx-bend} ${by}, ${bx} ${by}`);
      path.classList.add('studio-edge');
      path.dataset.edgeKey=edge.key;
      path.addEventListener('click',()=>{state.selected={type:'edge',key:edge.key};renderSelection();});
      edgeLayer.append(path);
    }
  };

  const renderNodes=()=>{
    nodeLayer.replaceChildren();
    for(const node of manifest().flow?.nodes||[]){
      const pos=nodePosition(node);
      const el=document.createElement('button');
      el.type='button';el.className='studio-node';el.dataset.nodeKey=node.key;
      el.style.left=pos.x+'px';el.style.top=pos.y+'px';
      if(state.selected?.type==='node'&&state.selected.key===node.key)el.setAttribute('aria-current','true');
      const type=document.createElement('span');type.textContent=node.type;
      const name=document.createElement('strong');name.textContent=node.key;
      const scene=document.createElement('small');scene.textContent=node.sceneKey?'Scene: '+node.sceneKey:'Global';
      el.append(type,name,scene);
      el.addEventListener('click',e=>{e.stopPropagation();selectNode(node.key);});
      el.addEventListener('pointerdown',e=>{
        if(e.button!==0)return;
        const start=nodePosition(node);
        state.drag={key:node.key,pointerId:e.pointerId,startX:e.clientX,startY:e.clientY,x:start.x,y:start.y};
        el.setPointerCapture?.(e.pointerId);
      });
      el.addEventListener('pointermove',e=>{
        if(!state.drag||state.drag.pointerId!==e.pointerId)return;
        e.preventDefault();
        const x=state.drag.x+(e.clientX-state.drag.startX),y=state.drag.y+(e.clientY-state.drag.startY);
        el.style.left=x+'px';el.style.top=y+'px';
      });
      el.addEventListener('pointerup',async e=>{
        if(!state.drag||state.drag.pointerId!==e.pointerId)return;
        const x=Math.max(0,parseFloat(el.style.left)||0),y=Math.max(0,parseFloat(el.style.top)||0);
        state.drag=null;
        await post('update-node',{version_id:versionId(),node_key:node.key,x,y});
        await load();
        selectNode(node.key);
      });
      nodeLayer.append(el);
    }
    renderEdges();
  };

  const field=(label,name,value,type='text')=>{
    const l=document.createElement('label');l.textContent=label;
    const input=document.createElement(type==='textarea'?'textarea':'input');
    if(type!=='textarea')input.type=type;
    input.name=name;input.value=value??'';l.append(input);return l;
  };

  const sceneInspector=scene=>{
    inspector.replaceChildren();
    const h=document.createElement('h2');h.textContent='Scene';
    const form=document.createElement('form');form.className='studio-inspector-form';
    form.append(field('Title','title',scene.title),field('Weight','weight',scene.weight,'number'));
    const enabled=document.createElement('label');enabled.className='studio-check';
    const check=document.createElement('input');check.type='checkbox';check.name='enabled';check.checked=scene.enabled!==false;
    enabled.append(check,document.createTextNode(' Enabled'));
    form.append(enabled);
    const settings=field('Scene settings JSON','settings',JSON.stringify(scene.settings||{},null,2),'textarea');form.append(settings);

    const layers=document.createElement('div');layers.className='studio-layer-list';
    const layersTitle=document.createElement('div');layersTitle.className='studio-layer-list-title';
    layersTitle.append(document.createElement('strong'),button('+ Layer','add-layer'));
    layersTitle.querySelector('strong').textContent='Layers';
    layers.append(layersTitle);
    for(const layer of scene.layers||[]){
      const row=document.createElement('div');row.className='studio-layer-row';row.dataset.layerKey=layer.key;
      const copy=document.createElement('div');
      const name=document.createElement('strong');name.textContent=layer.key;
      const meta=document.createElement('span');meta.textContent=layer.type+' · order '+layer.order;
      copy.append(name,meta);
      const controls=document.createElement('div');
      controls.append(button('Edit','edit-layer'),button('Delete','delete-layer'));
      row.append(copy,controls);layers.append(row);
    }
    form.append(layers);

    const actions=document.createElement('div');actions.className='studio-inspector-actions';
    actions.append(button('Save','save','primary'),button('Duplicate','duplicate'),button('Delete','delete'));
    form.append(actions);
    form.addEventListener('submit',e=>e.preventDefault());
    form.addEventListener('click',async e=>{
      const action=e.target?.dataset?.action;if(!action)return;
      if(action==='add-layer'){
        const layerKey=slug(prompt('Layer key','layer-'+((scene.layers?.length||0)+1))||'');if(!layerKey)return;
        const layerType=slug(prompt('Layer type (heading, text, image, button)','text')||'text');if(!layerType)return;
        const text=prompt('Text / label','')??'';
        await post('add-layer',{version_id:versionId(),scene_key:scene.key,layer_key:layerKey,layer_type:layerType,sort_order:(scene.layers?.length||0)+1,settings:JSON.stringify(text?{text}:{})});
      }else if(action==='edit-layer'){
        const row=e.target.closest('[data-layer-key]');const layer=(scene.layers||[]).find(item=>item.key===row?.dataset?.layerKey);if(!layer)return;
        const layerType=slug(prompt('Layer type',layer.type)||layer.type);
        const settingsRaw=prompt('Layer settings JSON',JSON.stringify(layer.settings||{}));if(settingsRaw===null)return;
        await post('update-layer',{version_id:versionId(),scene_key:scene.key,layer_key:layer.key,layer_type:layerType,sort_order:layer.order,settings:settingsRaw});
      }else if(action==='delete-layer'){
        const row=e.target.closest('[data-layer-key]');const layerKey=row?.dataset?.layerKey;if(!layerKey)return;
        if(!confirm('Delete this layer?'))return;
        await post('delete-layer',{version_id:versionId(),scene_key:scene.key,layer_key:layerKey});
      }else if(action==='save'){
        await post('update-scene',{version_id:versionId(),scene_key:scene.key,title:form.title.value,weight:form.weight.value,is_enabled:form.enabled.checked?'1':'0',settings:form.settings.value});
      }else if(action==='duplicate'){
        const key=slug(scene.key+'-copy-'+Date.now().toString().slice(-4));
        await post('add-scene',{version_id:versionId(),scene_key:key,title:scene.title+' Copy',sort_order:(manifest().scenes.length+1)*10,weight:scene.weight,is_enabled:'1',settings:JSON.stringify(scene.settings||{})});
        for(const layer of scene.layers||[])await post('add-layer',{version_id:versionId(),scene_key:key,layer_key:layer.key,layer_type:layer.type,sort_order:layer.order,settings:JSON.stringify(layer.settings||{})});
        state.selected={type:'scene',key};
      }else if(action==='delete'){
        if(!confirm('Delete this scene?'))return;
        await post('delete-scene',{version_id:versionId(),scene_key:scene.key});
        state.selected=null;
      }
      await load();
    });
    inspector.append(h,form);
  };

  const nodeInspector=node=>{
    inspector.replaceChildren();
    const h=document.createElement('h2');h.textContent='Flow Node';
    const form=document.createElement('form');form.className='studio-inspector-form';
    form.append(field('Node key','node_key',node.key),field('Type','node_type',node.type),field('Scene key','scene_key',node.sceneKey||''));
    const settings=field('Settings JSON','settings',JSON.stringify(node.settings||{},null,2),'textarea');form.append(settings);
    const actions=document.createElement('div');actions.className='studio-inspector-actions';
    actions.append(button('Save','save','primary'),button('Delete','delete'));
    form.append(actions);
    form.addEventListener('submit',e=>e.preventDefault());
    form.addEventListener('click',async e=>{
      const action=e.target?.dataset?.action;if(!action)return;
      if(action==='save')await post('update-node',{version_id:versionId(),node_key:node.key,node_type:form.node_type.value,scene_key:form.scene_key.value,settings:form.settings.value});
      if(action==='delete'){
        if(!confirm('Delete this node? Connected edges must be removed first.'))return;
        await post('delete-node',{version_id:versionId(),node_key:node.key});state.selected=null;
      }
      await load();
    });
    inspector.append(h,form);
  };

  const edgeInspector=edge=>{
    inspector.replaceChildren();
    const h=document.createElement('h2');h.textContent='Connection';
    const form=document.createElement('form');form.className='studio-inspector-form';
    form.append(
      field('From node','from_node_key',edge.from),
      field('From port','from_port',edge.fromPort||'out'),
      field('To node','to_node_key',edge.to),
      field('To port','to_port',edge.toPort||'in'),
      field('Condition JSON','condition',JSON.stringify(edge.condition||{},null,2),'textarea')
    );
    const actions=document.createElement('div');actions.className='studio-inspector-actions';
    actions.append(button('Save','save','primary'),button('Delete','delete'));
    form.append(actions);
    form.addEventListener('submit',e=>e.preventDefault());
    form.addEventListener('click',async e=>{
      const action=e.target?.dataset?.action;if(!action)return;
      if(action==='save'){
        await post('update-edge',{
          version_id:versionId(),edge_key:edge.key,
          from_node_key:form.from_node_key.value,from_port:form.from_port.value,
          to_node_key:form.to_node_key.value,to_port:form.to_port.value,
          condition:form.condition.value
        });
      }else if(action==='delete'){
        if(!confirm('Delete this connection?'))return;
        await post('delete-edge',{version_id:versionId(),edge_key:edge.key});
        state.selected=null;
      }
      await load();
    });
    inspector.append(h,form);
  };

  const renderSelection=()=>{
    renderFilmstrip();renderNodes();
    if(!state.selected){inspector.innerHTML='<h2>Inspector</h2><p>Select a scene, node, or connection to edit it.</p>';return;}
    if(state.selected.type==='scene'){
      const scene=(manifest().scenes||[]).find(s=>s.key===state.selected.key);if(scene)return sceneInspector(scene);
    }
    if(state.selected.type==='node'){
      const node=(manifest().flow?.nodes||[]).find(n=>n.key===state.selected.key);if(node)return nodeInspector(node);
    }
    if(state.selected.type==='edge'){
      const edge=(manifest().flow?.edges||[]).find(n=>n.key===state.selected.key);if(edge)return edgeInspector(edge);
    }
    state.selected=null;renderSelection();
  };

  const render=()=>{status.textContent=(manifest().flow?.nodes?.length||0)+' nodes · '+(manifest().flow?.edges?.length||0)+' connections';renderSelection();};

  const addScene=async()=>{
    const count=(manifest().scenes||[]).length+1;
    const key='scene-'+count+'-'+Date.now().toString().slice(-4);
    await post('add-scene',{version_id:versionId(),scene_key:key,title:'Scene '+count,sort_order:count*10,weight:1,is_enabled:'1',settings:JSON.stringify({title:'New Scene'})});
    state.selected={type:'scene',key};await load();
  };

  const addNode=async()=>{
    const nodes=manifest().flow?.nodes||[];
    const count=nodes.length+1,key='node-'+count+'-'+Date.now().toString().slice(-4);
    const scene=state.selected?.type==='scene'?state.selected.key:(manifest().scenes?.[0]?.key||'');
    await post('add-node',{version_id:versionId(),node_key:key,node_type:'effect',scene_key:scene,x:40+(count%4)*180,y:40+Math.floor(count/4)*110,settings:JSON.stringify({})});
    state.selected={type:'node',key};await load();
  };

  const connect=async()=>{
    const nodes=manifest().flow?.nodes||[];
    if(nodes.length<2)throw new Error('Add at least two nodes before connecting them.');
    const from=prompt('From node key',nodes[0].key);if(!from)return;
    const to=prompt('To node key',nodes.find(n=>n.key!==from)?.key||'');if(!to)return;
    const key=slug(from+'-to-'+to+'-'+Date.now().toString().slice(-4));
    await post('add-edge',{version_id:versionId(),edge_key:key,from_node_key:from,to_node_key:to});
    state.selected={type:'edge',key};await load();
  };

  const preview=()=>{
    const wrap=q('[data-studio-preview]'),stage=q('[data-studio-preview-stage]');
    stage.replaceChildren();
    for(const scene of manifest().scenes||[]){
      if(scene.enabled===false)continue;
      const card=document.createElement('section');card.className='studio-preview-scene';
      const eyebrow=document.createElement('span');eyebrow.textContent=scene.title;card.append(eyebrow);
      const title=document.createElement('h2');title.textContent=scene.settings?.title||scene.title;card.append(title);
      for(const layer of scene.layers||[]){
        if(layer.type==='heading'){const h=document.createElement('h3');h.textContent=layer.settings?.text||'';card.append(h);}
        if(layer.type==='text'){const p=document.createElement('p');p.textContent=layer.settings?.text||'';card.append(p);}
      }
      stage.append(card);
    }
    wrap.hidden=false;
  };

  const addEffectPreset=async()=>{
    const scene=state.selected?.type==='scene'?(manifest().scenes||[]).find(s=>s.key===state.selected.key):null;
    if(!scene)throw new Error('Select a scene before adding an effect preset.');
    const presets=window.DaveTunesEffectsLibrary?.presets||[];
    if(!presets.length)throw new Error('Effect presets are unavailable.');
    const choices=presets.map((p,i)=>(i+1)+'. '+p.label).join('\n');
    const selected=Number(prompt('Choose effect preset:\n'+choices,'1'))-1;
    const preset=presets[selected];if(!preset)return;
    const settings={...(scene.settings||{}),effects:preset.effects};
    await post('update-scene',{version_id:versionId(),scene_key:scene.key,settings:JSON.stringify(settings)});
    await load();selectScene(scene.key);
  };

  const publish=async()=>{
    if(!confirm('Publish this draft as the live experience?'))return;
    await post('publish',{version_id:versionId()});
    state.dirty=false;
    alert('Published. A new draft will be created when the Studio reloads.');
    await load();
  };

  q('[data-studio-action="add-scene"]').addEventListener('click',()=>addScene().catch(showError));
  q('[data-studio-action="add-node"]').addEventListener('click',()=>addNode().catch(showError));
  q('[data-studio-action="connect"]').addEventListener('click',()=>connect().catch(showError));
  q('[data-studio-action="effect-preset"]').addEventListener('click',()=>addEffectPreset().catch(showError));
  q('[data-studio-action="preview"]').addEventListener('click',preview);
  q('[data-studio-action="publish"]').addEventListener('click',()=>publish().catch(showError));
  q('[data-studio-preview-close]').addEventListener('click',()=>{q('[data-studio-preview]').hidden=true;});
  canvas.addEventListener('dblclick',()=>connect().catch(showError));
  canvas.addEventListener('click',e=>{if(e.target===canvas||e.target===nodeLayer){state.selected=null;renderSelection();}});
  window.addEventListener('resize',renderEdges);
  window.addEventListener('beforeunload',e=>{if(state.dirty){e.preventDefault();e.returnValue='';}});

  function showError(error){status.textContent=error?.message||'Studio action failed.';status.dataset.error='true';}
  load().catch(showError);
})();