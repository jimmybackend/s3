const { chromium } = require(process.env.READER_PLAYWRIGHT || 'playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
(async () => {
  const root = path.resolve(__dirname, '..');
  const php = `require '${root}/src/View/TranscriptReader.php'; require '${root}/src/View/ExtractedTextReader.php'; require '${root}/src/View/SharePageRenderer.php'; $d=['documentos'=>[['archivo'=>'IMG_10.jpg','texto'=>'Última página'],['archivo'=>'IMG_2.jpg','texto'=>'Segunda página'],['archivo'=>'IMG_1.jpg','texto'=>'Primera página']]]; echo (new ArcadeCloud\\Drive\\View\\SharePageRenderer())->render('text',['nombre'=>'Libro.json'],json_encode($d),true);`;
  const html = execFileSync('php', ['-r', php], {encoding:'utf8'});
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    await page.setContent(html);
    await page.addStyleTag({path:path.join(root,'css/share-reader.css')});
    await page.addScriptTag({path:path.join(root,'js/share-reader.js')});
    const check = (ok, message) => { if (!ok) throw new Error(message); };
    check(await page.locator('.book-page:visible').count() === 2, 'Two pages');
    check((await page.locator('.book-page:visible').first().textContent()).includes('IMG_1.jpg'), 'Natural order');
    const left = await page.locator('.book-page:visible').first().boundingBox();
    const right = await page.locator('.book-page:visible').nth(1).boundingBox();
    check(right.x > left.x && right.y === left.y, 'Facing pages');
    await page.click('#book-next');
    check(await page.locator('.book-page:visible').count() === 1, 'Odd final page');
    check(await page.locator('#book-next').isDisabled(), 'Last spread boundary');
    await page.click('#book-prev');
    await page.fill('#reader-search','Última');
    await page.waitForFunction(() => document.querySelector('mark.active'));
    check(await page.locator('.book-page:visible mark').count() === 1, 'Search navigates to hidden page');
    await page.click('[data-json-mode="raw"]');
    check(await page.locator('[data-reader-panel="raw"]').isVisible(), 'Raw JSON choice');
    await page.click('[data-json-mode="pages"]');
    await page.fill('#reader-search','');
    await page.fill('#book-jump','1');
    await page.locator('#book-jump').dispatchEvent('change');
    check((await page.locator('#book-position').textContent()).includes('1–2'), 'Page jump');
    await page.setViewportSize({width:390,height:844});
    check(await page.evaluate(() => document.documentElement.scrollWidth <= 390), 'Mobile width');
    console.log('Book browser interactions: OK');
  } finally { await browser.close(); }
})().catch(e => {console.error(e);process.exit(1);});
