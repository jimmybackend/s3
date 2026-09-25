<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function collectionSource(string $root, string $relative): string
{
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read source file: ' . $relative);
    }
    return $content;
}

function expectCollection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$links = collectionSource($root, 'src/Federation/ArcadeLinkService.php');
$fileFormat = collectionSource($root, 'src/Federation/ArcadeLinkFileFormat.php');
$service = collectionSource($root, 'src/Federation/FederationService.php');
$controller = collectionSource($root, 'src/Http/Controller/FederationCollectionController.php');
$singleController = collectionSource($root, 'src/Http/Controller/FederationController.php');
$dropController = collectionSource($root, 'src/Http/Controller/FederationDropController.php');
$renderer = collectionSource($root, 'src/View/FederationPageRenderer.php');
$js = collectionSource($root, 'js/arcadelink-share.js');
$pageJs = collectionSource($root, 'js/federation-page.js');

expectCollection(str_contains($links, 'public function createCollection('), 'ArcadeLinkService must create v2 collections.');
expectCollection(str_contains($links, "'resource_type' => 'collection'"), 'Collection must declare resource_type collection.');
expectCollection(str_contains($links, "'items' => $items"), 'Collection must contain its signed resource documents.');
expectCollection(str_contains($service, 'foreach ($document[\'items\'] as $item)'), 'Reader must resolve every resource stored inside a collection ArcadeLink.');
expectCollection(str_contains($links, 'MAX_COLLECTION_ITEMS = 500'), 'Collection size limit must remain explicit.');
expectCollection(str_contains($service, 'createCollectionByStorageRefs('), 'FederationService must create one collection from selected storage refs.');
expectCollection(str_contains($service, "'collection' => true"), 'FederationService must identify collection inspection results.');
expectCollection(str_contains($service, '$this->resolver->resolve($item, $viewerUserId, true)'), 'Each collection item must use the normal local/remote resolver.');
expectCollection(str_contains($fileFormat, "MIME_TYPE = 'application/vnd.arcadecloud.arcadelink'"), 'ArcadeLink must have its own ArcadeCloud media type.');
expectCollection(str_contains($fileFormat, "EXTENSION = '.arcadelink'"), 'ArcadeLink must have its own .arcadelink extension.');
expectCollection(str_contains($controller, 'ArcadeLinkFileFormat::MIME_TYPE'), 'Collection download must emit the native ArcadeLink media type.');
expectCollection(str_contains($singleController, 'ArcadeLinkFileFormat::MIME_TYPE'), 'Single download must emit the native ArcadeLink media type.');
expectCollection(str_contains($dropController, 'ArcadeLinkFileFormat::MIME_TYPE'), 'FederationDrop ArcadeLink must emit the same native media type.');
expectCollection(!str_contains($controller, 'application/zip'), 'Collection endpoint must not emit ZIP.');
expectCollection(!str_contains($controller, 'ZipArchive'), 'Collection endpoint must not use ZipArchive.');
expectCollection(!str_contains($singleController, 'ArcadeLinkBundleService'), 'Single sharing must not use a ZIP bundler.');
expectCollection(str_contains($renderer, 'foreach ($items as $position => $item)'), 'Reader must render every collection item.');
expectCollection(str_contains($renderer, 'application/vnd.arcadecloud.arcadelink'), 'File picker must advertise the native ArcadeLink media type.');
expectCollection(str_contains($pageJs, "lower.endsWith('.arcadelink.json')"), 'Reader may rescue historical Android-added .json suffixes.');
expectCollection(str_contains($singleController, 'ArcadeLinkFileFormat::acceptsFilename'), 'Server reader must validate the ArcadeLink file contract centrally.');
expectCollection(!str_contains($singleController, "Content-Type: application/json"), 'Single ArcadeLink must not be presented as a JSON download.');
expectCollection(!str_contains($controller, "Content-Type: application/json"), 'Collection ArcadeLink must not be presented as a JSON download.');
expectCollection(!str_contains($dropController, "Content-Type: application/json; charset=UTF-8"), 'FederationDrop ArcadeLink must not be presented as a JSON download.');
expectCollection(str_contains($js, "federationcloud/collection.php"), 'Frontend must use the collection endpoint.');
expectCollection(!str_contains($js, 'ArcadeLink ZIP'), 'Frontend must not offer ZIP output.');

echo "OK ArcadeLink single-file collection regression\n";
