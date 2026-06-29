<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Services\SquareService;

final class WebhookController
{
    public function __construct(
        private readonly SquareService $square = new SquareService(),
    ) {
    }

    public function square(): void
    {
        $raw = file_get_contents('php://input') ?: '{}';
        if (!$this->square->verifyWebhookSignature($raw)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Invalid webhook signature.']);
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload.']);
            return;
        }

        $result = $this->square->handleWebhook($payload);

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
