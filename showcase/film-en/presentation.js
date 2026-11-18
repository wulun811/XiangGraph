(()=>{'use strict';
const $=id=>document.getElementById(id);const film=new URLSearchParams(location.search).get('film')==='1';
if(film){document.body.classList.add('film');$('reader-toggle').textContent='Standard view';$('reader-toggle').href='index.html';}
document.querySelectorAll('.avatar').forEach((el,i)=>{el.textContent='';const letter=document.createElement('span');letter.className='letter';letter.textContent='ABC'[i];const img=document.createElement('img');img.src='assets/agent-pod.png';img.alt='';el.append(img,letter);});
const summary=document.createElement('dl');summary.className='summary-facts';summary.id='summary-facts';$('event-explanation').after(summary);
const origin=document.createElement('div');origin.className='record-origin';origin.id='record-origin';summary.after(origin);
const boundary=document.createElement('div');boundary.className='boundary-tag';boundary.id='boundary-tag';document.querySelector('.receipt').after(boundary);
if(film){const svg=document.querySelector('.scene svg');svg.setAttribute('viewBox','0 0 1000 410');$('path-a').setAttribute('d','M180 220 C180 250 400 235 500 260');$('path-b').setAttribute('d','M500 220 L500 260');$('path-c').setAttribute('d','M820 220 C820 250 600 235 500 260');}
const meanings={
'p3:6':'The task, roles and coordination protocols are recorded together.','p3:13':'A holds the host tool lease for this shared policy.','p3:20':'B writes its independent service file before its shared-resource requests.','p3:23':'The lease requests are denied. This does not globally stop the agent.','p3:36':'The policy update is directed to the affected feature and verification roles.','p3:50':'B acknowledges the update before rereading and resuming.','p3:58':'B applies its change under a newly acquired lease.',
'p4:2':'This separate contract task has a different protocol combination.','p4:12':'A changed contract affects the consumer that depends on it.','p4:13':'The contract update is delivered to its consumer.','p4:16':'The consumer acknowledges the changed contract.','p4:30':'The host check passed; the verification agent did not run.',
'p6:2':'This separate run limits negotiation before allowing escalation.','p6:24':'Round one ends without agreement.','p6:30':'Round two also ends in HOLD; the pre-set bound is reached.','p6:31':'The host creates one structured escalation request.','p6:32':'The Director returns a choice for W1 and W2.','p6:33':'Only W1 and W2 receive the revised protocol.','p6:36':'The API agent acknowledges the protocol patch.','p6:39':'Both affected agents have now acknowledged.','p6:47':'The failed and passing test receipts both remain in the record.','p6:61':'These are final host tests, separate from agent finish receipts.','p6:62':'The host oracle passed after repair; agent finish receipts remain 1/3.'};
function facts(key,step,n,sc){const r=n.raw;const path=p=>String(p||'').split('/').pop();
if(key==='p3'){
 if(step.seq===6)return [['Roles',String(r.works.length)+' work units'],['Protocols',sc.director.chips.join(' · ')]];
 if(step.seq===20)return [['Action','FILE_WRITTEN'],['File',path(r.path)],['Sequence','e20 · before both BUSY receipts']];
 if(step.seq===23)return [['Request','acquire_resource'],['Outcome',r.events[0].receipt.action],['Evidence','e23 + e26 · two denied leases']];
 if(step.seq===13||step.seq===58)return [['Actor',r.receipt.principal],['Resource',path(r.receipt.resource)],['Outcome',r.receipt.action]];
 if(step.seq===36)return [['Recipients',r.packets.map(x=>x.principal).join(' + ')],['Affected work',r.packets.flatMap(x=>x.scope).join(' + ')],['Next requirement','ACK + reread']];
 if(step.seq===50)return [['Actor',r.agent],['Acknowledgement',r.ack.ack.status],['Recorded next','Reread → new lease → apply']];
}
if(key==='p4'){
 if(step.seq===2)return [['Protocols',r.protocols.join(' · ')],['Resource conflict','Not in this plan'],['Verify agent','NOT RUN']];
 if(step.seq===12)return [['Resource',path(r.resource)],['Request',r.request_id],['Change','Contract revision 1 → 2']];
 if(step.seq===30)return [['Host oracle','PASS'],['Verify agent','NOT RUN']];
 return [['Event',step.kind],['Consumer','client-agent'],['Sequence','e'+step.seq]];
}
if(key==='p6'){
 if(step.seq===2)return [['Protocols',sc.director.chips.join(' · ')],['Negotiation bound','Two rounds']];
 if(step.seq===24||step.seq===30)return [['Round',r.round+' / 2'],['API choice',r.api_choice],['Compatibility choice',r.compatibility_choice]];
 if(step.seq===31)return [['Request status',r.status],['Request',r.request_id],['Recorded escalation','One request']];
 if(step.seq===32)return [['Chosen option',r.chosen_option],['Affected scope',r.scope.join(' + ')],['Source','Recorded Director response']];
 if(step.seq===33)return [['Affected scope',r.patch.affected_scope.join(' + ')],['Revision',r.patch.base_policy_revision+' → '+r.patch.new_policy_revision],['W3','Revision 1 retained'],['Published',String(r.patch.publication_committed)]];
 if(step.seq===36||step.seq===39)return [['Acknowledged by',r.agent],['Protocol patch',r.patch_id],['W3','Finished e18 · revision 1']];
 if(step.seq===47)return [['e47 · initial test','Exit '+r.events[0].exit_code],['e58 · after repair','Exit '+r.events[1].exit_code],['History','Both receipts preserved']];
 if(step.seq===61)return [['Final host tests',r.pass?'PASS':'FAIL'],['Test cases',String(Object.keys(r.results).length)],['Agent finish receipts','1 / 3']];
 if(step.seq===62)return [['Host oracle',r.exit_code===0?'PASS':'FAIL'],['Agent finish receipts','1 / 3'],['Publication','Not established by this check']];
}
return [['Event',step.kind],['Actor',step.actor]];
}
function update(){if(!window.xgReplay)return;const state=window.xgReplay.state(),key=Object.keys(window.xgReplay.configs).find(k=>window.xgReplay.configs[k].run===state.run),config=window.xgReplay.configs[key],step=config.steps.find(x=>x.seq===state.seq),sc=window.XG_PROJECTION.scenarios.find(x=>x.run_id===state.run),node=sc.nodes.find(x=>x.event_seq===state.seq);
if(film){$('event-explanation').textContent=meanings[key+':'+step.seq]||step.note;document.querySelector('.receipt').open=false;document.body.classList.toggle('show-protocols',step.seq===config.steps[0].seq||key==='p4');}
summary.replaceChildren(...facts(key,step,node,sc).map(([label,value])=>{const row=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;if(String(value).includes('255')||value==='RESOURCE_BUSY')dd.className='failure';else if(value==='PASS'||value==='ACKED'||value==='Exit 0')dd.className='success';row.append(dt,dd);return row;}));
origin.textContent=state.run+' · '+$('seq').textContent;
boundary.textContent=key==='p4'?'Verify agent: NOT RUN. Host checking is separate.':key==='p6'?(step.seq>=47?'Agent finish receipts: 1/3. Host checks followed repair.':'Protocol coordination only. publication_committed=false.'):'Host tool lease · recorded run · read only';
document.querySelectorAll('.agent').forEach((el,i)=>{const actors=['policy-agent','feature-agent','verify-agent'];const actors6=['api-agent','compatibility-agent','independent-agent'];el.classList.toggle('is-focused',(key==='p6'?actors6:actors)[i]===step.actor);});
}
window.addEventListener('xg-checkpoint',update);update();
})();
