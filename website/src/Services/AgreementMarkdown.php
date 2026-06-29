<?php

declare(strict_types=1);

namespace Starlink\Services;

final class AgreementMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (self::isTableRow($line) && $index + 1 < $count && self::isTableSeparator($lines[$index + 1])) {
                [$tableHtml, $index] = self::parseTable($lines, $index);
                $html[] = $tableHtml;
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $matches) === 1) {
                $level = strlen($matches[1]);
                $tag = match ($level) {
                    1 => 'h2',
                    2 => 'h3',
                    default => 'h4',
                };
                $html[] = sprintf('<%1$s class="agreement-heading">%2$s</%1$s>', $tag, self::inline($matches[2]));
                $index++;
                continue;
            }

            if (str_starts_with(ltrim($line), '- ')) {
                [$listHtml, $index] = self::parseList($lines, $index);
                $html[] = $listHtml;
                continue;
            }

            [$paragraphHtml, $index] = self::parseParagraph($lines, $index);
            $html[] = $paragraphHtml;
        }

        return implode("\n", $html);
    }

    /**
     * @param list<string> $lines
     * @return array{0: string, 1: int}
     */
    private static function parseTable(array $lines, int $start): array
    {
        $headerCells = self::splitTableRow($lines[$start]);
        $index = $start + 2;
        $bodyRows = [];

        while ($index < count($lines) && self::isTableRow($lines[$index])) {
            $bodyRows[] = self::splitTableRow($lines[$index]);
            $index++;
        }

        $thead = '<thead><tr>';
        foreach ($headerCells as $cell) {
            $thead .= '<th>' . self::inline($cell) . '</th>';
        }
        $thead .= '</tr></thead>';

        $tbody = '<tbody>';
        foreach ($bodyRows as $row) {
            $tbody .= '<tr>';
            foreach ($row as $cell) {
                $tbody .= '<td>' . self::inline($cell) . '</td>';
            }
            $tbody .= '</tr>';
        }
        $tbody .= '</tbody>';

        return ['<div class="agreement-table-wrap"><table class="agreement-table">' . $thead . $tbody . '</table></div>', $index];
    }

    /**
     * @param list<string> $lines
     * @return array{0: string, 1: int}
     */
    private static function parseList(array $lines, int $start): array
    {
        $items = [];
        $index = $start;

        while ($index < count($lines) && str_starts_with(ltrim($lines[$index]), '- ')) {
            $items[] = self::inline(substr(ltrim($lines[$index]), 2));
            $index++;
        }

        $html = '<ul class="agreement-list">';
        foreach ($items as $item) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</ul>';

        return [$html, $index];
    }

    /**
     * @param list<string> $lines
     * @return array{0: string, 1: int}
     */
    private static function parseParagraph(array $lines, int $start): array
    {
        $parts = [];
        $index = $start;

        while ($index < count($lines)) {
            $line = trim($lines[$index]);
            if ($line === '') {
                break;
            }
            if (preg_match('/^#{1,3}\s+/', $line) === 1 || str_starts_with($line, '- ') || self::isTableRow($lines[$index])) {
                break;
            }
            $parts[] = self::inline($line);
            $index++;
        }

        return ['<p class="agreement-paragraph">' . implode(' ', $parts) . '</p>', $index];
    }

    private static function isTableRow(string $line): bool
    {
        $trimmed = trim($line);

        return str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|');
    }

    private static function isTableSeparator(string $line): bool
    {
        return preg_match('/^\|\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|$/', trim($line)) === 1;
    }

    /** @return list<string> */
    private static function splitTableRow(string $line): array
    {
        $cells = explode('|', trim($line, "| \t"));
        $cells = array_map(static fn (string $cell): string => trim($cell), $cells);

        return array_values(array_filter($cells, static fn (string $cell): bool => $cell !== ''));
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;

        return $escaped;
    }
}
