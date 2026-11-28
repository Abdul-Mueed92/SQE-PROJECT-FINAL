<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$file = 'app/Jobs/Banking/CreateBankingDocumentTransaction.php';
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$finder = new NodeFinder();
$root = $parser->parse(file_get_contents(dirname(__DIR__) . '/' . $file));
$method = $finder->findFirst($root, static fn ($node) => $node instanceof ClassMethod && $node->name->name === 'checkAmount');
if (!$method instanceof ClassMethod) {
    throw new RuntimeException('Payment rule method unavailable.');
}
$decisions = $finder->find($method->stmts, static fn ($node) => $node instanceof If_ || $node instanceof Ternary);
echo json_encode([
    'file' => $file,
    'method' => 'checkAmount',
    'decisionLines' => array_map(static fn ($node) => $node->getStartLine(), $decisions),
    'cyclomaticComplexity' => 1 + count($decisions),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
