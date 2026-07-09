<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

/**
 * Builds the generic document HTML used when a PdfTemplate declares no Blade
 * view: accent-branded header (logo/title/number/status), from/to parties, a
 * line-items table (optional SKU column, optional striping), a right-aligned
 * summary, notes, footer and an optional signature line. Inline CSS only —
 * dompdf-safe (no flex/grid), DejaVu fonts for accents.
 */
class DocumentBuilder
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $data
     */
    public static function build(PdfTemplate $template, array $settings, array $data): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);

        $accent = $e($settings['accent'] ?? '#6366f1');
        $text   = $e($settings['text'] ?? '#0f172a');
        $font   = ($settings['font'] ?? 'sans') === 'serif'
            ? "'DejaVu Serif', Georgia, serif"
            : "'DejaVu Sans', Helvetica, Arial, sans-serif";

        $title   = $e($settings['doc_title'] ?? $template->label());
        $striped = (bool) ($settings['striped'] ?? true);
        $showSku = (bool) ($settings['show_sku'] ?? true) && collect($data['items'] ?? [])->contains(fn ($i) => isset($i['sku']));

        // --- Header --------------------------------------------------------
        $logo = '';
        if (($settings['show_logo'] ?? true) && $template->logo() !== null) {
            $logo = '<img src="'.$e($template->logo()).'" style="max-height:44px;max-width:180px;" alt="logo">';
        }

        $status = '';
        if (($settings['show_status'] ?? true) && ! empty($data['status'])) {
            $status = '<span style="display:inline-block;padding:3px 10px;border:1px solid '.$accent.';color:'.$accent.';border-radius:99px;font-size:10px;letter-spacing:1px;text-transform:uppercase;">'.$e($data['status']).'</span>';
        }

        $meta = '';
        if (! empty($data['number'])) {
            $meta .= '<div style="font-size:12px;color:#6b7280;">'.$e($data['number']).'</div>';
        }
        if (! empty($data['date'])) {
            $meta .= '<div style="font-size:11px;color:#6b7280;">'.$e($data['date']).'</div>';
        }

        // --- Parties ---------------------------------------------------------
        $party = static function (?array $side, string $heading) use ($e, $accent): string {
            if ($side === null || $side === []) {
                return '';
            }

            $lines = implode('<br>', array_map($e, (array) ($side['lines'] ?? [])));

            return '<td style="vertical-align:top;padding:0 24px 0 0;">'
                .'<div style="font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:'.$accent.';margin-bottom:4px;">'.$e($heading).'</div>'
                .'<div style="font-weight:bold;font-size:12px;">'.$e($side['name'] ?? '').'</div>'
                .($lines !== '' ? '<div style="font-size:11px;color:#6b7280;line-height:1.5;">'.$lines.'</div>' : '')
                .'</td>';
        };

        // --- Items table -----------------------------------------------------
        $head = '<tr>'
            .($showSku ? '<th style="text-align:left;">SKU</th>' : '')
            .'<th style="text-align:left;">Item</th>'
            .'<th style="text-align:right;">Qty</th>'
            .'<th style="text-align:right;">Price</th>'
            .'<th style="text-align:right;">Total</th></tr>';

        $rows = '';
        foreach ((array) ($data['items'] ?? []) as $i => $item) {
            $bg = $striped && $i % 2 === 1 ? 'background:#f8fafc;' : '';
            $rows .= '<tr>'
                .($showSku ? '<td style="'.$bg.'color:#6b7280;">'.$e($item['sku'] ?? '').'</td>' : '')
                .'<td style="'.$bg.'">'.$e($item['name'] ?? '').'</td>'
                .'<td style="'.$bg.'text-align:right;">'.$e($item['qty'] ?? '').'</td>'
                .'<td style="'.$bg.'text-align:right;">'.$e($item['price'] ?? '').'</td>'
                .'<td style="'.$bg.'text-align:right;">'.$e($item['total'] ?? '').'</td>'
                .'</tr>';
        }

        // --- Summary ---------------------------------------------------------
        $summary     = '';
        $summaryRows = (array) ($data['summary'] ?? []);
        foreach ($summaryRows as $i => $line) {
            $last = $i === count($summaryRows) - 1;
            $summary .= '<tr>'
                .'<td style="padding:4px 16px 4px 0;font-size:'.($last ? '13px;font-weight:bold;color:'.$accent : '11px;color:#6b7280').';">'.$e($line['label'] ?? '').'</td>'
                .'<td style="padding:4px 0;text-align:right;font-size:'.($last ? '13px;font-weight:bold;color:'.$accent : '11px').';">'.$e($line['value'] ?? '').'</td>'
                .'</tr>';
        }

        $notes = ! empty($data['notes'])
            ? '<div style="margin-top:24px;font-size:11px;color:#6b7280;"><strong>Notes</strong><br>'.$e($data['notes']).'</div>'
            : '';

        $signature = ($settings['signature'] ?? false)
            ? '<table width="100%" style="margin-top:56px;"><tr><td width="60%"></td><td style="border-top:1px solid #d1d5db;text-align:center;font-size:10px;color:#6b7280;padding-top:6px;">'.$e($settings['signature_label'] ?? 'Authorized signature').'</td></tr></table>'
            : '';

        $footer = ! empty($settings['footer_text'])
            ? $e($settings['footer_text'])
            : $e(config('app.name').' — '.now()->format('Y'));

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            ."body{font-family:{$font};color:{$text};font-size:12px;margin:36px 42px;}"
            .'table.items{width:100%;border-collapse:collapse;margin-top:20px;font-size:11px;}'
            .'table.items th{border-bottom:2px solid '.$accent.';padding:7px 8px;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:'.$accent.';}'
            .'table.items td{border-bottom:1px solid #e5e7eb;padding:7px 8px;}'
            .'</style></head><body>'
            // Header band
            .'<table width="100%"><tr>'
            .'<td style="vertical-align:middle;">'.$logo.'<div style="font-size:22px;font-weight:bold;color:'.$accent.';">'.$title.'</div>'.$meta.'</td>'
            .'<td style="vertical-align:top;text-align:right;">'.$status.'</td>'
            .'</tr></table>'
            .'<div style="height:3px;background:'.$accent.';margin:14px 0 20px;"></div>'
            // Parties
            .'<table><tr>'.$party($data['from'] ?? null, 'From').$party($data['to'] ?? null, 'For').'</tr></table>'
            // Items
            .'<table class="items"><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>'
            // Summary
            .'<table align="right" style="margin-top:14px;">'.$summary.'</table>'
            .'<div style="clear:both;"></div>'
            .$notes
            .$signature
            // Footer
            .'<div style="margin-top:40px;border-top:1px solid #e5e7eb;padding-top:8px;font-size:9px;color:#9ca3af;text-align:center;">'.$footer.'</div>'
            .'</body></html>';
    }
}
