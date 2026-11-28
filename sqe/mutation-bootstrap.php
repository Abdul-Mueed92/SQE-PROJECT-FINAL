<?php

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

$source = dirname(__DIR__) . '/app/Jobs/Banking/CreateBankingDocumentTransaction.php';
$operator = getenv('SQE_MUTATION_OPERATOR');
[$original, $replacement] = match ($operator) {
    'reject-exact-balance' => ['if ($compare === 1)', 'if ($compare >= 0)'],
    'ignore-previous-payments' => ['$this->model->amount - $this->model->paid_amount, $document_precision',
        '$this->model->amount, $document_precision'],
    default => throw new RuntimeException('Unknown SQE payment operator.'),
};
$code = str_replace($original, $replacement, file_get_contents($source), $occurrences);
if ($occurrences !== 1) {
    throw new RuntimeException('The payment operator must mutate exactly one expression.');
}
$mutated = tempnam(sys_get_temp_dir(), 'sqe-mutant-');
if (file_put_contents($mutated, $code) === false) {
    throw new RuntimeException('Unable to write the isolated payment mutant.');
}
try {
    require $mutated;
} finally {
    unlink($mutated);
}
