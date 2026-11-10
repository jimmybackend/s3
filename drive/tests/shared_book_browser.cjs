const { chromium } = require(process.env.READER_PLAYWRIGHT || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
(async () => {
  const root = path.resolve(__dirname, '..');
  const php = `require '${root}/src/View/TranscriptReader.php'; require '${root}/src/View/ExtractedTextReader.php'; require '${root}/src/View/SharePageRenderer.php'; $d=['documentos'=>[['archivo'=>'IMG_10.jpg','texto'=>'Última página'],['archivo'=>'IMG_2.jpg','texto'=>'Segunda página'],['archivo'=>'IMG_1.jpg','texto'=>'Primera página']]]; echo (new ArcadeCloud\\Drive\\View\\SharePageRenderer())->render('text',['nombre'=>'Libro.json'],json_encode($d),false,true);`;
  const html = execFileSync('php', ['-r', php], {encoding:'utf8'});
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    const aiCalls = [];
    await page.route('https://reader.test/**', async route => {
      if (route.request().method() === 'POST') {
        const index = new URLSearchParams(route.request().postData()).get('page');
        aiCalls.push(Number(index));
        await route.fulfill({contentType:'application/json', body:JSON.stringify({ok:true,styles:[{kind:'body',align:'justify',bold:true,text:'REPLACEMENT MUST NEVER APPEAR'}],cached:false})});
      } else await route.fulfill({contentType:'text/html',body:'<!doctype html><html></html>'});
    });
    await page.goto('https://reader.test/token_texto.php?t=fixture&Extraccion=1');
    await page.setContent(html);
    await page.addStyleTag({path:path.join(root,'css/styles.css')});
    await page.addStyleTag({path:path.join(root,'css/share-reader.css')});
    await page.addScriptTag({path:path.join(root,'js/book-ai-formatter.js')});
    await page.addScriptTag({path:path.join(root,'js/share-reader.js')});
    const check = (ok, message) => { if (!ok) throw new Error(message); };
    check(await page.locator('#book-dialog').evaluate(d => d.open), 'Automatic book modal');
    check(await page.locator('.book-page:visible').count() === 2, 'Two pages');
    await page.waitForFunction(() => document.querySelectorAll('.book-line').length >= 2);
    check(aiCalls.length === 2 && aiCalls.every(i => i === 0 || i === 1), 'Only visible spread uses AI');
    check(await page.locator('.book-text').first().textContent() === 'Primera página', 'AI preserves original text');
    check(await page.locator('.book-line').first().evaluate(e => getComputedStyle(e).fontWeight) === '700', 'AI styling applied');
    const typography = await page.evaluate(() => {
      const host = document.createElement('div');
      const formatter = new BookAiFormatter(document.getElementById('book-dialog'), [], () => {});
      const source = 'Texto original de JIMMY ÁVILA (I), ¿sí?; continúa\\nesta línea termina en punto.\\n\\nPÁRRAFO FINAL.';
      const value = source.replaceAll('\\n', '\n');
      formatter.render(host, value, (target, text) => target.append(document.createTextNode(text)));
      document.querySelector('.book-text').append(host);
      const result = {
        original: host.textContent === value,
        bold: [...host.querySelectorAll('strong')].map(e => e.textContent),
        justified: getComputedStyle(host.querySelector('.book-line')).textAlignLast,
        paragraph: host.querySelectorAll('[data-paragraph-end="true"]').length,
        blank: host.querySelectorAll('.book-line')[2].textContent === ''
      };
      host.remove(); return result;
    });
    check(typography.original && typography.blank, 'Local typography preserves text and line breaks without AI');
    check(typography.bold.includes('JIMMY') && typography.bold.includes('ÁVILA') && typography.bold.includes('PÁRRAFO'), 'Uppercase words bold locally');
    check(typography.justified === 'justify' && typography.paragraph === 2, 'Local justification and paragraph spacing');
    const book = await page.locator('#book-dialog').boundingBox();
    check(book.width <= 1280 && book.height <= 900, 'Desktop viewport bounds');
    const type = await page.locator('.book-text').first().evaluate(e => ({font:getComputedStyle(e).fontFamily,size:getComputedStyle(e).fontSize,align:getComputedStyle(e).textAlign,color:getComputedStyle(e).color}));
    check(type.font.includes('Arial') && Math.abs(parseFloat(type.size) - 14.6667) < .02 && type.align === 'justify', 'Arial 11pt justified');
    check(type.color === 'rgb(0, 0, 0)', 'Black text under actual application theme');
    check(await page.locator('.book-page header strong').first().evaluate(e => getComputedStyle(e).color) === 'rgb(0, 0, 0)', 'Black page heading');
    check(await page.locator('.book-dialog-bar>span').textContent() === 'Libro.json', 'Document filename in book title');
    await page.click('#book-larger');
    check(await page.locator('.book-text').first().evaluate(e => getComputedStyle(e).fontSize) === '16px', 'Book font increases to 12pt');
    await page.click('#book-smaller');
    check(await page.locator('#book-font-size').textContent() === '11 pt', 'Book font restores 11pt');
    check(await page.locator('.book-navigation').evaluate(e => e.getBoundingClientRect().top >= e.previousElementSibling.getBoundingClientRect().bottom), 'Pagination below book');
    check((await page.locator('.book-page:visible').first().textContent()).includes('IMG_1.jpg'), 'Natural order');
    const left = await page.locator('.book-page:visible').first().boundingBox();
    const right = await page.locator('.book-page:visible').nth(1).boundingBox();
    check(right.x > left.x && right.y === left.y, 'Facing pages');
    await page.click('#book-next');
    await page.waitForFunction(() => !document.querySelector('.book-turn-leaf'));
    check(await page.locator('.book-page:visible').count() === 1, 'Odd final page');
    check(await page.locator('#book-next').isDisabled(), 'Last spread boundary');
    await page.click('#book-prev');
    await page.waitForFunction(() => !document.querySelector('.book-turn-leaf'));
    await page.fill('#book-search','Última');
    await page.waitForFunction(() => document.querySelector('mark.active'));
    check(await page.locator('.book-page:visible mark').count() === 1, 'Search navigates to hidden page');
    await page.click('#book-close');
    await page.locator('[data-reader-panel="raw"]').waitFor({state:'visible'});
    check(await page.locator('[data-reader-panel="raw"]').isVisible(), 'Raw JSON choice');
    await page.click('[data-json-mode="pages"]');
    await page.fill('#book-search','');
    await page.fill('#book-jump','1');
    await page.locator('#book-jump').dispatchEvent('change');
    check((await page.locator('#book-position').textContent()).includes('1–2'), 'Page jump');
    await page.setViewportSize({width:390,height:844});
    await page.waitForFunction(() => [...document.querySelectorAll('.book-page')].filter(p => !p.hidden).length === 1);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= 390), 'Mobile width');
    check(await page.locator('.book-page:visible').count() === 1, 'Single readable leaf on phone');
    const mobile = await page.locator('#book-dialog').boundingBox();
    check(mobile.x >= 0 && mobile.y >= 0 && mobile.x + mobile.width <= 390 && mobile.y + mobile.height <= 844, 'Mobile modal bounds');
    await page.keyboard.press('Escape');
    check(!await page.locator('#book-dialog').evaluate(d => d.open), 'Escape closes modal');
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.click('[data-json-mode="pages"]');
    await page.click('#book-next');
    check(await page.locator('.book-turn-leaf').count() === 0, 'Reduced motion');
    check(aiCalls.filter(i => i === 0).length === 1, 'Returning to read page does not reinvoke AI');
    console.log('Book browser interactions: OK');
  } finally { await browser.close(); }
})().catch(e => {console.error(e);process.exit(1);});
