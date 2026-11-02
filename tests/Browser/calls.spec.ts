import { expect, test } from './fixtures';

test('agent finds customer records backdated callback updates contacts and resolves reminder', async ({page}) => {
    test.setTimeout(240000);
    const password=process.env.DCMS_E2E_PASSWORD;
    const admin=process.env.DCMS_E2E_ADMIN_EMAIL;
    test.skip(!password || !admin,'Requires isolated synthetic Call Centre fixtures.');
    const surface=test.info().project.name;
    const run=surface+' '+Date.now();
    const local=(days:number)=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Kuala_Lumpur',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(Date.now()+days*86400000));
    const errors:string[]=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('dialog',dialog=>dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill(admin!);
    await page.getByLabel('Password',{exact:true}).fill(password!);
    await page.getByRole('button',{name:'Sign in',exact:true}).click();
    await expect(page).toHaveURL(/\/admin$/,{timeout:90000});
    await page.goto('/admin/calls');
    await page.getByLabel('Search customers',{exact:true}).fill('CALL-'+surface.toUpperCase());
    await page.getByRole('button',{name:'Search',exact:true}).click();
    await expect(page.getByRole('link',{name:'Open call workspace',exact:true})).toHaveCount(1);
    expect(page.url()).not.toContain('CALL-');
    await page.getByRole('link',{name:'Open call workspace',exact:true}).click();
    await page.getByLabel('Record an earlier call (today or yesterday)',{exact:true}).check();
    await page.getByLabel('Actual call time (MYT)',{exact:true}).fill(local(-1)+'T10:00');
    await page.getByLabel('Backdating reason',{exact:true}).fill('Synthetic offline notes '+run);
    await page.getByLabel('Call outcome',{exact:true}).selectOption('call_back_requested');
    await page.getByLabel('Call remarks',{exact:true}).fill('Synthetic callback conversation '+run);
    await page.getByLabel('Follow-up due (MYT) — required',{exact:true}).fill(local(1)+'T10:00');
    await page.getByLabel('Follow-up notes (optional)',{exact:true}).fill('Synthetic callback reminder '+run);
    await page.getByRole('button',{name:'Save call outcome',exact:true}).click();
    await expect(page.getByRole('status').filter({hasText:'Outgoing call recorded'})).toBeVisible();
    await expect(page.getByText('Synthetic callback conversation '+run,{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Edit contact observations',exact:true}).click();
    await page.getByLabel('Contact value',{exact:true}).fill('+60198765432');
    await page.getByRole('button',{name:'Save contacts',exact:true}).click();
    await expect(page.getByRole('status').filter({hasText:'Contact observations saved.'})).toBeVisible();
    await expect(page.getByText('+60198765432',{exact:true})).toBeVisible();
    await page.getByLabel('Call outcome',{exact:true}).selectOption('promise_to_pay');
    await expect(page.getByLabel('Promise details',{exact:true})).toBeVisible();
    await page.getByLabel('Record an earlier call (today or yesterday)',{exact:true}).check();
    await expect(page.getByLabel('Actual call time (MYT)',{exact:true})).toBeVisible();
    if(surface==='rider-mobile'){
        for(const width of [320,430]){
            await page.setViewportSize({width,height:900});
            expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
            for(const input of await page.locator('input[type="datetime-local"], input[type="date"]').all()){
                const box=await input.boundingBox();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x+box!.width).toBeLessThanOrEqual(width+1);
            }
        }
        await page.setViewportSize({width:375,height:667});
    }
    await page.screenshot({path:'.local-tools/m11-'+surface+'-workspace.png',fullPage:true});
    await page.goto('/admin/calls/follow-ups');
    const card=page.locator('article').filter({hasText:'Synthetic Call Customer '+surface});
    await card.getByLabel('Resolution remarks',{exact:true}).fill('Synthetic callback complete '+run);
    await card.getByRole('button',{name:'Record reminder decision',exact:true}).click();
    await expect(page.getByRole('status').filter({hasText:'Follow-up decision recorded.'})).toBeVisible();
    await page.getByLabel('Reminder status',{exact:true}).selectOption('done');
    await page.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect(page.getByText('Synthetic callback complete '+run,{exact:true})).toBeVisible();
    await page.screenshot({path:'.local-tools/m11-'+surface+'-queue.png',fullPage:true});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    expect(errors).toEqual([]);
});
