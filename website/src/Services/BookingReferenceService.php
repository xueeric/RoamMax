<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use RuntimeException;

final class BookingReferenceService
{
    /** Uppercase letters and digits, excluding ambiguous 0/O, 1/I/L. */
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function generate(PDO $db): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = $this->randomCode();
            $stmt = $db->prepare('SELECT 1 FROM bookings WHERE reference_code = :code LIMIT 1');
            $stmt->execute(['code' => $code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique booking reference.');
    }

    public function backfillMissing(PDO $db): int
    {
        $stmt = $db->query(
            "SELECT id FROM bookings WHERE reference_code IS NULL OR TRIM(reference_code) = '' ORDER BY id ASC"
        );
        $rows = $stmt->fetchAll();
        $count = 0;

        foreach ($rows as $row) {
            $update = $db->prepare('UPDATE bookings SET reference_code = :code WHERE id = :id');
            $update->execute([
                'code' => $this->generate($db),
                'id' => (int) $row['id'],
            ]);
            $count++;
        }

        return $count;
    }

    private function randomCode(): string
    {
        $charset = self::CHARSET;
        $length = strlen($charset);
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $charset[random_int(0, $length - 1)];
        }

        return $code;
    }
}
