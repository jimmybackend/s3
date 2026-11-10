<?php
declare(strict_types=1);
require __DIR__ . '/../src/Book/BookLayout.php';
require __DIR__ . '/../src/Book/BookLayoutStore.php';
use ArcadeCloud\Drive\Book\BookLayout;
use ArcadeCloud\Drive\Book\BookLayoutStore;
function aiCheck(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$text = "TÍTULO (I)\n\n¿Texto original? Sí; $1.\nFIN.";
$styles = BookLayout::validate($text, ['lines' => [['id'=>0,'kind'=>'heading','align'=>'center','bold'=>true,'text'=>'INVENTADO'], ['id'=>2,'kind'=>'<script>','align'=>'url(x)'], ['id'=>999,'bold'=>true]]]);
aiCheck(count($styles) === 4 && $styles[0]['bold'], 'All original lines including blanks retained');
aiCheck($styles[2]['kind'] === 'body' && $styles[2]['align'] === 'justify', 'Only allowlisted styles');
aiCheck(!str_contains(json_encode($styles), 'INVENTADO'), 'Model cannot replace text');
aiCheck(implode("\n", BookLayout::lines($text)) === $text, 'Original punctuation and line breaks unchanged');
aiCheck(BookLayout::validate($text, null)[3]['bold'], 'Uppercase fallback');
$directory = sys_get_temp_dir() . '/book-ai-test-' . bin2hex(random_bytes(6));
$store = new BookLayoutStore($directory); $calls = 0;
$generate = function () use (&$calls, $styles): array { $calls++; return ['styles'=>$styles,'usage'=>['input'=>10], 'correlation'=>'test']; };
try {
 $first = $store->remember('hash', 'token', 'ip', $generate);
 $cached = $store->remember('hash', 'different-token', 'different-ip', $generate);
 aiCheck($calls === 1 && !$first['cached'] && $cached['cached'], 'Shared content cache prevents duplicate inference');
 putenv('ARCADECLOUD_BOOK_AI_DAILY_LIMIT=0');
 $denied = false;
 try { $store->remember('new-hash', 'token', 'ip', $generate); } catch (RuntimeException) { $denied = true; }
 aiCheck($denied && $calls === 1, 'Global ceiling before paid calls');
 aiCheck($store->remember('hash', 'token', 'ip', $generate)['cached'], 'Cached styles available with spending disabled');
 putenv('ARCADECLOUD_BOOK_AI_DAILY_LIMIT=200');
 $failed = false;
 try { $store->remember('failed', 'token', 'ip', static function (): array { throw new RuntimeException('provider'); }); } catch (RuntimeException) { $failed = true; }
 $budget = json_decode(file_get_contents($directory . '/budget.json'), true);
 aiCheck($failed && $budget['count'] === 2, 'Failed attempts also consume ceiling');
} finally {
 putenv('ARCADECLOUD_BOOK_AI_DAILY_LIMIT');
 foreach (glob($directory . '/*') as $file) unlink($file);
 rmdir($directory);
}
echo "Book AI validation/cache/budget: OK\n";
