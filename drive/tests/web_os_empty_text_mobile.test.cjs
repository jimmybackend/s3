const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { ArcadeCloudMobileEditorSelection } = require('../js/editor-mobile-selection.js');

class Range {
  constructor(a, b, c, d) {
    this.startLineNumber = a; this.startColumn = b;
    this.endLineNumber = c; this.endColumn = d;
  }
  isEmpty() {
    return this.startLineNumber === this.endLineNumber && this.startColumn === this.endColumn;
  }
}
let content = 'abcdef';
let caret = { lineNumber: 1, column: 2 };
let selection = new Range(1, 2, 1, 2);
let writes = '';
const model = {
  getValueInRange(range) { return content.slice(range.startColumn - 1, range.endColumn - 1); },
  getFullModelRange() { return new Range(1, 1, 1, content.length + 1); }
};
const editor = {
  getModel: () => model,
  getPosition: () => caret,
  getSelection: () => selection,
  setSelection(range) { selection = range; },
  revealRangeInCenterIfOutsideViewport() {},
  pushUndoStop() {},
  executeEdits(source, edits) {
    assert.equal(source, 'mobile-toolbar');
    const { range, text } = edits[0];
    content = content.slice(0, range.startColumn - 1) + text + content.slice(range.endColumn - 1);
    selection = new Range(1, range.startColumn + text.length, 1, range.startColumn + text.length);
  },
  focus() {}
};
const controls = {
  mobileSelectionBar: { addEventListener() {} },
  mobileSelectionStatus: { textContent: '', dataset: {} },
  mobilePastePanel: { hidden: true },
  mobilePasteText: { value: '', focus() {} }
};
const documentMock = { getElementById: id => controls[id] };
const windowMock = {
  monaco: { Range },
  isSecureContext: true,
  navigator: { clipboard: { async writeText(text) { writes = text; }, async readText() { return 'PEGADO'; } } }
};
const mobile = new ArcadeCloudMobileEditorSelection(windowMock, documentMock, editor);
(async () => {
mobile.init();
mobile.mark('start');
caret = { lineNumber: 1, column: 5 };
mobile.mark('end');
assert.equal(mobile.selectedText(), 'bcd');
await mobile.copy(false);
assert.equal(writes, 'bcd');
await mobile.copy(true);
assert.equal(content, 'aef');
assert.equal(writes, 'bcd');
assert.equal(mobile.start, null);
caret = { lineNumber: 1, column: 2 };
selection = new Range(1, 2, 1, 2);
await mobile.paste();
assert.equal(content, 'aPEGADOef');
mobile.selectAll();
assert.equal(mobile.selectedText(), 'aPEGADOef');
assert.deepEqual(ArcadeCloudMobileEditorSelection.ordered(
  { lineNumber: 4, column: 2 }, { lineNumber: 2, column: 8 }
), [{ lineNumber: 2, column: 8 }, { lineNumber: 4, column: 2 }]);
windowMock.navigator.clipboard.readText = async () => { throw new Error('denied'); };
await mobile.paste();
assert.equal(controls.mobilePastePanel.hidden, false, 'Fallback must be visible when mobile clipboard blocked');
controls.mobilePasteText.value = 'Manual';
selection = new Range(1, 1, 1, 1);
mobile.insertFallback();
assert.equal(content, 'ManualaPEGADOef');
assert.equal(controls.mobilePastePanel.hidden, true);

const root = path.join(__dirname, '..');
const so = fs.readFileSync(path.join(root, 'so.php'), 'utf8');
const folderAction = fs.readFileSync(path.join(root, 'js/so-folders.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'src/Http/Controller/FolderDocumentController.php'), 'utf8');
const service = fs.readFileSync(path.join(root, 'src/Application/FolderDocumentService.php'), 'utf8');
const desktopEditor = fs.readFileSync(path.join(root, 'editor.php'), 'utf8');
const creator = fs.readFileSync(path.join(root, 'js/so-new-text-file.js'), 'utf8');
for (const marker of ['data-folder-action="create-empty-file"', 'modalCrearArchivoVacio', 'emptyTextFileName', 'emptyTextFileFormat']) {
  assert.ok(so.includes(marker), 'SO missing new-file surface ' + marker);
}
assert.ok(folderAction.includes("action === 'create-empty-file'"));
assert.ok(folderAction.includes("this.currentFolderFromDom(body.closest('.os-explorer-window') || root)"),
  'Blank-area menu must use its own Explorer folder, not another window');
assert.ok(folderAction.includes("this.currentFolderFromDom(button.closest('.os-explorer-window') || root)"),
  'Toolbar action must use the Explorer that contains its button');
assert.ok(controller.includes("postString('create_empty') === '1'"));
assert.ok(service.includes('assertFolderExists($userId, $route)'));
assert.ok(service.includes('assertNameAvailable($userId, $route, $filename)'));
assert.ok(service.includes("SELECT id_ FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Nombre = ? AND Found = 1"));
assert.ok(service.includes("private const FORMATS"));
assert.ok(creator.includes("create_empty: '1'") && creator.includes("'X-Drive-CSRF'"));
assert.ok(desktopEditor.includes('data-mobile-edit="start"'));
assert.ok(desktopEditor.includes('data-mobile-edit="end"'));
assert.ok(desktopEditor.includes('editor-mobile-selection.js'));
console.log('SO empty document and mobile Monaco editing regression OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
