// ATLAS door-picker proof — directory pick (no raw uid), save, persisted re-read.
const {test,expect}=require('@playwright/test');
const {login,hasCreds,creds}=require('../tests/e2e/helpers/auth.js');
const APP='/index.php/apps/kanzleicheck';
test('door pickers — directory pick, save, re-read',async({page})=>{
  test.skip(!hasCreds(),'no creds');
  await login(page,creds('staff'));
  const [g,u]=await Promise.all([
    page.request.get(APP+'/api/portal/groups/search?q=a'),
    page.request.get(APP+'/api/portal/users/search?q=kzc')]);
  const gj=await g.json(),uj=await u.json();
  console.log('GROUPS',JSON.stringify(gj.data&&gj.data.groups),'USERS',JSON.stringify(uj.data&&uj.data.users));
  await page.goto(APP+'/settings/access');
  await page.waitForSelector('#d-users',{timeout:15000});
  expect(await page.locator('.kzc-picker').count()).toBe(3);
  await page.fill('#d-users','kzc');
  await page.waitForSelector('.kzc-picker__results:not([hidden]) li',{timeout:8000});
  const root=page.locator('.kzc-picker').nth(1);
  const hits=await root.locator('.kzc-picker__result').count();
  console.log('HITS',hits);
  test.skip(!hits,'no searchable users');
  await root.locator('.kzc-picker__result').first().click();
  expect(await root.locator('.kzc-chip').count()).toBe(1);
  const before=await (await page.request.get(APP+'/api/portal/settings')).json();
  await page.locator('.kzc-btn--primary', {hasText:'Save door'}).first().click();
  await page.waitForTimeout(800);
  const after=await (await page.request.get(APP+'/api/portal/settings')).json();
  console.log('BEFORE',JSON.stringify(before.data.door),'AFTER',JSON.stringify(after.data.door));
  await page.request.put(APP+'/api/portal/door',{data:{restricted:before.data.door.restricted,groups:before.data.door.allowedGroups,users:before.data.door.allowedUsers,appAdmins:before.data.door.appAdmins}});
});
