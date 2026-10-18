<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * `{{ path }}` placeholders in a flow's texts, URLs and values, filled from
 * the run's variables (`contact.name`, `answer`, `order.total`…). An unknown
 * one renders empty.
 */
final class FlowTemplate
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function render(?string $template, array $variables): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        return (string) preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', static function (array $match) use ($variables): string {
            $value = data_get($variables, $match[1]);

            return match (true) {
                is_bool($value)   => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                is_array($value)  => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                default           => '',
            };
        }, $template);
    }
}
