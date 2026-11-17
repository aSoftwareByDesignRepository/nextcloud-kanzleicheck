// ATLAS resp-staff picker proof — "Set responsible staff" dialog:
// open → Escape/Cancel writes nothing → directory pick → Save → persisted
// re-read, then fixture restored. Also pins the cramped_form_controls
// regression: dialog field must be wide/stacked, not NC-core ~130px inline.
const {test,expect}=require('@playwright/test');
const {login,hasCreds,creds}=require('../tests/e2e/helpers/auth.js');
const APP='/index.php/apps/kanzleicheck';

test('resp-staff picker — save persists, escape/cancel write nothing',async({page})=>{
  test.skip(!hasCreds(),'no creds');
  await login(page,creds('staff'));

  const orgsJson=await (await page.request.get(APP+'/api/portal/orgs')).json();
  const orgs=(orgsJson.data&&orgsJson.data.orgs)||[];
  test.skip(!orgs.length,'no orgs');
  const readContacts=async(id)=>{
    const j=await (await page.request.get(APP+'/api/portal/orgs/'+id)).json();
    return ((j.data&&j.data.contacts)||[]).map(c=>String(c.user_id||c.userId)).sort();
  };

  await page.goto(APP+'/?view=orgs');
  await page.waitForSelector('button.kzc-row .kzc-row__title',{timeout:15000});
  let orgId=null;
  for(const o of orgs.slice(0,10)){
    const row=page.locator('button.kzc-row',{has:page.locator('.kzc-row__title')}).filter({hasText:o.name});
    if(await row.count()===0)continue;
    await row.first().click();
    const ok=await page.waitForSelector('#kzc-resp',{timeout:12000}).then(()=>true).catch(()=>false);
    if(ok){orgId=o.id;break;}
    await page.goto(APP+'/?view=orgs');
    await page.waitForSelector('button.kzc-row .kzc-row__title',{timeout:15000});
  }
  test.skip(orgId===null,'no rendered org row');
  const original=await readContacts(orgId);

  try{
    const openDlg=async()=>{
      await page.locator('.kzc-card:has(#kzc-resp) .kzc-card__header-actions .kzc-btn').evaluate(el=>el.click());
      await page.waitForSelector('dialog[open].kzc-dialog .kzc-picker input',{timeout:8000});
      return page.locator('dialog[open].kzc-dialog');
    };

    // ── dialog field-layer pin (cramped_form_controls regression guard):
    // picker input must render the app field chrome — full-width stacked
    // under its label, not the NC-core ~130px pin with an inline label.
    let dlg=await openDlg();
    const input=dlg.locator('.kzc-picker input.kzc-input');
    const label=dlg.locator('.kzc-field > .kzc-label').first();
    const ib=await input.boundingBox(),lb=await label.boundingBox();
    console.log('GEOM input',JSON.stringify(ib),'label',JSON.stringify(lb));
    expect(ib.width).toBeGreaterThan(240);
    expect(lb.y+lb.height).toBeLessThanOrEqual(ib.y+1); // label stacked above

    // ── cancel leg 1: Escape → close, zero write
    await page.keyboard.press('Escape');
    await expect(page.locator('dialog[open].kzc-dialog')).toHaveCount(0);
    expect(await readContacts(orgId)).toEqual(original);

    // ── cancel leg 2: Cancel button → close, zero write
    dlg=await openDlg();
    await dlg.locator('.kzc-dialog__actions .kzc-btn:not(.kzc-btn--primary)').click();
    await expect(page.locator('dialog[open].kzc-dialog')).toHaveCount(0);
    expect(await readContacts(orgId)).toEqual(original);

    // ── confirm leg: directory pick a STAFF user (contacts must be staff —
    // server enforces not_staff) → Save → PUT → persisted re-read
    let staffUid=null;
    for(const q of ['atlas','e2e','staff','a']){
      const sj=await (await page.request.get(APP+'/api/portal/users/search?q='+q)).json();
      const hit=(sj.data&&sj.data.users||[]).find(u=>u.staff===true);
      if(hit){staffUid=hit.uid;break;}
    }
    test.skip(!staffUid,'no staff user in directory');
    dlg=await openDlg();
    const search=dlg.locator('.kzc-picker input');
    await search.fill(staffUid);
    const hit=dlg.locator('.kzc-picker__results:not([hidden]) .kzc-picker__result').filter({hasText:staffUid}).first();
    await hit.waitFor({timeout:8000});
    const picked=await hit.textContent();
    await hit.click();
    await expect(dlg.locator('.kzc-chip')).toHaveCount(1);
    const putResp=page.waitForResponse(r=>r.url().includes('/api/portal/orgs/'+orgId+'/contacts')&&r.request().method()==='PUT');
    await dlg.locator('.kzc-dialog__actions .kzc-btn--primary').click();
    const resp=await putResp;
    const sent=(((resp.request().postDataJSON()||{}).userIds)||[]).map(String).sort();
    await expect(page.locator('dialog[open].kzc-dialog')).toHaveCount(0);
    const after=await readContacts(orgId);
    console.log('RESP-STAFF before',JSON.stringify(original),'sent',JSON.stringify(sent),'after',JSON.stringify(after),'picked',JSON.stringify(picked));
    expect(after).toEqual(sent);          // durable postcondition: GET re-read = PUT payload
    expect(after).not.toEqual(original);  // the write actually changed state
  }finally{
    if(orgId!==null){
      await page.request.put(APP+'/api/portal/orgs/'+orgId+'/contacts',{data:{userIds:original}});
    }
  }
});
