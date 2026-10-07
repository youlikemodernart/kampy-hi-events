import assert from 'node:assert/strict';
import fs from 'node:fs';

/** Native-only fixture; the paired Portal harness separately proves canonical waiver completion. */
export async function completeInvitationFixture({page,context,origin,width,output,prefix}) {
    const counts=async()=> (await context.request.get(origin+'/_fixture/counts')).json();
    await page.getByRole('heading',{name:'Complete a waiver for each attendee',exact:true}).waitFor();
    if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:`${output}/${prefix}-purchase-entry-${width}.png`,fullPage:true});}
    assert.equal(await page.getByLabel('Verification code',{exact:true}).count(),0);
    const requested=page.waitForResponse(response=>response.url().endsWith('/request-verification'));
    await page.getByRole('button',{name:'Email the waiver link',exact:true}).click();
    assert.equal((await requested).status(),202);
    assert.deepEqual(await counts(),{challenges:1,assignments:0,outbox:0,consumed:0});
    await page.goto(origin+'/_fixture/mailbox');
    const link=await page.getByRole('link',{name:'Complete waivers',exact:true}).getAttribute('href');
    assert.match(new URL(link).hash,/^#[a-f0-9]{64}$/);
    await page.getByRole('link',{name:'Complete waivers',exact:true}).click();
    await page.locator('#choices:not([hidden])').waitFor();
    assert.equal(new URL(page.url()).hash,'');
    await page.locator('select').nth(0).selectOption('adult');
    await page.locator('select').nth(1).selectOption('guardian');
    assert.equal(await page.getByLabel('Parent or legal guardian’s full name').nth(0).isVisible(),false);
    await page.getByLabel('Parent or legal guardian’s full name').nth(1).fill('Invented Guardian');
    await page.getByLabel('Signer’s email').nth(0).fill('adult@example.test');
    await page.getByLabel('Signer’s email').nth(1).fill('guardian@example.test');
    await page.getByLabel('I confirm the appropriate signer for every attendee.').check();
    const respondents=await page.locator('fieldset').evaluateAll(groups=>groups.map(g=>({attendee_id:Number(g.dataset.id),route:g.querySelector('[name=route]').value,respondent_name:g.querySelector('[name=respondent_name]').value,email:g.querySelector('[name=email]').value})));
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.getByRole('button',{name:'Continue to waivers',exact:true}).click();
    await page.getByText('Your choices are saved.',{exact:false}).waitFor();
    assert.deepEqual(await counts(),{challenges:1,assignments:2,outbox:1,consumed:1});
    const replay=rows=>page.evaluate(async respondents=>(await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json','X-Kamp-Respondent-Intent':'confirm'},body:JSON.stringify({action:'confirm',acknowledged:true,respondents})})).status,rows);
    assert.equal(await replay(respondents),200);
    respondents[1].email='changed@example.test';assert.equal(await replay(respondents),409);
    await page.reload();await page.getByText('Your choices are saved.',{exact:false}).waitFor();
    return {width,invitationOnly:true,exactReplay:true,changedReplayDenied:true,resumed:true,outbox:1};
}
