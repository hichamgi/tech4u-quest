<?php
declare(strict_types=1);

use App\Core\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

// En CLI, http_response_code() ne peut plus modifier le statut dès qu'une sortie
// a été effectivement envoyée. On garde donc toute la sortie du test en mémoire
// jusqu'à la fin ; les buffers locaux de $dispatch continuent à capturer chaque
// réponse du routeur séparément.
ob_start();

$failures = [];

$check = static function (bool $condition, string $label, ?string $detail = null) use (&$failures): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }

    $message = $detail !== null && $detail !== '' ? "{$label} — {$detail}" : $label;
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};

$dispatch = static function (Router $router, string $method, string $uri): array {
    http_response_code(200);
    ob_start();
    $router->dispatch($method, $uri);
    $output = (string)ob_get_clean();
    return [http_response_code(), $output];
};

$router = new Router();
$router->get('/legacy.php', static function (): void {
    echo 'legacy';
});
$router->get('/module/{id}', static function (string $id): void {
    echo 'module:' . $id;
});

[$status, $output] = $dispatch($router, 'GET', '/legacy.php');
$check($status === 200 && $output === 'legacy', 'Route statique exacte');

[$status, $output] = $dispatch($router, 'GET', '/legacyXphp');
$check($status === 404, 'Le point des routes .php reste littéral', 'statut : ' . $status . ', sortie : ' . $output);

[$status, $output] = $dispatch($router, 'GET', '/module/42');
$check($status === 200 && $output === 'module:42', 'Paramètre dynamique de route');

[$status, $output] = $dispatch($router, 'GET', '/module/abc%20123');
$check($status === 200 && $output === 'module:abc 123', 'Décodage du paramètre dynamique');

echo "\n";
if ($failures !== []) {
    echo count($failures) . " contrôle(s) routeur en échec.\n";
    ob_end_flush();
    exit(1);
}

echo "Tous les contrôles du routeur sont OK.\n";
ob_end_flush();
exit(0);
