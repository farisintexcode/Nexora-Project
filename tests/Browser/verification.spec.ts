import { expect, test } from './fixtures';

test('Admin independently approves or returns frozen submissions and Rider sees correction instructions',async({page})=>{
    test.setTimeout(120000);
    const email=process.env.DCMS_E2E_ADMIN_EMAIL;
    const rider=process.env.DCMS_E2E_RIDER_EMAIL;
    const password=process.env.DCMS_E2E_PASSWORD;
    test.skip(!email||!rider||!password,'Requires isolated synthetic review fixtures.');
    const surface=test.info().project.name;
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill(email!);
    await page.getByLabel('Password',{exact:true}).fill(password!);
    await page.getByRole('button',{name:'Sign in',exact:true}).click();
    await expect(page).toHaveURL(/\/admin$/,{timeout:90000});
    let returnedTask='';
    for(const decision of ['approve','return']){
        await page.goto('/admin/verification');
        await page.getByLabel('Account or customer reference',{exact:true}).fill('M07-'+decision.toUpperCase()+'-'+surface.toUpperCase());
        await expect(page.getByRole('link',{name:'Review submission',exact:true})).toHaveCount(1);
        await page.getByRole('link',{name:'Review submission',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Review submission',exact:true})).toBeVisible();
        await expect(page.getByText('Synthetic frozen verification visit',{exact:true})).toBeVisible();
        if(decision==='return'){
            returnedTask=(await page.getByRole('link',{name:'Task context',exact:true}).getAttribute('href'))!.replace('/admin/tasks/','/rider/tasks/');
            await page.getByLabel('Decision',{exact:true}).selectOption('returned');
            await expect(page.getByLabel('Verification remarks *',{exact:true})).toHaveJSProperty('required',true);
            await page.getByLabel('Verification remarks *',{exact:true}).fill('Please provide a clearer site photo.');
        }
        await page.getByRole('button',{name:'Prepare review preview',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Confirm exact review selection',exact:true})).toBeVisible();
        await page.getByRole('button',{name:'Confirm verification decision',exact:true}).click();
        const dialog=page.getByRole('dialog',{name:'Commit this review?',exact:true});
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button',{name:'Confirm',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Recorded verification decision',exact:true})).toBeVisible();
        if(decision==='return'){await expect(page.getByText('Please provide a clearer site photo.',{exact:true})).toBeVisible();}
        await page.screenshot({path:'.local-tools/m07-'+surface+'-'+decision+'.png',fullPage:true});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    }
    await page.getByRole('button',{name:'Sign out',exact:true}).first().click();
    await page.goto('/rider/login');
    await page.getByLabel('Email',{exact:true}).fill(rider!);
    await page.getByLabel('Password',{exact:true}).fill(password!);
    await page.getByRole('button',{name:'Sign in',exact:true}).click();
    await expect(page).toHaveURL(/\/rider$/,{timeout:90000});
    await expect(page.locator('a[href="'+returnedTask+'"]').filter({hasText:'Your task was returned for correction.'})).toBeVisible();
    await page.goto(returnedTask);
    await expect(page.getByText('Please provide a clearer site photo.',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Start correction',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Record site visit',exact:true})).toBeVisible();
});

