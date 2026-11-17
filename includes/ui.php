<?php
// shadcn/ui-style presentation primitives for server-rendered PHP.
// Each helper emits the same class composition a shadcn/ui React component would,
// so the visual language is consistent and a future React island can reuse the tokens.

require_once __DIR__ . '/icons.php';

// ---- Shared class tokens -------------------------------------------------
const UI_CARD        = 'rounded-lg border border-border bg-card text-card-foreground shadow-card';
const UI_CARD_HEADER = 'flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4';
const UI_CARD_TITLE  = 'text-sm font-semibold leading-5 text-foreground';
const UI_CARD_BODY   = 'px-5 py-5';
const UI_LABEL       = 'text-sm font-medium leading-none text-foreground';
const UI_INPUT       = 'flex h-10 w-full rounded-md border border-input bg-white px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive/25';
const UI_HINT        = 'text-xs leading-relaxed text-muted-foreground';

/** Join non-empty class strings. */
function cn(string ...$classes): string
{
    return implode(' ', array_filter(array_map('trim', $classes)));
}

/** Escape + normalise a value for output. */
function val($v): string
{
    return e($v ?? '');
}

// ---- Button --------------------------------------------------------------
function ui_button(string $label, array $o = []): string
{
    $variants = [
        'primary'     => 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
        'secondary'   => 'bg-secondary text-secondary-foreground hover:bg-secondary/70',
        'outline'     => 'border border-input bg-white text-foreground hover:bg-secondary/60',
        'ghost'       => 'text-foreground hover:bg-secondary/70',
        'destructive' => 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
        'success'     => 'bg-success text-success-foreground shadow-sm hover:bg-success/90',
        'link'        => 'text-primary underline-offset-4 hover:underline',
    ];
    $sizes = [
        'sm'      => 'h-9 gap-1.5 px-3 text-sm',
        'default' => 'h-10 gap-2 px-4 text-sm',
        'lg'      => 'h-11 gap-2 px-5 text-[0.95rem]',
        'icon'    => 'h-9 w-9 justify-center',
    ];

    $variant = $o['variant'] ?? 'primary';
    $size = $o['size'] ?? 'default';
    $classes = cn(
        'inline-flex items-center justify-center whitespace-nowrap rounded-md font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-50',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['default'],
        $o['class'] ?? ''
    );

    $iconName = $o['icon'] ?? null;
    $iconHtml = $iconName ? icon($iconName, $o['icon_class'] ?? 'h-4 w-4', 2) : '';
    $inner = ($iconName && ($o['icon_position'] ?? 'start') === 'start' ? $iconHtml : '')
        . '<span>' . e($label) . '</span>'
        . ($iconName && ($o['icon_position'] ?? 'start') === 'end' ? $iconHtml : '');

    $extra = $o['attrs'] ?? '';
    if (isset($o['href'])) {
        return '<a href="' . e($o['href']) . '" class="' . $classes . '"' . ($extra ? ' ' . $extra : '') . '>'
            . $inner . '</a>';
    }
    $type = $o['type'] ?? 'button';
    $disabled = !empty($o['disabled']) ? ' disabled' : '';
    $name = isset($o['name']) ? ' name="' . e($o['name']) . '"' : '';
    $value = isset($o['value']) ? ' value="' . e($o['value']) . '"' : '';
    $aria = isset($o['aria_label']) ? ' aria-label="' . e($o['aria_label']) . '"' : '';
    return '<button type="' . e($type) . '"' . $name . $value . $aria . $disabled
        . ' class="' . $classes . '"' . ($extra ? ' ' . $extra : '') . '>' . $inner . '</button>';
}

// ---- Badge ---------------------------------------------------------------
function ui_badge(string $label, string $variant = 'default', string $class = ''): string
{
    $variants = [
        'default'     => 'border-transparent bg-primary/10 text-primary',
        'primary'     => 'border-transparent bg-primary text-primary-foreground',
        'secondary'   => 'border-transparent bg-secondary text-secondary-foreground',
        'outline'     => 'border-border bg-white text-foreground',
        'success'     => 'border-transparent bg-success/10 text-success',
        'warning'     => 'border-transparent bg-warning/15 text-warning',
        'destructive' => 'border-transparent bg-destructive/10 text-destructive',
        'muted'       => 'border-transparent bg-muted text-muted-foreground',
    ];
    return '<span class="' . cn(
        'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold',
        $variants[$variant] ?? $variants['default'],
        $class
    ) . '">' . e($label) . '</span>';
}

/** Glucose band → badge variant, shared by every view. */
function band_badge(string $band, string $label): string
{
    return match ($band) {
        'severe_low', 'very_high' => ui_badge($label, 'destructive'),
        'low', 'high'             => ui_badge($label, 'warning'),
        default                   => ui_badge($label, 'success'),
    };
}

// ---- Form field (shadcn Form = Label + input + FormMessage) --------------
function ui_field(array $o): string
{
    $id = $o['id'] ?? $o['name'];
    $name = $o['name'] ?? $id;
    $label = $o['label'] ?? '';
    $type = $o['type'] ?? 'text';
    $value = $o['value'] ?? '';
    $hint = $o['hint'] ?? '';
    $error = $o['error'] ?? '';
    $required = !empty($o['required']);
    $tag = $o['tag'] ?? 'input';
    $attrs = $o['attrs'] ?? '';
    $class = $o['class'] ?? '';
    $inputClass = cn(UI_INPUT, $error ? 'border-destructive' : '', $class);

    $described = [];
    if ($hint !== '') {
        $described[] = $id . '-hint';
    }
    if ($error !== '') {
        $described[] = $id . '-error';
    }
    $aria = ' aria-invalid="' . ($error ? 'true' : 'false') . '"'
        . ($described ? ' aria-describedby="' . implode(' ', $described) . '"' : '')
        . ($required ? ' required aria-required="true"' : '')
        . ($error ? ' autofocus' : '');

    if ($tag === 'select') {
        $control = '<select id="' . e($id) . '" name="' . e($name) . '" class="' . $inputClass . '"' . $aria
            . ($attrs ? ' ' . $attrs : '') . '>' . ($o['children'] ?? '') . '</select>';
    } elseif ($tag === 'textarea') {
        $control = '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int)($o['rows'] ?? 3)
            . '" class="' . $inputClass . ' min-h-[80px] py-2.5"' . $aria . ($attrs ? ' ' . $attrs : '') . '>'
            . e((string)$value) . '</textarea>';
    } else {
        $control = '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e((string)$value)
            . '" class="' . $inputClass . '"' . $aria . ($attrs ? ' ' . $attrs : '') . '>';
    }

    $html = '<div class="space-y-2">';
    if ($label !== '') {
        $html .= '<label for="' . e($id) . '" class="' . UI_LABEL . '">' . e($label)
            . ($required ? ' <span class="text-destructive" aria-hidden="true">*</span>' : '')
            . '</label>';
    }
    $html .= $control;
    if ($hint !== '' && $error === '') {
        $html .= '<p id="' . e($id) . '-hint" class="' . UI_HINT . '">' . e($hint) . '</p>';
    }
    if ($error !== '') {
        $html .= '<p id="' . e($id) . '-error" role="alert" class="flex items-start gap-1.5 text-xs font-medium text-destructive">'
            . icon('alert-triangle', 'mt-px h-3.5 w-3.5 shrink-0') . '<span>' . e($error) . '</span></p>';
    }
    return $html . '</div>';
}

// ---- Progress + circular gauge ------------------------------------------
function ui_progress(float $pct, bool $highConfidence = true, string $class = ''): string
{
    $pct = max(0, min(100, $pct));
    $bar = $highConfidence
        ? 'bg-gradient-to-r from-primary to-primary/80'
        : 'bg-gradient-to-r from-warning to-warning/80';
    return '<div class="h-2 w-full overflow-hidden rounded-full bg-muted ' . e($class) . '"'
        . ' role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e((string)$pct) . '"'
        . ' aria-label="' . e($highConfidence ? 'Adherence, high data confidence' : 'Adherence, below confidence threshold') . '">'
        . '<div class="h-full rounded-full transition-all duration-500 ' . $bar . '" style="width:' . $pct . '%"></div></div>';
}

/** Circular gauge: adherence ring with the threshold marker at 70%. */
function ui_gauge(float $pct, string $caption = '', string $size = 'md'): string
{
    $pct = max(0, min(100, $pct));
    $high = $pct >= HIGH_CONFIDENCE_PCT;
    $dim = $size === 'lg' ? 128 : 96;
    $stroke = $size === 'lg' ? 10 : 8;
    $r = ($dim - $stroke) / 2;
    $c = 2 * M_PI * $r;
    $dash = $c * ($pct / 100);
    $tone = $high ? 'text-primary' : 'text-warning';

    $html = '<div class="flex items-center gap-4">';
    $html .= '<div class="relative shrink-0" style="width:' . $dim . 'px;height:' . $dim . 'px">';
    $html .= '<svg viewBox="0 0 ' . $dim . ' ' . $dim . '" class="h-full w-full -rotate-90" aria-hidden="true">';
    $html .= '<circle cx="' . ($dim / 2) . '" cy="' . ($dim / 2) . '" r="' . $r . '" fill="none" stroke="hsl(var(--muted))" stroke-width="' . $stroke . '"/>';
    $html .= '<circle cx="' . ($dim / 2) . '" cy="' . ($dim / 2) . '" r="' . $r . '" fill="none" stroke="currentColor"'
        . ' class="' . $tone . '" stroke-width="' . $stroke . '" stroke-linecap="round"'
        . ' stroke-dasharray="' . round($dash, 2) . ' ' . round($c, 2) . '"'
        . ' style="transition:stroke-dasharray .6s ease"/>';
    $html .= '</svg>';
    $html .= '<div class="absolute inset-0 flex flex-col items-center justify-center">'
        . '<span class="text-xl font-bold tabular text-foreground">' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '%</span>'
        . '<span class="text-[0.6rem] font-semibold uppercase tracking-wide text-muted-foreground">adherence</span></div>';
    $html .= '</div>';
    if ($caption !== '') {
        $html .= '<div class="min-w-0 text-sm"><p class="font-medium text-foreground">' . e($caption) . '</p>'
            . '<p class="mt-1 text-xs text-muted-foreground">Target ≥ ' . HIGH_CONFIDENCE_PCT . '%</p>'
            . '<p class="mt-2">' . ui_badge($high ? 'High data confidence' : 'Low data confidence', $high ? 'success' : 'warning') . '</p></div>';
    }
    return $html . '</div>';
}

// ---- Alert ---------------------------------------------------------------
function ui_alert(string $tone, string $title, string $body = '', string $iconName = '', string $class = ''): string
{
    $tones = [
        'info'        => ['border-primary/25 bg-primary-subtle text-primary', 'info'],
        'success'     => ['border-success/25 bg-success-subtle text-success', 'check-circle'],
        'warning'     => ['border-warning/30 bg-warning-subtle text-warning', 'alert-triangle'],
        'destructive' => ['border-destructive/25 bg-destructive-subtle text-destructive', 'alert-octagon'],
    ];
    [$toneClass, $defaultIcon] = $tones[$tone] ?? $tones['info'];
    return '<div role="' . ($tone === 'destructive' || $tone === 'warning' ? 'alert' : 'status') . '" class="'
        . cn('flex gap-3 rounded-lg border px-4 py-3 text-sm', $toneClass, $class) . '">'
        . '<span class="mt-0.5 shrink-0">' . icon($iconName ?: $defaultIcon, 'h-4 w-4') . '</span>'
        . '<div class="min-w-0 space-y-0.5">'
        . '<p class="font-semibold leading-5">' . e($title) . '</p>'
        . ($body !== '' ? '<div class="text-[0.83rem] leading-relaxed text-foreground/80">' . $body . '</div>' : '')
        . '</div></div>';
}

// ---- Empty state ---------------------------------------------------------
function ui_empty(string $iconName, string $title, string $body = ''): string
{
    return '<div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">'
        . '<span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground">'
        . icon($iconName, 'h-5 w-5') . '</span>'
        . '<p class="text-sm font-medium text-foreground">' . e($title) . '</p>'
        . ($body !== '' ? '<p class="max-w-sm text-xs leading-relaxed text-muted-foreground">' . e($body) . '</p>' : '')
        . '</div>';
}

// ---- Page header ---------------------------------------------------------
function ui_page_header(string $title, string $description = '', string $actions = ''): string
{
    return '<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">'
        . '<div class="min-w-0"><h1 class="text-2xl font-semibold tracking-tight text-foreground">' . e($title) . '</h1>'
        . ($description !== '' ? '<p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-muted-foreground">' . e($description) . '</p>' : '')
        . '</div>'
        . ($actions !== '' ? '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>' : '')
        . '</div>';
}

// ---- Pagination -----------------------------------------------------------
/**
 * Footer for a paged list. $query keeps the current filters in the links.
 * Always rendered, so a capped list can never hide rows silently.
 */
function ui_pager(int $page, int $perPage, int $total, array $query = [], string $param = 'page'): string
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $to = min($total, $page * $perPage);
    $href = fn(int $p) => '?' . http_build_query(array_merge($query, [$param => $p]));

    $link = function (int $p, string $label, bool $enabled) use ($href): string {
        $base = 'inline-flex h-8 items-center rounded-md border px-2.5 text-xs font-medium transition-colors ';
        if (!$enabled) {
            return '<span class="' . $base . 'border-border text-muted-foreground/50">' . e($label) . '</span>';
        }
        return '<a class="' . $base . 'border-border bg-white text-foreground hover:bg-muted" href="' . e($href($p)) . '">' . e($label) . '</a>';
    };

    return '<div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">'
        . '<p class="text-xs text-muted-foreground">Showing <span class="font-semibold text-foreground">' . $from . '–' . $to . '</span> of ' . $total . '</p>'
        . ($pages > 1
            ? '<div class="flex items-center gap-2">' . $link($page - 1, 'Previous', $page > 1)
                . '<span class="text-xs text-muted-foreground">Page ' . $page . ' of ' . $pages . '</span>'
                . $link($page + 1, 'Next', $page < $pages) . '</div>'
            : '')
        . '</div>';
}

// ---- Dialog (native <dialog> = focus trap, Esc, aria-modal) --------------
function ui_dialog(string $id, string $title, string $description = '', string $body = '', string $footer = '', array $o = []): string
{
    $wide = !empty($o['wide']) ? ' max-w-2xl' : '';
    $autoshow = !empty($o['autoshow']) ? ' data-autoshow="1"' : '';
    // 'flush' lets a dialog own its padding (e.g. full-width action rows).
    $bodyClass = 'app-dialog-body border-t border-border' . (!empty($o['flush']) ? ' px-0 py-0' : '');
    $iconName = $o['icon'] ?? '';
    return '<dialog id="' . e($id) . '" class="app-dialog' . $wide . '" aria-labelledby="' . e($id) . '-title"'
        . ($description !== '' ? ' aria-describedby="' . e($id) . '-desc"' : '') . $autoshow . '>'
        . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
        . '<div class="flex items-start gap-3">'
        . ($iconName ? '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">'
            . icon($iconName, 'h-4 w-4') . '</span>' : '')
        . '<div><h2 id="' . e($id) . '-title" class="text-base font-semibold leading-6 text-foreground">' . e($title) . '</h2>'
        . ($description !== '' ? '<p id="' . e($id) . '-desc" class="mt-1 text-sm leading-relaxed text-muted-foreground">' . e($description) . '</p>' : '')
        . '</div></div>'
        . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">'
        . icon('x', 'h-4 w-4') . '</button></div>'
        . '<div class="' . $bodyClass . '">' . $body . '</div>'
        . ($footer !== '' ? '<div class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">' . $footer . '</div>' : '')
        . '</dialog>';
}
