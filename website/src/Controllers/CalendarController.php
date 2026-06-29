<?php

declare(strict_types=1);

namespace Starlink\Controllers;

final class CalendarController
{
    public function show(): void
    {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $target = route_path('/') . ($query !== '' ? '?' . $query : '') . '#book';
        header('Location: ' . $target, true, 302);
        exit;
    }
}
